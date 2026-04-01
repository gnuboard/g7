<?php

namespace App\Http\Resources\Traits;

use App\Helpers\PermissionHelper;
use App\Models\User;

/**
 * 권한(ability) 체크 공통 로직을 제공합니다.
 *
 * BaseApiResource와 ResourceCollection 양쪽에서 사용하여
 * checkAbility() 로직 중복을 방지합니다.
 */
trait HasAbilityCheck
{
    /**
     * 단일 permission identifier를 체크합니다.
     * Gate/Policy 미사용 — User 모델 직접 호출.
     *
     * @param  string  $ability  Permission identifier
     * @param  User|null  $user  현재 사용자 (null = Guest)
     * @return bool 권한 보유 여부
     */
    protected function checkAbility(string $ability, ?User $user): bool
    {
        return PermissionHelper::check($ability, $user);
    }

    /**
     * 능력 맵을 불리언으로 변환합니다.
     *
     * 권한 체크 후 scope_type 기반 스코프 접근 체크를 수행합니다.
     *
     * @param  array<string, string>  $map  ['can_update' => 'module.entity.update', ...]
     * @param  User|null  $user  현재 사용자
     * @return array<string, bool> 능력 불리언 맵
     */
    protected function resolveAbilitiesFromMap(array $map, ?User $user): array
    {
        if (empty($map)) {
            return [];
        }

        return collect($map)
            ->mapWithKeys(function (string $identifier, string $key) use ($user) {
                $hasPermission = PermissionHelper::check($identifier, $user);

                // 권한이 없으면 스코프 체크 불필요
                if (! $hasPermission) {
                    return [$key => false];
                }

                // 리소스 모델이 있으면 스코프 접근 체크
                // JsonResource 이중 래핑 시 내부 모델까지 추출
                $resource = $this->resource;
                while ($resource instanceof \Illuminate\Http\Resources\Json\JsonResource) {
                    $resource = $resource->resource;
                }
                if ($resource instanceof \Illuminate\Database\Eloquent\Model) {
                    return [$key => PermissionHelper::checkScopeAccess($resource, $identifier, $user)];
                }

                return [$key => true];
            })
            ->toArray();
    }
}
