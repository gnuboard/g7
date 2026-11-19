<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;

/**
 * 회원 IdP 추가 속성 저장소 계약.
 *
 * @since 1.0.0
 */
interface SsoUserAttributeRepositoryInterface
{
    /**
     * (회원, IdP, 키) 한 칸을 찾습니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  string  $key  속성 키
     * @return SsoUserAttribute|null 속성
     */
    public function find(int $userId, int $idpId, string $key): ?SsoUserAttribute;

    /**
     * (회원, IdP, 키) 값을 저장합니다 (없으면 만든다).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  string  $key  속성 키
     * @param  string|null  $value  값
     * @return SsoUserAttribute 저장한 속성
     */
    public function put(int $userId, int $idpId, string $key, ?string $value): SsoUserAttribute;

    /**
     * 회원의 속성을 모두 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoUserAttribute> 속성
     */
    public function forUser(int $userId): Collection;

    /**
     * 여러 회원의 속성을 한 번에 반환합니다 (목록 화면 — 1쿼리).
     *
     * @param  array<int, int>  $userIds  회원 ID
     * @param  array<int, string>  $keys  읽을 키 (빈 배열이면 전부)
     * @return Collection<int, SsoUserAttribute> 속성
     */
    public function forUsers(array $userIds, array $keys = []): Collection;

    /**
     * 회원의 속성을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int;
}
