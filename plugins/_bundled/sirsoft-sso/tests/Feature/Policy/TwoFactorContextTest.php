<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Extension\HookManager;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * SSO 로그인 뒤 2단계 인증 생략(D13, B-4) — IdP 방식 3 × IdP 다중 인증 신호 3.
 *
 * @since 1.0.0
 */
class TwoFactorContextTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 방식과 신호별 `skip_two_factor` 를 확인한다.
     *
     * @scenario two_factor_mode=always_skip|skip_if_mfa|always, mfa=true|false|null
     *
     * @effects skip_matrix
     *
     * @return void 반환값 없음
     */
    public function test_skip_matrix(): void
    {
        $member = $this->makeMember();
        $expected = [
            'always_skip' => [true, true, true],
            'skip_if_mfa' => [true, false, false],
            'always' => [false, false, false],
        ];

        foreach ($expected as $mode => $results) {
            $idp = $this->makeIdp('tf_'.$mode, ['two_factor_mode' => $mode]);
            $account = $this->linkAccount($member, $idp);

            foreach ([true, false, null] as $i => $mfa) {
                $identity = new ExternalIdentity(subject: 's', mfa: $mfa);
                $context = HookManager::applyFilters(LoginFlowService::FILTER_HANDOFF_CONTEXT, ['login_method' => 'sso:'.$idp->key], $member, $account, $identity, $idp);
                $this->assertSame($results[$i], $context['skip_two_factor'], $mode.' mfa='.var_export($mfa, true));
                $this->assertSame('sso:'.$idp->key, $context['login_method']);
            }
        }
    }
}
