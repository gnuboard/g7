<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Extension\HookManager;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 언어팩 시스템 회귀 테스트.
 *
 * 활성 언어팩이 없을 때 기존 동작이 100% 동일해야 함을 보장합니다 (계획서 §16.11).
 */
class LanguagePackRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_filters_returns_input_unchanged_when_no_pack(): void
    {
        $original = [
            'module' => ['identifier' => 'core', 'name' => ['ko' => '코어', 'en' => 'Core']],
            'categories' => [],
        ];

        $result = HookManager::applyFilters('core.permissions.config', $original);

        $this->assertSame($original, $result);
    }

    public function test_inject_core_permissions_passthrough_without_active_pack(): void
    {
        $injector = $this->app->make(LanguagePackSeedInjector::class);
        $config = [
            'module' => ['identifier' => 'core', 'name' => ['ko' => '코어']],
            'categories' => [],
        ];

        $this->assertSame($config, $injector->injectCorePermissions($config));
    }

    public function test_inject_notifications_passthrough_without_active_pack(): void
    {
        $injector = $this->app->make(LanguagePackSeedInjector::class);
        $defs = [
            ['type' => 'welcome', 'name' => ['ko' => '환영'], 'description' => ['ko' => '']],
        ];

        $this->assertSame($defs, $injector->injectNotifications($defs));
    }

    public function test_template_language_merge_filter_returns_input_unchanged_when_no_pack(): void
    {
        $original = ['admin' => ['title' => '관리자']];

        $result = HookManager::applyFilters('template.language.merge', $original, 'sirsoft-admin_basic', 'ko');

        $this->assertSame($original, $result);
    }

    public function test_supported_locales_includes_bundled_ko_en(): void
    {
        // 활성 코어 언어팩 없을 때 — 번들 ko/en 은 항상 포함
        $registry = $this->app->make(\App\Services\LanguagePack\LanguagePackRegistry::class);
        $registry->invalidate();

        $locales = $registry->getActiveCoreLocales();

        $this->assertContains('ko', $locales);
        $this->assertContains('en', $locales);
    }
}
