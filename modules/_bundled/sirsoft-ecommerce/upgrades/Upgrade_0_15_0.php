<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Enums\PermissionType;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * v0.15.0 업그레이드 스텝
 *
 * - 사용자 권한 2개 생성 (구매확정, 리뷰 작성)
 * - 기존 모든 역할에 신규 권한 할당
 * - order_options 테이블에 confirmed_at 컬럼 추가
 * - 레이아웃 캐시 클리어
 */
class Upgrade_0_15_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->addConfirmedAtColumn($context);
        $this->createUserPermissions($context);
        $this->clearLayoutCache($context);
    }

    /**
     * order_options 테이블에 confirmed_at 컬럼을 추가합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function addConfirmedAtColumn(UpgradeContext $context): void
    {
        if (Schema::hasColumn('ecommerce_order_options', 'confirmed_at')) {
            $context->logger->info('[v0.15.0] confirmed_at 컬럼이 이미 존재합니다.');

            return;
        }

        Schema::table('ecommerce_order_options', function ($table) {
            $table->timestamp('confirmed_at')->nullable()->after('option_status')
                  ->comment('구매확정 일시');
        });

        $context->logger->info('[v0.15.0] ecommerce_order_options 테이블에 confirmed_at 컬럼 추가 완료');
    }

    /**
     * 사용자 권한을 생성하고 모든 역할에 할당합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function createUserPermissions(UpgradeContext $context): void
    {
        $permissions = [
            [
                'identifier' => 'sirsoft-ecommerce.user-orders.confirm',
                'name' => ['ko' => '구매확정', 'en' => 'Confirm Purchase'],
                'type' => PermissionType::User,
            ],
            [
                'identifier' => 'sirsoft-ecommerce.user-reviews.write',
                'name' => ['ko' => '리뷰 작성', 'en' => 'Write Review'],
                'type' => PermissionType::User,
            ],
        ];

        $created = 0;
        $permissionIds = [];

        foreach ($permissions as $permData) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permData['identifier']],
                $permData
            );

            $permissionIds[] = $permission->id;

            if ($permission->wasRecentlyCreated) {
                $created++;
            }
        }

        // 모든 역할에 신규 권한 할당 (기존 동작 유지)
        $roles = Role::all();
        $assigned = 0;

        foreach ($roles as $role) {
            $role->permissions()->syncWithoutDetaching($permissionIds);
            $assigned++;
        }

        $context->logger->info("[v0.15.0] 사용자 권한 생성 완료: {$created}건 생성 (총 ".count($permissions).'건 중), '.$assigned.'개 역할에 할당');
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.15.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.15.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
