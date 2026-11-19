<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;

/**
 * 회원-IdP 신원 연결 저장소.
 *
 * @since 1.0.0
 */
class SsoAccountRepository implements SsoAccountRepositoryInterface
{
    /**
     * (IdP, subject) 로 연결을 찾습니다.
     *
     * subject 컬럼은 utf8mb4_bin 이라 대소문자만 다른 값은 다른 신원이다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $subject  IdP 안의 고유 식별자
     * @return SsoAccount|null 연결
     */
    public function findByIdpSubject(int $idpId, string $subject): ?SsoAccount
    {
        return SsoAccount::query()->where('idp_id', $idpId)->where('subject', $subject)->first();
    }

    /**
     * (IdP, 회원) 으로 연결을 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $userId  회원 ID
     * @return SsoAccount|null 연결
     */
    public function findByIdpUser(int $idpId, int $userId): ?SsoAccount
    {
        return SsoAccount::query()->where('idp_id', $idpId)->where('user_id', $userId)->first();
    }

    /**
     * 회원의 연결을 모두 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoAccount> 연결 목록
     */
    public function forUser(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: (idp_id, user_id) 고유 색인 — 회원 한 명의 연결은 IdP 수를 넘지 않는다
        return SsoAccount::query()->where('user_id', $userId)->orderBy('id')->get();
    }

    /**
     * 연결을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return SsoAccount 만든 연결
     */
    public function create(array $attributes): SsoAccount
    {
        return SsoAccount::query()->create($attributes);
    }

    /**
     * 연결을 고칩니다.
     *
     * @param  SsoAccount  $account  연결
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoAccount 고친 연결
     */
    public function update(SsoAccount $account, array $attributes): SsoAccount
    {
        $account->forceFill($attributes)->save();

        return $account;
    }

    /**
     * 회원의 연결을 모두 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int
    {
        return SsoAccount::query()->where('user_id', $userId)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function findUserByEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }
}
