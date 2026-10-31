<?php

namespace App\Http\Resources;

use App\Extension\HookManager;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class UserCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 키 => 권한 식별자 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_read' => 'core.users.read',
            'can_create' => 'core.users.create',
            'can_update' => 'core.users.update',
            'can_delete' => 'core.users.delete',
            // 역할 부여는 "사용자 관리"(core.users.update)의 일부다 — "역할 정의 수정"
            // (core.permissions.update)이 아니다. 부여 가능한 개별 역할의 범위는 서버
            // 상한(PermissionEscalationGuard)이 역할별로 강제한다.
            'can_assign_roles' => 'core.users.update',
        ];
    }

    /**
     * 사용자 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 사용자 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->listRows(),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 목록 행을 만들고 확장이 열 데이터를 한 번에 붙이게 합니다.
     *
     * 행마다 `core.user.filter_resource_data` 를 걸면 구독자마다 회원 수만큼 쿼리가 나간다(N+1). 목록은 페이지 전체 행과
     * 그 회원 모델 묶음을 필터 `core.user.filter_list_rows` 에 **한 번** 넘긴다 — 확장은 회원 id 로 한 번 조회해 각 행에
     * 자기 키를 더한다(기존 키 덮어쓰기 금지). 돌려준 값이 배열이 아니거나 행 수가 다르면 원래 행을 쓰고 경고를 남긴다.
     *
     * @return array<int, array<string, mixed>> 목록 행
     *
     * @since 7.2.0
     */
    private function listRows(): array
    {
        $rows = $this->mapWithRowNumber(function ($user) {
            return (new UserResource($user))->toListArray(request());
        })->values()->all();

        /** @var Collection<int, \App\Models\User> $users */
        $users = $this->collection
            ->map(fn ($item) => $item instanceof JsonResource ? $item->resource : $item)
            ->values();

        $filtered = HookManager::applyFilters('core.user.filter_list_rows', $rows, $users);

        if (! is_array($filtered) || count($filtered) !== count($rows)) {
            Log::warning('core.user.filter_list_rows 필터가 행 배열을 돌려주지 않아 원래 행을 사용합니다', [
                'expected_rows' => count($rows),
                'returned_type' => get_debug_type($filtered),
            ]);

            return $rows;
        }

        return array_values($filtered);
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 사용자 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $isPaginator = $this->resource instanceof LengthAwarePaginator;

        return [
            'data' => $this->listRows(),
            'statistics' => $statistics,
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'pagination' => $isPaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
        ];
    }

    /**
     * 관리자용 상세 정보가 포함된 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 관리자 정보가 포함된 사용자 컬렉션
     */
    public function withAdminInfo(): array
    {
        return [
            'data' => $this->mapWithRowNumber(function ($user) {
                return (new UserResource($user))->withAdminInfo();
            }),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 간단한 목록 형태의 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 간략한 사용자 정보 배열
     */
    public function toSimpleArray(): array
    {
        return $this->collection->map(function ($user) {
            return [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $this->canExposeMemberEmail(null, $user->id) ? $user->email : null,
            ];
        })->toArray();
    }
}
