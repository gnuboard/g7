<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoSitePolicy;

/**
 * SSO 사이트 정책과 강제 판정에 필요한 조회 저장소 계약.
 *
 * 강제 범위(scope)는 `{site: bool, roles: string[], linked: bool, domains: string[]}` 모양이다 — 「이 범위에 드는 회원」을
 * 회원 표 조건으로 바꿔 영향 미리보기와 비밀번호 토큰 회수(W34)에 쓴다.
 *
 * @since 1.0.0
 */
interface SitePolicyRepositoryInterface
{
    /**
     * 정책 표가 있는지 확인합니다 (설치 전이면 false).
     *
     * @return bool 표가 있으면 true
     */
    public function tableExists(): bool;

    /**
     * 정책 행을 반환합니다.
     *
     * @return SsoSitePolicy|null 정책 행
     */
    public function current(): ?SsoSitePolicy;

    /**
     * 정책 값을 저장합니다 (행이 없으면 만든다).
     *
     * @param  array<string, mixed>  $policy  정책 값 전체
     * @param  int|null  $updatedBy  저장한 관리자 ID
     * @return SsoSitePolicy 저장된 행
     */
    public function save(array $policy, ?int $updatedBy): SsoSitePolicy;

    /**
     * 가장 먼저 만든 최고관리자 ID 를 반환합니다 (비상 계정 기본값).
     *
     * @return int|null 회원 ID
     */
    public function firstSuperAdminId(): ?int;

    /**
     * 최고관리자 전원을 반환합니다 (최고관리자 퇴사 알림 수신자).
     *
     * @return Collection<int, User> 최고관리자
     */
    public function superAdmins(): Collection;

    /**
     * 있는 회원 ID 만 남깁니다.
     *
     * @param  array<int, int>  $ids  회원 ID
     * @return array<int, int> 있는 회원 ID
     */
    public function existingUserIds(array $ids): array;

    /**
     * 회원 묶음을 ID 순서와 무관하게 반환합니다 (비상 계정 표시).
     *
     * @param  array<int, int>  $ids  회원 ID
     * @return Collection<int, User> 회원
     */
    public function usersByIds(array $ids): Collection;

    /**
     * 비상 계정 이메일 목록(소문자)을 반환합니다.
     *
     * @param  array<int, int>  $ids  비상 계정 ID
     * @return array<int, string> 이메일
     */
    public function emailsForUsers(array $ids): array;

    /**
     * 회원이 역할 중 하나를 갖는지 확인합니다.
     *
     * @param  User  $user  회원
     * @param  array<int, string>  $roleIdentifiers  역할 identifier
     * @return bool 하나라도 가지면 true
     */
    public function userHasAnyRole(User $user, array $roleIdentifiers): bool;

    /**
     * 회원의 활성 연결 IdP 를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, IdentityProvider> IdP (활성 연결만)
     */
    public function activeLinkedIdps(int $userId): Collection;

    /**
     * 강제를 켜는 IdP 의 소유 도메인(소문자)을 모읍니다.
     *
     * @return array<int, string> 도메인
     */
    public function enforcedDomains(): array;

    /**
     * 강제 범위 변화로 새로 강제 대상이 되는 회원 중 비밀번호가 있는 회원 수를 셉니다.
     *
     * @param  array<string, mixed>  $before  이전 범위
     * @param  array<string, mixed>  $after  새 범위
     * @param  array<int, int>  $excludeIds  제외 회원(비상 계정)
     * @return int 회원 수
     */
    public function countNewlyEnforcedWithPassword(array $before, array $after, array $excludeIds): int;

    /**
     * 새로 강제 대상이 되는 회원의 살아 있는 비밀번호 로그인 토큰을 가진 회원 수를 셉니다.
     *
     * @param  array<string, mixed>  $before  이전 범위
     * @param  array<string, mixed>  $after  새 범위
     * @param  array<int, int>  $excludeIds  제외 회원
     * @return int 회원 수
     */
    public function countNewlyEnforcedPasswordSessions(array $before, array $after, array $excludeIds): int;

    /**
     * 새로 강제 대상이 된 회원의 비밀번호 로그인 토큰(`login_method='password'`)을 지웁니다 (W34).
     *
     * @param  array<string, mixed>  $before  이전 범위
     * @param  array<string, mixed>  $after  새 범위
     * @param  array<int, int>  $excludeIds  제외 회원
     * @return int 지운 토큰 수
     */
    public function revokeNewlyEnforcedPasswordTokens(array $before, array $after, array $excludeIds): int;
}
