<?php

namespace Tests\Feature\Console;

use App\Support\SettingsVersion;
use Tests\TestCase;

/**
 * `settings:install` 뒤 캐시·설정 미러·설정 버전이 화면 저장과 같이 갱신된다 (7.1.4 F32).
 *
 * 회귀 성격: 저장소에 직접 쓰고 끝나 실행 뒤에도 캐시가 옛 설정을 돌려줬다. 설정 디스크는 TestCase 의 페이크다.
 */
class InstallSettingsCommandCacheTest extends TestCase
{
    /**
     * 덮어쓰기 설치 뒤 설정 버전이 오르고 미러가 기본값으로 다시 채워진다.
     *
     * @effects settings_install_invalidates_settings_cache
     *
     * @return void 반환값 없음
     */
    public function test_install_force_bumps_version_and_refreshes_mirror(): void
    {
        SettingsVersion::forget();
        config(['g7_settings.core.seo.meta_title_suffix' => 'stale-mirror-value']);

        $this->artisan('settings:install', ['--force' => true])->assertExitCode(0);

        $this->assertNotNull(SettingsVersion::current(), '설정 버전이 올라야 상주 프로세스가 다시 읽는다');
        $this->assertNotSame('stale-mirror-value', config('g7_settings.core.seo.meta_title_suffix'));
    }
}
