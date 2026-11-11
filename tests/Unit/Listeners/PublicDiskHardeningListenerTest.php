<?php

namespace Tests\Unit\Listeners;

use App\Listeners\PublicDiskHardeningListener;
use App\Support\PublicDiskHardeningProbe;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 드라이버 탭 저장 뒤 공개 디스크 보호 규칙 파일을 맞추고 직접 주소를 점검한다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class PublicDiskHardeningListenerTest extends TestCase
{
    private string $storageRoot;

    private string $originalStoragePath;

    /**
     * 저장소 루트를 임시 디렉터리로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->storageRoot = $this->originalStoragePath.'/framework/testing/hardening-listener-'.uniqid();
        mkdir($this->storageRoot.'/app/public', 0777, true);
        $this->app->useStoragePath($this->storageRoot);

        config([
            'app.url' => 'https://probe.example.test',
            'cache.default' => 'array',
            // 리스너는 자동 시험 중 실제 공개 디스크를 건드리지 않게 꺼져 있다 — 이 시험만 켠다.
            'core.storage.public_disk_hardening_in_tests' => true,
        ]);
        PublicDiskHardeningProbe::forget();
    }

    /**
     * 저장소 루트를 되돌리고 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);

        foreach (glob($this->storageRoot.'/app/public/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storageRoot.'/app/public');
        @rmdir($this->storageRoot.'/app');
        @rmdir($this->storageRoot);

        parent::tearDown();
    }

    /**
     * 공개 자산 디스크가 로컬 공개 디스크면 규칙 파일을 방금 저장한 목록으로 만들고 직접 주소를 점검한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_save__operator_added
     *
     * @effects drivers_save_refreshes_rules_and_runs_self_check
     */
    public function test_drivers_save_with_public_disk_writes_rules_and_probes(): void
    {
        Http::fake(fn () => Http::response('probe', 200, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment',
        ]));
        Config::set('core.storage.public_asset_extensions', ['jpg', 'png']);

        (new PublicDiskHardeningListener)->onSettingsSave('drivers', [
            'public_asset_disk' => 'public',
            'public_asset_extensions' => ['png', 'pdf'],
        ], true);

        $rules = (string) file_get_contents($this->storageRoot.'/app/public/.htaccess');
        $this->assertStringContainsString('(?!.*\.(png)$)', $rules);
        $this->assertTrue(PublicDiskHardeningProbe::cached()['ok']);
        Http::assertSentCount(1);
    }

    /**
     * 공개 자산 디스크가 로컬 공개 디스크가 아니면 점검하지 않고 지난 결과를 지운다(규칙 파일은 만든다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_save__exportable
     *
     * @effects self_check_result_cleared_when_public_disk_not_used
     */
    public function test_drivers_save_without_public_disk_clears_the_result(): void
    {
        Http::fake(fn () => Http::response('probe', 200));
        PublicDiskHardeningProbe::run($this->storageRoot.'/app/public');
        $this->assertNotNull(PublicDiskHardeningProbe::cached());

        (new PublicDiskHardeningListener)->onSettingsSave('drivers', ['public_asset_disk' => 'none'], true);

        $this->assertNull(PublicDiskHardeningProbe::cached());
        $this->assertFileExists($this->storageRoot.'/app/public/.htaccess');
        Http::assertSentCount(1);
    }

    /**
     * 다른 탭의 저장이나 실패한 저장에는 아무것도 하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings_save__exportable
     *
     * @effects drivers_save_refreshes_rules_and_runs_self_check
     */
    public function test_other_tabs_and_failed_saves_are_ignored(): void
    {
        Http::fake();

        $listener = new PublicDiskHardeningListener;
        $listener->onSettingsSave('upload', ['public_asset_disk' => 'public'], true);
        $listener->onSettingsSave('drivers', ['public_asset_disk' => 'public'], false);

        $this->assertFileDoesNotExist($this->storageRoot.'/app/public/.htaccess');
        Http::assertNothingSent();
    }
}
