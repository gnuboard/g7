<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Settings;

use App\Support\SettingsVersion;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 설정을 저장하면 설정 버전이 바뀐다 — 큐 워커가 다시 읽을 신호
 *
 * 큐 워커는 부팅 때 읽은 모듈 설정을 들고 있다. 모듈 저장 공통 지점(g7_refresh_module_settings_config)이 설정
 * 버전을 올리면 워커는 다음 작업 시작 전에 비교해 다시 읽고, 작업마다 새로 만들어지는 설정 서비스(scoped)가 새 값을
 * 쓴다(#724).
 */
class ModuleSettingsVersionBumpTest extends ModuleTestCase
{
    /**
     * 이커머스 설정 저장이 설정 버전을 올린다. (실패-먼저)
     *
     * @scenario mutation=settings_save
     *
     * @effects worker_scope_reset
     *
     * @return void 반환값 없음
     */
    public function test_module_settings_save_bumps_version(): void
    {
        SettingsVersion::forget();
        $settings = app(EcommerceSettingsService::class);

        $settings->saveSettings([
            'language_currency' => $settings->getSettings('language_currency'),
        ]);

        $this->assertNotNull(SettingsVersion::current(), '이커머스 설정을 저장했는데 설정 버전이 없습니다 — 큐 워커가 바뀐 것을 모릅니다.');
    }
}
