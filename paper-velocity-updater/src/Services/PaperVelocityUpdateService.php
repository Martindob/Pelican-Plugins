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
        //
        // The lock's own TTL and the wait timeout are the same value on purpose:
        // a waiter must never give up before a legitimate holder's own budget
        // runs out, or it would abort thinking something is stuck when the first
        // request is simply still within its allowed time.
        $ttl = $this->lockTtlSeconds();

        Cache::lock("paper-velocity-updater:server:{$server->id}", $ttl)
            ->block($ttl, function () use ($server) {
                $plan = $this->plan($server);
                if ($plan !== null) {
                    $this->applyPlan($server, $plan);
                }
            });
    }

    /**
     * How long the per-server lock is held for/waited on. This has to cover the
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
     * Works out whether an update is needed and, if so, what to download. Every
     * failure here (PaperMC or the daemon being unreachable, a bad response, ...)
     * is safe to swallow: nothing has been written yet, so the server can just
     * start on whatever jar is already on disk.
     *
     * @return array{fileRepository: DaemonFileRepository, project: string, version: string, build: int, jar: string, url: string}|null
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

            $version = $this->resolveVersion($project, $requestedVersion);
            if ($version === null) {
                return null;
            }

            $build = $this->resolveLatestBuild($project, $version);
            if ($build === null) {
                return null;
            }

            $download = $build['downloads']['server:default'] ?? null;
            if (!is_array($download) || !isset($download['url'])) {
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
                return null;
            }

            return [
                'fileRepository' => $fileRepository,
                'project' => $project,
                'version' => $version,
                'build' => $build['id'],
                'jar' => $jarFile,
                'url' => $download['url'],
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
     * @param  array{fileRepository: DaemonFileRepository, project: string, version: string, build: int, jar: string, url: string}  $plan
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

    /** Detects whether this server is a Paper or Velocity server from its startup variables. */
    private function detectProject(Server $server): ?string
    {
        foreach (self::PROJECT_VERSION_VARIABLES as $project => $variableNames) {
            foreach ($variableNames as $variableName) {
                if ($server->variables->firstWhere('env_variable', $variableName)) {
                    return $project;
                }
            }
        }

        return null;
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

    /**
     * Resolves the requested version to a concrete Minecraft/Velocity version string.
     *
     * Only "latest" (or an empty/missing variable) resolves to the newest available
     * version. A *pinned* version is a hard lock: if it can't be positively verified
     * against PaperMC's own version list - because it's genuinely invalid, or
     * because the API/cache is temporarily unavailable - this returns null rather
     * than silently falling back to the newest version. Falling back on an
     * inconclusive check would mean a single transient PaperMC hiccup could bump a
     * pinned server onto a version its admin never asked for, which defeats the
     * entire point of pinning one. The caller treats null as "skip this update
     * cycle", not as "use latest".
     */
    private function resolveVersion(string $project, ?string $requestedVersion): ?string
    {
        if ($this->isLatest($requestedVersion)) {
            return $this->resolveLatestVersion($project);
        }

        // isLatest() already returned false for null, so $requestedVersion is a
        // real string here; the cast just keeps static analysis happy about it.
        $version = trim((string) $requestedVersion);

        return $this->versionExists($project, $version) ? $version : null;
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

    private function resolveLatestVersion(string $project): ?string
    {
        $groups = $this->fetchVersionGroups($project);
        $firstGroup = reset($groups);

        if (!is_array($firstGroup) || empty($firstGroup)) {
            return null;
        }

        return reset($firstGroup);
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

    /** @return array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}|null */
    private function resolveLatestBuild(string $project, string $version): ?array
    {
        // cache()->remember() can't distinguish "cached null" from "cache miss"
        // (it checks the value with is_null()), so a closure returning null on
        // failure would never actually be cached - every restart during a
        // PaperMC outage would re-hit the API instead of reusing a cached
        // failure. false is used as the "nothing found" sentinel instead, since
        // that a remember() call can actually cache.
        $build = cache()->remember(
            "paper-velocity-updater:build:$project:$version",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project, $version) {
                try {
                    $builds = $this->http()->get("/projects/$project/versions/$version/builds")->json();
                } catch (Exception $exception) {
                    $this->reportOncePerWindow("build:$project:$version", $exception);

                    return false;
                }

                if (!is_array($builds) || empty($builds)) {
                    return false;
                }

                // The API returns builds newest first; prefer a stable one but fall
                // back to the newest build of any channel (e.g. version-only-in-beta).
                $stable = array_values(array_filter($builds, fn ($build) => is_array($build) && ($build['channel'] ?? null) === 'STABLE'));
                $candidate = $stable[0] ?? $builds[0];

                if (!is_array($candidate) || !isset($candidate['id'])) {
                    return false;
                }

                return $candidate;
            }
        );

        return is_array($build) ? $build : null;
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
