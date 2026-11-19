<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 > 일반 「레이아웃 편집 단추」(`general.layout_edit_button_enabled`, 7.2.0)
 *
 * 운영 화면 오른쪽 아래의 레이아웃 편집 단추를 켜고 끄는 값이다. 기본은 켜짐이고, 화면이 읽도록 공개 설정에 실리며,
 * 저장 응답의 `frontend_settings` 로도 돌아가 관리자 화면이 `_global.settings` 를 바로 바꾼다. 불리언만 받는다.
 *
 * @scenario-manifest tests/scenarios/layout-editor-edit-entry-fab.yaml
 */
class LayoutEditButtonSettingTest extends TestCase
{
    use DatabaseTransactions;

    /** 관리자 토큰 */
    private string $token;

    /**
     * 설정 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['email' => 'layout-edit-button-'.Str::lower(Str::random(8)).'@example.com']);
        $ids = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $ids[] = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core, 'extension_identifier' => 'core', 'type' => 'admin',
            ])->id;
        }
        $role = Role::create([
            'identifier' => 'layout_edit_button_test_'.Str::lower(Str::random(6)),
            'name' => json_encode(['ko' => 't', 'en' => 't']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($ids);
        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']), 'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core', 'is_active' => true,
        ]);
        $user->roles()->attach($admin->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $user->createToken('layout-edit-button')->plainTextToken;
    }

    /**
     * 일반 탭을 저장합니다 — 일반 탭의 필수 항목을 함께 보냅니다.
     *
     * @param  array<string, mixed>  $general  덮어쓸 general 값
     * @return TestResponse 응답
     */
    private function saveGeneral(array $general): TestResponse
    {
        $base = [
            'site_name' => '그누보드7',
            'site_url' => 'https://example.com',
            'admin_email' => 'admin@example.com',
            'timezone' => 'Asia/Seoul',
            'language' => 'ko',
        ];

        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', ['_tab' => 'general', 'general' => [...$base, ...$general]]);
    }

    /**
     * 기본은 켜짐이고 공개 설정(화면이 읽는 값)에 불리언으로 실린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=setting_default_and_exposed
     *
     * @effects layout_edit_button_setting_defaults_on_and_is_public
     */
    public function test_defaults_on_and_is_exposed_to_frontend(): void
    {
        $defaults = json_decode((string) file_get_contents(config_path('settings/defaults.json')), true);
        $this->assertTrue($defaults['defaults']['general']['layout_edit_button_enabled']);

        config(['g7_settings.core.general.layout_edit_button_enabled' => null]);
        $frontend = app(SettingsService::class)->getFrontendSettings();
        $this->assertArrayHasKey('layout_edit_button_enabled', $frontend['general']);
        $this->assertIsBool($frontend['general']['layout_edit_button_enabled']);
    }

    /**
     * 끄고 저장하면 저장되고, 응답의 공개 설정에도 꺼진 값이 실린다 — 화면이 이 값으로 단추를 바로 숨긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=setting_toggle_live
     *
     * @effects ops_screen_fab_hidden_when_setting_off, ops_screen_fab_follows_setting_save
     */
    public function test_turning_off_is_saved_and_returned_in_frontend_settings(): void
    {
        $response = $this->saveGeneral(['layout_edit_button_enabled' => false])->assertStatus(200);

        $this->assertFalse(app(ConfigRepositoryInterface::class)->getCategory('general')['layout_edit_button_enabled']);
        $this->assertFalse($response->json('data.frontend_settings.general.layout_edit_button_enabled'));

        $response = $this->saveGeneral(['layout_edit_button_enabled' => true])->assertStatus(200);
        $this->assertTrue($response->json('data.frontend_settings.general.layout_edit_button_enabled'));
    }

    /**
     * 불리언이 아닌 값은 거절한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=setting_toggle
     *
     * @effects ops_screen_fab_hidden_when_setting_off
     */
    public function test_rejects_non_boolean(): void
    {
        $this->saveGeneral(['layout_edit_button_enabled' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('general.layout_edit_button_enabled');
    }
}
