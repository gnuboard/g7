<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

use App\Support\ExtensionSettingsMirror;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Page\Services\PageSettingsService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 모듈 설정 미러 테스트 (공개이슈 #109)
 *
 * 이 모듈에는 `ModuleSettingsInterface` 구현체가 없어 `g7_settings.modules.sirsoft-page`
 * 미러가 영구히 존재하지 않았고, 소비자는 항상 인자 기본값으로 폴백했습니다.
 * 기존 정책 테스트는 config 를 직접 주입해 우회하고 있어 이 결함이 검출되지 않았습니다 —
 * 여기서는 **주입 없이** 설정 서비스 경유로 값이 도달하는지를 본다.
 *
 * @scenario scope=module, trigger=save, trigger=cache_clear, value_origin=defaults_only
 *
 * @effects page_module_mirror_exists, page_module_consumers_read_configured_values, module_mirror_refreshed_after_save
 */
class PageSettingsMirrorTest extends ModuleTestCase
{
    /**
     * 설정 서비스가 defaults.json 을 읽어 전체 설정을 만든다.
     */
    public function test_settings_service_reads_defaults(): void
    {
        $settings = app(PageSettingsService::class)->getAllSettings();

        $this->assertArrayHasKey('attachment', $settings);
        $this->assertArrayHasKey('max_count', $settings['attachment']);
        $this->assertIsInt($settings['attachment']['max_count']);
    }

    /**
     * 미러가 채워지고, 소비자가 config 직접 주입 없이 그 값을 읽는다.
     */
    public function test_mirror_is_populated_without_direct_config_injection(): void
    {
        // 미러가 비어 있는 상태에서 출발한다
        Config::set('g7_settings.modules.sirsoft-page', null);
        $this->assertSame(0, (int) g7_module_settings('sirsoft-page', 'attachment.max_count', 0));

        app(ExtensionSettingsMirror::class)->refreshModule('sirsoft-page');

        $expected = (int) app(PageSettingsService::class)->getSetting('attachment.max_count');

        $this->assertGreaterThan(0, $expected, 'defaults.json 의 max_count 가 0 입니다');
        $this->assertSame(
            $expected,
            (int) g7_module_settings('sirsoft-page', 'attachment.max_count', 0),
            '미러가 채워지지 않아 소비자가 기본값 폴백을 탑니다'
        );
    }

    /**
     * 설정 저장이 같은 프로세스의 미러를 즉시 갱신한다.
     */
    public function test_save_refreshes_the_mirror_in_the_same_process(): void
    {
        $service = app(PageSettingsService::class);
        $current = (int) $service->getSetting('attachment.max_count');
        $next = $current + 3;

        $service->saveSettings(['attachment' => array_merge(
            $service->getSettings('attachment'),
            ['max_count' => $next]
        )]);

        $this->assertSame(
            $next,
            (int) g7_module_settings('sirsoft-page', 'attachment.max_count', 0),
            '저장 후에도 미러가 옛 값을 유지합니다'
        );
    }
}
