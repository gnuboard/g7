<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Enums\PermissionType;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;

/**
 * v0.5.0 업그레이드 스텝
 *
 * - 사용자 권한 3개 생성 (상품 조회, 주문하기, 주문 취소)
 * - 기존 모든 역할에 신규 권한 할당
 * - 레이아웃 캐시 클리어
 */
class Upgrade_0_5_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->createUserPermissions($context);
        $this->clearLayoutCache($context);
    }

    /**
     * 사용자 권한을 생성하고 모든 역할에 할당합니다.
     *
     * 블랙컨슈머 차단용 사용자 권한 3개를 생성합니다.
     * 기존 설치에서는 모든 역할에 기본 부여하여 기존 동작을 유지합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function createUserPermissions(UpgradeContext $context): void
    {
        $permissions = [
            [
                'identifier' => 'sirsoft-ecommerce.user-products.read',
                'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
                'type' => PermissionType::User,
            ],
            [
                'identifier' => 'sirsoft-ecommerce.user-orders.create',
                'name' => ['ko' => '주문하기', 'en' => 'Create Order'],
                'type' => PermissionType::User,
            ],
            [
                'identifier' => 'sirsoft-ecommerce.user-orders.cancel',
                'name' => ['ko' => '주문 취소', 'en' => 'Cancel Order'],
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

        $context->logger->info("[v0.5.0] 이커머스 사용자 권한 생성 완료: {$created}건 생성 (총 ".count($permissions)."건 중), {$assigned}개 역할에 할당");
    }

    /**
     * 레이아웃 캐시를 클리어합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function clearLayoutCache(UpgradeContext $context): void
    {
        try {
            Artisan::call('template:cache-clear');
            $context->logger->info('[v0.5.0] 템플릿 캐시 클리어 완료');
        } catch (\Exception $e) {
            $context->logger->warning("[v0.5.0] 템플릿 캐시 클리어 실패: {$e->getMessage()}");
        }
    }
}
