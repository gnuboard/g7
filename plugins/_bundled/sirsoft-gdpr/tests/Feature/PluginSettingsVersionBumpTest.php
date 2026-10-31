<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Services\PluginSettingsService;
use App\Support\SettingsVersion;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 플러그인 설정을 저장하면 설정 버전이 바뀐다 — 큐 워커가 다시 읽을 신호
 *
 * 큐 워커는 부팅 때 읽은 플러그인 설정을 들고 있다. 저장 공통 지점(PluginSettingsService)이 설정 버전을 올리면
 * 워커는 다음 작업 시작 전에 비교해 다시 읽는다(코어 #724). 플러그인이 로드되는 이 테스트에서 저장 경로를 검증한다.
 */
class PluginSettingsVersionBumpTest extends PluginTestCase
{
    /**
     * 플러그인 설정 저장이 설정 버전을 올린다. (실패-먼저)
     *
     * @scenario path=service, queue=database
     *
     * @effects settings_version_bumped
     *
     * @return void 반환값 없음
     */
    public function test_plugin_settings_save_bumps_version(): void
    {
        SettingsVersion::forget();

        $saved = app(PluginSettingsService::class)->save('sirsoft-gdpr', [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '버전 확인', 'en' => 'Version probe']],
            ],
        ]);

        $this->assertTrue($saved);
        $this->assertNotNull(SettingsVersion::current(), '플러그인 설정을 저장했는데 설정 버전이 없습니다 — 큐 워커가 바뀐 것을 모릅니다.');
    }
}
