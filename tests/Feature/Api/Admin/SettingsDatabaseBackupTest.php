<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DB 백업 엔드포인트의 미제공 응답 검증 (공개 #115 부록 B3)
 *
 * `POST /api/admin/settings/backup-database` 는 존재하지 않는
 * `SettingsService::backupDatabase()` 를 호출해 왔다. PHP 가 던지는 것은 `Error` 라
 * 컨트롤러의 `catch (\Exception)` 에 걸리지 않고 그대로 500 이 됐다.
 * 화면 버튼이 없어 운영 중에는 드러나지 않지만, 공개 API 레퍼런스에는 정상 기능으로
 * 실려 있어 문서를 보고 호출한 쪽에는 무조건 서버 오류였다.
 *
 * 기능은 아직 없으므로 "서버가 고장났다"(500)가 아니라 "이 기능은 제공하지 않는다"(501)로
 * 정직하게 답하는 것이 계약이다.
 */
class SettingsDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 설정 갱신 권한을 가진 관리자 생성
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $adminRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 미제공 기능은 500 이 아니라 501 로 응답해야 한다.
     */
    #[Test]
    public function database_backup_responds_with_not_implemented(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/backup-database');

        $response->assertStatus(501);
        $response->assertJson(['success' => false]);
    }

    /**
     * 응답 메시지는 다국어 키가 해석된 사람 말이어야 한다.
     *
     * 키 원문(`settings.database_backup_unavailable`)이 그대로 나가면 호출자는
     * 무슨 상태인지 알 수 없다.
     */
    #[Test]
    public function not_implemented_response_carries_a_translated_message(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/settings/backup-database');

        $message = (string) $response->json('message');

        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('settings.', $message);
        $this->assertSame(__('settings.database_backup_unavailable'), $message);
    }

    /**
     * 미구현 응답으로 바꾸더라도 권한 경계는 그대로여야 한다.
     *
     * 501 을 먼저 돌려주느라 인증·권한 검사를 건너뛰면, 권한 없는 요청에까지
     * 내부 구현 상태를 알려주게 된다.
     */
    #[Test]
    public function guest_still_receives_unauthenticated(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/admin/settings/backup-database');

        $response->assertStatus(401);
    }
}
