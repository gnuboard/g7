<?php

namespace Tests\Feature\Console;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `ext-static:status` / `ext-static:publish` 의 root 실행 안내 + 상태 보고서 소비 테스트 (#651 C1·D3).
 *
 * 상태 명령은 종전에 "즉시 게시하려면 `php artisan ext-static:publish`" 를 실행 계정과 무관하게
 * 권했다. 운영자가 그 안내를 sudo 셸에서 그대로 따르면 캐시 락 샤드가 root 소유가 되어 이후 웹
 * 요청이 500 이 된다. 이제 root 이면 경고와 `sudo -u {웹계정}` 형태의 힌트를 함께 낸다 — 중단은
 * 하지 않는다(D3).
 */
class ExtStaticStatusCommandTest extends TestCase
{
    private string $isolatedPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedPublicPath = storage_path('framework/testing/public-cmd-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);

        ClearsTemplateCaches::resetExtensionCacheStoreMemo();
        Cache::put('g7:core:ext.cache_version', 987600000);
        Cache::forget('g7:core:ext.static.publish_failure');
        ExtensionStaticCacheService::resetPublishScheduleForTesting();
    }

    protected function tearDown(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(null);
        File::deleteDirectory($this->isolatedPublicPath);
        ExtensionStaticCacheService::resetPublishScheduleForTesting();

        parent::tearDown();
    }

    /**
     * root 가 아니면 경고 없이 종전 문구 그대로다 (미게시 → 비-0 종료).
     *
     * @effects status_command_warns_root_and_hints_web_account
     */
    public function test_non_root_status_prints_plain_publish_hint_without_root_warning(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'non_root', 'name' => null]);

        $this->artisan('ext-static:status')
            ->expectsOutputToContain('부트스트랩 리소스 정적 게시 상태')
            ->expectsOutputToContain('현재 캐시 버전 : 987600000')
            ->expectsOutputToContain('게시 상태      : 미게시')
            ->expectsOutputToContain('php artisan ext-static:publish')
            ->doesntExpectOutputToContain('root 로 실행 중')
            ->doesntExpectOutputToContain('sudo -u')
            ->assertExitCode(1);
    }

    /**
     * root + 웹 계정 식별 → 경고 + `sudo -u {계정}` 힌트. 종료 코드는 종전과 같다.
     *
     * @effects status_command_warns_root_and_hints_web_account
     */
    public function test_root_status_warns_and_hints_sudo_with_web_account(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'root_web_known', 'name' => 'www-data']);

        $this->artisan('ext-static:status')
            ->expectsOutputToContain('root 로 실행 중')
            ->expectsOutputToContain('sudo -u www-data php artisan ext-static:publish')
            ->assertExitCode(1);
    }

    /**
     * root + 웹 계정 미상 → placeholder 와 확인 방법.
     *
     * @effects status_command_warns_root_and_hints_web_account
     */
    public function test_root_status_with_unknown_web_account_uses_placeholder(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'root_web_unknown', 'name' => null]);

        // 한 줄에 두 부분 문자열을 따로 기대하면 Mockery 가 첫 기대만 소비한다 — 한 문자열로 잠근다
        $this->artisan('ext-static:status')
            ->expectsOutputToContain('sudo -u <웹서버계정> php artisan ext-static:publish (웹서버 계정은 storage 디렉토리 소유자로 확인)')
            ->assertExitCode(1);
    }

    /**
     * root 서비스 구성(웹 계정도 root)은 sudo 접두 없이 안내한다 — 재실행도 root 로 무해.
     *
     * @effects status_command_warns_root_and_hints_web_account
     */
    public function test_root_symmetric_hint_has_no_sudo_prefix(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'root_web_symmetric', 'name' => null]);

        $this->artisan('ext-static:status')
            ->expectsOutputToContain('root 로 실행 중')
            ->doesntExpectOutputToContain('sudo -u')
            ->assertExitCode(1);
    }

    /**
     * 게시 명령은 root 여도 **중단하지 않고** 경고 뒤 계속 실행한다 (D3 — 명시적 명령은 운영자 책임).
     *
     * @effects publish_command_warns_root_but_continues
     */
    public function test_publish_warns_root_but_still_publishes(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'root_web_known', 'name' => 'www-data']);
        Cache::lock('ext-static.publish.987600000', 300)->forceRelease();

        $this->artisan('ext-static:publish')
            ->expectsOutputToContain('root 로 실행 중')
            ->expectsOutputToContain('sudo -u www-data php artisan ext-static:publish')
            ->expectsOutputToContain('정적 게시가 완료되었습니다')
            ->assertExitCode(0);

        $this->assertFileExists(public_path('build/ext/987600000/manifest.json'));
    }

    /**
     * 상태 명령은 서비스 보고서를 소비한다 — 게시 뒤에는 완료·파일 수·게시 시각을 그대로 보인다.
     *
     * @effects status_command_consumes_service_report
     */
    public function test_status_reflects_published_report(): void
    {
        FilePermissionHelper::fakeWebServerAccountForTesting(['mode' => 'non_root', 'name' => null]);
        Cache::lock('ext-static.publish.987600000', 300)->forceRelease();

        $this->assertTrue(app(ExtensionStaticCacheService::class)->publishCurrent());

        $this->artisan('ext-static:status')
            ->expectsOutputToContain('게시 상태      : 완료')
            ->expectsOutputToContain('manifest 파일  : 1건')
            ->expectsOutputToContain('이상 없음.')
            ->assertExitCode(0);
    }
}
