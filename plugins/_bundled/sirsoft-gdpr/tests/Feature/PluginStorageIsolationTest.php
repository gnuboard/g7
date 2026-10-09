<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 플러그인 테스트의 설정 저장소 격리 테스트
 *
 * 설정 저장 테스트(관리자 설정 API, 연간 통지 명령)는 `PluginSettingsService::save` 로 `plugins` 디스크의
 * `storage/app/plugins/sirsoft-gdpr/settings/setting.json` 에 실제로 쓴다. 2026-10-07 실측(O-M1F-A)에서 설정 저장 테스트 실행 시각에
 * 개발 사이트 설정 파일이 테스트 값(쿠키 분류 라벨 「필수」/Necessary)으로 바뀌었다. 테스트마다 따로 둔 임시 루트에만
 * 쓰고, 개발 사이트 설정 파일과 여러 실행이 함께 쓰는 테스트 저장소 경로는 건드리지 않는지 본다.
 *
 * @scenario-manifest tests/scenarios/plugin-storage-isolation.yaml
 */
class PluginStorageIsolationTest extends PluginTestCase
{
    /**
     * 설정 저장은 이 테스트만의 임시 루트에 쓰이고, 개발 사이트·공용 테스트 경로의 설정 파일은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario writer=plugin_settings_save
     *
     * @effects settings_write_lands_in_isolated_root, site_settings_file_untouched
     */
    public function test_settings_save_writes_only_to_isolated_root(): void
    {
        // audit:allow extension-storage-path-hand-assembled 운영 경로를 **의도적으로** 가리킨다 — 이 단언의 대상은 "테스트가 개발 사이트 설정 파일을 건드리지 않았는가" 이므로 해석기를 쓰면 검사가 성립하지 않는다.
        $siteFile = storage_path('app/plugins/sirsoft-gdpr/settings/setting.json');
        $sharedTestingFile = storage_path('framework/testing/plugins/sirsoft-gdpr/settings/setting.json');
        $siteBefore = is_file($siteFile) ? md5_file($siteFile) : null;
        $sharedBefore = is_file($sharedTestingFile) ? md5_file($sharedTestingFile) : null;

        $root = strtr((string) config('filesystems.disks.plugins.root'), DIRECTORY_SEPARATOR, '/');
        $this->assertStringContainsString('/storage/framework/testing/plugin-storage-', $root);

        $saved = app(PluginSettingsService::class)->save('sirsoft-gdpr', [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '격리 시험', 'en' => 'Isolation probe']],
            ],
        ]);

        $this->assertTrue($saved);
        $isolatedFile = config('filesystems.disks.plugins.root').'/sirsoft-gdpr/settings/setting.json';
        $this->assertFileExists($isolatedFile);
        $this->assertStringContainsString('Isolation probe', (string) file_get_contents($isolatedFile));

        $this->assertSame($siteBefore, is_file($siteFile) ? md5_file($siteFile) : null, '개발 사이트 설정 파일이 바뀌면 안 된다');
        $this->assertSame($sharedBefore, is_file($sharedTestingFile) ? md5_file($sharedTestingFile) : null, '여러 실행이 함께 쓰는 테스트 경로에 남기지 않는다');
    }

    /**
     * 앞 테스트가 저장한 설정이 다음 테스트로 새지 않는다(테스트마다 빈 루트에서 시작).
     *
     * @return void 반환값 없음
     *
     * @scenario writer=previous_test
     *
     * @effects settings_do_not_leak_between_tests
     */
    public function test_each_test_starts_from_empty_settings_root(): void
    {
        $file = config('filesystems.disks.plugins.root').'/sirsoft-gdpr/settings/setting.json';

        $this->assertFileDoesNotExist($file);
    }
}
