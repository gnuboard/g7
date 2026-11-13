<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 환경설정 범주 `member` — 기본값, 탭 저장, 공개 노출 범위.
 *
 * 닉네임 금지어와 영역별 표시 이름 설정은 비로그인 공개 API 로 나가면 안 된다.
 */
class MemberSettingsCategoryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /**
     * 권한을 시딩하고 최고관리자 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->token = $admin->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 인증 헤더를 만듭니다.
     *
     * @return array<string, string> 헤더
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    /**
     * 기본값은 종전 동작과 같다 — 닉네임 정책 전부 꺼짐, 모든 영역 「이름」(상품 문의만 가린 이름).
     *
     * @scenario setting=defaults
     *
     * @effects member_settings_defaults_keep_legacy_behavior
     *
     * @return void 반환값 없음
     */
    public function test_defaults_keep_legacy_behavior(): void
    {
        $member = app(SettingsService::class)->getSettings('member');

        $this->assertFalse($member['nickname_unique']);
        $this->assertSame([], $member['nickname_banned_words']);
        $this->assertSame(0, $member['nickname_change_interval_days']);
        $this->assertSame(['name'], $member['display_name_selectable_kinds']);
        $this->assertSame('name', $member['display_name_member_default_kind']);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'name'], $member['display_name_areas']['core:site']);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'name'], $member['display_name_areas']['core:admin']);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'masked_name'], $member['display_name_areas']['sirsoft-ecommerce:inquiry']);
    }

    /**
     * 회원 탭 저장이 통과하고 저장한 값이 다시 읽힌다.
     *
     * @scenario setting=save
     *
     * @effects member_settings_tab_save_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_member_tab_save_roundtrip(): void
    {
        $this->postJson('/api/admin/settings', [
            '_tab' => 'member',
            'member' => [
                'nickname_unique' => true,
                'nickname_banned_words' => ['관리자', 'admin'],
                'nickname_change_interval_days' => 30,
            ],
        ], $this->auth())->assertOk();

        $member = $this->getJson('/api/admin/settings', $this->auth())->assertOk()->json('data.member');
        $this->assertTrue($member['nickname_unique']);
        $this->assertSame(['관리자', 'admin'], $member['nickname_banned_words']);
        $this->assertSame(30, $member['nickname_change_interval_days']);
        // 보내지 않은 키는 기본값으로 남는다(범주는 기본값과 얕게 병합된다).
        $this->assertSame(['name'], $member['display_name_selectable_kinds']);
    }

    /**
     * 회원 탭의 값 검증 — 변경 주기 범위, 금지어 길이·중복, 영역 방식.
     *
     * @scenario setting=validation
     *
     * @effects member_settings_validation
     *
     * @return void 반환값 없음
     */
    public function test_member_tab_validation(): void
    {
        $save = fn (array $member) => $this->postJson('/api/admin/settings', ['_tab' => 'member', 'member' => $member], $this->auth());

        $save(['nickname_change_interval_days' => -1])->assertStatus(422)->assertJsonValidationErrors(['member.nickname_change_interval_days']);
        $save(['nickname_change_interval_days' => 3651])->assertStatus(422)->assertJsonValidationErrors(['member.nickname_change_interval_days']);
        $save(['nickname_banned_words' => [str_repeat('가', 51)]])->assertStatus(422)->assertJsonValidationErrors(['member.nickname_banned_words.0']);
        $save(['nickname_banned_words' => ['중복', '중복']])->assertStatus(422);
        $save(['display_name_areas' => ['core:site' => ['mode' => 'random']]])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_areas.core:site.mode']);
        $save(['display_name_selectable_kinds' => []])->assertStatus(422)->assertJsonValidationErrors(['member.display_name_selectable_kinds']);
    }

    /**
     * 공개 설정에는 회원이 고를 수 있는 종류, 회원 기본 종류, 닉네임 변경 주기만 나가고 금지어·영역 설정은 나가지 않는다.
     *
     * @scenario setting=frontend_exposure
     *
     * @effects member_settings_public_exposure_is_limited
     *
     * @return void 반환값 없음
     */
    public function test_public_frontend_config_exposes_only_safe_keys(): void
    {
        $this->postJson('/api/admin/settings', [
            '_tab' => 'member',
            'member' => ['nickname_banned_words' => ['비밀금지어'], 'nickname_change_interval_days' => 7],
        ], $this->auth())->assertOk();

        $frontend = app(SettingsService::class)->getFrontendSettings();
        $this->assertSame(
            ['display_name_selectable_kinds', 'display_name_member_default_kind', 'nickname_change_interval_days'],
            array_keys($frontend['member']),
        );
        $this->assertSame(7, $frontend['member']['nickname_change_interval_days']);

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/frontend-config', ['Accept' => 'application/json'])->assertOk();
        $body = $public->getContent();
        $this->assertStringNotContainsString('비밀금지어', $body, '닉네임 금지어가 공개 설정 응답에 실렸다');
        $this->assertStringNotContainsString('nickname_banned_words', $body);
        $this->assertStringNotContainsString('display_name_areas', $body, '영역별 표시 이름 설정이 공개 설정 응답에 실렸다');
    }
}
