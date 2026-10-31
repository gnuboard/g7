<?php

namespace Plugins\Sirsoft\Sso\Services\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;

/**
 * 마이페이지 「연결된 회사 계정」 카드 — 내 연결 목록, 연결 시작(W25 시작 티켓), 연결 해제.
 *
 * 해제는 두 경우에 막는다 — 비밀번호가 없고 이 연결이 유일한 로그인 수단일 때, 그리고 SSO 강제 대상일 때(해제하면 로그인할 길이 없다).
 *
 * @since 1.0.0
 */
class MyAccountService
{
    /** 연결 뒤 돌아올 경로 */
    public const RETURN_PATH = '/mypage/profile';

    /**
     * @param  AdminAccountRepositoryInterface  $accounts  연결 조회
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  LoginFlowService  $flow  시작 티켓, 공개 IdP 목록
     * @param  EnforcementPolicy  $enforcement  강제 판정
     * @param  AccountAdminService  $admin  연결 해제
     */
    public function __construct(
        private readonly AdminAccountRepositoryInterface $accounts,
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly LoginFlowService $flow,
        private readonly EnforcementPolicy $enforcement,
        private readonly AccountAdminService $admin,
    ) {}

    /**
     * 카드 데이터.
     *
     * @param  User  $user  로그인 회원
     * @return array{accounts: array<int, array<string, mixed>>, linkable: array<int, array<string, mixed>>, enforced: bool} 카드
     */
    public function overview(User $user): array
    {
        $links = $this->accounts->forUserWithIdp((int) $user->getKey());
        $enforced = $this->enforcement->isEnforcedUser($user);
        $linkedIdpIds = $links->pluck('idp_id')->map(fn ($id) => (int) $id)->all();

        $linkable = array_values(array_filter(
            $this->flow->publicIdps('user'),
            fn (array $idp) => ! in_array((int) ($this->idps->findByKey($idp['key'])?->id ?? 0), $linkedIdpIds, true),
        ));

        return [
            'accounts' => $links->map(fn (SsoAccount $account) => [
                'id' => (int) $account->id,
                'idp_key' => $account->idp?->key,
                'idp_label' => $account->idp instanceof IdentityProvider ? $account->idp->localizedLabel() : '',
                'active' => (bool) $account->active,
                'linked_at' => $account->linked_at?->toIso8601String(),
                'last_login_at' => $account->last_login_at?->toIso8601String(),
                'unlink_block' => $this->unlinkBlock($user, $account, $enforced),
            ])->values()->all(),
            'linkable' => array_map(fn (array $idp) => ['key' => $idp['key'], 'label' => $idp['label'], 'initial' => $idp['initial'], 'button_style' => $idp['button_style']], $linkable),
            'enforced' => $enforced,
        ];
    }

    /**
     * 연결 시작 주소를 발급합니다 (1회용 시작 티켓).
     *
     * @param  User  $user  로그인 회원
     * @param  string  $idpKey  IdP 키
     * @param  Request  $request  요청 (묶음 쿠키)
     * @return array{start_url: string} 시작 주소
     *
     * @throws SsoException IdP 없음·꺼짐 (`idp_disabled`), 이미 연결 (`already_linked`)
     */
    public function startLink(User $user, string $idpKey, Request $request): array
    {
        $idp = $this->idps->findByKey($idpKey);

        if ($idp === null || ! $idp->enabled || $idp->protocol === 'ldap') {
            throw new SsoException('idp_disabled', $idpKey, 422);
        }

        if ($this->accounts->forUserWithIdp((int) $user->getKey())->contains(fn (SsoAccount $a) => (int) $a->idp_id === (int) $idp->id)) {
            throw new SsoException('already_linked', '', 409);
        }

        return $this->flow->issueStartUrl($user, $idp, 'link', $request, self::RETURN_PATH);
    }

    /**
     * 내 연결을 해제합니다.
     *
     * @param  User  $user  로그인 회원
     * @param  int  $accountId  연결 ID
     * @return void 반환값 없음
     *
     * @throws SsoException 내 연결이 아님 404, 해제 불가 422 (`last_method`, `unlink_enforced`)
     */
    public function unlink(User $user, int $accountId): void
    {
        $account = $this->accounts->forUserWithIdp((int) $user->getKey())->first(fn (SsoAccount $a) => (int) $a->id === $accountId)
            ?? throw new SsoException('account_not_found', '', 404);

        $block = $this->unlinkBlock($user, $account, $this->enforcement->isEnforcedUser($user));

        if ($block !== null) {
            throw new SsoException($block, '', 422);
        }

        $this->admin->detach($account, $user, 'user');
    }

    /**
     * 해제를 막는 사유.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  bool  $enforced  강제 대상 여부
     * @return string|null 사유 코드 (막지 않으면 null)
     */
    private function unlinkBlock(User $user, SsoAccount $account, bool $enforced): ?string
    {
        if ($enforced) {
            return 'unlink_enforced';
        }

        return $this->admin->isLastMethod($user, $account) ? 'last_method' : null;
    }
}
