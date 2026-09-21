<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminUserAddressBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminUserAddressListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\AdminUserAddressCollection;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;

/**
 * 관리자 회원 배송지 관리 컨트롤러
 *
 * 전 회원의 배송지를 조회하고 개별·일괄 삭제합니다.
 * 회원 소유 데이터라 회원 상세에서 `user_uuid` 를 달고 넘어오는 경로가 있다 —
 * 없는 uuid 는 빈 결과로 답한다(필터를 무시하고 전체를 보여주면 남의 데이터가 뜬다).
 * 화면의 전용 회원 필터는 여러 명(`user_uuids[]`)을 싣는다. 목록 응답에 그 회원들의 이름을
 * 함께 돌려주는 것은(`filters.members`) 화면이 uuid 만으로는 칩에 이름을 세울 수 없기 때문이다 —
 * 코어 회원 검색은 uuid 를 한 건씩만 받아, 한정이 세 명이면 왕복이 세 번 난다.
 */
class AdminUserAddressController extends AdminBaseController
{
    /**
     * @param  UserAddressService  $addressService  도메인 서비스
     * @param  UserRepositoryInterface  $userRepository  코어 사용자 리포지토리 (UUID 변환용)
     */
    public function __construct(
        private UserAddressService $addressService,
        private UserRepositoryInterface $userRepository,
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
        $members = $this->resolveMembers($filters);

        if (! empty($filters['user_uuids'])) {
            // 고른 uuid 가 전부 사라진 회원이어도 빈 배열을 그대로 싣는다 — 필터가 풀려 전체 목록이
            // 뜨면 "이 회원들" 을 요청한 화면에 남의 데이터가 보인다.
            $filters['user_ids'] = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $filters['user_id'] = $this->resolveUserId($filters);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $rows = $this->addressService->paginateForAdmin($filters, $perPage);

        $payload = (new AdminUserAddressCollection($rows))->toArray($request);
        // 적용된 회원 한정을 이름까지 붙여 되돌린다 — 화면의 칩이 이 값으로 선다.
        $payload['filters'] = [
            'members' => $members
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

    /**
     * 회원 필터를 user_id 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를 무시하고
     * 전체를 보여주면 "이 회원의 데이터" 를 요청한 화면에 남의 데이터가 뜬다.
     * 다중 필터(`user_uuids`)가 이미 회원을 잡았으면 단건 해석은 건너뛴다 — 둘을 함께 걸면
     * 교집합이 비어 "고른 회원이 있는데 결과가 0건" 이 된다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return int|null 회원 ID (필터 없으면 null)
     */
    private function resolveUserId(array $filters): ?int
    {
        if (! empty($filters['user_id'])) {
            return (int) $filters['user_id'];
        }

        if (! empty($filters['user_uuids'])) {
            return null;
        }

        if (empty($filters['user_uuid'])) {
            return null;
        }

        return (int) ($this->userRepository->findByUuid((string) $filters['user_uuid'])?->id ?? -1);
    }

    /**
     * 회원 한정 필터(uuid 목록)를 회원으로 해석합니다.
     *
     * 없는 uuid 는 조용히 빠진다 — 지워진 회원을 가리키는 오래된 북마크 하나가 목록 화면 전체를
     * 세우지는 않아야 한다. 대신 한 명도 남지 않았을 때 필터가 통째로 풀리지 않도록, 호출 측이
     * 빈 ID 목록을 그대로 싣는다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return Collection<int, \App\Models\User> 해석된 회원 (필터가 없으면 빈 컬렉션)
     */
    private function resolveMembers(array $filters): Collection
    {
        $uuids = array_values(array_unique(array_filter((array) ($filters['user_uuids'] ?? []))));

        if ($uuids === []) {
            return collect();
        }

        return $this->userRepository->findManyByUuids($uuids);
    }
}
