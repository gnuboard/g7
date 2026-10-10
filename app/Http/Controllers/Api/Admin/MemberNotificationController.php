<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\MemberNotification\MemberNotificationBulkDeleteRequest;
use App\Http\Requests\MemberNotification\MemberNotificationIndexRequest;
use App\Http\Resources\MemberNotificationCollection;
use App\Services\MemberNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 관리자 회원 알림함 컨트롤러
 *
 * 회원이 «받은» in-app 알림을 전 회원 범위로 조회하고 삭제한다. 기존
 * `admin/notifications` 는 **관리자 본인의 수신함**이고, `admin/notification-logs` 는
 * 발송 이력이다 — 셋은 서로 다른 축이라 한 화면으로 합치지 않는다.
 */
class MemberNotificationController extends AdminBaseController
{
    /**
     * MemberNotificationController 생성자
     *
     * @param  MemberNotificationService  $service  회원 알림함 서비스
     * @param  UserRepositoryInterface  $userRepository  사용자 리포지토리 (UUID 변환용)
     */
    public function __construct(
        private readonly MemberNotificationService $service,
        private readonly UserRepositoryInterface $userRepository,
    ) {
        parent::__construct();
    }

    /**
     * 전 회원의 알림함 목록을 조회합니다.
     *
     * @param  MemberNotificationIndexRequest  $request  목록 조회 요청
     * @return JsonResponse 알림 목록 응답
     */
    public function index(MemberNotificationIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $filters['user_id'] = $this->resolveUserId($filters);
            $perPage = (int) ($filters['per_page'] ?? 20);

            $notifications = $this->service->paginateForAdmin($filters, $perPage);

            return $this->success(
                __('member_notification.list_retrieved'),
                (new MemberNotificationCollection($notifications))->toArray($request)
            );
        } catch (\Exception $e) {
            Log::error('회원 알림함 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('member_notification.list_failed'), 500);
        }
    }

    /**
     * 알림 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  string  $id  알림 uuid
     * @return JsonResponse 삭제 결과
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            if (! $this->service->deleteByAdmin($id)) {
                return $this->notFound('member_notification.not_found');
            }

            return $this->success(__('member_notification.deleted'));
        } catch (\Exception $e) {
            Log::error('회원 알림 삭제 실패', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->error(__('member_notification.delete_failed'), 500);
        }
    }

    /**
     * 선택한 알림을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  MemberNotificationBulkDeleteRequest  $request  uuid 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(MemberNotificationBulkDeleteRequest $request): JsonResponse
    {
        try {
            $ids = $request->validated()['ids'];
            $count = $this->service->bulkDeleteByAdmin($ids);

            return $this->success(
                trans_choice('member_notification.bulk_deleted', $count),
                ['deleted_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('회원 알림 일괄 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error(__('member_notification.delete_failed'), 500);
        }
    }

    /**
     * 회원 필터를 user_id 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를 무시하고
     * 전체를 보여주면 "이 회원의 알림함" 을 요청한 화면에 남의 알림이 뜬다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return int|null 회원 ID (필터 없으면 null)
     */
    private function resolveUserId(array $filters): ?int
    {
        if (! empty($filters['user_id'])) {
            return (int) $filters['user_id'];
        }

        if (empty($filters['user_uuid'])) {
            return null;
        }

        return (int) ($this->userRepository->findByUuid((string) $filters['user_uuid'])?->id ?? -1);
    }
}
