<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;

/**
 * 회원 IdP 추가 속성 저장소.
 *
 * @since 1.0.0
 */
class SsoUserAttributeRepository implements SsoUserAttributeRepositoryInterface
{
    /**
     * (회원, IdP, 키) 한 칸을 찾습니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  string  $key  속성 키
     * @return SsoUserAttribute|null 속성
     */
    public function find(int $userId, int $idpId, string $key): ?SsoUserAttribute
    {
        return SsoUserAttribute::query()->where('user_id', $userId)->where('idp_id', $idpId)->where('key', $key)->first();
    }

    /**
     * (회원, IdP, 키) 값을 저장합니다 (없으면 만든다).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  string  $key  속성 키
     * @param  string|null  $value  값
     * @return SsoUserAttribute 저장한 속성
     */
    public function put(int $userId, int $idpId, string $key, ?string $value): SsoUserAttribute
    {
        return SsoUserAttribute::query()->updateOrCreate(
            ['user_id' => $userId, 'idp_id' => $idpId, 'key' => $key],
            ['value' => $value, 'synced_at' => now()],
        );
    }

    /**
     * 회원의 속성을 모두 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoUserAttribute> 속성
     */
    public function forUser(int $userId): Collection
    {
        return SsoUserAttribute::query()->where('user_id', $userId)->orderBy('idp_id')->orderBy('key')->get();
    }

    /**
     * 여러 회원의 속성을 한 번에 반환합니다 (목록 화면 — 1쿼리).
     *
     * @param  array<int, int>  $userIds  회원 ID
     * @param  array<int, string>  $keys  읽을 키 (빈 배열이면 전부)
     * @return Collection<int, SsoUserAttribute> 속성
     */
    public function forUsers(array $userIds, array $keys = []): Collection
    {
        if ($userIds === []) {
            return new Collection;
        }

        $query = SsoUserAttribute::query()->whereIn('user_id', $userIds);
        if ($keys !== []) {
            $query->whereIn('key', $keys);
        }

        return $query->orderBy('idp_id')->get(['user_id', 'idp_id', 'key', 'value']);
    }

    /**
     * 회원의 속성을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int
    {
        return SsoUserAttribute::query()->where('user_id', $userId)->delete();
    }
}
