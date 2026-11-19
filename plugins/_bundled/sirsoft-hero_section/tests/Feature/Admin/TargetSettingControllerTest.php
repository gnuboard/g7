<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Models\TargetSetting;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\RegistersTestPosition;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 동적 위치 대상별 슬라이더 설정 — 플러그인 기본값 → 위치 기본 설정 → 대상 재정의(매니페스트 target-settings.yaml).
 *
 * 시험 위치 `fake_hero` 는 외부 확장처럼 등록 필터로 더한 동적 위치다(대상 1 alpha · 2 beta). 재정의는 명시한 항목만 담고, 키가 없으면
 * 상속, `false`·`null` 은 명시값이다. 공개 API 는 같은 해석기를 쓰고 캐시는 대상 단위로 비운다.
 */
class TargetSettingControllerTest extends PluginTestCase
{
    use RegistersTestPosition;

    /**
     * 배너 조회·수정·삭제 권한이 있는 관리자
     *
     * @var User
     */
    private User $admin;

    /**
     * 테스트 준비 — 시험용 동적 위치를 등록하고 공개 캐시를 켭니다(대상 격리·무효화를 캐시까지 본다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->setPluginSettings(['public_cache_minutes' => 10]);
        $this->registerTestPosition();
        $this->admin = $this->fullAdmin();
    }

    /**
     * 처음 열면 모든 항목이 위치 기본값을 상속한다 — 열기만으로 행을 만들지 않는다. 위치 행이 없으면 출처는 플러그인 기본값.
     *
     * @scenario surface=target_settings_inherit_by_default
     *
     * @return void 반환값 없음
     */
    public function test_show_inherits_everything_without_creating_a_row(): void
    {
        $response = $this->actingAs($this->admin)->getJson($this->url('fake_hero', '1'))->assertOk();
        $data = $response->json('data');

        // 재정의가 없어도 객체(`{}`)다 — 배열(`[]`)이면 화면의 작업본(`{}`)과 문자열 비교가 어긋나 저장 단추가 풀린다
        $this->assertStringContainsString('"overrides":{}', $response->getContent());
        $this->assertSame(['key' => '1', 'label' => '알파', 'exists' => true], $data['target']);
        $this->assertSame([], $data['overrides']);
        $this->assertSame(PositionSetting::DEFAULTS, $data['settings']);
        $this->assertSame(array_fill_keys(array_keys(PositionSetting::DEFAULTS), 'default'), $data['sources']);
        $this->assertSame(0, TargetSetting::query()->count());

        PositionSetting::query()->create(['position' => 'fake_hero', 'autoplay' => true, 'interval_seconds' => 7, 'effect' => 'slide']);
        $this->assertSame('position', $this->actingAs($this->admin)->getJson($this->url('fake_hero', '1'))->json('data.sources.effect'));
    }

    /**
     * 대상 A 만 fade·full·pill·높이 제한으로 재정의해도 대상 B 와 위치 기본값은 그대로이고, 공개 API 도 A 만 바뀐다(캐시 포함).
     *
     * @scenario surface=target_settings_isolated_per_target
     *
     * @effects public_output_follows_target_override, target_cache_invalidated_only_for_that_target
     *
     * @return void 반환값 없음
     */
    public function test_override_applies_only_to_that_target(): void
    {
        PositionSetting::query()->create(['position' => 'fake_hero', 'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'content', 'pagination' => 'dots']);
        Banner::factory()->withImages()->forPosition('fake_hero', '1')->create();
        Banner::factory()->withImages()->forPosition('fake_hero', '2')->create();
        // 캐시를 먼저 채운다 — 저장이 A 의 캐시만 비우는지 본다
        $this->assertSame('slide', $this->hero('alpha')->json('data.settings.effect'));
        $this->assertSame('slide', $this->hero('beta')->json('data.settings.effect'));

        $overrides = ['effect' => 'fade', 'width' => 'full', 'pagination' => 'pill', 'max_height_pc' => 400];
        $saved = $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => $overrides])->assertOk()->json('data');
        $this->assertSame($overrides, $saved['overrides']);
        $this->assertSame(['fade', 'full', 'pill', 400], [$saved['settings']['effect'], $saved['settings']['width'], $saved['settings']['pagination'], $saved['settings']['max_height_pc']]);
        $this->assertSame('target', $saved['sources']['effect']);
        $this->assertSame('position', $saved['sources']['autoplay']);

        $alpha = $this->hero('alpha')->json('data.settings');
        $this->assertSame(['fade', 'full', 'pill', 400], [$alpha['effect'], $alpha['width'], $alpha['pagination'], $alpha['max_height_pc']]);
        $beta = $this->hero('beta')->json('data.settings');
        $this->assertSame(['slide', 'content', 'dots', null], [$beta['effect'], $beta['width'], $beta['pagination'], $beta['max_height_pc']]);
        $this->assertSame('slide', PositionSetting::query()->where('position', 'fake_hero')->value('effect'));
        $this->assertSame([], $this->actingAs($this->admin)->getJson($this->url('fake_hero', '2'))->json('data.overrides'));
    }

    /**
     * 일부 항목만 재정의한 뒤 위치 기본값을 바꾸면 상속 항목만 바뀐다. 자동 재생 끔(false)·최대 높이 제한 없음(null) 명시값은 위치를 따라가지 않는다.
     *
     * @scenario surface=target_settings_partial_inherit
     *
     * @effects inherited_items_follow_position_change
     *
     * @return void 반환값 없음
     */
    public function test_partial_override_follows_position_for_inherited_items_and_keeps_explicit_false_null(): void
    {
        PositionSetting::query()->create(['position' => 'fake_hero', 'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'max_height_pc' => 400]);
        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['autoplay' => false, 'max_height_pc' => null]])->assertOk();
        $this->assertSame(['autoplay' => false, 'max_height_pc' => null], TargetSetting::query()->first()->overrides);

        $this->actingAs($this->admin)->putJson($this->api('/admin/position-settings/fake_hero'), ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'cube', 'max_height_pc' => 300])->assertOk();

        $settings = $this->actingAs($this->admin)->getJson($this->url('fake_hero', '1'))->assertOk()->json('data.settings');
        $this->assertSame(7, $settings['interval_seconds'], '상속 항목은 위치 기본값을 따라 바뀐다');
        $this->assertSame('cube', $settings['effect']);
        $this->assertFalse($settings['autoplay'], 'false 명시값은 상속으로 바뀌지 않는다');
        $this->assertNull($settings['max_height_pc'], 'null(제한 없음) 명시값은 위치의 300 을 따르지 않는다');
        $public = $this->hero('alpha')->json('data.settings');
        $this->assertSame([false, null, 7], [$public['autoplay'], $public['max_height_pc'], $public['interval_seconds']]);
    }

    /**
     * 항목 하나를 빼고 저장하면 그 항목만 상속으로 돌아가고, 빈 묶음 저장·되돌리기는 행을 지워 모두 위치 기본값이 된다. 배너·위치 설정은 그대로.
     *
     * @scenario surface=target_settings_reset
     *
     * @return void 반환값 없음
     */
    public function test_item_inherit_and_full_reset(): void
    {
        PositionSetting::query()->create(['position' => 'fake_hero', 'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide']);
        $banner = Banner::factory()->withImages()->forPosition('fake_hero', '1')->create();
        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'fade', 'width' => 'full']])->assertOk();

        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['width' => 'full']])->assertOk()
            ->assertJsonPath('data.settings.effect', 'slide')->assertJsonPath('data.sources.effect', 'position')->assertJsonPath('data.settings.width', 'full');
        $this->assertSame('slide', $this->hero('alpha')->json('data.settings.effect'));

        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => []])->assertOk()->assertJsonPath('data.overrides', []);
        $this->assertSame(0, TargetSetting::query()->count());

        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['pagination' => 'tabs']])->assertOk();
        $reset = $this->actingAs($this->admin)->deleteJson($this->url('fake_hero', '1'))->assertOk()->assertJsonPath('data.overrides', [])->assertJsonPath('data.settings.pagination', 'dots');
        $this->assertStringContainsString('"overrides":{}', $reset->getContent());
        $this->assertSame(0, TargetSetting::query()->count());
        $this->assertSame('dots', $this->hero('alpha')->json('data.settings.pagination'));
        $this->assertNotNull(Banner::query()->find($banner->id));
        $this->assertSame('slide', PositionSetting::query()->where('position', 'fake_hero')->value('effect'));
        // 되돌릴 것이 없어도 성공한다(같은 결과)
        $this->actingAs($this->admin)->deleteJson($this->url('fake_hero', '1'))->assertOk();
    }

    /**
     * 검증 — 모르는 항목·잘못된 값은 422, 정적·미등록 위치는 404, 없는 대상은 저장 422 이되 조회·되돌리기는 된다.
     *
     * @scenario surface=target_settings_validation
     *
     * @return void 반환값 없음
     */
    public function test_validation_and_missing_targets(): void
    {
        $url = $this->url('fake_hero', '1');
        $this->actingAs($this->admin)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['overrides']);
        $this->actingAs($this->admin)->putJson($url, ['overrides' => ['color' => 'red']])->assertStatus(422)->assertJsonValidationErrors(['overrides']);
        foreach ([['autoplay' => 'yes'], ['interval_seconds' => 4], ['effect' => 'spin'], ['width' => 'wide'], ['pagination' => 'bullets'], ['max_height_pc' => 100], ['max_height_mobile' => 2001], ['header_overlay' => null]] as $bad) {
            $this->actingAs($this->admin)->putJson($url, ['overrides' => $bad])->assertStatus(422)->assertJsonValidationErrors(['overrides.'.array_key_first($bad)]);
        }
        $this->assertSame(0, TargetSetting::query()->count());

        $this->actingAs($this->admin)->getJson($this->url('home_hero', '1'))->assertNotFound();
        $this->actingAs($this->admin)->putJson($this->url('home_hero', '1'), ['overrides' => []])->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->url('nope_hero', '1'))->assertNotFound();

        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'fade']])->assertOk();
        FakeTargetResolver::forget('1');
        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'cube']])->assertStatus(422);
        $this->actingAs($this->admin)->getJson($this->url('fake_hero', '1'))->assertOk()
            ->assertJsonPath('data.target.exists', false)->assertJsonPath('data.overrides', ['effect' => 'fade']);
        $this->actingAs($this->admin)->deleteJson($this->url('fake_hero', '1'))->assertOk();
        $this->assertSame(0, TargetSetting::query()->count());
    }

    /**
     * 권한 — 조회 권한만으로는 저장·되돌리기를 못 하고(403), 로그인하지 않으면 401 이다.
     *
     * @scenario surface=target_settings_permission
     *
     * @return void 반환값 없음
     */
    public function test_permissions(): void
    {
        $reader = $this->adminWith(['sirsoft-hero_section.banners.read']);
        $this->actingAs($reader)->getJson($this->url('fake_hero', '1'))->assertOk();
        $this->actingAs($reader)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'fade']])->assertForbidden();
        $this->actingAs($reader)->deleteJson($this->url('fake_hero', '1'))->assertForbidden();
        $this->assertSame(0, TargetSetting::query()->count());

        $this->app['auth']->forgetGuards();
        $this->getJson($this->url('fake_hero', '1'))->assertUnauthorized();
    }

    /**
     * 저장·되돌리기는 활동 로그에 바꾸기 전·후 재정의를 남기고, 바뀐 것이 없으면 남기지 않는다.
     *
     * @scenario surface=target_settings_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_activity_log(): void
    {
        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'fade']])->assertOk();
        $this->actingAs($this->admin)->putJson($this->url('fake_hero', '1'), ['overrides' => ['effect' => 'fade']])->assertOk();
        $this->actingAs($this->admin)->deleteJson($this->url('fake_hero', '1'))->assertOk();
        $this->actingAs($this->admin)->deleteJson($this->url('fake_hero', '1'))->assertOk();

        $actions = DB::table('activity_logs')->whereIn('action', ['hero_target_setting.update', 'hero_target_setting.reset'])->orderBy('id')->pluck('action')->all();
        $this->assertSame(['hero_target_setting.update', 'hero_target_setting.reset'], $actions);
    }

    /**
     * 대상 설정 주소를 만듭니다.
     *
     * @param  string  $position  위치 키
     * @param  string  $target  대상 키
     * @return string 주소
     */
    private function url(string $position, string $target): string
    {
        return $this->api('/admin/position-settings/'.$position.'/targets/'.rawurlencode($target));
    }

    /**
     * 공개 API 로 시험 위치의 한 대상을 받습니다.
     *
     * @param  string  $target  화면 대상 값
     * @return TestResponse 응답
     */
    private function hero(string $target): TestResponse
    {
        return $this->getJson($this->api('/hero?position=fake_hero&target='.rawurlencode($target)))->assertOk();
    }
}
