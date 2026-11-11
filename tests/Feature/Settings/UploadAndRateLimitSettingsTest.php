<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 환경설정 저장 — 레이아웃 첨부 허용 확장자·공개 디스크로 내보낼 확장자·요청 한도 항목의 검증과 저장 형태.
 *
 * 확장자 목록 둘은 빈 목록이 "제한 없음" 이 아니라서 비울 수 없고, 화면의 콤마 문자열과 API 의 배열을 같은
 * 소문자 배열로 저장한다. 요청 한도는 1~1000 이고 0(끄기)은 받지 않는다.
 *
 * @scenario-manifest tests/scenarios/upload-stored-extension.yaml
 */
class UploadAndRateLimitSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /**
     * 설정 수정 권한을 가진 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'settings_admin_'.uniqid(),
            'name' => json_encode(['ko' => '설정 관리자', 'en' => 'Settings Admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        $this->token = $user->fresh()->createToken('settings')->plainTextToken;
    }

    /**
     * 환경설정 저장 API 를 호출합니다.
     *
     * @param  array<string, mixed>  $payload  요청 본문
     * @return TestResponse 응답
     */
    private function save(array $payload): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->postJson('/api/admin/settings', $payload);
    }

    /**
     * 저장된 설정 범주를 읽습니다.
     *
     * @param  string  $category  범주
     * @return array<string, mixed> 저장값
     */
    private function stored(string $category): array
    {
        return app(ConfigRepositoryInterface::class)->getCategory($category);
    }

    /**
     * 레이아웃 첨부 허용 확장자는 비울 수 없고, 콤마 문자열은 소문자 배열로 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings__allowlist_core_setting
     *
     * @effects extension_list_settings_reject_empty_and_store_normalized
     */
    public function test_layout_attachment_extensions_cannot_be_empty_and_are_normalized(): void
    {
        $base = ['max_file_size' => 10, 'allowed_extensions' => 'jpg,png'];

        $this->save(['_tab' => 'upload', 'upload' => $base + ['layout_attachment_extensions' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.layout_attachment_extensions']);

        $this->save(['_tab' => 'upload', 'upload' => $base + ['layout_attachment_extensions' => ' , ']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.layout_attachment_extensions']);

        $this->save(['_tab' => 'upload', 'upload' => $base + ['layout_attachment_extensions' => 'PNG, .pdf ,png']])
            ->assertStatus(200);

        $this->assertSame(['png', 'pdf'], $this->stored('upload')['layout_attachment_extensions']);
    }

    /**
     * 그 항목을 보내지 않는 저장(옛 화면)은 그대로 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings__allowlist_default
     *
     * @effects extension_list_settings_reject_empty_and_store_normalized
     */
    public function test_saving_without_the_new_keys_still_succeeds(): void
    {
        $this->save(['_tab' => 'upload', 'upload' => ['max_file_size' => 10, 'allowed_extensions' => 'jpg,png']])
            ->assertStatus(200);
    }

    /**
     * 공개 디스크로 내보낼 확장자는 비울 수 없고, 배열·문자열 모두 소문자 배열로 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings__operator_exportable_with_public_disk
     *
     * @effects extension_list_settings_reject_empty_and_store_normalized
     */
    public function test_public_asset_extensions_cannot_be_empty_and_are_normalized(): void
    {
        $base = [
            'storage_driver' => 'local',
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'queue_driver' => 'sync',
            'log_driver' => 'daily',
            'log_level' => 'error',
            'public_asset_disk' => 'none',
        ];

        $this->save(['_tab' => 'drivers', 'drivers' => $base + ['public_asset_extensions' => []]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.public_asset_extensions']);

        $this->save(['_tab' => 'drivers', 'drivers' => $base + ['public_asset_extensions' => 'JPG, png,.PDF']])
            ->assertStatus(200);

        $this->assertSame(['jpg', 'png', 'pdf'], $this->stored('drivers')['public_asset_extensions']);

        $this->save(['_tab' => 'drivers', 'drivers' => $base + ['public_asset_extensions' => implode(',', array_map(fn (int $i) => 'ext'.$i, range(1, 120)))]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drivers.public_asset_extensions']);

        // 그 항목을 보내지 않는 저장(옛 화면)은 통과하고 저장값을 지우지 않는다.
        $this->save(['_tab' => 'drivers', 'drivers' => $base])->assertStatus(200);
        $this->assertSame(['jpg', 'png', 'pdf'], $this->stored('drivers')['public_asset_extensions']);
    }

    /**
     * 요청 한도는 1~1000 의 정수만 받는다 — 0(끄기)은 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=settings__allowlist_default
     *
     * @effects rate_limit_settings_accept_1_to_1000
     */
    public function test_rate_limit_settings_accept_only_1_to_1000(): void
    {
        foreach ([0, 1001, -1] as $bad) {
            $this->save(['_tab' => 'security', 'security' => [
                'force_https' => false,
                'login_attempt_enabled' => true,
                'auth_token_lifetime' => 30,
                'password_reset_rate_limit_per_minute' => $bad,
            ]])->assertStatus(422)->assertJsonValidationErrors(['security.password_reset_rate_limit_per_minute']);

            $this->save(['_tab' => 'security', 'security' => [
                'force_https' => false,
                'login_attempt_enabled' => true,
                'auth_token_lifetime' => 30,
                'register_rate_limit_per_minute' => $bad,
            ]])->assertStatus(422)->assertJsonValidationErrors(['security.register_rate_limit_per_minute']);
        }

        $this->save(['_tab' => 'security', 'security' => [
            'force_https' => false,
            'login_attempt_enabled' => true,
            'auth_token_lifetime' => 30,
            'password_reset_rate_limit_per_minute' => '20',
            'register_rate_limit_per_minute' => 1000,
        ]])->assertStatus(200);

        $stored = $this->stored('security');
        $this->assertSame(20, $stored['password_reset_rate_limit_per_minute']);
        $this->assertSame(1000, $stored['register_rate_limit_per_minute']);
    }
}
