<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\ModuleManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\IdentityVerificationLogSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 IdentityVerificationLogSeeder 통합 테스트.
 *
 * source_identifier='sirsoft-ecommerce' 정책만 채워지는지 검증.
 */
class IdentityVerificationLogSeederTest extends ModuleTestCase
{
    private function bootstrap(): void
    {
        // 테스트 격리: 다른 테스트 클래스가 트랜잭션 외부에서 남긴 잔여 데이터를 명시적으로 정리.
        // (RefreshDatabase ↔ DatabaseTransactions 혼합 환경에서 transaction rollback 누수가 관찰되어
        // 결정론적 시작 상태를 보장하기 위함)
        IdentityVerificationLog::query()->delete();
        IdentityPolicy::query()->delete();

        $this->seed(RolePermissionSeeder::class);
        // 코어 정책 (테스트가 모듈 정책만 채우는지 확인하려면 코어도 있어야 함)
        $this->seed(IdentityPolicySeeder::class);
        // 이커머스 정책 — 테스트 환경에서는 ModuleManager install 경로가 타지 않으므로
        // 모듈이 선언한 getIdentityPolicies() 를 직접 sync 해 둔다.
        $this->syncEcommerceModulePolicies();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()
            ->count(15)
            ->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    /**
     * 이커머스 모듈이 module.php 에 선언한 IDV 정책을 DB 에 동기화한다.
     */
    private function syncEcommerceModulePolicies(): void
    {
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $module = $manager->getModule('sirsoft-ecommerce');

        if ($module === null || ! method_exists($module, 'getIdentityPolicies')) {
            return;
        }

        $helper = app(IdentityPolicySyncHelper::class);
        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, [
                'source_type' => 'module',
                'source_identifier' => 'sirsoft-ecommerce',
            ]));
        }
    }

    public function test_seeder_only_seeds_ecommerce_scope(): void
    {
        $this->bootstrap();

        $this->assertTrue(
            IdentityPolicy::query()->where('source_identifier', 'sirsoft-ecommerce')->exists(),
            'bootstrap 단계에서 이커머스 정책이 동기화되어야 합니다',
        );

        $this->seed(IdentityVerificationLogSeeder::class);

        $ecommerce = IdentityVerificationLog::query()
            ->where('origin_identifier', 'sirsoft-ecommerce')
            ->count();
        $other = IdentityVerificationLog::query()
            ->where('origin_identifier', '!=', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(100, $ecommerce, '이커머스 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    public function test_seeder_uses_only_ecommerce_policies(): void
    {
        $this->bootstrap();

        $this->seed(IdentityVerificationLogSeeder::class);

        $ecommercePolicyKeys = IdentityPolicy::query()
            ->where('source_identifier', 'sirsoft-ecommerce')
            ->pluck('key')
            ->all();

        foreach (IdentityVerificationLog::query()->get() as $log) {
            $this->assertContains($log->origin_policy_key, $ecommercePolicyKeys);
        }
    }
}
