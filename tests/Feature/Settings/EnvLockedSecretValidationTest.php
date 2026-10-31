<?php

namespace Tests\Feature\Settings;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `.env` 로 잠긴 비밀값이 저장·테스트 발송 검증에서 "빈 값" 으로 거절되지 않는지(#703).
 *
 * `.env` 우선 모드에서 잠긴 민감 키는 화면에 값을 싣지 않는다(유출 차단). 그래서 폼은 그 칸을
 * 빈 채로 보낸다. 값의 주인은 `.env` 이고 저장 필터가 그 키를 어차피 버리는데, 검증이 요청에서
 * 값을 필수로 요구하면 드라이버·메일 탭 저장과 테스트 메일이 늘 422 로 끝난다.
 *
 * 시나리오 매니페스트: tests/scenarios/admin-action-gate-parity.yaml
 */
class EnvLockedSecretValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 스위치를 켜고 지정한 env 변수만 명시 상태로 만듭니다.
     *
     * @param  array<int, string>  $explicitVars  명시 상태로 만들 env 변수 목록
     * @return void 반환값 없음
     */
    private function enableWith(array $explicitVars): void
    {
        config([
            'env-priority.enabled' => true,
            'env-priority.explicit' => array_fill_keys($explicitVars, true),
        ]);
    }

    /**
     * 설정 조회·수정 권한을 가진 관리자의 API 토큰을 만듭니다.
     *
     * @return string 토큰
     */
    private function adminToken(): string
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

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

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $scopedRole = Role::create([
            'identifier' => 'admin_env_locked_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);
        $scopedRole->permissions()->sync($permissionIds);

        foreach ([$adminRole->id, $scopedRole->id] as $roleId) {
            $user->roles()->attach($roleId, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $user->fresh()->createToken('env-locked-secret-test')->plainTextToken;
    }

    /**
     * 웹소켓을 켠 드라이버 탭 저장 — `REVERB_APP_SECRET` 이 잠겨 비밀값 칸이 비어 와도 통과한다.
     *
     * @scenario defect=env_locked_drivers_save
     *
     * @effects env_locked_secret_not_required_on_save
     *
     * @return void 반환값 없음
     */
    public function test_drivers_tab_save_passes_when_websocket_secret_is_env_locked(): void
    {
        $this->enableWith(['REVERB_APP_SECRET']);

        $response = $this->withToken($this->adminToken())->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => [
                'storage_driver' => 'local',
                'cache_driver' => 'file',
                'session_driver' => 'file',
                'queue_driver' => 'database',
                'log_driver' => 'daily',
                'log_level' => 'error',
                'websocket_enabled' => true,
                'websocket_app_id' => 'app-id',
                'websocket_app_key' => 'app-key',
                'websocket_app_secret' => '',
            ],
        ]);

        $response->assertOk();
    }

    /**
     * 잠기지 않은 비밀값은 여전히 필수다(과완화 금지) — 같은 요청이 잠금 없이 오면 422.
     *
     * @scenario defect=env_locked_drivers_save
     *
     * @effects unlocked_secret_still_required
     *
     * @return void 반환값 없음
     */
    public function test_drivers_tab_save_still_requires_secret_when_not_locked(): void
    {
        $this->enableWith([]);

        $response = $this->withToken($this->adminToken())->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => [
                'storage_driver' => 'local',
                'cache_driver' => 'file',
                'session_driver' => 'file',
                'queue_driver' => 'database',
                'log_driver' => 'daily',
                'log_level' => 'error',
                'websocket_enabled' => true,
                'websocket_app_id' => 'app-id',
                'websocket_app_key' => 'app-key',
                'websocket_app_secret' => '',
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['drivers.websocket_app_secret']);
    }

    /**
     * 메일 탭 저장(Mailgun) — `MAILGUN_SECRET` 이 잠겨 비밀값 칸이 비어 와도 통과한다.
     *
     * @scenario defect=env_locked_mail_save
     *
     * @effects env_locked_secret_not_required_on_save
     *
     * @return void 반환값 없음
     */
    public function test_mail_tab_save_passes_when_mailgun_secret_is_env_locked(): void
    {
        $this->enableWith(['MAILGUN_SECRET']);

        $response = $this->withToken($this->adminToken())->postJson('/api/admin/settings', [
            '_tab' => 'mail',
            'mail' => [
                'mailer' => 'mailgun',
                'mailgun_domain' => 'mg.example.com',
                'mailgun_secret' => '',
                'from_address' => 'noreply@example.com',
                'from_name' => 'G7',
            ],
        ]);

        $response->assertOk();
    }

    /**
     * 테스트 메일(SES) — AWS 키가 잠겨 칸이 비어 와도 통과하고, 발송에는 `.env` 유효값이 쓰인다.
     *
     * @scenario defect=env_locked_test_mail
     *
     * @effects env_locked_secret_not_required_on_test_mail, test_mail_uses_env_effective_value_for_locked_keys
     *
     * @return void 반환값 없음
     */
    public function test_test_mail_passes_and_uses_env_value_when_ses_keys_are_env_locked(): void
    {
        Mail::fake();
        $this->enableWith(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY']);

        // `.env` 가 권위인 값 — 잠긴 키는 settings 주입을 건너뛰므로 런타임 config 가 곧 env 값이다
        config([
            'services.ses.key' => 'ENV-ACCESS-KEY',
            'services.ses.secret' => 'ENV-SECRET-KEY',
        ]);

        $response = $this->withToken($this->adminToken())->postJson('/api/admin/settings/test-mail', [
            'to_email' => 'test@example.com',
            'mailer' => 'ses',
            'ses_key' => '',
            'ses_secret' => '',
            'ses_region' => 'ap-northeast-2',
            'from_address' => 'noreply@example.com',
            'from_name' => 'G7',
        ]);

        $response->assertOk();
        $this->assertSame('ENV-ACCESS-KEY', config('services.ses.key'), '잠긴 키는 빈 입력 대신 .env 유효값으로 발송해야 합니다.');
        $this->assertSame('ENV-SECRET-KEY', config('services.ses.secret'));
    }
}
