<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\NotificationService;
use App\Services\SchedulerService;

/**
 * Notification preferences, history and operational health.
 */
final class NotificationController extends BaseController
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SchedulerService $scheduler,
    ) {
    }

    /** GET /api/v1/notifications/preferences */
    public function preferences(Request $request): Response
    {
        return Response::success($this->notifications->preferencesFor($request), 'Preferences loaded');
    }

    /** PATCH /api/v1/notifications/preferences */
    public function updatePreferences(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'sms' => 'nullable|boolean',
            'email' => 'nullable|boolean',
            'whatsapp' => 'nullable|boolean',
            'push' => 'nullable|boolean',
        ]);

        $channels = [];

        foreach (['sms', 'email', 'whatsapp', 'push'] as $channel) {
            if ($request->has($channel)) {
                $channels[$channel] = (bool) $data[$channel];
            }
        }

        if ($channels === []) {
            throw new HttpException('No preferences were supplied.', 422);
        }

        return Response::success(
            $this->notifications->updatePreferences($request, $channels),
            'Preferences updated'
        );
    }

    /** GET /api/v1/notifications/history */
    public function history(Request $request): Response
    {
        return Response::success(
            ['notifications' => $this->notifications->historyFor($request)],
            'Notification history loaded'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/notifications/health */
    public function health(Request $request): Response
    {
        return Response::success(
            ['channels' => $this->notifications->health()],
            'Notification health loaded'
        );
    }

    /** POST /api/v1/admin/notifications/dispatch */
    public function dispatch(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->input('limit', 50)));

        return Response::success($this->notifications->dispatchQueue($limit), 'Queue dispatched');
    }

    /** GET /api/v1/admin/scheduler/tasks */
    public function tasks(Request $request): Response
    {
        return Response::success([
            'tasks' => $this->scheduler->tasks(),
            'recent_runs' => $this->scheduler->recentRuns(25),
        ], 'Scheduled tasks loaded');
    }

    /**
     * POST /api/v1/admin/scheduler/run
     *
     * Runs due work on demand. Useful during an incident, and safe alongside
     * cron because each task is claimed under a lock.
     */
    public function runScheduler(Request $request): Response
    {
        $task = $request->input('task');

        return Response::success(
            $this->scheduler->run($request, is_string($task) && $task !== '' ? $task : null),
            'Scheduler run complete'
        );
    }

    /** PATCH /api/v1/admin/scheduler/tasks/{code} — turn any scheduled task on or off. */
    public function setTaskEnabled(Request $request): Response
    {
        $data = Validator::make($request->all(), ['is_enabled' => 'required|boolean']);

        $this->scheduler->setEnabled((string) $request->routeParam('code'), (bool) $data['is_enabled']);

        return Response::success([], 'Task updated');
    }
}
