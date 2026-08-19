<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 설정 미러 재채움 테스트 (공개이슈 #109, A3)
 *
 * 모듈 설정 저장에는 코어 공통 지점이 없어 각 모듈 서비스가 직접 저장한다.
 * 그 지점에서 미러를 갱신하지 않으면 상주 프로세스(큐 워커 등)가 옛 값을 계속 읽는다.
 *
 * @scenario scope=module, trigger=save, trigger=cache_clear
 *
 * @effects module_mirror_refreshed_after_save, module_mirror_refreshed_after_cache_clear
 */
class EcommerceSettingsMirrorTest extends ModuleTestCase
{
    /**
     * 설정 저장 후 같은 프로세스의 미러가 신값을 읽는다.
     */
    public function test_module_settings_mirror_is_refreshed_after_save(): void
    {
        $service = app(EcommerceSettingsService::class);

        $basic = $service->getSettings('basic_info');
        $newName = '미러 확인 상점 '.uniqid();

        $service->saveSettings(['basic_info' => array_merge($basic, ['shop_name' => $newName])]);

        $this->assertSame(
            $newName,
            g7_module_settings('sirsoft-ecommerce', 'basic_info.shop_name'),
            '저장 후에도 미러가 옛 값을 유지합니다'
        );
    }

    /**
     * 캐시 초기화도 미러를 함께 갱신한다.
     */
    public function test_clear_cache_also_refreshes_mirror(): void
    {
        $service = app(EcommerceSettingsService::class);

        config(['g7_settings.modules.sirsoft-ecommerce' => null]);

        $service->clearCache();

        $this->assertIsArray(
            g7_module_settings('sirsoft-ecommerce'),
            '캐시 초기화 후 미러가 다시 채워지지 않았습니다'
        );
        $this->assertNotEmpty(g7_module_settings('sirsoft-ecommerce'));
    }
}
