<?php

namespace Martindob\PaperVelocityUpdater\Repositories;

use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

/**
 * Decorates the panel's own DaemonServerRepository so that a "start"/"restart"
 * power action runs the Paper/Velocity update check first, before the signal
 * is forwarded to Wings. This covers both manual power actions (console,
 * client API) and the "Power Action" scheduled task, since both resolve this
 * class out of the container.
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
}
