<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\Notification\NotificationBatchReadRequest;
use App\Http\Requests\Notification\NotificationIndexRequest;
use App\Http\Resources\UserNotificationCollection;
use App\Http\Resources\UserNotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 사용자 알림 컨트롤러
 *
 * 인증된 사용자의 사이트내 알림을 관리합니다.
 */
class NotificationController extends AuthBaseController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    /**
     * 알림 목록을 조회합니다.
     */
    public function index(NotificationIndexRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $user = $request->user();
            $perPage = (int) ($validated['per_page'] ?? 20);
            $filters = collect($validated)->only(['read'])->filter(fn ($v) => $v !== null)->toArray();

            $notifications = $this->notificationService->getNotifications($user, $filters, $perPage);

            return $this->success(
                __('notification.user.list_success'),
                new UserNotificationCollection($notifications)
            );
        } catch (\Exception $e) {
            Log::error('알림 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.user.list_failed'), 500);
        }
    }

    /**
     * 미읽음 알림 수를 반환합니다.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $count = $this->notificationService->getUnreadCount($request->user());

            return $this->success(
                __('notification.user.unread_count_success'),
                ['unread_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('미읽음 알림 수 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.user.unread_count_failed'), 500);
        }
    }

    /**
     * 알림을 읽음 처리합니다.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->notificationService->markAsRead($request->user(), $id);

            if (! $notification) {
                return $this->error(__('notification.user.not_found'), 404);
            }

            return $this->success(
                __('notification.user.read_success'),
                new UserNotificationResource($notification)
            );
        } catch (\Exception $e) {
            Log::error('알림 읽음 처리 실패', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->error(__('notification.user.read_failed'), 500);
        }
    }

    /**
     * 지정된 알림들을 일괄 읽음 처리합니다.
     */
    public function markBatchAsRead(NotificationBatchReadRequest $request): JsonResponse
    {
        try {
            $ids = $request->validated()['ids'];

            $count = $this->notificationService->markBatchAsRead($request->user(), $ids);

            return $this->success(
                __('notification.user.read_success'),
                ['marked_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('알림 일괄 읽음 처리 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.user.read_failed'), 500);
        }
    }

    /**
     * 모든 알림을 읽음 처리합니다.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $count = $this->notificationService->markAllAsRead($request->user());

            return $this->success(
                __('notification.user.read_all_success'),
                ['marked_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('전체 읽음 처리 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.user.read_all_failed'), 500);
        }
    }

    /**
     * 사용자의 모든 알림을 삭제합니다.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        try {
            $count = $this->notificationService->deleteAllNotifications($request->user());

            return $this->success(
                __('notification.user.delete_all_success'),
                ['deleted_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('전체 알림 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification.user.delete_all_failed'), 500);
        }
    }

    /**
     * 알림을 삭제합니다.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $deleted = $this->notificationService->deleteNotification($request->user(), $id);

            if (! $deleted) {
                return $this->error(__('notification.user.not_found'), 404);
            }

            return $this->success(__('notification.user.delete_success'));
        } catch (\Exception $e) {
            Log::error('알림 삭제 실패', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->error(__('notification.user.delete_failed'), 500);
        }
    }
}
