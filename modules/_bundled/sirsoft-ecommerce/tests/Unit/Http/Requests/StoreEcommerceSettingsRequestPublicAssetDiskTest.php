<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
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
    /**
     * 훅과 테스트가 기록한 설정 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();

        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($settingsPath)) {
            File::cleanDirectory($settingsPath);
        }

        parent::tearDown();
    }

    /**
     * basic_info 탭 저장 요청을 해석합니다.
     *
     * @param  string  $disk  public_asset_disk 값
     * @param  array<string, mixed>  $extra  basic_info 에 함께 실을 값
     * @return StoreEcommerceSettingsRequest 검증을 마친 요청
     */
    private function resolve(string $disk, array $extra = []): StoreEcommerceSettingsRequest
    {
        $request = StoreEcommerceSettingsRequest::create('/', 'POST', [
            '_tab' => 'basic_info',
            'basic_info' => array_merge([
                // 같은 탭의 필수 형제 필드 — 이 축과 무관하지만 없으면 탭 전체가 422 라
                // 디스크 규칙만 분리해 보기 위해 채운다.
                'shop_name' => '테스트 상점',
                'route_path' => 'shop',
                'public_asset_disk' => $disk,
            ], $extra),
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    /**
     * 코어 카탈로그의 디스크는 저장 검증을 통과합니다.
     *
     * @scenario disk_setting=public, override=module_override
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
    public function test_core_catalog_disk_is_accepted(): void
    {
        $request = $this->resolve('public');

        $this->assertSame('public', $request->validated()['basic_info']['public_asset_disk']);
    }

    /**
     * 빈 값(코어 설정 따름)은 저장 검증을 통과합니다.
     *
     * @scenario disk_setting=none, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
    public function test_empty_value_is_accepted_as_follow_core(): void
    {
        $request = $this->resolve('');

        // 빈 값 = "코어 설정 따름" 이므로 검증을 통과해야 한다.
        $this->assertSame('', $request->validated()['basic_info']['public_asset_disk'] ?? '');
    }

    /**
     * 카탈로그에 없는 디스크는 basic_info.public_asset_disk 오류로 거부됩니다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     *
     * @effects invalid_disk_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 플러그인이 훅으로 등록한 디스크도 저장 검증을 통과합니다.
     *
     * @scenario disk_setting=fake_cdn, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
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

    /**
     * 저장했던 디스크를 제공하던 확장이 꺼져 카탈로그에서 사라지면, 설정 응답은 그 값을 실제 동작(스트리밍)인
     * `none` 으로 바꿔 싣고 사라진 디스크 이름을 `basic_info.public_asset_disk_unavailable` 로 알립니다.
     * 화면이 받은 기본정보를 그대로 돌려보내면 저장 검증을 통과하고, 안내 키는 저장되지 않습니다.
     *
     * 종전에는 저장값을 그대로 실어 선택 칸이 어떤 선택지와도 맞지 않은 채 보였고, 기본정보 탭 저장이
     * 다른 항목만 고쳐도 매번 `public_asset_disk_invalid` 422 로 막혔습니다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     *
     * @effects stale_disk_normalized_with_notice
     *
     * @return void 반환값 없음
     */
    public function test_stale_disk_is_shown_as_streaming_with_notice_and_saves(): void
    {
        app(EcommerceSettingsService::class)->setSetting('basic_info.public_asset_disk', 'gone_cdn');

        $data = app(EcommerceSettingsController::class)->index()->getData(true)['data'] ?? [];

        $this->assertSame('none', $data['basic_info']['public_asset_disk'] ?? null);
        $this->assertSame('gone_cdn', $data['basic_info']['public_asset_disk_unavailable'] ?? null);

        // 화면이 받은 값을 그대로 돌려보내면 저장이 통과하고, 안내 키는 저장 대상에서 빠진다
        $request = $this->resolve('none', ['public_asset_disk_unavailable' => 'gone_cdn']);

        $this->assertSame('none', $request->validated()['basic_info']['public_asset_disk']);
        $this->assertArrayNotHasKey('public_asset_disk_unavailable', $request->validated()['basic_info']);
    }

    /**
     * 카탈로그에 있는 저장값과 「코어 설정 따름」(빈 값)은 건드리지 않고 안내도 싣지 않습니다.
     *
     * @scenario disk_setting=public, override=follow_core
     *
     * @effects stale_disk_normalized_with_notice
     *
     * @return void 반환값 없음
     */
    public function test_available_disk_and_follow_core_are_left_as_is(): void
    {
        foreach (['public', ''] as $stored) {
            app(EcommerceSettingsService::class)->setSetting('basic_info.public_asset_disk', $stored);

            $data = app(EcommerceSettingsController::class)->index()->getData(true)['data'] ?? [];

            $this->assertSame($stored, $data['basic_info']['public_asset_disk'] ?? null);
            $this->assertArrayNotHasKey('public_asset_disk_unavailable', $data['basic_info'] ?? []);
        }
    }
}
