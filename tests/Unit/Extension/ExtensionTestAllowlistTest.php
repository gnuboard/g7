<?php

namespace Tests\Unit\Extension;

use App\Extension\Testing\ExtensionTestAllowlist;
use Tests\TestCase;

/**
 * ExtensionTestAllowlist 단위 테스트
 *
 * 테스트 환경 확장 격리 allowlist 컨테이너의 set/reset/isAllowed/isActive 동작을 검증합니다.
 */
class ExtensionTestAllowlistTest extends TestCase
{
    /**
     * 각 테스트 후 allowlist 를 깨끗한 상태로 되돌립니다.
     *
     * (TestCase::tearDown 이 이미 reset 하지만, 본 테스트는 set 동작을
     *  직접 검증하므로 명시적으로 한 번 더 보장합니다.)
     */
    protected function tearDown(): void
    {
        ExtensionTestAllowlist::reset();

        parent::tearDown();
    }

    /**
     * @effects set_classifies_plugin_and_module_by_path_prefix
     */
    public function test_set_classifies_plugin_and_module_by_path_prefix(): void
    {
        ExtensionTestAllowlist::set([
            'plugins/sirsoft-gdpr',
            'modules/sirsoft-ecommerce',
        ]);

        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-ecommerce'));
    }

    public function test_is_allowed_returns_false_for_extension_outside_allowlist(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-marketing'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-gdpr'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('unknown', 'sirsoft-gdpr'));
    }

    /**
     * @effects set_normalizes_trailing_slash
     */
    public function test_set_normalizes_trailing_slash(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr/']);

        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * @effects set_ignores_prefixless_entries
     */
    public function test_set_ignores_prefixless_entries(): void
    {
        ExtensionTestAllowlist::set(['sirsoft-gdpr', 'templates/sirsoft-basic']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-gdpr'));
    }

    /**
     * @effects allowlist_active_when_testing_and_configured
     */
    public function test_is_active_returns_true_when_testing_env_and_configured(): void
    {
        ExtensionTestAllowlist::set([]);

        // phpunit.xml 의 APP_ENV=testing + 명시적 set → 가드 활성
        $this->assertTrue(ExtensionTestAllowlist::isActive());
    }

    /**
     * @effects empty_allowlist_is_active_and_blocks_all_extensions
     */
    public function test_empty_allowlist_is_active_and_blocks_all_extensions(): void
    {
        // 빈 allowlist = core-only 테스트 → 가드는 활성, 모든 확장이 차단됨
        ExtensionTestAllowlist::set([]);

        $this->assertTrue(ExtensionTestAllowlist::isActive());
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * @effects reset_deactivates_guard
     */
    public function test_reset_deactivates_guard(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);
        ExtensionTestAllowlist::reset();

        $this->assertFalse(ExtensionTestAllowlist::isActive());
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * @effects set_recall_replaces_previous_allowlist
     */
    public function test_set_recall_replaces_previous_allowlist(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);
        ExtensionTestAllowlist::set(['plugins/sirsoft-marketing']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-marketing'));
    }

    /**
     * @effects allowlist_inactive_when_app_env_not_testing, production_env_keeps_full_extension_loading
     */
    public function test_is_active_returns_false_in_non_testing_env_even_when_configured(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);

        // 운영/개발 환경에서는 가드가 비활성 → 확장 전수 로딩 보존
        $this->app['env'] = 'production';

        $this->assertFalse(ExtensionTestAllowlist::isActive());

        // 후속 테스트 보호: 환경 복원
        $this->app['env'] = 'testing';
    }
}
