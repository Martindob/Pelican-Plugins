<?php

namespace Martindob\PaperVelocityUpdater\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PaperVelocityUpdateService
{
    private const STATE_FILE = '.paper-velocity-updater.json';

    private const FILL_BASE_URL = 'https://fill.papermc.io/v3';

    /** How many versions resolveLatestStable() will check before giving up. */
    private const MAX_LATEST_VERSION_CANDIDATES = 10;

    /** Egg variable name(s) that identify a project and hold its target version. */
    private const PROJECT_VERSION_VARIABLES = [
        'paper' => ['MINECRAFT_VERSION', 'MC_VERSION'],
        'velocity' => ['VELOCITY_VERSION'],
    ];

    /**
     * Checks whether a newer Paper/Velocity build is available for the server and,
     * if so, downloads it and replaces the server jar before it (re)starts.
     *
     * A failed *check* (PaperMC unreachable, daemon read error, ...) never prevents
     * the server from starting - it just boots on whatever jar is already there.
     * A failed *download* is different: once the daemon has been asked to pull a
     * file, it may still be mid-write on the exact jar the server is about to run
     * even if our request to it times out (the daemon keeps writing in the
     * background regardless of whether the panel is still waiting on it). So unlike
     * the check itself, that failure is deliberately allowed to propagate out of
     * this method and out of power() - the whole power action fails instead of
     * risking a start against a half-written jar.
     *
     * @throws Exception
     */
    public function maybeUpdate(Server $server): void
    {
        if (!config('paper-velocity-updater.enabled', true)) {
            return;
        }

        // Cheap, in-memory check done *before* touching the cache/lock at all.
        // Most restarts on a panel are not for a Paper/Velocity server, and
        // those must not pay for a distributed lock acquisition (and the
        // variables eager-load) on every single restart of every server on the
        // whole panel just to find out this plugin has nothing to do.
        $server->loadMissing('variables');
        if ($this->detectProject($server) === null) {
            return;
        }

        // Two "start"/"restart" clicks fired in quick succession (a double click, or
        // restart followed immediately by start) must never let the second one race
        // ahead of the first's download: skipping the check outright when the lock
        // is already held would let it proceed straight to its own power signal
        // while the first request might still be mid-write on the very jar the
        // server is about to run. So this *waits* for any in-flight check/download
        // for this server to finish - rather than skipping past it - before this
        // action is allowed to continue to its own power signal. A LockTimeoutException
        // here is deliberately not caught, for the same reason a download failure
        // isn't: proceeding without knowing whether the other write finished is
        // exactly the risk this is meant to avoid.
        Cache::lock("paper-velocity-updater:server:{$server->id}", $this->lockTtlSeconds())
            ->block($this->lockWaitSeconds(), function () use ($server) {
                $plan = $this->plan($server);
                if ($plan !== null) {
                    $this->applyPlan($server, $plan);
                }
            });
    }

    /**
     * How long a legitimate lock holder may run for. This has to cover the
     * *entire* critical section, not just the download: the two PaperMC lookups
     * and the two daemon reads (state file, directory listing) in plan() each
     * have their own independent timeout and run before the download even
     * starts, and writeState() is another daemon call after it. The fixed 180s
     * margin comfortably covers all of that on top of the configurable download
     * timeout, regardless of how low the latter is set.
     */
    private function lockTtlSeconds(): int
    {
        return $this->downloadTimeoutSeconds() + 180;
    }

    /**
     * How long a *waiter* blocks before giving up - deliberately much shorter
     * than lockTtlSeconds(), and not tied to the download timeout at all. This
     * runs synchronously inside the request handling the power action, so
     * waiting anywhere near as long as a full download could take would hit
     * the web server's/reverse proxy's own request timeout (commonly 30-60s)
     * long before our own timeout would - turning what's meant to be a
     * graceful wait-then-proceed into a raw, unhandled gateway timeout for the
     * user instead of the clean, retryable error a LockTimeoutException here
     * becomes (see power(), which converts it to a ConnectionException the
     * panel already shows a proper notification for).
     */
    private function lockWaitSeconds(): int
    {
        return 10;
    }

    /**
     * Works out whether an update is needed and, if so, what to download. Every
     * failure here (PaperMC or the daemon being unreachable, a bad response, ...)
     * is safe to swallow: nothing has been written yet, so the server can just
     * start on whatever jar is already on disk.
     *
     * @return array{fileRepository: DaemonFileRepository, project: string, version: string, build: int, jar: string, url: string, confirmedKey: string}|null
     */
    private function plan(Server $server): ?array
    {
        try {
            $server->loadMissing('variables');

            $project = $this->detectProject($server);
            if ($project === null) {
                return null;
            }

            // Respect an explicitly pinned build number; only "latest" (the default
            // shown in the startup variables) triggers an automatic update.
            $buildVariable = $this->getVariable($server, 'BUILD_NUMBER');
            if ($buildVariable !== null && !$this->isLatest($buildVariable)) {
                return null;
            }

            $jarFile = $this->getVariable($server, 'SERVER_JARFILE') ?? ($project === 'velocity' ? 'velocity.jar' : 'server.jar');

            $requestedVersion = null;
            foreach (self::PROJECT_VERSION_VARIABLES[$project] as $variableName) {
                $requestedVersion = $this->getVariable($server, $variableName);
                if ($requestedVersion !== null) {
                    break;
                }
            }

            // A pinned version is a hard lock: if it can't be positively
            // verified against PaperMC's own version list - genuinely invalid,
            // or the API/cache being temporarily unavailable - this skips the
            // update rather than silently falling back to "latest". Falling
            // back on an inconclusive check would mean a single transient
            // PaperMC hiccup could bump a pinned server onto a version its
            // admin never asked for, which defeats the entire point of
            // pinning one.
            if ($this->isLatest($requestedVersion)) {
                $resolved = $this->resolveLatestStable($project);
                if ($resolved === null) {
                    return null;
                }

                $version = $resolved['version'];
                $build = $resolved['build'];
            } else {
                $version = trim((string) $requestedVersion);

                if (!$this->versionExists($project, $version)) {
                    return null;
                }

                $build = $this->resolveBuildForPinnedVersion($project, $version);
                if ($build === null) {
                    return null;
                }
            }

            $download = $build['downloads']['server:default'] ?? null;
            if (!is_array($download) || !isset($download['url'])) {
                return null;
            }

            $confirmedKey = $this->confirmedCacheKey($server->id, $project, $version, $build['id'], $jarFile);

            // Once a restart has actually confirmed this exact build is already
            // installed and present on disk, later restarts within the same
            // version/build cache window skip the daemon round-trips entirely
            // instead of re-reading the marker file and re-listing the server
            // directory every single time - restarting an already up to date
            // server repeatedly (e.g. while configuring it) then costs zero
            // daemon calls instead of two.
            if (Cache::has($confirmedKey)) {
                return null;
            }

            $fileRepository = app(DaemonFileRepository::class)->setServer($server);

            $state = $this->readState($fileRepository, $server->id);
            $upToDate = $state !== null
                && ($state['project'] ?? null) === $project
                && ($state['version'] ?? null) === $version
                && ($state['build'] ?? null) === $build['id']
                && ($state['jar'] ?? null) === $jarFile
                && $this->jarExists($fileRepository, $jarFile);

            if ($upToDate) {
                Cache::put($confirmedKey, true, now()->addMinutes($this->cacheMinutes()));

                return null;
            }

            return [
                'fileRepository' => $fileRepository,
                'project' => $project,
                'version' => $version,
                'build' => $build['id'],
                'jar' => $jarFile,
                'url' => $download['url'],
                'confirmedKey' => $confirmedKey,
            ];
        } catch (Exception $exception) {
            // Someone restarting a server repeatedly (e.g. while still configuring
            // it) would otherwise log the same daemon/network failure on every
            // single restart. One log entry per server/error per window is enough
            // to notice a real, persistent problem without spamming the log.
            $this->reportOncePerWindow("server:{$server->id}:" . $exception::class, $exception);

            return null;
        }
    }

    /**
     * Downloads the resolved build straight into the server directory, replacing
     * the existing jar, and records what was installed.
     *
     * Uses a much longer timeout than the daemon client's 15 second default
     * (config('panel.guzzle.timeout')): that default is fine for small API calls
     * but a ~50-60MB Paper/Velocity jar can easily take longer than that,
     * especially on a slower node.
     *
     * @param  array{fileRepository: DaemonFileRepository, project: string, version: string, build: int, jar: string, url: string, confirmedKey: string}  $plan
     *
     * @throws Exception
     */
    private function applyPlan(Server $server, array $plan): void
    {
        $plan['fileRepository']->getHttpClient()
            ->timeout(max((int) config('panel.guzzle.timeout'), $this->downloadTimeoutSeconds()))
            ->post("/api/servers/{$server->uuid}/files/pull", [
                'url' => $plan['url'],
                'root' => '/',
                'file_name' => $plan['jar'],
                'foreground' => true,
            ])
            ->throw();

        Cache::put($plan['confirmedKey'], true, now()->addMinutes($this->cacheMinutes()));

        try {
            $this->writeState($plan['fileRepository'], [
                'project' => $plan['project'],
                'version' => $plan['version'],
                'build' => $plan['build'],
                'jar' => $plan['jar'],
                'updated_at' => now()->toIso8601String(),
            ]);
        } catch (Exception $exception) {
            // The jar itself already downloaded fine at this point; losing the
            // marker only means the next restart re-verifies (and, worst case,
            // re-downloads) unnecessarily - not worth failing the power action over.
            $this->reportOncePerWindow("server:{$server->id}:write-state", $exception);
        }
    }

    private function downloadTimeoutSeconds(): int
    {
        // Clamped here too, not just in the settings form's minValue(60): a
        // value edited directly in .env could otherwise bypass that and bring
        // back the daemon client's own too-short 15 second default.
        return max(60, (int) config('paper-velocity-updater.download_timeout_seconds', 300));
    }

    private function confirmedCacheKey(int $serverId, string $project, string $version, int $build, string $jarFile): string
    {
        return "paper-velocity-updater:confirmed:$serverId:$project:$version:$build:$jarFile";
    }

    /**
     * Detects whether this server is a Paper or Velocity server from its
     * startup variables. Requires BUILD_NUMBER to be defined alongside the
     * version variable: MINECRAFT_VERSION/MC_VERSION alone is not unique to
     * Paper - the sibling minecraft-modrinth plugin (and Fabric/Forge/Quilt/
     * NeoForge eggs generally) reads the exact same variable for modded
     * loaders, which have no concept of a PaperMC "build number". Without this
     * check, a modded server whose egg happens to define MINECRAFT_VERSION but
     * no BUILD_NUMBER would be misidentified as Paper and have its actual jar
     * overwritten with a vanilla Paper one.
     */
    private function detectProject(Server $server): ?string
    {
        if (!$this->hasVariable($server, 'BUILD_NUMBER')) {
            return null;
        }

        foreach (self::PROJECT_VERSION_VARIABLES as $project => $variableNames) {
            foreach ($variableNames as $variableName) {
                if ($this->hasVariable($server, $variableName)) {
                    return $project;
                }
            }
        }

        return null;
    }

    /** Whether the egg defines this variable at all, regardless of its value. */
    private function hasVariable(Server $server, string $name): bool
    {
        return $server->variables->firstWhere('env_variable', $name) !== null;
    }

    private function getVariable(Server $server, string $name): ?string
    {
        /** @var \App\Models\EggVariable|null $variable */
        $variable = $server->variables->firstWhere('env_variable', $name);
        if (!$variable) {
            return null;
        }

        $value = $variable->server_value ?? $variable->default_value;

        return $value !== null && $value !== '' ? $value : null;
    }

    private function isLatest(?string $value): bool
    {
        return $value === null || strtolower(trim($value)) === 'latest';
    }

    /** @return array<string, array<int, string>> */
    private function fetchVersionGroups(string $project): array
    {
        return cache()->remember(
            "paper-velocity-updater:versions:$project",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project) {
                try {
                    $versions = $this->http()->get("/projects/$project")->json('versions');

                    return is_array($versions) ? $versions : [];
                } catch (Exception $exception) {
                    $this->reportOncePerWindow("versions:$project", $exception);

                    return [];
                }
            }
        );
    }

    /**
     * Resolves "latest" to the newest version that actually has a
     * STABLE-channel build, returning both together.
     *
     * Version *names* are not a reliable stability signal, verified live
     * against the Fill API: Paper's own "26.3" is a perfectly clean version
     * string with no snapshot/rc/pre suffix, yet every one of its builds is
     * currently channel ALPHA, while every "26.2" build is STABLE - matching
     * papermc.io/downloads/paper's own "Latest Stable Version: Paper 26.2".
     * Conversely Velocity's "4.2.1-SNAPSHOT" - which *does* look like a
     * pre-release by name - has a STABLE build and is exactly what
     * papermc.io/downloads/velocity itself presents as the current download.
     * So this ignores version names entirely and walks versions newest first,
     * accepting the first one whose builds actually include a STABLE one.
     *
     * @return array{version: string, build: array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}}|null
     */
    private function resolveLatestStable(string $project): ?array
    {
        $checked = 0;

        foreach ($this->fetchVersionGroups($project) as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $version) {
                if (!is_string($version)) {
                    continue;
                }

                // Bounded so a long run of alpha/experimental versions (e.g. a
                // whole new major line still early in development) can't turn
                // a single "latest" resolution into an unbounded chain of API
                // calls.
                if (++$checked > self::MAX_LATEST_VERSION_CANDIDATES) {
                    return null;
                }

                $build = $this->pickStableBuild($this->fetchBuilds($project, $version));
                if ($build !== null) {
                    return ['version' => $version, 'build' => $build];
                }
            }
        }

        return null;
    }

    private function versionExists(string $project, ?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        foreach ($this->fetchVersionGroups($project) as $group) {
            if (is_array($group) && in_array($version, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the build to use for a version the admin explicitly pinned.
     * Unlike resolveLatestStable(), this falls back to the newest build of
     * any channel if the pinned version genuinely has no STABLE build yet -
     * the admin asked for this exact version, so it's better to give them the
     * best available build for it than nothing at all.
     *
     * @return array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}|null
     */
    private function resolveBuildForPinnedVersion(string $project, string $version): ?array
    {
        $builds = $this->fetchBuilds($project, $version);

        return $this->pickStableBuild($builds) ?? $this->pickAnyBuild($builds);
    }

    /** @param  array<int, mixed>  $builds */
    private function pickStableBuild(array $builds): ?array
    {
        foreach ($builds as $build) {
            if (is_array($build) && ($build['channel'] ?? null) === 'STABLE' && isset($build['id'])) {
                return $build;
            }
        }

        return null;
    }

    /** @param  array<int, mixed>  $builds */
    private function pickAnyBuild(array $builds): ?array
    {
        $candidate = $builds[0] ?? null;

        return is_array($candidate) && isset($candidate['id']) ? $candidate : null;
    }

    /**
     * Raw, cached build list for a single version. An empty array is used as
     * the "nothing/failed" sentinel (not null/false): cache()->remember()
     * can't distinguish a cached null from a cache miss, but an empty array
     * is unambiguous, so a failure still actually gets cached instead of
     * re-hitting PaperMC's API on every single restart during an outage.
     *
     * @return array<int, mixed>
     */
    private function fetchBuilds(string $project, string $version): array
    {
        $builds = cache()->remember(
            "paper-velocity-updater:build:$project:$version",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project, $version) {
                try {
                    $builds = $this->http()->get("/projects/$project/versions/$version/builds")->json();
                } catch (Exception $exception) {
                    $this->reportOncePerWindow("build:$project:$version", $exception);

                    return [];
                }

                return is_array($builds) ? $builds : [];
            }
        );

        return is_array($builds) ? $builds : [];
    }

    private function cacheMinutes(): int
    {
        return (int) config('paper-velocity-updater.cache_minutes', 15);
    }

    /**
     * Reports an exception at most once per $key within the configured throttle
     * window, so a persistent problem (daemon unreachable, bad credentials, ...)
     * isn't logged again on every single restart of the affected server.
     */
    private function reportOncePerWindow(string $key, Exception $exception): void
    {
        $minutes = (int) config('paper-velocity-updater.report_throttle_minutes', 30);

        if ($minutes <= 0 || Cache::add("paper-velocity-updater:reported:$key", true, now()->addMinutes($minutes))) {
            report($exception);
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::FILL_BASE_URL)
            ->withHeaders(['User-Agent' => config('paper-velocity-updater.user_agent')])
            ->timeout(10)
            ->connectTimeout(5)
            ->throw();
    }

    /** @return array{project?: string, version?: string, build?: int, jar?: string}|null */
    private function readState(DaemonFileRepository $fileRepository, int $serverId): ?array
    {
        try {
            $content = $fileRepository->getContent(self::STATE_FILE);
        } catch (FileNotFoundException) {
            // No marker yet - first check for this server.
            return null;
        } catch (Exception $exception) {
            $this->reportOncePerWindow("server:$serverId:read-state", $exception);

            return null;
        }

        $state = json_decode($content, true);

        return is_array($state) ? $state : null;
    }

    /** @param  array{project: string, version: string, build: int, jar: string, updated_at: string}  $state */
    private function writeState(DaemonFileRepository $fileRepository, array $state): void
    {
        $fileRepository->putContent(self::STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function jarExists(DaemonFileRepository $fileRepository, string $jarFile): bool
    {
        try {
            $files = $fileRepository->getDirectory('/');
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return false;
            }

            throw $exception;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $jarFile) {
                return true;
            }
        }

        return false;
    }
}
