<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use App\Http\Controllers\Api\Admin\PluginSettingsController;
use App\Http\Requests\Admin\UpdatePluginSettingsRequest;
use App\Services\PluginSettingsService;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 플러그인 설정 응답 공개 자산 카탈로그 부착 테스트 (공개#100)
 *
 * 설정 스키마가 public_asset_disk 를 선언한 플러그인(ckeditor5)의 설정 GET 응답에
 * available_public_asset_disks 카탈로그가 서버 부착되는지 검증합니다. 화면이 코어
 * 설정 API(core.settings.read)를 교차 조회하면 화면 권한(core.plugins.read)과
 * 표면이 갈려 커스텀 역할에서 카탈로그만 조용히 비므로, 단일 표면 계약을 고정합니다.
 *
 * @effects settings_catalog_includes_plugin_registered_disks
 */
class PluginSettingsCatalogAttachTest extends PluginTestCase
{
    /**
     * 설정 GET 응답에 코어·플러그인 등록 디스크 카탈로그가 붙는다.
     *
     * @scenario disk_setting=public, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function settings_response_attaches_public_asset_catalog(): void
    {
        $response = app(PluginSettingsController::class)->show('sirsoft-ckeditor5');

        $this->assertSame(200, $response->getStatusCode());

        $data = $response->getData(true)['data'] ?? [];
        $this->assertArrayHasKey('available_public_asset_disks', $data);

        $ids = array_column($data['available_public_asset_disks'], 'id');
        $this->assertContains('none', $ids);
        $this->assertContains('public', $ids);
        $this->assertContains('s3', $ids);
    }

    /**
     * 스키마에 public_asset_disk 가 없는 플러그인에는 카탈로그를 붙이지 않는다.
     *
     * @scenario disk_setting=none, override=follow_core
     *
     * @effects settings_catalog_includes_plugin_registered_disks
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function catalog_is_not_attached_when_schema_lacks_public_asset_disk(): void
    {
        // 스키마에 public_asset_disk 가 없는 플러그인 시뮬레이션 — 게이트가 닫혀야 한다
        $plugin = $this->createMock(AbstractPlugin::class);
        $plugin->method('getSettingsSchema')->willReturn([
            'some_other_key' => ['type' => 'string'],
        ]);

        $manager = $this->createMock(PluginManager::class);
        $manager->method('getPlugin')->willReturn($plugin);
        $this->app->instance(PluginManager::class, $manager);

        $response = app(PluginSettingsController::class)->show('sirsoft-ckeditor5');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey(
            'available_public_asset_disks',
            $response->getData(true)['data'] ?? []
        );
    }

    /**
     * 저장된 디스크를 제공하던 확장이 꺼져 카탈로그에서 사라지면, 응답은 그 값을 실제 동작(스트리밍)인
     * `none` 으로 바꿔 싣고 사라진 디스크 이름을 `public_asset_disk_unavailable` 로 알린다.
     *
     * 종전에는 저장값을 그대로 실어 화면의 선택 칸이 어떤 선택지와도 맞지 않은 채 보였고,
     * 폼 전체를 보내는 저장이 매번 `public_asset_disk_invalid` 422 로 막혔다.
     *
     * @scenario disk_setting=fake_cdn, override=module_override
     *
     * @effects stale_disk_normalized_with_notice
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stale_disk_is_shown_as_streaming_with_notice_and_saves(): void
    {
        app(PluginSettingsService::class)->save('sirsoft-ckeditor5', ['public_asset_disk' => 'gone_cdn']);

        $data = app(PluginSettingsController::class)->show('sirsoft-ckeditor5')->getData(true)['data'] ?? [];

        $this->assertSame('none', $data['public_asset_disk'] ?? null);
        $this->assertSame('gone_cdn', $data['public_asset_disk_unavailable'] ?? null);

        // 화면이 받은 값을 그대로 돌려보내면 저장이 통과해야 한다
        $request = UpdatePluginSettingsRequest::create('/', 'PUT', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setRouteResolver(fn () => new class
        {
            /**
             * 라우트 매개변수 조회를 흉내 내 항상 이 플러그인 식별자를 돌려준다.
             *
             * @param  string  $key  매개변수 이름
             * @param  mixed  $default  기본값
             * @return string 플러그인 식별자
             */
            public function parameter(string $key, $default = null): string
            {
                return 'sirsoft-ckeditor5';
            }
        });
        $request->validateResolved();

        $this->assertSame('none', $request->validated()['public_asset_disk']);
        $this->assertArrayNotHasKey('public_asset_disk_unavailable', $request->validated());
    }

    /**
     * 카탈로그에 있는 저장값과 「코어 설정 따름」(빈 값)은 건드리지 않고 안내도 싣지 않는다.
     *
     * @scenario disk_setting=public, override=follow_core
     *
     * @effects stale_disk_normalized_with_notice
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function available_disk_and_follow_core_are_left_as_is(): void
    {
        foreach (['public', ''] as $stored) {
            app(PluginSettingsService::class)->save('sirsoft-ckeditor5', ['public_asset_disk' => $stored]);

            $data = app(PluginSettingsController::class)->show('sirsoft-ckeditor5')->getData(true)['data'] ?? [];

            $this->assertSame($stored, $data['public_asset_disk'] ?? null);
            $this->assertArrayNotHasKey('public_asset_disk_unavailable', $data);
        }
    }

    /**
     * 테스트가 바꿔 끼운 플러그인 관리자를 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->app->forgetInstance(PluginManager::class);

        parent::tearDown();
    }
}
