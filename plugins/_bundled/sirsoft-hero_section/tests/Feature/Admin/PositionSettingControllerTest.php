<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 위치 설정 API 테스트 (매니페스트 admin-banners.yaml — 위치 설정 케이스)
 */
class PositionSettingControllerTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->fullAdmin();
    }

    /**
     * 위치 목록 응답에 업로드 한도 세 값(설정·서버·실제)이 실린다.
     *
     * @scenario surface=position_settings_reports_upload_limits
     *
     * @return void 반환값 없음
     */
    public function test_reports_upload_limits(): void
    {
        $this->setPluginSettings(['max_upload_mb' => 7]);

        $upload = $this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.upload');

        $this->assertSame(7, $upload['max_mb']);
        $this->assertEquals(UploadLimit::serverLimitMb(), $upload['server_limit_mb']);
        $server = UploadLimit::serverLimitMb();
        $this->assertEquals($server > 0 ? min(7, $server) : 7, $upload['effective_mb']);
    }

    /**
     * 저장된 행이 없으면 두 위치 모두 기본값으로 응답한다.
     *
     * @scenario surface=position_settings_default_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_defaults_when_missing(): void
    {
        $positions = $this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.positions');

        $this->assertSame(['home_hero', 'shop_hero'], array_column($positions, 'key'));
        $this->assertSame(['home_hero', 'shop_hero'], array_column($positions, 'extension_point'));
        foreach ($positions as $position) {
            $this->assertSame(PositionSetting::DEFAULTS, $position['settings']);
        }
    }

    /**
     * 처음 저장하면 행을 만들고, 다시 저장하면 그 행을 고친다.
     *
     * @scenario surface=position_settings_upsert
     *
     * @return void 반환값 없음
     */
    public function test_upsert(): void
    {
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/shop_hero'), ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade'])
            ->assertOk()->assertJsonPath('data.settings', ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/shop_hero'), ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none'])
            ->assertOk();

        $this->assertSame(1, PositionSetting::query()->where('position', 'shop_hero')->count());
        $this->assertSame(['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none'], PositionSetting::query()->where('position', 'shop_hero')->first()->toSettings());
        $this->assertSame(0, PositionSetting::query()->where('position', 'home_hero')->count());
    }

    /**
     * 간격은 3·5·7초, 효과는 slide·fade·none 만 받고, 모르는 위치는 404 다.
     *
     * @scenario surface=position_settings_interval_enum
     *
     * @return void 반환값 없음
     */
    public function test_interval_and_effect_enum(): void
    {
        $url = $this->api('/admin/position-settings/home_hero');

        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 4, 'effect' => 'slide'])
            ->assertStatus(422)->assertJsonValidationErrors(['interval_seconds']);
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'zoom'])
            ->assertStatus(422)->assertJsonValidationErrors(['effect']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/side_bar'), ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide'])
            ->assertNotFound();
    }
}
