<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Enums\ExtensionStatus;
use App\Enums\PermissionType;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;

/**
 * 테스트 환경용 시더
 *
 * RefreshDatabase 트레이트의 시딩 단계에서 실행되어
 * 트랜잭션 시작 전에 필요한 기본 데이터를 삽입합니다.
 */
class TestingSeeder extends Seeder
{
    /**
     * 테스트용 기본 데이터를 시딩합니다.
     */
    public function run(): void
    {
        // 모듈 등록
        Module::updateOrCreate(
            ['identifier' => 'sirsoft-ecommerce'],
            [
                'vendor' => 'sirsoft',
                'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
                'status' => ExtensionStatus::Active->value,
                'version' => '1.0.0',
            ]
        );

        // 기본 역할 생성
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => ['ko' => '관리자', 'en' => 'Administrator']]
        );

        // admin 역할에 admin 타입 권한 부여
        $adminPermission = Permission::firstOrCreate(
            ['identifier' => 'admin.access'],
            [
                'name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'],
                'type' => PermissionType::Admin,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching([$adminPermission->id]);

        $userRole = Role::firstOrCreate(
            ['identifier' => 'user'],
            ['name' => ['ko' => '일반 사용자', 'en' => 'User']]
        );

        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest']]
        );

        // 사용자(User) 타입 권한 생성 및 guest/user 역할에 부여
        $this->createUserPermissions($guestRole, $userRole);

        // 주문 시퀀스 초기화 (주문번호 채번용)
        $this->createOrderSequence();

        // 배송사 초기 데이터 생성
        $this->createShippingCarriers();

        // 클래임 사유 초기 데이터 생성
        $this->createClaimReasons();
    }

    /**
     * 주문 시퀀스를 초기화합니다.
     */
    private function createOrderSequence(): void
    {
        // ORDER, CANCEL, REFUND, PRODUCT 등 모든 시퀀스 타입 초기화
        // (취소/환불 처리 등 일부 서비스가 다른 타입을 채번하므로 전체 생성)
        foreach (SequenceType::cases() as $type) {
            $defaultConfig = $type->getDefaultConfig();

            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $defaultConfig['algorithm']->value,
                    'prefix' => $defaultConfig['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $defaultConfig['max_value'],
                    'cycle' => false,
                    'pad_length' => $defaultConfig['pad_length'],
                ]
            );
        }
    }

    /**
     * 배송사 초기 데이터를 생성합니다.
     */
    private function createShippingCarriers(): void
    {
        $carriers = [
            ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'hanjin', 'name' => ['ko' => '한진택배', 'en' => 'Hanjin Express'], 'type' => 'domestic', 'tracking_url' => 'https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'lotte', 'name' => ['ko' => '롯데택배', 'en' => 'Lotte Global Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'logen', 'name' => ['ko' => '로젠택배', 'en' => 'Logen Logistics'], 'type' => 'domestic', 'tracking_url' => 'https://www.ilogen.com/web/personal/trace/{tracking_number}', 'is_active' => true, 'sort_order' => 4],
            ['code' => 'ems', 'name' => ['ko' => 'EMS', 'en' => 'EMS'], 'type' => 'international', 'tracking_url' => 'https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}', 'is_active' => true, 'sort_order' => 5],
            ['code' => 'dhl', 'name' => ['ko' => 'DHL', 'en' => 'DHL'], 'type' => 'international', 'tracking_url' => 'https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}', 'is_active' => true, 'sort_order' => 6],
            ['code' => 'other', 'name' => ['ko' => '기타', 'en' => 'Other'], 'type' => 'domestic', 'tracking_url' => null, 'is_active' => true, 'sort_order' => 99],
        ];

        foreach ($carriers as $carrier) {
            ShippingCarrier::firstOrCreate(
                ['code' => $carrier['code']],
                $carrier
            );
        }
    }

    /**
     * 클래임 사유 초기 데이터를 생성합니다.
     */
    private function createClaimReasons(): void
    {
        $reasons = [
            ['type' => 'refund', 'code' => 'order_mistake', 'name' => ['ko' => '주문 실수', 'en' => 'Order Mistake'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 0],
            ['type' => 'refund', 'code' => 'changed_mind', 'name' => ['ko' => '단순 변심', 'en' => 'Changed Mind'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 1],
            ['type' => 'refund', 'code' => 'reorder_other', 'name' => ['ko' => '다른 상품으로 재주문', 'en' => 'Reorder with Different Product'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 2],
            ['type' => 'refund', 'code' => 'delayed_delivery', 'name' => ['ko' => '배송 지연', 'en' => 'Delayed Delivery'], 'fault_type' => 'seller', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 3],
            ['type' => 'refund', 'code' => 'product_info_different', 'name' => ['ko' => '상품 정보 상이', 'en' => 'Product Info Different'], 'fault_type' => 'seller', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 4],
            ['type' => 'refund', 'code' => 'admin_cancel', 'name' => ['ko' => '관리자 취소', 'en' => 'Admin Cancel'], 'fault_type' => 'seller', 'is_user_selectable' => false, 'is_active' => true, 'sort_order' => 5],
            ['type' => 'refund', 'code' => 'etc', 'name' => ['ko' => '기타', 'en' => 'Etc'], 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 6],
        ];

        foreach ($reasons as $reason) {
            ClaimReason::firstOrCreate(
                ['type' => $reason['type'], 'code' => $reason['code']],
                $reason
            );
        }
    }

    /**
     * 사용자 타입 권한을 생성하고 guest/user 역할에 부여합니다.
     *
     * @param  Role  $guestRole  비회원 역할
     * @param  Role  $userRole  일반 사용자 역할
     */
    private function createUserPermissions(Role $guestRole, Role $userRole): void
    {
        $userPermissions = [
            ['identifier' => 'sirsoft-ecommerce.user-products.read', 'name' => ['ko' => '상품 조회', 'en' => 'View Products']],
            ['identifier' => 'sirsoft-ecommerce.user-orders.create', 'name' => ['ko' => '주문하기', 'en' => 'Create Order']],
            ['identifier' => 'sirsoft-ecommerce.user-orders.cancel', 'name' => ['ko' => '주문 취소', 'en' => 'Cancel Order']],
            ['identifier' => 'sirsoft-ecommerce.user-orders.confirm', 'name' => ['ko' => '구매확정', 'en' => 'Confirm Purchase']],
            ['identifier' => 'sirsoft-ecommerce.user-reviews.write', 'name' => ['ko' => '리뷰 작성', 'en' => 'Write Review']],
        ];

        $permissionIds = [];
        foreach ($userPermissions as $perm) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $perm['identifier']],
                [
                    'name' => $perm['name'],
                    'type' => PermissionType::User,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // guest와 user 역할에 모든 사용자 권한 부여
        $guestRole->permissions()->syncWithoutDetaching($permissionIds);
        $userRole->permissions()->syncWithoutDetaching($permissionIds);
    }
}
