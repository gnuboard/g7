<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\ModuleManager;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\IdentityVerificationLogSeeder;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 IdentityVerificationLogSeeder 통합 테스트.
 *
 * source_identifier='sirsoft-board' 정책만 채워지는지 검증.
 */
class IdentityVerificationLogSeederTest extends ModuleTestCase
{
    private function bootstrap(): void
    {
        // 테스트 격리: 다른 테스트 클래스가 트랜잭션 외부에서 남긴 잔여 데이터 정리.
        IdentityVerificationLog::query()->delete();
        IdentityPolicy::query()->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityPolicySeeder::class);
        $this->syncBoardModulePolicies();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()
            ->count(15)
            ->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    /**
     * 게시판 모듈이 module.php 에 선언한 IDV 정책을 DB 에 동기화한다.
     */
    private function syncBoardModulePolicies(): void
    {
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $module = $manager->getModule('sirsoft-board');

        if ($module === null || ! method_exists($module, 'getIdentityPolicies')) {
            return;
        }

        $helper = app(IdentityPolicySyncHelper::class);
        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, [
                'source_type' => 'module',
                'source_identifier' => 'sirsoft-board',
            ]));
        }
    }

    public function test_seeder_only_seeds_board_scope(): void
    {
        $this->bootstrap();

        $this->assertTrue(
            IdentityPolicy::query()->where('source_identifier', 'sirsoft-board')->exists(),
            'bootstrap 단계에서 게시판 정책이 동기화되어야 합니다',
        );

        $this->seed(IdentityVerificationLogSeeder::class);

        $board = IdentityVerificationLog::query()->where('origin_identifier', 'sirsoft-board')->count();
        $other = IdentityVerificationLog::query()->where('origin_identifier', '!=', 'sirsoft-board')->count();

        $this->assertSame(100, $board, '게시판 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    public function test_seeder_uses_only_board_policies(): void
    {
        $this->bootstrap();

        $this->seed(IdentityVerificationLogSeeder::class);

        $boardPolicyKeys = IdentityPolicy::query()
            ->where('source_identifier', 'sirsoft-board')
            ->pluck('key')
            ->all();

        foreach (IdentityVerificationLog::query()->get() as $log) {
            $this->assertContains($log->origin_policy_key, $boardPolicyKeys);
        }
    }
}
