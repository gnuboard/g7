<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Mockery;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쇼핑몰 환경설정의 기능별 작성자 표시 이름
 *
 * 리뷰 설정 탭(후기)과 기본 정보 탭(문의)의 표시 이름 칸은 이커머스 설정 파일이 아니라 코어 설정
 * `member.display_name_areas` 의 두 영역 키에 저장된다. 그래서 환경설정 > 회원 탭의 같은 영역과 값이 하나다.
 */
class DisplayNameSettingsTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $admin;

    /**
     * 표시 이름 설정을 기본값으로 되돌리고 설정 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->storeAreas(null);
        config(['g7_settings.core.member.display_name_selectable_kinds' => ['name']]);
        config(['g7_settings.core.member.display_name_member_default_kind' => 'name']);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update', 'core.settings.read',
        ]);
        // 종류 미리보기의 표본 회원과 영역 이름은 요청 언어를 따른다 — 한국어로 고정한다.
        $this->admin->forceFill(['language' => 'ko'])->save();
    }

    /**
     * 저장된 영역 묶음을 바꿉니다 (저장 파일과 읽기용 미러 모두).
     *
     * @param  array<string, array<string, mixed>>|null  $areas  영역 키 => 설정 (null 이면 비움)
     * @return void 반환값 없음
     */
    private function storeAreas(?array $areas): void
    {
        app(ConfigRepositoryInterface::class)->set('member.display_name_areas', $areas ?? []);
        config(['g7_settings.core.member.display_name_areas' => $areas]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }

    /**
     * 리뷰 설정 탭 저장 본문을 만듭니다.
     *
     * @param  array<string, mixed>|null  $displayName  표시 이름 묶음 (null 이면 싣지 않는다)
     * @param  array<string, mixed>  $extra  덧붙일 최상위 키
     * @return array<string, mixed> 본문
     */
    private function reviewTabBody(?array $displayName, array $extra = []): array
    {
        $body = ['_tab' => 'review_settings', 'review_settings' => ['write_deadline_days' => 30, 'max_images' => 5, 'max_image_size_mb' => 5]] + $extra;
        if ($displayName !== null) {
            $body['display_name'] = $displayName;
        }

        return $body;
    }

    /**
     * 설정 조회 응답에 후기와 문의 영역의 저장된 설정, 종류 목록, 지금 적용되는 값이 실린다.
     *
     * @scenario case=settings_response_carries_display_name
     *
     * @effects settings_response_carries_area_settings, settings_response_carries_kind_options_with_preview, settings_response_carries_effective_rule_with_source_label
     *
     * @return void 반환값 없음
     */
    public function test_settings_response_carries_display_name_and_options(): void
    {
        $data = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([
            'review' => ['mode' => 'inherit', 'kind' => null],
            'inquiry' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ], $data['display_name']);

        $kinds = collect($data['display_name_options']['kinds'])->keyBy('key');
        $this->assertSame(['key', 'label', 'preview', 'requires_nickname'], array_keys($data['display_name_options']['kinds'][0]));
        $this->assertSame(['홍길동', '홍*동', '길동이'], [$kinds['name']['preview'], $kinds['masked_name']['preview'], $kinds['nickname']['preview']]);
        $this->assertSame([false, true], [$kinds['name']['requires_nickname'], $kinds['nickname']['requires_nickname']]);

        $effective = $data['display_name_options']['effective'];
        // 후기는 사이트 기본을 따르고, 사이트 기본의 처음 방식은 「회원이 고른 값」이라 종류가 없다
        $this->assertSame(['mode' => 'member', 'kind' => null, 'kind_label' => null], array_intersect_key($effective['review'], ['mode' => 1, 'kind' => 1, 'kind_label' => 1]));
        $this->assertSame(app(DisplayNameAreaRegistry::class)->get('core:site')->label(), $effective['review']['source_label']);
        $this->assertSame(['fixed', 'masked_name', $kinds['masked_name']['label']], [$effective['inquiry']['mode'], $effective['inquiry']['kind'], $effective['inquiry']['kind_label']]);
        $this->assertSame(app(DisplayNameAreaRegistry::class)->get('sirsoft-ecommerce:inquiry')->label(), $effective['inquiry']['source_label']);
    }

    /**
     * 저장하면 코어 설정의 두 영역에만 쓰이고(다른 영역 보존), 조회 응답과 코어 회원 탭 API 가 같은 값을 보이며, 이커머스 설정 파일에는 남지 않는다.
     *
     * @scenario case=settings_save_writes_core_area_settings
     *
     * @effects display_name_saved_to_core_area_settings, other_area_settings_preserved, kind_dropped_unless_fixed, display_name_not_written_to_module_settings, display_name_options_ignored_on_save, core_member_tab_shows_same_area_value, seo_cache_cleared_after_save
     *
     * @return void 반환값 없음
     */
    public function test_save_writes_two_areas_into_core_setting_and_preserves_others(): void
    {
        $seo = Mockery::mock(SeoCacheManagerInterface::class)->shouldIgnoreMissing();
        $seo->shouldReceive('clearAll')->atLeast()->once();
        $this->app->instance(SeoCacheManagerInterface::class, $seo);

        $this->storeAreas([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'sirsoft-board:posts' => ['mode' => 'member'],
            'gone-extension:reviews' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ]);

        $saved = $this->actingAs($this->admin)->putJson(self::URL, $this->reviewTabBody(
            ['review' => ['mode' => 'fixed', 'kind' => 'masked_id'], 'inquiry' => ['mode' => 'member', 'kind' => 'nickname']],
            ['display_name_options' => ['kinds' => [['key' => 'hacked']]]],
        ))->assertOk()->json('data');

        $this->assertSame([
            'review' => ['mode' => 'fixed', 'kind' => 'masked_id'],
            'inquiry' => ['mode' => 'member', 'kind' => null],
        ], $saved['display_name'], '저장 응답이 새 값을 보이지 않는다');

        $stored = app(ConfigRepositoryInterface::class)->getCategory('member')['display_name_areas'];
        $this->assertSame([
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'sirsoft-board:posts' => ['mode' => 'member'],
            'gone-extension:reviews' => ['mode' => 'fixed', 'kind' => 'masked_name'],
            'sirsoft-ecommerce:review' => ['mode' => 'fixed', 'kind' => 'masked_id'],
            'sirsoft-ecommerce:inquiry' => ['mode' => 'member'],
        ], $stored);

        // 이커머스 설정 파일에는 표시 이름이 없다
        $module = app(EcommerceSettingsService::class);
        $module->clearCache();
        $all = $module->getAllSettings();
        $this->assertArrayNotHasKey('display_name', $all);
        $this->assertArrayNotHasKey('display_name_options', $all);
        $this->assertArrayNotHasKey('display_name', $all['review_settings'] ?? []);
        $this->assertSame(30, (int) $module->getSetting('review_settings.write_deadline_days'), '같은 요청의 리뷰 설정이 저장되지 않았다');

        // 다시 조회해도, 코어 회원 탭 API 로 봐도 같은 값이다
        $fetched = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data.display_name');
        $this->assertSame($saved['display_name'], $fetched);

        $areas = collect($this->actingAs($this->admin)->getJson('/api/admin/member-fields/display-name-options')->assertOk()->json('data.areas'))->keyBy('key');
        $this->assertSame(['fixed', 'masked_id'], [$areas['sirsoft-ecommerce:review']['mode'], $areas['sirsoft-ecommerce:review']['kind']]);
        $this->assertSame(['member', null], [$areas['sirsoft-ecommerce:inquiry']['mode'], $areas['sirsoft-ecommerce:inquiry']['kind']]);
    }

    /**
     * 표시 이름 묶음이 오지 않은 저장(다른 탭)은 영역 설정을 건드리지 않는다.
     *
     * @scenario case=settings_save_without_display_name_keeps_areas
     *
     * @effects area_settings_untouched_when_display_name_absent
     *
     * @return void 반환값 없음
     */
    public function test_save_without_display_name_leaves_area_settings_untouched(): void
    {
        $before = [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'sirsoft-ecommerce:review' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ];
        $this->storeAreas($before);

        $this->actingAs($this->admin)->putJson(self::URL, $this->reviewTabBody(null))->assertOk();
        $this->assertSame($before, app(ConfigRepositoryInterface::class)->getCategory('member')['display_name_areas']);

        // 한 기능만 온 저장은 그 영역만 바꾼다
        $this->actingAs($this->admin)->putJson(self::URL, [
            '_tab' => 'basic_info',
            'basic_info' => ['shop_name' => '테스트샵', 'route_path' => 'shop'],
            'display_name' => ['inquiry' => ['mode' => 'inherit', 'kind' => null]],
        ])->assertOk();
        $this->assertSame($before + ['sirsoft-ecommerce:inquiry' => ['mode' => 'inherit']], app(ConfigRepositoryInterface::class)->getCategory('member')['display_name_areas']);
    }

    /**
     * 방식은 세 값만 받고, 고정이면 등록된 종류가 필요하다. 오류 키는 칸의 경로다.
     *
     * @scenario case=settings_save_validates_display_name
     *
     * @effects display_name_mode_validated, display_name_kind_required_when_fixed, display_name_kind_must_be_registered, invalid_display_name_saves_nothing
     *
     * @return void 반환값 없음
     */
    public function test_save_validates_mode_and_kind(): void
    {
        $this->storeAreas(['core:site' => ['mode' => 'fixed', 'kind' => 'name']]);
        $save = fn (array $displayName) => $this->actingAs($this->admin)->putJson(self::URL, $this->reviewTabBody($displayName));

        $save(['review' => ['mode' => 'whatever']])->assertStatus(422)->assertJsonValidationErrors(['display_name.review.mode']);
        $save(['review' => ['kind' => 'name']])->assertStatus(422)->assertJsonValidationErrors(['display_name.review.mode']);
        $save(['review' => ['mode' => 'fixed', 'kind' => null]])->assertStatus(422)->assertJsonValidationErrors(['display_name.review.kind']);
        $save(['review' => ['mode' => 'inherit'], 'inquiry' => ['mode' => 'fixed', 'kind' => 'no_such_kind']])
            ->assertStatus(422)->assertJsonValidationErrors(['display_name.inquiry.kind'])->assertJsonMissingValidationErrors(['display_name.review.mode']);

        $this->assertSame(['core:site' => ['mode' => 'fixed', 'kind' => 'name']], app(ConfigRepositoryInterface::class)->getCategory('member')['display_name_areas'], '검증에 실패한 요청이 영역 설정을 바꿨다');

        // 고정이 아니면 종류에 무엇이 와도 받는다(저장하지 않는다)
        $save(['review' => ['mode' => 'member', 'kind' => 'no_such_kind']])->assertOk();
        $this->assertSame(['mode' => 'member'], app(ConfigRepositoryInterface::class)->getCategory('member')['display_name_areas']['sirsoft-ecommerce:review']);
    }

    /**
     * 후기 영역을 닉네임으로 고정하면 닉네임 항목이 사용, 필수로 잠기고, 되돌리면 풀린다.
     *
     * @scenario case=nickname_kind_locks_nickname_field
     *
     * @effects nickname_fixed_area_locks_nickname_field, reverting_area_unlocks_nickname_field
     *
     * @return void 반환값 없음
     */
    public function test_fixing_review_area_to_nickname_locks_the_nickname_field(): void
    {
        $this->assertSame(NicknameRequirement::LEVEL_NONE, app(NicknameRequirement::class)->level());

        $this->actingAs($this->admin)->putJson(self::URL, $this->reviewTabBody(['review' => ['mode' => 'fixed', 'kind' => 'nickname']]))->assertOk();
        $this->assertSame(NicknameRequirement::LEVEL_ALL, app(NicknameRequirement::class)->level(), '닉네임으로 고정했는데 닉네임 항목이 잠기지 않았다');

        $this->actingAs($this->admin)->putJson(self::URL, $this->reviewTabBody(['review' => ['mode' => 'inherit', 'kind' => null]]))->assertOk();
        $this->assertSame(NicknameRequirement::LEVEL_NONE, app(NicknameRequirement::class)->level());
    }
}
