<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Services\SettingsService;
use Plugins\Sirsoft\VerificationNhnkcp\Plugin;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 설치/삭제 시 성인인증 목적의 본인확인 수단 매핑 검증.
 *
 * 설치 직후 별도 설정 없이 성인인증이 KCP 로 분기되도록 초기값을 심되, 운영자가 이미 지정한
 * 값이 있으면 덮어쓰지 않아야 한다(재설치 시 선택 보존). 삭제 시에는 심어 둔 값만 거두어
 * 존재하지 않는 provider 를 가리키는 고아 설정을 남기지 않는다.
 *
 * @since 1.0.0
 */
class KcpInstallDefaultPurposeProviderTest extends PluginTestCase
{
    private const SETTING_KEY = 'identity.purpose_providers.nhnkcp.adult_verification';

    /**
     * @scenario stage=install,existing_purpose_provider=absent
     *
     * @effects install_seeds_adult_purpose_provider_when_absent
     */
    public function test_install_seeds_provider_when_absent(): void
    {
        $settings = app(SettingsService::class);
        $this->assertEmpty($settings->getSetting(self::SETTING_KEY));

        (new Plugin)->install();

        $this->assertSame('nhnkcp', $settings->getSetting(self::SETTING_KEY));
    }

    /**
     * @scenario stage=install,existing_purpose_provider=present
     *
     * @effects install_preserves_existing_purpose_provider
     */
    public function test_install_preserves_operator_choice(): void
    {
        $settings = app(SettingsService::class);
        $settings->setSetting(self::SETTING_KEY, 'g7:core.mail');

        (new Plugin)->install();

        $this->assertSame(
            'g7:core.mail',
            $settings->getSetting(self::SETTING_KEY),
            '이미 지정된 운영자 선택을 덮어쓰면 안 된다',
        );
    }

    /**
     * @scenario stage=uninstall,existing_purpose_provider=absent
     *
     * @effects uninstall_removes_seeded_purpose_provider
     */
    public function test_uninstall_removes_seeded_provider(): void
    {
        $settings = app(SettingsService::class);
        (new Plugin)->install();
        $this->assertSame('nhnkcp', $settings->getSetting(self::SETTING_KEY));

        (new Plugin)->uninstall();

        $this->assertEmpty(
            $settings->getSetting(self::SETTING_KEY),
            '삭제 후에도 존재하지 않는 provider 를 가리키는 매핑이 남으면 안 된다',
        );
    }

    /**
     * @scenario stage=uninstall,existing_purpose_provider=present
     *
     * @effects uninstall_preserves_operator_chosen_provider
     */
    public function test_uninstall_preserves_operator_choice(): void
    {
        $settings = app(SettingsService::class);
        $settings->setSetting(self::SETTING_KEY, 'g7:core.mail');

        (new Plugin)->uninstall();

        $this->assertSame(
            'g7:core.mail',
            $settings->getSetting(self::SETTING_KEY),
            '우리가 심지 않은 값은 거두지 않는다',
        );
    }

    /**
     * @scenario stage=install,existing_purpose_provider=absent
     *
     * @effects install_seeds_adult_purpose_provider_when_absent
     */
    public function test_declared_purpose_uses_plugin_namespace_lang_keys(): void
    {
        $purposes = (new Plugin)->getIdentityPurposes();

        $this->assertArrayHasKey('nhnkcp.adult_verification', $purposes);
        $meta = $purposes['nhnkcp.adult_verification'];

        $this->assertSame('nhnkcp', $meta['default_provider']);
        $this->assertSame(['phone'], $meta['allowed_channels']);
        $this->assertStringStartsWith('sirsoft-verification_nhnkcp::', $meta['label']);
        $this->assertNotSame($meta['label'], __($meta['label']), '다국어 키가 해석되어야 한다');
    }
}
