<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminWishlistBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminWishlistListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\AdminWishlistCollection;
use Modules\Sirsoft\Ecommerce\Services\ProductWishlistService;

/**
 * 관리자 찜 관리 컨트롤러
 *
 * 전 회원의 찜을 한 화면에서 조회하고 개별·일괄 삭제합니다.
 * 회원 소유 데이터라 회원 상세에서 `user_uuid` 를 달고 넘어오는 경로가 있다 —
 * 없는 uuid 는 빈 결과로 답한다(필터를 무시하고 전체를 보여주면 남의 데이터가 뜬다).
 */
class AdminWishlistController extends AdminBaseController
{
    /**
     * @param  ProductWishlistService  $wishlistService  도메인 서비스
     * @param  UserRepositoryInterface  $userRepository  코어 사용자 리포지토리 (UUID 변환용)
     */
    public function __construct(
        private ProductWishlistService $wishlistService,
        private UserRepositoryInterface $userRepository,
    ) {
        parent::__construct();
    }

    /**
     * 목록을 조회합니다.
     *
     * @param  AdminWishlistListRequest  $request  목록 조회 요청
     * @return JsonResponse 목록 응답
     */
    public function index(AdminWishlistListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['user_id'] = $this->resolveUserId($filters);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $rows = $this->wishlistService->paginateForAdmin($filters, $perPage);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.wishlist.admin_list_retrieved',
            (new AdminWishlistCollection($rows))->toArray($request)
        );
    }

    /**
     * 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  int  $id  행 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        if (! $this->wishlistService->deleteByAdmin($id)) {
            return ResponseHelper::notFound('common.not_found');
        }

        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.wishlist.admin_deleted');
    }

    /**
     * 선택한 행을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  AdminWishlistBulkDeleteRequest  $request  ID 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(AdminWishlistBulkDeleteRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated()['ids']);
        $count = $this->wishlistService->bulkDeleteByAdmin($ids);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.wishlist.admin_bulk_deleted',
            ['deleted_count' => $count],
            200,
            // :count 자리표시자는 messageParams 로만 채워진다 — 빼면 토스트에
            // 「:count개를 삭제했습니다」 가 그대로 뜬다(2026-09-15 브라우저 실측).
            ['count' => $count]
        );
    }

    /**
     * 회원 필터를 user_id 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를 무시하고
     * 전체를 보여주면 "이 회원의 데이터" 를 요청한 화면에 남의 데이터가 뜬다.
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
