<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Testing\Concerns\AssertsIdentityPolicyDeclaration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 모듈이 module.php::getIdentityPolicies() 로 선언한 정책이
 * IdentityPolicySyncHelper 를 통해 identity_policies 테이블에 정확히 동기화되는지 검증한다.
 *
 * - source_type='module', source_identifier='sirsoft-board' 으로 적재
 * - 운영자가 enabled/grace_minutes 등을 수정하면 user_overrides 보존
 * - 재실행(install/update 시뮬레이션) 후에도 user_overrides 필드는 덮어쓰지 않음
 */
class BoardIdentityPolicyDeclarationTest extends ModuleTestCase
{
    use AssertsIdentityPolicyDeclaration;

    /**
     * 게시판 모듈이 선언한 정책 키 목록 (module.php::getIdentityPolicies() 와 동기화 유지).
     *
     * @var list<string>
     */
    private const DECLARED_KEYS = [
        'sirsoft-board.post.delete',
        'sirsoft-board.post.blind',
        'sirsoft-board.report.bulk_action',
        'sirsoft-board.report.delete',
        'sirsoft-board.post.user_delete',
        'sirsoft-board.comment.user_delete',
        'sirsoft-board.report.create',
        'sirsoft-board.post.user_create',
    ];

    /**
     * applies_to=self 정책 4건이 정확한 분류로 동기화되는지 검증.
     */
    private const SELF_POLICY_KEYS = [
        'sirsoft-board.post.user_delete',
        'sirsoft-board.comment.user_delete',
        'sirsoft-board.report.create',
        'sirsoft-board.post.user_create',
    ];

    /**
     * declarative 정책이 모듈 source 컨텍스트로 동기화된다.
     *
     * 공통 어설션은 AssertsIdentityPolicyDeclaration trait 에 위임하고,
     * 게시판 도메인 고유 검사(scope=hook, purpose=sensitive_action) 만 추가로 검증한다.
     */
    public function test_identity_policies_are_synced_for_module_source(): void
    {
        $this->assertIdentityPoliciesSyncedForExtension(
            extensionType: 'module',
            extensionIdentifier: 'sirsoft-board',
            declaredKeys: self::DECLARED_KEYS,
            syncCallback: fn () => $this->syncBoardIdentityPolicies(),
        );

        foreach (self::DECLARED_KEYS as $key) {
            $policy = IdentityPolicy::query()->where('key', $key)->first();
            $this->assertSame('hook', $policy->scope->value);
            $this->assertSame('sensitive_action', $policy->purpose);
        }
    }

    /**
     * 운영자가 enabled 토글 시 user_overrides 에 'enabled' 가 기록되고,
     * 모듈 재동기화(updateModule 시뮬레이션) 후에도 운영자 값이 보존된다.
     */
    public function test_user_overrides_preserved_on_resync(): void
    {
        $this->assertIdentityPolicyUserOverridesPreserved(
            key: 'sirsoft-board.post.delete',
            overrides: ['enabled' => true, 'grace_minutes' => 120],
            syncCallback: fn () => $this->syncBoardIdentityPolicies(),
        );
    }

    /**
     * cleanupStalePolicies — 모듈이 정책을 1 개 제거하면 (시뮬레이션) 그 정책이 DB 에서 사라진다.
     */
    public function test_stale_policies_are_cleaned_up(): void
    {
        $this->assertIdentityPolicyStaleCleanup(
            extensionType: 'module',
            extensionIdentifier: 'sirsoft-board',
            declaredKeys: self::DECLARED_KEYS,
            syncCallback: fn () => $this->syncBoardIdentityPolicies(),
        );
    }

    /**
     * 게시판은 신규 purpose 를 등록하지 않는다(코어 sensitive_action 재사용).
     */
    public function test_no_custom_purposes_declared(): void
    {
        $module = new \Modules\Sirsoft\Board\Module(
            'sirsoft-board',
            $this->getModuleBasePath(),
        );

        $this->assertSame([], $module->getIdentityPurposes());
    }

    /**
     * applies_to=self 정책 4건이 사용자 도메인 정책으로 정확히 동기화되는지 검증.
     *
     * 게시판 self 정책은 자기 글 삭제·자기 댓글 삭제·신고 작성·첫 글 작성 4건이며,
     * admin 정책과 같은 훅 (`*.before_delete` / `*.before_create`) 을 공유하나
     * `applies_to=self` 분기로 일반 사용자에게만 강제된다.
     */
    public function test_self_policies_are_declared_for_user_domain(): void
    {
        $this->syncBoardIdentityPolicies();

        foreach (self::SELF_POLICY_KEYS as $key) {
            $policy = IdentityPolicy::query()->where('key', $key)->first();
            $this->assertNotNull($policy, "self 정책 {$key} 가 동기화되지 않음");
            $this->assertSame('self', $policy->applies_to->value, "{$key} 의 applies_to 가 self 여야 함");
            $this->assertFalse((bool) $policy->enabled, "self 정책 {$key} 의 기본값은 enabled=false");
            $this->assertSame('hook', $policy->scope->value);
        }
    }

    /**
     * module.php::getIdentityPolicies() 결과를 IdentityPolicySyncHelper 로 동기화.
     * ModuleManager::syncModuleIdentityPolicies() 의 핵심 동작을 그대로 재현.
     */
    private function syncBoardIdentityPolicies(): void
    {
        $helper = app(IdentityPolicySyncHelper::class);
        $module = new \Modules\Sirsoft\Board\Module(
            'sirsoft-board',
            $this->getModuleBasePath(),
        );

        $declaredKeys = [];
        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, [
                'source_type' => 'module',
                'source_identifier' => 'sirsoft-board',
            ]));
            $declaredKeys[] = $policy['key'];
        }
        $helper->cleanupStalePolicies('module', 'sirsoft-board', $declaredKeys);
    }

    // ===========================================================================
    // Part B-2/B-3 — enforce 매트릭스 + grace 윈도우 (보드 정책 4건은 모두 admin scope)
    // ===========================================================================

    private function enabledPolicy(string $key): IdentityPolicy
    {
        $this->syncBoardIdentityPolicies();
        $policy = IdentityPolicy::where('key', $key)->first();
        $this->assertNotNull($policy);
        $policy->enabled = true;
        $policy->save();

        return $policy->fresh();
    }

    private function regularUser(): User
    {
        return User::factory()->create();
    }

    private function adminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $admin->fresh();
    }

    /** D2 — admin 정책이 enabled+admin 사용자에게 enforce 발동 */
    public function test_post_delete_enforces_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-board.post.delete');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->adminUser(), []);
    }

    /** D10 — admin 정책이 일반 사용자 우회 */
    public function test_post_blind_skips_for_regular_user(): void
    {
        $policy = $this->enabledPolicy('sirsoft-board.post.blind');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->regularUser(), []);
        $this->assertTrue(true);
    }

    public function test_report_bulk_action_enforces_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-board.report.bulk_action');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->adminUser(), []);
    }

    public function test_report_delete_skips_for_regular_user(): void
    {
        $policy = $this->enabledPolicy('sirsoft-board.report.delete');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->regularUser(), []);
        $this->assertTrue(true);
    }

    /**
     * 보드 4개 정책 라이프사이클 매트릭스 — Service-level (admin scope, sensitive_action purpose).
     *
     * @dataProvider boardLifecycleProvider
     */
    public function test_board_policy_full_service_lifecycle(string $policyKey, int $graceMinutes): void
    {
        $admin = $this->adminUser();
        $policy = $this->enabledPolicy($policyKey);
        $service = app(IdentityPolicyService::class);

        try {
            $service->enforce($policy, $admin, []);
            $this->fail("정책 '{$policyKey}' 가 인증 이력 없을 때 throw 해야 함");
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame($policyKey, $e->policyKey);
        }

        $this->seedVerifiedLog($admin, 'sensitive_action', Carbon::now());

        $service->enforce($policy, $admin->fresh(), []);
        $this->assertTrue(true, "verified 직후 enforce 통과");

        if ($graceMinutes > 0) {
            Carbon::setTestNow(Carbon::now()->addMinutes($graceMinutes + 1));
            try {
                $service->enforce($policy, $admin->fresh(), []);
                $this->fail("정책 '{$policyKey}' grace+1 분 경과 후 throw 해야 함");
            } catch (IdentityVerificationRequiredException $e) {
                $this->assertSame($policyKey, $e->policyKey);
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    public static function boardLifecycleProvider(): array
    {
        return [
            'post.delete (admin, grace=5)' => ['sirsoft-board.post.delete', 5],
            'post.blind (admin, grace=5)' => ['sirsoft-board.post.blind', 5],
            'report.bulk_action (admin, grace=5)' => ['sirsoft-board.report.bulk_action', 5],
            'report.delete (admin, grace=0)' => ['sirsoft-board.report.delete', 0],
        ];
    }

    private function seedVerifiedLog(User $user, string $purpose, Carbon $when): void
    {
        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /** D12 — post.delete grace=5 윈도우 내 인증 이력이 있으면 enforce skip */
    public function test_post_delete_grace_window_skips(): void
    {
        $admin = $this->adminUser();
        $policy = $this->enabledPolicy('sirsoft-board.post.delete');
        $service = app(IdentityPolicyService::class);

        $when = Carbon::now()->subMinutes(3); // grace=5 이내
        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => $admin->id,
            'target_hash' => hash('sha256', mb_strtolower($admin->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        $service->enforce($policy, $admin->fresh(), []);
        $this->assertTrue(true);
    }
}
