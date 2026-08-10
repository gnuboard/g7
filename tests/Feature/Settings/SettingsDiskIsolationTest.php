<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * settings 디스크 격리 회귀 테스트
 *
 * settings 디스크의 root 는 storage/app/settings — 개발/운영이 실제로 쓰는 설정 파일입니다.
 * TestCase 가 이 디스크를 페이크로 대체하지 않으면, 설정 저장 경로를 타는 모든 테스트가
 * 실제 설정을 덮어쓰고 RefreshDatabase 로도 되돌아가지 않습니다.
 *
 * 실제 피해 사례: 설정 저장 테스트가 general.json 의 사이트명/언어를 테스트 값으로 덮어써
 * 개발 사이트 언어가 ja 로 바뀌어 있었고, SEO/캐시 TTL 도 테스트 값으로 대체되어 있었음.
 *
 * 이 가드가 풀리면 조용히 환경이 오염되므로(테스트는 계속 green) 명시적으로 고정합니다.
 */
class SettingsDiskIsolationTest extends TestCase
{
    /**
     * settings 디스크가 페이크로 대체되어 실경로를 가리키지 않는지 확인합니다.
     *
     * config 의 root 는 페이크 후에도 실경로 그대로이므로(Storage::fake 는 해석된 디스크
     * 인스턴스만 교체), 반드시 해석된 디스크의 경로로 판정해야 합니다.
     */
    public function test_settings_disk_is_faked_and_does_not_point_to_real_storage(): void
    {
        $root = str_replace('\\', '/', Storage::disk('settings')->path(''));

        $this->assertStringContainsString(
            'framework/testing',
            $root,
            'settings 디스크가 페이크로 대체되지 않았습니다 — 테스트가 실제 설정 파일을 덮어씁니다.'
        );
        $this->assertStringNotContainsString(
            'storage/app/settings',
            $root,
            'settings 디스크가 실제 설정 경로를 가리킵니다.'
        );
    }

    /**
     * 설정을 저장해도 실제 설정 파일이 만들어지지 않는지 확인합니다.
     */
    public function test_saving_settings_does_not_write_to_real_storage_path(): void
    {
        $realPath = storage_path('app/settings/general.json');
        $before = file_exists($realPath) ? file_get_contents($realPath) : null;

        app(ConfigRepositoryInterface::class)->saveCategory('general', [
            'site_name' => '격리 검증용 값',
            'language' => 'ja',
        ]);

        $after = file_exists($realPath) ? file_get_contents($realPath) : null;

        $this->assertSame($before, $after, '설정 저장이 실제 설정 파일을 변경했습니다.');

        // 저장 자체는 페이크 디스크에서 정상 동작해야 한다 (격리가 기능을 죽이면 안 됨)
        Storage::disk('settings')->assertExists('general.json');
        $this->assertSame(
            '격리 검증용 값',
            app(ConfigRepositoryInterface::class)->getCategory('general')['site_name']
        );
    }
}
