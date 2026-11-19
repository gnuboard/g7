<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoUserAttributeRepositoryInterface;

/**
 * 회원의 IdP 추가 속성(부서, 직급, 사번 등)을 다른 확장에 돌려주는 공개 통로.
 *
 * 같은 키를 여러 IdP 가 주면 먼저 연결된 IdP(작은 IdP ID)의 값을 쓴다. 결과는 필터 `sirsoft-sso.user.filter_attributes` 를 거친다.
 *
 * @since 1.0.0
 */
class AttributeQuery
{
    /** 공개 필터 */
    public const FILTER_ATTRIBUTES = 'sirsoft-sso.user.filter_attributes';

    /**
     * @param  SsoUserAttributeRepositoryInterface  $attributes  추가 속성 저장소
     */
    public function __construct(private readonly SsoUserAttributeRepositoryInterface $attributes) {}

    /**
     * 회원의 추가 속성을 키 => 값으로 반환합니다.
     *
     * @param  User  $user  회원
     * @return array<string, string|null> 속성
     */
    public function forUser(User $user): array
    {
        $values = [];
        foreach ($this->attributes->forUser((int) $user->getKey()) as $attribute) {
            $values[$attribute->key] ??= $attribute->value;
        }

        $filtered = HookManager::applyFilters(self::FILTER_ATTRIBUTES, $values, $user);

        return is_array($filtered) ? $filtered : $values;
    }

    /**
     * 여러 회원의 추가 속성을 한 번에 반환합니다 (목록 화면 — 1쿼리, 필터 미적용).
     *
     * @param  array<int, int>  $userIds  회원 ID
     * @param  array<int, string>  $keys  읽을 키 (빈 배열이면 전부)
     * @return array<int, array<string, string|null>> 회원 ID => 속성
     */
    public function forUsers(array $userIds, array $keys = []): array
    {
        $map = [];
        foreach ($this->attributes->forUsers($userIds, $keys) as $attribute) {
            $map[(int) $attribute->user_id][$attribute->key] ??= $attribute->value;
        }

        return $map;
    }
}
