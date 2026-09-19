<?php

namespace Martindob\PaperVelocityUpdater\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

/**
 * Decorates the panel's own DaemonServerRepository so that a "start"/"restart"
 * power action, or a reinstall, runs the Paper/Velocity update check first,
 * before the request is forwarded to Wings. This covers manual power actions
 * (console, client API), the "Power Action" scheduled task, and reinstalls,
 * since all of them resolve this class out of the container.
 */
class UpdateCheckingDaemonServerRepository extends DaemonServerRepository
{
    private const UPDATE_ON_SIGNALS = ['start', 'restart'];

    public function __construct(private readonly PaperVelocityUpdateService $updateService) {}

    /**
     * If the update check itself fails (PaperMC/daemon unreachable, ...) this still
     * proceeds to the actual power signal - see PaperVelocityUpdateService::maybeUpdate().
     * A failed *download*, however, is deliberately allowed to abort this method
     * entirely: never send "start"/"restart" to Wings while the jar it's about to
     * run might still be mid-write on the daemon.
     *
     * @throws ConnectionException|Exception
     */
    public function power(string $action): Response
    {
        if (isset($this->server) && in_array($action, self::UPDATE_ON_SIGNALS, true)) {
            $this->updateService->maybeUpdate($this->server);
        }

        return parent::power($action);
    }

    /**
     * A reinstall re-runs the egg's own install script, which may or may not
     * resolve a pinned version/build the same way this plugin does. Running the
     * same check here first guarantees the same "download this exact pinned
     * version's newest build" behaviour applies on reinstall too, not just on
     * start/restart. Same failure semantics as power(): a failed check is
     * swallowed, a failed download aborts the reinstall.
     *
     * @throws ConnectionException|Exception
     */
    public function reinstall(): void
    {
        if (isset($this->server)) {
            $this->updateService->maybeUpdate($this->server);
        }

        parent::reinstall();
    }
}
