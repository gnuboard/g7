<?php

namespace Plugins\Sirsoft\Sso\Listeners\Provisioning;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;

/**
 * 로그인으로 회원이 정해질 때마다 속성과 역할을 IdP 에 맞추고, 주기 점검용 갱신 토큰을 보관합니다
 * (액션 `sirsoft-sso.account.after_resolve`).
 *
 * 동기화 실패가 로그인을 막지 않는다 — 실패는 보고만 하고 로그인은 이어진다(IdP 가 인증해 준 회원이다).
 * 갱신 토큰은 주기 점검을 켠 OIDC IdP 에서만 연결 행에 암호화해 둔다(신원 객체에는 싣지 않는다).
 *
 * @since 1.0.0
 */
class SyncOnAccountResolvedListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.account.after_resolve' => ['method' => 'handle', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 속성, 역할을 맞추고 갱신 토큰을 보관합니다.
     *
     * @param  mixed  ...$args  [User, SsoAccount, ExternalIdentity, string $outcome]
     * @return void
     */
    public function handle(...$args): void
    {
        [$user, $account, $identity, $outcome] = array_pad($args, 4, null);
        if (! $user instanceof User || ! $account instanceof SsoAccount || ! $identity instanceof ExternalIdentity) {
            return;
        }

        $idp = $account->idp;
        if (! $idp instanceof IdentityProvider) {
            return;
        }

        $created = $outcome === 'jit';

        $this->guard(fn () => $this->storeRefreshToken($account, $idp));
        $this->guard(fn () => app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, $created));
        $this->guard(fn () => app(RoleSyncService::class)->sync(
            $user,
            $account,
            $idp,
            $identity->groups,
            $created ? RoleSyncService::TRIGGER_CREATED : RoleSyncService::TRIGGER_UPDATE,
        ));
    }

    /**
     * 주기 점검을 켠 OIDC IdP 면 이번 토큰 교환의 갱신 토큰을 연결 행에 둡니다.
     *
     * 드라이버는 레지스트리의 같은 인스턴스라 콜백에서 받은 값을 꺼낼 수 있다(꺼내면 비운다).
     *
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @return void
     */
    private function storeRefreshToken(SsoAccount $account, IdentityProvider $idp): void
    {
        if ($idp->protocol !== 'oidc') {
            return;
        }

        $driver = $idp->driver();
        if (! method_exists($driver, 'pullRefreshToken')) {
            return;
        }

        $token = $driver->pullRefreshToken();
        $periodic = is_array($idp->deprovision) && (bool) ($idp->deprovision['periodic'] ?? false);
        if ($periodic && is_string($token) && $token !== '') {
            app(ProvisioningAccountRepositoryInterface::class)->update($account, ['refresh_token' => $token]);
        }
    }

    /**
     * 동기화 한 단계를 실행하고 실패는 보고만 합니다.
     *
     * @param  callable  $step  단계
     * @return void
     */
    private function guard(callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
