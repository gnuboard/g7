<?php

namespace App\Http\Controllers\Api\Admin\Identity;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Identity\AdminIdentityLogIndexRequest;
use App\Http\Requests\Identity\AdminIdentityLogPurgeRequest;
use App\Http\Resources\IdentityLogResource;
use App\Services\IdentityLogService;
use Illuminate\Http\JsonResponse;

/**
 * 관리자 — IDV 이력 열람/파기 컨트롤러.
 *
 * S2 관리자 인증 이력 목록 + 보관주기 파기 API.
 */
class AdminIdentityLogController extends AdminBaseController
{
    /**
     * @param  IdentityLogService  $logService  이력 Service
     */
    public function __construct(
        protected IdentityLogService $logService,
    ) {}

    /**
     * 이력 목록을 조회합니다.
     *
     * @param  AdminIdentityLogIndexRequest  $request  검증된 요청
     * @return JsonResponse 페이지네이션 포함 이력 목록
     */
    public function index(AdminIdentityLogIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $filters = array_filter(
            array_intersect_key($validated, array_flip([
                'provider_id', 'purpose', 'status', 'channel', 'origin_type',
                'provider_ids', 'purposes', 'statuses', 'channels', 'origin_types',
                'source_type', 'source_identifier',
                'user_id', 'target_hash', 'search', 'search_type',
                'sort_by', 'sort_order', 'date_from', 'date_to',
            ])),
            fn ($v) => $v !== null && $v !== '' && $v !== [],
        );

        $paginated = $this->logService->search($filters, (int) ($validated['per_page'] ?? 20));

        return $this->success('messages.success', [
            'data' => IdentityLogResource::collection(collect($paginated->items()))->resolve(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'has_more_pages' => $paginated->hasMorePages(),
            ],
            'abilities' => [
                'can_purge' => $request->user()?->hasPermission(
                    'core.admin.identity.logs.purge',
                    \App\Enums\PermissionType::Admin,
                ) ?? false,
            ],
        ]);
    }

    /**
     * 보관주기 경과 이력을 파기합니다.
     *
     * @param  AdminIdentityLogPurgeRequest  $request  검증된 요청
     * @return JsonResponse 삭제된 행 수
     */
    public function purge(AdminIdentityLogPurgeRequest $request): JsonResponse
    {
        $days = (int) ($request->validated()['older_than_days'] ?? 180);
        $count = $this->logService->purge($days);

        return $this->success('messages.success', [
            'purged_count' => $count,
            'older_than_days' => $days,
        ]);
    }
}
