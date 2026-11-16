<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 관리자 주문 상세 「가상계좌 입금통보 이력」 조회
 *
 * 조회 계약은 호출자가 넘긴 결제사 id 목록(첫 값 자기 id, 나머지 옛 id)의 결제 행만 싣는다. 옛 식별자(`nicepay`)로
 * 발급된 가상계좌도 이 플러그인의 결제이므로, 그 이력이 비어 보이면 안 된다.
 */
class AdminVbankNotificationControllerTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-pay_nicepayments/admin';

    /**
     * 옛 결제사 식별자로 발급된 가상계좌의 입금통보 이력도 관리자에게 보인다.
     *
     * @scenario case=admin_vbank_notifications_legacy_provider_id
     *
     * @effects admin_vbank_notifications_include_legacy_provider_id
     *
     * @return void 반환값 없음
     */
    public function test_notifications_of_legacy_provider_id_are_listed(): void
    {
        $admin = $this->orderReader();
        $order = OrderFactory::new()->create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-NICE-LEGACY-'.random_int(100000, 999999),
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nicepay',
            'transaction_id' => 'NICE-LEGACY-TID',
            'vbank_issued_at' => now(),
            'paid_amount_local' => 0,
            'paid_at' => null,
            'payment_meta' => [
                'vbank_notifications' => [
                    ['type' => 'other', 'tid' => 'NICE-LEGACY-TID', 'result_code' => '4110'],
                ],
                'vbank_notification_summary' => ['count' => 1],
            ],
        ]);

        $response = $this->actingAs($admin)->getJson(self::BASE."/orders/{$order->order_number}/vbank-notifications");

        $response->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.tid', 'NICE-LEGACY-TID')
            ->assertJsonPath('data.summary.count', 1);
    }

    /**
     * 이커머스 주문 읽기 권한(범위 제한 없음)을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function orderReader(): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'nice-vbank-reader-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '주문 조회 관리자', 'en' => 'Order Reader'],
        ]);
        $user->roles()->attach($role->id);

        $access = Permission::firstOrCreate(['identifier' => 'admin.access'], ['name' => ['ko' => '관리자 접근', 'en' => 'Admin Access'], 'type' => PermissionType::Admin]);
        $read = Permission::updateOrCreate(
            ['identifier' => 'sirsoft-ecommerce.orders.read'],
            ['name' => ['ko' => '주문 조회', 'en' => 'Read Orders'], 'type' => PermissionType::Admin, 'resource_route_key' => 'order', 'owner_key' => 'user_id']
        );
        $role->permissions()->syncWithoutDetaching([$access->id => ['scope_type' => null], $read->id => ['scope_type' => null]]);

        PermissionHelper::clearPermissionScopeCache();

        return $user->fresh();
    }
}
