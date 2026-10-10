<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminUserAddressBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminUserAddressListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\AdminUserAddressCollection;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;

/**
 * 관리자 회원 배송지 관리 컨트롤러
 *
 * 전 회원의 배송지를 조회하고 개별·일괄 삭제합니다. 회원 한정 필터(`user_uuid` / `user_uuids[]`)의
 * 해석과 "없는 uuid 는 빈 결과" 규칙은 서비스(`UserAddressService::paginateForAdminWithMembers()`)가
 * 소유한다 — 이 컨트롤러는 응답만 조립한다. 목록 응답에 한정 회원의 이름을 함께 돌려주는 것은
 * (`filters.members`) 화면이 uuid 만으로는 칩에 이름을 세울 수 없기 때문이다.
 */
class AdminUserAddressController extends AdminBaseController
{
    /**
     * @param  UserAddressService  $addressService  도메인 서비스
     */
    public function __construct(
        private UserAddressService $addressService,
    ) {
        parent::__construct();
    }

    /**
     * 목록을 조회합니다.
     *
     * @param  AdminUserAddressListRequest  $request  목록 조회 요청
     * @return JsonResponse 목록 응답
     */
    public function index(AdminUserAddressListRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $result = $this->addressService->paginateForAdminWithMembers($filters, (int) ($filters['per_page'] ?? 20));

        $payload = (new AdminUserAddressCollection($result['rows']))->toArray($request);
        // 적용된 회원 한정을 이름까지 붙여 되돌린다 — 화면의 칩이 이 값으로 선다.
        $payload['filters'] = [
            'members' => $result['members']
                ->map(fn ($user) => [
                    'uuid' => (string) $user->uuid,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ])
                ->values()
                ->all(),
        ];

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.user_address.admin_list_retrieved',
            $payload
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
        if (! $this->addressService->deleteByAdmin($id)) {
            return ResponseHelper::notFound('common.not_found');
        }

        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.user_address.admin_deleted');
    }

    /**
     * 선택한 행을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  AdminUserAddressBulkDeleteRequest  $request  ID 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(AdminUserAddressBulkDeleteRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated()['ids']);
        $count = $this->addressService->bulkDeleteByAdmin($ids);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.user_address.admin_bulk_deleted',
            ['deleted_count' => $count],
            200,
            // :count 자리표시자는 messageParams 로만 채워진다 — 빼면 토스트에
            // 「:count개를 삭제했습니다」 가 그대로 뜬다(2026-09-15 브라우저 실측).
            ['count' => $count]
        );
    }
}
