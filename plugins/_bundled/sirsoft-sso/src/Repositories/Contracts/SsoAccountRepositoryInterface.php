<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * 회원-IdP 신원 연결 저장소 계약.
 *
 * @since 1.0.0
 */
interface SsoAccountRepositoryInterface
{
    /**
     * (IdP, subject) 로 연결을 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $subject  IdP 안의 고유 식별자 (대소문자 구분)
     * @return SsoAccount|null 연결
     */
    public function findByIdpSubject(int $idpId, string $subject): ?SsoAccount;

    /**
     * (IdP, 회원) 으로 연결을 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $userId  회원 ID
     * @return SsoAccount|null 연결
     */
    public function findByIdpUser(int $idpId, int $userId): ?SsoAccount;

    /**
     * 회원의 연결을 모두 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoAccount> 연결 목록
     */
    public function forUser(int $userId): Collection;

    /**
     * 연결을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return SsoAccount 만든 연결
     */
    public function create(array $attributes): SsoAccount;

    /**
     * 연결을 고칩니다.
     *
     * @param  SsoAccount  $account  연결
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoAccount 고친 연결
     */
    public function update(SsoAccount $account, array $attributes): SsoAccount;

    /**
     * 회원의 연결을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int;

    /**
     * 이메일로 회원을 찾습니다 — 대소문자를 무시한다(운영 DB 가 `_bin` collation 이어도 `Hong@Corp.com` 과 `hong@corp.com` 을 같은 회원으로, §7 G-1).
     *
     * @param  string  $email  이메일
     * @return User|null 회원
     */
    public function findUserByEmail(string $email): ?User;
}
