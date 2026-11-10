<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PreviewFrameResolver;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\PinsActiveModules;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 위치 설정 API 테스트 (매니페스트 admin-banners.yaml — 위치 설정 케이스)
 */
class PositionSettingControllerTest extends PluginTestCase
{
    use PinsActiveModules;

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
        $this->pinActiveModules([]);
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
        $this->pinActiveModules([]);
        $frame = $this->actingAs($this->adminWith(['sirsoft-hero_section.banners.read']))
            ->getJson($this->api('/admin/position-settings'))->assertOk()->json('data.editor.preview_frame');

        $this->assertIsArray($frame);
        $this->assertArrayHasKey('template', $frame);
        $this->assertIsArray($frame['stylesheets']);
        $this->assertSame(['home_hero', 'shop_hero'], array_keys($frame['positions']));
    }

    /**
     * 개요는 위치 레지스트리를 따른다 — 켜진 모듈의 동적 위치가 정적 위치 뒤에 대상 정보와 함께 서고, 미리보기 틀에도 그 위치가 있다.
     * 배너가 남은 등록되지 않은 위치(확장을 껐을 때)는 `registered: false` 로 맨 뒤에 붙는다(D7).
     *
     * @scenario case=overview_follows_registry
     *
     * @return void 반환값 없음
     */
    public function test_overview_follows_registry_with_dynamic_and_unregistered_positions(): void
    {
        $this->pinActiveModules(['sirsoft-board']);
        Banner::factory()->forPosition('gone_hero')->create();

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/position-settings'))->assertOk()->json('data');
        $positions = collect($data['positions'])->keyBy('key');

        $this->assertSame(['home_hero', 'shop_hero', 'board_hero', 'gone_hero'], $positions->keys()->all());
        $this->assertFalse($positions['home_hero']['dynamic']);
        $this->assertNull($positions['home_hero']['target']);
        $this->assertSame('sirsoft-hero_section.admin.position.home_hero', $positions['home_hero']['label_key']);
        $this->assertTrue($positions['board_hero']['dynamic']);
        $this->assertTrue($positions['board_hero']['registered']);
        $this->assertSame('board', $positions['board_hero']['target']['type']);
        $this->assertSame('sirsoft-hero_section.admin.target.board', $positions['board_hero']['target']['label_key']);
        $this->assertFalse($positions['gone_hero']['registered']);
        $this->assertSame('gone_hero', $positions['gone_hero']['label']);
        $this->assertSame(['home_hero', 'shop_hero', 'board_hero'], array_keys($data['editor']['preview_frame']['positions']));
    }

    /**
     * 등록되지 않은 위치의 슬라이더 설정은 저장하지 않는다(404) — 위치를 더한 확장이 꺼져 있으면 설정 화면도 없다.
     *
     * @scenario case=unregistered_position_setting_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_position_setting_update_is_not_found(): void
    {
        $this->pinActiveModules([]);

        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/board_hero'), [
            'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide',
        ])->assertNotFound();
        $this->assertDatabaseMissing('hero_section_position_settings', ['position' => 'board_hero']);
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
            ->assertOk()->assertJsonPath('data.settings', ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots']);
        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/shop_hero'), ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none'])
            ->assertOk();

        $this->assertSame(1, PositionSetting::query()->where('position', 'shop_hero')->count());
        $this->assertSame(['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'none', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots'], PositionSetting::query()->where('position', 'shop_hero')->first()->toSettings());
        $this->assertSame(0, PositionSetting::query()->where('position', 'home_hero')->count());
    }

    /**
     * 같은 위치를 동시에 처음 저장해도 500 이 아니다 — 이 요청이 행을 만들려는 순간 다른 요청이 먼저 만들었으면(유일 키 충돌)
     * 그 행을 잠가 고치고, 「바뀌기 전」은 앞 요청이 저장한 값이다.
     *
     * @scenario surface=position_settings_concurrent_first_save
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_first_save_updates_row_made_by_other_request(): void
    {
        $other = ['autoplay' => false, 'interval_seconds' => 7, 'effect' => 'fade', 'width' => 'full', 'header_overlay' => false];
        $raced = false;
        // 이 요청이 위치 설정 행을 INSERT 하기 직전에 다른 요청의 INSERT 가 먼저 들어간 상황을 만든다
        DB::beforeExecuting(function (string $query) use (&$raced, $other) {
            if (! $raced && str_starts_with(strtolower($query), 'insert') && str_contains($query, 'hero_section_position_settings')) {
                $raced = true;
                DB::table('hero_section_position_settings')->insert(['position' => 'shop_hero'] + $other + ['created_at' => now(), 'updated_at' => now()]);
            }
        });
        $snapshot = null;
        HookManager::addAction('sirsoft-hero_section.position_setting.after_update', function ($setting, $before) use (&$snapshot) {
            $snapshot = $before;
        });

        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/shop_hero'), ['autoplay' => true, 'interval_seconds' => 3, 'effect' => 'none', 'width' => 'content'])
            ->assertOk()->assertJsonPath('data.settings', ['autoplay' => true, 'interval_seconds' => 3, 'effect' => 'none', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots']);

        $this->assertTrue($raced);
        $this->assertSame(1, PositionSetting::query()->where('position', 'shop_hero')->count());
        $this->assertSame(['autoplay' => true, 'interval_seconds' => 3, 'effect' => 'none', 'width' => 'content', 'header_overlay' => false, 'pagination' => 'dots'], PositionSetting::query()->where('position', 'shop_hero')->first()->toSettings());
        $this->assertSame([7, 'fade', 'full'], [$snapshot['interval_seconds'] ?? null, $snapshot['effect'] ?? null, $snapshot['width'] ?? null]);
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

    /**
     * 페이징 모양은 dots·progress·tabs·fraction 만 받는다. 저장하면 공개 응답 설정에 실리고, 빼고 저장하면 지금 값을 그대로 둔다.
     * 처음 저장 전(행 없음)과 기존 행은 점(dots)이다.
     *
     * @scenario surface=position_settings_pagination
     *
     * @return void 반환값 없음
     */
    public function test_pagination_saved_validated_and_kept_when_omitted(): void
    {
        $url = $this->api('/admin/position-settings/home_hero');
        $base = ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide'];

        $this->assertSame('dots', $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.settings.pagination'));
        $this->actingAs($this->admin)->putJson($url, $base + ['pagination' => 'bullets'])
            ->assertStatus(422)->assertJsonValidationErrors(['pagination']);

        foreach (['progress', 'tabs', 'fraction', 'dots'] as $pagination) {
            $this->actingAs($this->admin)->putJson($url, $base + ['pagination' => $pagination])
                ->assertOk()->assertJsonPath('data.settings.pagination', $pagination);
            $this->assertSame($pagination, $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->json('data.settings.pagination'));
        }

        $this->actingAs($this->admin)->putJson($url, $base + ['pagination' => 'tabs'])->assertOk();
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => false, 'interval_seconds' => 3, 'effect' => 'fade'])
            ->assertOk()->assertJsonPath('data.settings.pagination', 'tabs');
        $this->assertSame('tabs', PositionSetting::query()->where('position', 'home_hero')->first()->toSettings()['pagination']);
        $this->assertSame('dots', $this->getJson($this->api('/hero?position=shop_hero'))->assertOk()->json('data.settings.pagination'));
    }
}
