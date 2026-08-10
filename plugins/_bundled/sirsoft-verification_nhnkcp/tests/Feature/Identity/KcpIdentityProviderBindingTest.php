<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 코어 provider 레지스트리 등록 검증.
 *
 * 활성 상태에서는 코어 레지스트리에 본 provider 가 나타나고, 설정이 주입되어야 한다.
 * 비활성 상태(훅 미등록)에서는 나타나지 않아야 한다.
 *
 * @since 1.0.0
 */
class KcpIdentityProviderBindingTest extends PluginTestCase
{
    /**
     * @scenario stage=activate,existing_purpose_provider=absent
     *
     * @effects activate_registers_provider_in_core_registry
     */
    public function test_provider_is_registered_in_core_registry(): void
    {
        $manager = app(IdentityVerificationManager::class);

        $this->assertTrue($manager->has(KcpIdentityProvider::PROVIDER_ID));

        $provider = $manager->get(KcpIdentityProvider::PROVIDER_ID);
        $this->assertInstanceOf(KcpIdentityProvider::class, $provider);
        $this->assertSame('phone', $provider->getChannels()[0]);
        $this->assertNotSame('', $provider->getLabel());
        $this->assertArrayHasKey('phone', $provider->getChannelLabels());
    }

    /**
     * @scenario stage=activate,existing_purpose_provider=absent
     *
     * @effects activate_registers_provider_in_core_registry
     */
    public function test_registered_provider_receives_saved_settings(): void
    {
        app(PluginSettingsService::class)->save(self::PLUGIN_IDENTIFIER, [
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'live-key',
        ]);

        $provider = app(IdentityVerificationManager::class)->get(KcpIdentityProvider::PROVIDER_ID);

        $this->assertInstanceOf(KcpIdentityProvider::class, $provider);
        $this->assertSame('SMA1B2C', $provider->resolveSiteCd(), '저장된 설정이 provider 에 반영되어야 한다');
        $this->assertTrue($provider->isAvailable());
    }

    /**
     * @scenario stage=deactivate,existing_purpose_provider=absent
     *
     * @effects deactivate_removes_provider_from_core_registry
     */
    public function test_provider_disappears_when_listener_is_not_registered(): void
    {
        // 비활성 상태를 재현 — 훅 리스너가 등록되지 않으면 레지스트리에도 나타나지 않아야 한다.
        // HookManager 는 정적 상태라 같은 프로세스의 후속 테스트에 영향을 주므로 반드시 복구한다.
        HookManager::clearFilter('core.identity.registered_providers');

        try {
            $this->assertFalse(app(IdentityVerificationManager::class)->has(KcpIdentityProvider::PROVIDER_ID));
        } finally {
            // Registrar 는 중복 등록을 막는 정적 목록을 갖고 있어, 훅을 지운 뒤에는 목록도 비워야
            // 재등록이 실제로 이뤄진다.
            HookListenerRegistrar::clear();
            $this->registerPluginHookListeners();
        }
    }

    /**
     * @scenario stage=activate,existing_purpose_provider=absent
     *
     * @effects activate_registers_provider_in_core_registry
     */
    public function test_provider_supports_all_purposes_and_uses_text_code_hint(): void
    {
        $provider = app(IdentityVerificationManager::class)->get(KcpIdentityProvider::PROVIDER_ID);

        $this->assertTrue($provider->supportsPurpose('signup'));
        $this->assertTrue($provider->supportsPurpose(KcpIdentityProvider::ADULT_PURPOSE));
        $this->assertSame('text_code', $provider->getRenderHint());
    }
}
