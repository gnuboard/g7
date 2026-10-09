<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Enums\ScopeType;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 클레임 판정이 운영자의 주문 조회 범위를 넘지 않는지 검증합니다. (EC-667)
 *
 * 권한 관리에서 「주문 조회」 를 「본인만」 으로 묶어도 클레임 권한이 기본값 「전체」 면, 그 운영자는
 * 취소/반품/교환 관리 화면에서 남의 주문 신청서(환불 계좌 포함)를 보고 처리할 수 있었다. 클레임
 * 판정은 그 라우트의 클레임 권한 범위와 **주문 조회 범위의 교집합**이어야 한다.
 *
 * 주문 조회 권한 자체가 없는 운영자(클레임 전담 역할)는 클레임 권한 범위만으로 판정한다 — 코어가
 * 보유하지 않은 권한을 범위 진술로 보지 않고(`User::getEffectiveScopeForPermission()` 이 null),
 * 이 모듈도 클레임 전담 역할을 정상 구성으로 둔다(환불 계좌 원문 열람 규칙 `MasksRefundBankAccount`).
 */
class ClaimScopeOrderReadIntersectionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /** 주문 조회 권한 식별자 */
    private const ORDER_READ = 'sirsoft-ecommerce.orders.read';

    /** 운영자에게 주는 클레임 권한 식별자 */
    private const CLAIM_PERMISSIONS = [
        'sirsoft-ecommerce.claims.read',
        'sirsoft-ecommerce.claims.update-status',
        'sirsoft-ecommerce.claims.update-amounts',
        'sirsoft-ecommerce.claims.execute-refund',
        'sirsoft-ecommerce.claims.create',
    ];

    protected User $customer;

    /**
     * 통화·사유를 심고, 주문·클레임 권한 행에 모듈 선언의 스코프 축을 반영합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->clearPermissionCache();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'PGTXN-EC667', 'error_code' => null, 'error_message' => null,
        ]);

        $this->customer = $this->createUser();
    }

    /**
     * 통화 설정과 권한 스코프 캐시를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        $this->clearPermissionCache();
        parent::tearDown();
    }

    /**
     * 권한 스코프 정적 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function clearPermissionCache(): void
    {
        PermissionHelper::clearPermissionScopeCache();
    }

    /**
     * 모듈 선언(`module.php`)에서 권한 카테고리의 스코프 축을 읽습니다.
     *
     * @param  string  $categoryIdentifier  권한 카테고리 식별자 (orders · claims)
     * @return array{resource_route_key: ?string, owner_key: ?string} 선언된 축
     */
    protected function declaredScopeAxis(string $categoryIdentifier): array
    {
        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            if (($category['identifier'] ?? null) === $categoryIdentifier) {
                return [
                    'resource_route_key' => $category['resource_route_key'] ?? null,
                    'owner_key' => $category['owner_key'] ?? null,
                ];
            }
        }

        return ['resource_route_key' => null, 'owner_key' => null];
    }

    /**
     * 주문 조회·클레임 권한 범위를 지정한 운영자를 만듭니다.
     *
     * 권한 행의 스코프 축은 모듈 선언에서 옮긴다 — 테스트가 축을 직접 박으면 선언 누락을 보지 못한다.
     *
     * @param  ScopeType|null|false  $orderReadScope  주문 조회 범위 (null = 전체, false = 권한 없음)
     * @param  ScopeType|null  $claimScope  클레임 권한 범위 (null = 전체)
     * @return User 운영자
     */
    protected function operator(ScopeType|null|false $orderReadScope, ?ScopeType $claimScope): User
    {
        $identifiers = self::CLAIM_PERMISSIONS;

        if ($orderReadScope !== false) {
            $identifiers[] = self::ORDER_READ;
        }

        $admin = $this->createAdminUser($identifiers);
        $role = $admin->roles()->first();

        foreach (self::CLAIM_PERMISSIONS as $identifier) {
            $permission = Permission::where('identifier', $identifier)->firstOrFail();
            $permission->update($this->declaredScopeAxis('claims'));
            $role->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => $claimScope]]);
        }

        if ($orderReadScope !== false) {
            $permission = Permission::where('identifier', self::ORDER_READ)->firstOrFail();
            $permission->update($this->declaredScopeAxis('orders'));
            $role->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => $orderReadScope]]);
        }

        $this->clearPermissionCache();

        return $admin;
    }

    /**
     * 관리자 API 접두 경로를 돌려줍니다.
     *
     * @return string API 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 지정 회원 소유 주문에 승인된 직접 환불신청서를 만듭니다.
     *
     * @param  int  $ownerId  주문 주인 회원 ID
     * @return array{0: Order, 1: OrderRefund} 주문 + 환불신청서
     */
    protected function approvedRefundOwnedBy(int $ownerId): array
    {
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $ownerId])->save();
        $order = $this->moveOrderTo($order->fresh(['options']), OrderStatusEnum::PREPARING);
        $order = $order->fresh(['options', 'payment']);

        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);
        $refund->update([
            'refund_amount' => 10000,
            'refund_method' => RefundMethodEnum::PG,
            'reason_code' => 'changed_mind',
        ]);

        return [$order, $refund->fresh()];
    }

    /**
     * 환불 탭 목록의 신청서 번호를 돌려줍니다.
     *
     * @param  User  $admin  운영자
     * @return array{numbers: array<int, string>, count: int} 목록 번호 + 환불 탭 건수
     */
    protected function refundList(User $admin): array
    {
        $response = $this->actingAs($admin)->getJson($this->base().'/claims?type=refund')->assertOk();

        return [
            'numbers' => array_column($response->json('data.data'), 'claim_number'),
            'count' => (int) $response->json('data.counts.refund'),
        ];
    }

    /**
     * 주문 조회 「본인만」 + 클레임 「전체」 운영자의 목록·건수에 남의 주문 신청서가 나오지 않는지 검증합니다.
     *
     * 수정 전: 클레임 권한 범위만 보고 거르므로 남의 주문 신청서까지 2건이 모두 나온다.
     *
     * @scenario order_read_scope=self, claim_scope=all, surface=list
     *
     * @effects claim_list_is_bounded_by_order_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_list_is_bounded_by_the_order_read_scope(): void
    {
        $admin = $this->operator(ScopeType::Self, null);
        [, $own] = $this->approvedRefundOwnedBy($admin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $list = $this->refundList($admin);

        $this->assertContains($own->refund_number, $list['numbers']);
        $this->assertNotContains($foreign->refund_number, $list['numbers'], '주문 조회 범위 밖 주문의 신청서가 목록에 나왔다');
        $this->assertSame(1, $list['count']);
    }

    /**
     * 주문 조회 「본인만」 + 클레임 「전체」 운영자가 범위 밖 신청서 상세·번호 상세·로그를 열 수 없는지 검증합니다.
     *
     * 수정 전: 가드가 클레임 조회 범위(전체)만 판정해 200 이다 — 환불 계좌가 실린 상세가 열린다.
     *
     * @scenario order_read_scope=self, claim_scope=all, surface=detail
     *
     * @effects claim_detail_is_bounded_by_order_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_detail_is_bounded_by_the_order_read_scope(): void
    {
        $admin = $this->operator(ScopeType::Self, null);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);
        [, $own] = $this->approvedRefundOwnedBy($admin->id);

        $this->actingAs($admin)->getJson($this->base()."/refunds/{$foreign->id}")->assertForbidden();
        $this->actingAs($admin)->getJson($this->base()."/claims/{$foreign->refund_number}")->assertForbidden();
        $this->actingAs($admin)->getJson($this->base()."/claims/{$foreign->refund_number}/logs")->assertForbidden();

        // 대조군 — 주문 조회 범위 안 신청서는 그대로 열린다.
        $this->actingAs($admin)->getJson($this->base()."/refunds/{$own->id}")->assertOk();
        $this->actingAs($admin)->getJson($this->base()."/claims/{$own->refund_number}")->assertOk();
    }

    /**
     * 주문 조회 「본인만」 + 클레임 「전체」 운영자가 범위 밖 신청서를 전이·환불 실행할 수 없는지 검증합니다.
     *
     * 수정 전: 전이·실행 가드가 클레임 권한 범위만 판정해 남의 주문 신청서가 보류·환불 완료된다.
     *
     * @scenario order_read_scope=self, claim_scope=all, surface=transition
     *
     * @effects claim_actions_are_bounded_by_order_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_transition_and_refund_execution_are_bounded_by_the_order_read_scope(): void
    {
        $admin = $this->operator(ScopeType::Self, null);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $this->actingAs($admin)
            ->patchJson($this->base()."/refunds/{$foreign->id}/status", [
                'status' => 'on_hold',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson($this->base()."/refunds/{$foreign->id}/execute")
            ->assertForbidden();

        $this->assertSame(RefundStatusEnum::APPROVED, $foreign->fresh()->refund_status);
    }

    /**
     * 주문 조회 「본인만」 + 클레임 「전체」 운영자의 일괄 전이에 범위 밖 신청서가 섞이면 전량 거절되는지 검증합니다.
     *
     * 수정 전: 일괄 가드가 클레임 권한 범위(전체)만 보고 건너뛰어 남의 주문 신청서까지 보류된다.
     *
     * @scenario order_read_scope=self, claim_scope=all, surface=bulk
     *
     * @effects claim_bulk_actions_are_bounded_by_order_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_is_bounded_by_the_order_read_scope(): void
    {
        $admin = $this->operator(ScopeType::Self, null);
        [, $own] = $this->approvedRefundOwnedBy($admin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $this->actingAs($admin)
            ->postJson($this->base().'/claims/bulk-status', [
                'type' => 'refund',
                'ids' => [$own->id, $foreign->id],
                'status' => 'on_hold',
            ])
            ->assertForbidden();

        $this->assertSame(RefundStatusEnum::APPROVED, $foreign->fresh()->refund_status);
        $this->assertSame(RefundStatusEnum::APPROVED, $own->fresh()->refund_status);
    }

    /**
     * 주문에 묶인 클레임 라우트(클레임 내역·취소 가능 금액·직권 접수 문맥)도 주문 조회 범위를 넘지 않는지 검증합니다.
     *
     * 수정 전: 미들웨어가 그 라우트의 클레임 권한 범위(전체)만 판정해 남의 주문으로도 200 이다.
     *
     * @scenario order_read_scope=self, claim_scope=all, surface=order_linked
     *
     * @effects order_linked_claim_routes_are_bounded_by_order_read_scope
     *
     * @return void 반환값 없음
     */
    public function test_order_linked_claim_routes_are_bounded_by_the_order_read_scope(): void
    {
        $admin = $this->operator(ScopeType::Self, null);
        [$foreignOrder, $foreignRefund] = $this->approvedRefundOwnedBy($this->customer->id);
        [$ownOrder, $ownRefund] = $this->approvedRefundOwnedBy($admin->id);

        $this->actingAs($admin)->getJson($this->base()."/orders/{$foreignOrder->id}/claims")->assertForbidden();
        $this->actingAs($admin)
            ->getJson($this->base()."/orders/{$foreignOrder->id}/cancellable-amount?refund_id={$foreignRefund->id}")
            ->assertForbidden();
        $this->actingAs($admin)->getJson($this->base()."/orders/{$foreignOrder->id}/claims/request-context")->assertForbidden();

        // 대조군 — 자기 주문은 그대로 열린다.
        $this->actingAs($admin)->getJson($this->base()."/orders/{$ownOrder->id}/claims")->assertOk();
        $this->actingAs($admin)
            ->getJson($this->base()."/orders/{$ownOrder->id}/cancellable-amount?refund_id={$ownRefund->id}")
            ->assertOk();
        $this->actingAs($admin)->getJson($this->base()."/orders/{$ownOrder->id}/claims/request-context")->assertOk();
    }

    /**
     * 주문 조회·클레임 모두 「전체」 인 운영자는 종전대로 모든 신청서를 보고 처리하는지 검증합니다. (회귀)
     *
     * @scenario order_read_scope=all, claim_scope=all, surface=list
     *
     * @effects unscoped_operator_keeps_full_claim_access
     *
     * @return void 반환값 없음
     */
    public function test_unscoped_operator_keeps_full_claim_access(): void
    {
        $admin = $this->operator(null, null);
        [, $own] = $this->approvedRefundOwnedBy($admin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $list = $this->refundList($admin);

        $this->assertContains($own->refund_number, $list['numbers']);
        $this->assertContains($foreign->refund_number, $list['numbers']);
        $this->assertSame(2, $list['count']);

        $this->actingAs($admin)->getJson($this->base()."/refunds/{$foreign->id}")->assertOk();
        $this->actingAs($admin)
            ->postJson($this->base().'/claims/bulk-status', [
                'type' => 'refund',
                'ids' => [$own->id, $foreign->id],
                'status' => 'on_hold',
            ])
            ->assertOk();

        $this->assertSame(RefundStatusEnum::ON_HOLD, $foreign->fresh()->refund_status);
    }

    /**
     * 주문 조회 「전체」 + 클레임 「본인만」 운영자는 종전대로 클레임 범위로 좁혀지는지 검증합니다. (회귀)
     *
     * 교집합이므로 더 좁은 쪽(클레임 「본인만」)이 그대로 이긴다.
     *
     * @scenario order_read_scope=all, claim_scope=self, surface=detail
     *
     * @effects claim_self_scope_still_narrows_under_wider_order_read
     *
     * @return void 반환값 없음
     */
    public function test_claim_self_scope_still_narrows_under_a_wider_order_read_scope(): void
    {
        $admin = $this->operator(null, ScopeType::Self);
        [, $own] = $this->approvedRefundOwnedBy($admin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $list = $this->refundList($admin);

        $this->assertSame([$own->refund_number], $list['numbers']);
        $this->assertSame(1, $list['count']);
        $this->actingAs($admin)->getJson($this->base()."/refunds/{$foreign->id}")->assertForbidden();
        $this->actingAs($admin)->getJson($this->base()."/refunds/{$own->id}")->assertOk();
    }

    /**
     * 주문 조회 권한이 없는 클레임 전담 운영자는 클레임 권한 범위만으로 판정되는지 검증합니다.
     *
     * 보유하지 않은 권한은 범위 진술이 아니다(코어 판정이 null = 제한 없음). 클레임 「전체」 면 전부,
     * 클레임 「본인만」 이면 본인 주문의 것만 보인다 — 종전 동작 그대로다.
     *
     * @scenario order_read_scope=none, claim_scope=all, surface=list
     *
     * @effects claim_only_operator_is_judged_by_claim_scope_alone
     *
     * @return void 반환값 없음
     */
    public function test_claim_only_operator_is_judged_by_the_claim_scope_alone(): void
    {
        $allClaims = $this->operator(false, null);
        $selfClaims = $this->operator(false, ScopeType::Self);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);
        [, $selfOwn] = $this->approvedRefundOwnedBy($selfClaims->id);

        $this->assertContains($foreign->refund_number, $this->refundList($allClaims)['numbers']);
        $this->actingAs($allClaims)->getJson($this->base()."/refunds/{$foreign->id}")->assertOk();

        $this->assertSame([$selfOwn->refund_number], $this->refundList($selfClaims)['numbers']);
        $this->actingAs($selfClaims)->getJson($this->base()."/refunds/{$foreign->id}")->assertForbidden();
    }
}
