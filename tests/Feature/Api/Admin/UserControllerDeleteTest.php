<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\IdentityVerificationStatus;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Admin UserController 삭제 테스트
 *
 * 관리자/슈퍼관리자 사용자 삭제 시 구체적 에러 메시지 응답을 검증합니다.
 */
class UserControllerDeleteTest extends TestCase
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
     * 관리자 역할 및 삭제 권한을 가진 사용자 생성
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['is_super' => true]);

        $permissions = [];
        foreach (['core.users.read', 'core.users.delete'] as $identifier) {
            $permissions[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            );
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        foreach ($permissions as $permission) {
            if (! $adminRole->permissions()->where('permissions.id', $permission->id)->exists()) {
                $adminRole->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 슈퍼 관리자 삭제 시 구체적 에러 메시지 응답
    // ========================================================================

    /**
     * 슈퍼 관리자 삭제 시 422 상태 코드와 구체적 에러 메시지 반환
     */
    public function test_delete_super_admin_returns_specific_error_message(): void
    {
        $superAdmin = User::factory()->create(['is_super' => true]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$superAdmin->uuid}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('exceptions.cannot_delete_super_admin'));
    }

    // ========================================================================
    // 관리자 삭제: 권한/스코프 시스템에 위임
    // ========================================================================

    /**
     * 회귀 — 훅 정책에서 throw 되는 428 응답에도 return_request 가 포함되어야 한다.
     *
     * 사례: IDV 모달이 verify 성공 후 return_request.url 에 토큰을 부착해 재실행해야 하는데,
     * Listener 가 context.return_request 를 누락해 응답이 null 로 나가 → 인터셉터가 재시도를
     * 시작하지 못함 → 사용자가 인증을 마쳐도 삭제가 진행되지 않고 본인확인 토스트만 반복.
     *
     * 미들웨어(scope=route) 는 return_request 를 채우지만 Listener(scope=hook) 는 누락하던 결함.
     */
    public function test_hook_policy_428_response_includes_return_request_for_retry(): void
    {
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::where('key', 'core.admin.user_delete')->update(['enabled' => true]);

        $target = User::factory()->create(['is_super' => false]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$target->uuid}");

        $response->assertStatus(428);
        $response->assertJsonPath('verification.policy_key', 'core.admin.user_delete');
        $response->assertJsonPath('verification.return_request.method', 'DELETE');
        $this->assertStringContainsString(
            "/api/admin/users/{$target->uuid}",
            (string) $response->json('verification.return_request.url'),
            'return_request.url 가 원 요청 URL 이어야 retry 가 가능함'
        );
    }

    /**
     * 회귀 — 삭제 정책 활성 + verification_token 부착 시 HTTP 흐름 전체에서 정상 삭제.
     *
     * 사례: 관리자가 사용자 삭제 클릭 → 428 → IDV 모달에서 인증 → token 부착 retry →
     *       그래도 "본인 확인이 필요합니다" 토스트가 다시 뜨고 삭제 안 되던 결함.
     *
     * 검증: verification_token 우회가 라우트 미들웨어 + 훅 리스너 양쪽 모두에서 일관 동작해야
     * end-to-end DELETE 가 통과하고 사용자가 실제로 삭제되는지 확인.
     */
    public function test_delete_with_verification_token_passes_through_hook_policy(): void
    {
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::where('key', 'core.admin.user_delete')->update(['enabled' => true]);

        $target = User::factory()->create(['is_super' => false]);
        $token = 'tok-'.bin2hex(random_bytes(8));

        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $this->admin->id,
            'target_hash' => hash('sha256', mb_strtolower($this->admin->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => $token,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now()->subSeconds(2),
            'updated_at' => now()->subSeconds(2),
        ]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$target->uuid}?verification_token={$token}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    /**
     * 슈퍼관리자(is_super=true) 가 admin 권한을 가진 일반 관리자 계정을 삭제하면 성공한다.
     *
     * 회귀: 과거 UserService::deleteUser 가 target.isAdmin() 만 보고 무조건 차단해
     * 슈퍼관리자도 다른 관리자를 지울 수 없던 결함. 권한/스코프 검증은 PermissionMiddleware
     * 가 담당하며, Service 는 시스템 불변식인 슈퍼관리자 보호만 유지한다.
     */
    public function test_super_admin_can_delete_admin_user(): void
    {
        $adminPermission = Permission::create([
            'identifier' => 'core.admin.delete.test',
            'name' => json_encode(['ko' => '관리자 권한', 'en' => 'Admin Permission']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);

        $role = Role::create([
            'identifier' => 'test-admin-delete',
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
        ]);
        $role->permissions()->attach($adminPermission->id);

        $adminUser = User::factory()->create(['is_super' => false]);
        $adminUser->roles()->attach($role->id);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$adminUser->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('users', ['id' => $adminUser->id]);
    }

    // ========================================================================
    // 일반 사용자 삭제 성공
    // ========================================================================

    /**
     * 일반 사용자 삭제 시 성공 메시지 반환
     */
    public function test_delete_regular_user_returns_success(): void
    {
        $regularUser = User::factory()->create(['is_super' => false]);

        $response = $this->authRequest()
            ->deleteJson("/api/admin/users/{$regularUser->uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('user.delete_success'));
    }

    // ========================================================================
    // 삭제 실패 시 에러 상세 메시지 노출 (:error placeholder 치환)
    // ========================================================================

    /**
     * 회귀 — 삭제 실패 시 토스트 message 에 `:error` placeholder 가 그대로 노출되지 않고,
     * 구체적 실패 사유가 치환되어 사용자에게 보여야 한다.
     *
     * 사례: 플러그인 FK 제약 등으로 UserService::deleteUser 가 ValidationException 을
     * 던질 때, UserController::destroy 의 422 경로가 message 용 치환값을 전달하지 않아
     * 토스트에 `사용자 삭제에 실패했습니다: :error` 가 그대로 노출됨 (#415).
     *
     * 검증: 응답 message 에 `:error` 가 남지 않고 구체 사유가 포함되며, errors.general 에도
     * 동일 상세가 담긴다 (에러 상세 표시 기능 유지 — 메시지를 숨기지 않음).
     */
    public function test_delete_failure_message_substitutes_error_detail_not_raw_placeholder(): void
    {
        $target = User::factory()->create(['is_super' => false]);

        $hookName = 'core.user.before_delete';
        $reason = '연결된 외부 데이터가 있어 삭제할 수 없습니다 (regression-marker)';

        // UserService::deleteUser 의 before_delete 훅에서 예외를 던져 삭제 실패를 강제.
        // Service 가 이를 잡아 __('user.delete_failed', ['error' => ...]) ValidationException 으로 변환.
        HookManager::addAction($hookName, function () use ($reason) {
            throw new RuntimeException($reason);
        });

        try {
            $response = $this->authRequest()
                ->deleteJson("/api/admin/users/{$target->uuid}");
        } finally {
            HookManager::clearAction($hookName);
        }

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        $message = (string) $response->json('message');

        // 핵심: placeholder 가 그대로 노출되면 안 됨
        $this->assertStringNotContainsString(':error', $message, 'message 에 미치환 :error 가 남으면 안 된다');
        // 핵심: 실패 사유가 사용자에게 보여야 함 (기능 유지)
        $this->assertStringContainsString($reason, $message, '구체적 실패 사유가 message 에 노출되어야 한다');

        // errors.general 에도 동일 상세가 담긴다
        $this->assertStringContainsString($reason, (string) $response->json('errors.general.0'));
        $this->assertStringNotContainsString(':error', (string) $response->json('errors.general.0'));
    }
}
