<?php

namespace Plugins\Sirsoft\Sso\Listeners\Admin;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use App\Models\User;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;

/**
 * 관리자 회원 상세 「SSO」 탭 데이터 — 코어 필터 `core.user.filter_resource_data` 로 회원 응답에 `sso` 키를 더합니다.
 *
 * 연결된 IdP(방식, 상태, 퇴사 표시, 마지막 로그인, IdP 이메일과 다름 — P12), 추가 속성, 매핑이 붙인 역할(역할 출처 배지)을 싣는다.
 * 회원 연결 열람 권한(`sirsoft-sso.accounts.view`)이 없으면 더하지 않는다. subject 원문과 토큰은 싣지 않는다.
 *
 * @since 1.0.0
 */
class MemberDetailSsoListener implements HookListenerInterface
{
    /** 응답에 더하는 키 */
    public const KEY = 'sso';

    /** 열람 권한 */
    public const PERMISSION = 'sirsoft-sso.accounts.view';

    /**
     * @param  AdminAccountRepositoryInterface  $accounts  연결 조회
     */
    public function __construct(private readonly AdminAccountRepositoryInterface $accounts) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => ['method' => 'inject', 'priority' => 60, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * SSO 정보를 더합니다 (이미 있는 키는 덮어쓰지 않는다).
     *
     * @param  array<string, mixed>  $data  회원 응답
     * @param  User|null  $user  회원
     * @return array<string, mixed> 더한 응답
     */
    public function inject(array $data, ?User $user = null): array
    {
        if (! $user instanceof User || array_key_exists(self::KEY, $data) || ! PermissionHelper::check(self::PERMISSION)) {
            return $data;
        }

        $links = $this->accounts->forUserWithIdp((int) $user->getKey());
        $labels = [];
        foreach ($links as $link) {
            if ($link->idp instanceof IdentityProvider) {
                $labels[(int) $link->idp_id] = $link->idp->localizedLabel();
            }
        }

        $userEmail = mb_strtolower((string) $user->email);

        $data[self::KEY] = [
            'accounts' => $links->map(fn (SsoAccount $account) => [
                'id' => (int) $account->id,
                'idp' => $account->idp instanceof IdentityProvider ? ['id' => (int) $account->idp->id, 'key' => $account->idp->key, 'label' => $account->idp->localizedLabel(), 'protocol' => $account->idp->protocol] : null,
                'linked_via' => $account->linked_via,
                'active' => (bool) $account->active,
                'deprovisioned_at' => $account->deprovisioned_at?->toIso8601String(),
                'deprovision_reason' => $account->deprovision_reason,
                'linked_at' => $account->linked_at?->toIso8601String(),
                'last_login_at' => $account->last_login_at?->toIso8601String(),
                'idp_email' => $account->email, // audit:allow resource-member-email-ungated 회원 연결 열람 권한(accounts.view) 관리자 상세 탭
                'email_differs' => $account->email !== null && $account->email !== '' && mb_strtolower($account->email) !== $userEmail,
            ])->values()->all(),
            'attributes' => array_map(fn (array $row) => $row + ['idp_label' => $labels[$row['idp_id']] ?? ''], $this->accounts->attributesForUser((int) $user->getKey())),
            'mapped_roles' => $this->accounts->mappedRoleIdentifiers((int) $user->getKey()),
        ];

        return $data;
    }
}
