<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 공개 자산 디스크 저장 검증 테스트 (공개#100)
 *
 * 모듈 오버라이드 필드도 코어 환경설정(SaveSettingsRequest)과 같은 강도로 검증한다.
 * 이 게이트가 없으면 오타 디스크명이 200 으로 저장된 뒤 런타임 폴백만 남아,
 * 운영자에게는 "저장은 됐는데 CDN 이 안 붙는" 무증상 상태로만 보인다.
 *
 * 선택지는 코어 3종 + 플러그인 훅 등록 디스크라 정적 in 으로 고정할 수 없으므로,
 * 카탈로그 게터(SSoT)를 조회하는 closure 가 실제로 걸려 있는지를 고정한다.
 *
 * @effects invalid_disk_rejected_with_422, settings_catalog_includes_plugin_registered_disks
 */
class StoreEcommerceSettingsRequestPublicAssetDiskTest extends ModuleTestCase
{
    protected function tearDown(): void
    {
        HookManager::resetAll();

        parent::tearDown();
    }

    /**
     * basic_info 탭 저장 요청을 해석합니다.
     *
     * @param  string  $disk  public_asset_disk 값
     */
    private function resolve(string $disk): StoreEcommerceSettingsRequest
    {
        $request = StoreEcommerceSettingsRequest::create('/', 'POST', [
            '_tab' => 'basic_info',
            'basic_info' => [
                // 같은 탭의 필수 형제 필드 — 이 축과 무관하지만 없으면 탭 전체가 422 라
                // 디스크 규칙만 분리해 보기 위해 채운다.
                'shop_name' => '테스트 상점',
                'route_path' => 'shop',
                'public_asset_disk' => $disk,
            ],
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    public function test_core_catalog_disk_is_accepted(): void
    {
        $request = $this->resolve('public');

        $this->assertSame('public', $request->validated()['basic_info']['public_asset_disk']);
    }

    public function test_empty_value_is_accepted_as_follow_core(): void
    {
        $request = $this->resolve('');

        // 빈 값 = "코어 설정 따름" 이므로 검증을 통과해야 한다.
        $this->assertSame('', $request->validated()['basic_info']['public_asset_disk'] ?? '');
    }

    public function test_unknown_disk_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->resolve('nonexistent_disk');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey(
                'basic_info.public_asset_disk',
                $e->validator->errors()->toArray(),
                '카탈로그에 없는 디스크는 basic_info.public_asset_disk 오류를 내야 한다'
            );

            throw $e;
        }
    }

    public function test_plugin_registered_disk_is_accepted(): void
    {
        // 플러그인이 훅으로 등록한 디스크도 통과해야 한다 — 정적 in 이었다면 여기서 깨진다.
        Config::set('filesystems.disks.plugin_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/plugin_cdn'),
            'url' => 'https://plugin-cdn.test/assets',
        ]);

        HookManager::addFilter(
            'core.settings.available_public_asset_drivers',
            function (array $drivers): array {
                $drivers[] = [
                    'id' => 'plugin_cdn',
                    'label' => ['ko' => '플러그인 CDN', 'en' => 'Plugin CDN'],
                    'provider' => 'test-plugin',
                ];

                return $drivers;
            }
        );

        $request = $this->resolve('plugin_cdn');

        $this->assertSame('plugin_cdn', $request->validated()['basic_info']['public_asset_disk']);
    }
}
