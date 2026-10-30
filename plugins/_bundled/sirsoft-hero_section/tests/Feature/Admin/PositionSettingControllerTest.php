<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PreviewFrameResolver;
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
     * 편집 메타에 미리보기 구좌 틀(템플릿·CSS·위치별 사슬)이 실린다 — 배너 조회 권한만으로 받는다.
     *
     * @scenario surface=preview_frame_chain_from_active_user_template
     *
     * @return void 반환값 없음
     */
    public function test_editor_meta_carries_preview_frame(): void
    {
        $frame = $this->actingAs($this->adminWith(['sirsoft-hero_section.banners.read']))
            ->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.editor.preview_frame');

        $this->assertIsArray($frame);
        $this->assertArrayHasKey('template', $frame);
        $this->assertIsArray($frame['stylesheets']);
        $this->assertSame(['home_hero', 'shop_hero'], array_keys($frame['positions']));
    }

    /**
     * 관리자 레이아웃 편집기에서 사용자 템플릿 홈 레이아웃을 저장하면 미리보기 구좌 틀이 1시간 캐시를 기다리지 않고 바뀐다.
     *
     * 틀 캐시 키에는 확장 캐시 버전이 들어 있다 — 편집기 저장(PUT 레이아웃)이 그 버전을 올리지 않으면 옛 본문 상자 폭이 남는다.
     *
     * @scenario surface=preview_frame_refreshes_after_layout_edit
     *
     * @return void 반환값 없음
     */
    public function test_preview_frame_refreshes_after_layout_editor_save(): void
    {
        Template::query()->where('type', 'user')->update(['status' => ExtensionStatus::Inactive->value]);
        $template = Template::factory()->create(['type' => 'user', 'status' => ExtensionStatus::Active->value]);
        $content = fn (string $class) => [
            'version' => '1.0.0',
            'layout_name' => 'home',
            'components' => [[
                'id' => 'main', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => $class],
                'children' => [['id' => 'slot', 'type' => 'extension_point', 'name' => 'home_hero']],
            ]],
            'data_sources' => [],
            'metadata' => [],
        ];
        TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'home', 'content' => $content('max-w-7xl mx-auto px-8')]);
        $admin = $this->adminWith(['sirsoft-hero_section.banners.read', 'core.templates.layouts.edit']);
        $chain = fn () => $this->actingAs($admin)->getJson($this->api('/admin/position-settings'))
            ->assertOk()->json('data.editor.preview_frame.positions.home_hero.chain');

        $this->assertSame([['className' => 'max-w-7xl mx-auto px-8', 'responsive' => []]], $chain());
        $before = PreviewFrameResolver::getExtensionCacheVersion();

        $this->actingAs($admin)->putJson("/api/admin/templates/{$template->identifier}/layouts/home", [
            'expected_lock_version' => 0,
            'content' => $content('max-w-3xl mx-auto px-4'),
        ])->assertOk();

        $this->assertGreaterThan($before, PreviewFrameResolver::getExtensionCacheVersion());
        $this->assertSame([['className' => 'max-w-3xl mx-auto px-4', 'responsive' => []]], $chain());
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
            ->assertOk()->assertJsonPath('data.settings', ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade', 'width' => 'content']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/shop_hero'), ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none'])
            ->assertOk();

        $this->assertSame(1, PositionSetting::query()->where('position', 'shop_hero')->count());
        $this->assertSame(['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none', 'width' => 'content'], PositionSetting::query()->where('position', 'shop_hero')->first()->toSettings());
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

    /**
     * 표시 폭은 content·full 만 받는다. 저장하면 공개 응답 설정에 실리고, 표시 폭을 빼고 저장하면 지금 값을 그대로 둔다.
     *
     * @scenario surface=position_settings_width
     *
     * @return void 반환값 없음
     */
    public function test_width_saved_validated_and_kept_when_omitted(): void
    {
        $url = $this->api('/admin/position-settings/home_hero');

        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'wide'])
            ->assertStatus(422)->assertJsonValidationErrors(['width']);
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'full'])
            ->assertOk()->assertJsonPath('data.settings.width', 'full');
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade'])
            ->assertOk()->assertJsonPath('data.settings.width', 'full');

        $this->assertSame('full', PositionSetting::query()->where('position', 'home_hero')->first()->toSettings()['width']);
        $this->assertSame('full', $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.settings.width'));
    }
}
