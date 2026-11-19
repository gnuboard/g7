<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Extension\HookManager;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\Services\AccountResolver;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 퇴사 처리된 연결의 로그인(W30) — 주기 점검, 다음 로그인 차단이 끈 연결은 IdP 로그인으로 다시 켜고, SCIM, 관리자가 끈 연결은 거절.
 *
 * @since 1.0.0
 */
class ReactivateOnLoginTest extends PluginTestCase
{
    /**
     * 퇴사 출처별 기대.
     *
     * @return array<string, array{0: string, 1: bool}> 출처 → 다시 켜지는지
     */
    public static function reasons(): array
    {
        return [
            'periodic' => ['periodic', true],
            'login' => ['login', true],
            'scim' => ['scim', false],
            'admin' => ['admin', false],
        ];
    }

    /**
     * 퇴사 출처에 따라 로그인으로 다시 켜거나 거절하는지 확인한다.
     *
     * @scenario deprovision_reason=periodic|login|scim|admin
     *
     * @effects reactivated_or_deprovisioned
     *
     * @param  string  $reason  퇴사 출처
     * @param  bool  $reactivates  다시 켜지는지
     * @return void 반환값 없음
     */
    #[DataProvider('reasons')]
    public function test_reactivation_by_reason(string $reason, bool $reactivates): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create();
        $account = $this->link($user, $idp, 'ra-'.$reason, ['active' => false, 'deprovisioned_at' => now(), 'deprovision_reason' => $reason]);

        $reactivated = 0;
        HookManager::addAction(AccountResolver::ACTION_AFTER_REACTIVATE, function () use (&$reactivated) {
            $reactivated++;
        });

        $response = $this->roundTrip($idp, 'full', ['sub' => 'ra-'.$reason]);

        if ($reactivates) {
            $this->assertSame($user->id, $this->redeemHandoff($response)['user']->id);
            $fresh = $account->fresh();
            $this->assertTrue($fresh->active);
            $this->assertNull($fresh->deprovision_reason);
            $this->assertSame(1, $reactivated);
        } else {
            $this->assertSame('deprovisioned', $this->redirectQuery($response)['sso_error']);
            $this->assertFalse($account->fresh()->active);
            $this->assertSame(0, $reactivated);
        }
    }
}
