<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 응답 결제 통화 주입 리스너 테스트 (A3, D-LOGIN-CUR)
 */
class UserCurrencyInfoListenerTest extends ModuleTestCase
{
    private function listener(): UserCurrencyInfoListener
    {
        return app(UserCurrencyInfoListener::class);
    }

    public function test_injects_persisted_currency_into_resource_data(): void
    {
        $user = User::factory()->create();
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredCurrency($user->id, 'JPY');

        $data = $this->listener()->injectPreferredCurrency([], $user);

        $this->assertSame('JPY', $data['ecommerce_preferred_currency']);
    }

    public function test_injects_null_when_no_profile(): void
    {
        $user = User::factory()->create();

        $data = $this->listener()->injectPreferredCurrency([], $user);

        $this->assertArrayHasKey('ecommerce_preferred_currency', $data);
        $this->assertNull($data['ecommerce_preferred_currency']);
    }

    public function test_subscribes_to_user_resource_filter_hook(): void
    {
        $hooks = UserCurrencyInfoListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.filter_resource_data', $hooks);
        $this->assertSame('filter', $hooks['core.user.filter_resource_data']['type']);
    }
}
