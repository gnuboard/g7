<?php

namespace App\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;

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
            'can_create' => 'core.users.create',
            'can_update' => 'core.users.update',
            'can_delete' => 'core.users.delete',
            'can_assign_roles' => 'core.permissions.update',
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
            'data' => $this->mapWithRowNumber(function ($user) {
                return (new UserResource($user))->toListArray(request());
            }),
            'pagination' => $this->when($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator, [
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
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 사용자 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $isPaginator = $this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator;

        return [
            'data' => $this->mapWithRowNumber(function ($user) {
                return (new UserResource($user))->toListArray(request());
            }),
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
            'pagination' => $this->when($this->resource instanceof \Illuminate\Pagination\LengthAwarePaginator, [
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
                'email' => $user->email,
            ];
        })->toArray();
    }
}
