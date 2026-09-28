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
 * 관리자 클레임의 권한 스코프가 주문 스코프를 물려받는지 검증합니다. (#599 validate P3 #4)
 *
 * 신청서는 한 주문에 속하고, 그 주문을 볼 수 없는 운영자가 신청서로는 같은 고객의 주문 내용·환불
 * 계좌를 보거나 환불을 실행할 수 있으면 주문 스코프가 우회된다. 수정 전에는 `claims` 권한에 스코프
 * 축(`resource_route_key`·`owner_key`)이 없어 `scope_type = self` 를 걸어도 아무 효과가 없었다.
 *
 * 검증 축: 권한 정의 · 목록/건수 필터 · 상세/로그 · 전이·환불 실행 · 일괄 전이 · 주문 연계 라우트.
 */
class ValidateP3ClaimScopeTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /** 스코프를 거는 클레임 권한 식별자 */
    private const CLAIM_PERMISSIONS = [
        'sirsoft-ecommerce.claims.read',
        'sirsoft-ecommerce.claims.update-status',
        'sirsoft-ecommerce.claims.update-amounts',
        'sirsoft-ecommerce.claims.execute-refund',
    ];

    protected User $scopedAdmin;

    protected User $customer;

    /**
     * 통화·사유를 심고, 클레임 권한 행에 모듈 선언의 스코프 축을 반영한 뒤 `self` 스코프 운영자를 만듭니다.
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
            'success' => true, 'transaction_id' => 'PGTXN-P3', 'error_code' => null, 'error_message' => null,
        ]);

        $this->scopedAdmin = $this->createAdminUser(self::CLAIM_PERMISSIONS);
        $this->customer = $this->createUser();

        // 권한 행의 스코프 축은 **모듈 선언에서** 옮긴다 — 선언이 비어 있으면(수정 전) 축도 비어
        // 스코프가 아무것도 막지 않는다. 테스트가 축을 직접 박으면 선언 누락을 보지 못한다.
        $declared = $this->declaredClaimScopeAxis();
        $role = $this->scopedAdmin->roles()->first();

        foreach (self::CLAIM_PERMISSIONS as $identifier) {
            $permission = Permission::where('identifier', $identifier)->firstOrFail();
            $permission->update($declared);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['scope_type' => ScopeType::Self]]);
        }

        $this->clearPermissionCache();
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
     * 모듈 선언(`module.php`)의 `claims` 권한 카테고리 스코프 축을 읽습니다.
     *
     * @return array{resource_route_key: ?string, owner_key: ?string} 선언된 축
     */
    protected function declaredClaimScopeAxis(): array
    {
        foreach ((new Module)->getPermissions()['categories'] ?? [] as $category) {
            if (($category['identifier'] ?? null) === 'claims') {
                return [
                    'resource_route_key' => $category['resource_route_key'] ?? null,
                    'owner_key' => $category['owner_key'] ?? null,
                ];
            }
        }

        return ['resource_route_key' => null, 'owner_key' => null];
    }

    /**
     * 권한 스코프 정적 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function clearPermissionCache(): void
    {
        $property = new \ReflectionProperty(PermissionHelper::class, 'permissionCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
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
     * 모듈이 `claims` 권한에 주문과 같은 스코프 축을 선언하는지 검증합니다.
     *
     * 수정 전: `claims` 카테고리에 `resource_route_key`·`owner_key` 가 없어 null 이 돌아온다.
     *
     * @scenario defect=claims_permission_inherits_order_scope_axis
     *
     * @effects claims_permission_inherits_order_scope_axis
     *
     * @return void 반환값 없음
     */
    public function test_claims_permission_declares_the_order_scope_axis(): void
    {
        $this->assertSame(
            ['resource_route_key' => 'order', 'owner_key' => 'user_id'],
            $this->declaredClaimScopeAxis(),
        );
    }

    /**
     * `self` 스코프 운영자의 클레임 목록·건수에 스코프 밖 주문의 신청서가 나오지 않는지 검증합니다.
     *
     * 수정 전: 목록 저장소가 스코프 조건을 걸지 않아 남의 주문 신청서까지 2건이 모두 나온다.
     *
     * @scenario defect=claim_list_is_filtered_by_order_scope
     *
     * @effects claim_list_is_filtered_by_order_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_list_excludes_claims_of_out_of_scope_orders(): void
    {
        [, $own] = $this->approvedRefundOwnedBy($this->scopedAdmin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $response = $this->actingAs($this->scopedAdmin)->getJson($this->base().'/claims?type=refund')->assertOk();

        $numbers = array_column($response->json('data.data'), 'claim_number');

        $this->assertContains($own->refund_number, $numbers);
        $this->assertNotContains($foreign->refund_number, $numbers, '스코프 밖 주문의 신청서가 목록에 나왔다');
        $this->assertSame(1, $response->json('data.counts.refund'));
    }

    /**
     * `self` 스코프 운영자가 스코프 밖 주문의 신청서 상세·번호 상세·로그를 열 수 없는지 검증합니다.
     *
     * 수정 전: `{claimType}/{id}`·번호 경로는 모델 바인딩이 없어 미들웨어가 스코프를 건너뛰고 200 이다.
     *
     * @scenario defect=claim_detail_is_guarded_by_order_scope
     *
     * @effects claim_detail_is_guarded_by_order_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_cannot_open_an_out_of_scope_claim(): void
    {
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);
        [, $own] = $this->approvedRefundOwnedBy($this->scopedAdmin->id);

        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/refunds/{$foreign->id}")->assertForbidden();
        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/claims/{$foreign->refund_number}")->assertForbidden();
        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/claims/{$foreign->refund_number}/logs")->assertForbidden();

        // 대조군 — 자기 스코프 안 신청서는 그대로 열린다.
        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/refunds/{$own->id}")->assertOk();
    }

    /**
     * `self` 스코프 운영자가 스코프 밖 신청서의 환불을 실행할 수 없는지 검증합니다.
     *
     * 수정 전: 실행 경로에 스코프 판정이 없어 남의 주문 환불이 실행된다(상태가 completed 로 바뀐다).
     *
     * @scenario defect=claim_actions_are_guarded_by_order_scope
     *
     * @effects claim_actions_are_guarded_by_order_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_cannot_execute_an_out_of_scope_refund(): void
    {
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $this->actingAs($this->scopedAdmin)
            ->postJson($this->base()."/refunds/{$foreign->id}/execute")
            ->assertForbidden();

        $this->assertSame(RefundStatusEnum::APPROVED, $foreign->fresh()->refund_status);
    }

    /**
     * 일괄 전이에 스코프 밖 신청서가 섞이면 전량 거절되는지 검증합니다.
     *
     * 수정 전: 일괄 경로는 모델 바인딩이 없어 스코프 판정 없이 전이한다.
     *
     * @scenario defect=claim_bulk_actions_are_guarded_by_order_scope
     *
     * @effects claim_bulk_actions_are_guarded_by_order_scope
     *
     * @return void 반환값 없음
     */
    public function test_scoped_admin_bulk_status_rejects_out_of_scope_claims(): void
    {
        [, $own] = $this->approvedRefundOwnedBy($this->scopedAdmin->id);
        [, $foreign] = $this->approvedRefundOwnedBy($this->customer->id);

        $this->actingAs($this->scopedAdmin)
            ->postJson($this->base().'/claims/bulk-status', [
                'type' => 'refund',
                'ids' => [$own->id, $foreign->id],
                'status' => 'rejected',
                'reject_reason' => 'scope test',
            ])
            ->assertForbidden();

        $this->assertSame(RefundStatusEnum::APPROVED, $foreign->fresh()->refund_status);
    }

    /**
     * 주문 연계 라우트(`orders/{order}/claims`)가 클레임 권한의 스코프로 판정되는지 검증합니다.
     *
     * 수정 전: `claims.read` 에 `resource_route_key` 가 없어 미들웨어가 스코프 판정을 건너뛰고 200 이다.
     *
     * @scenario defect=order_linked_claim_routes_follow_order_scope
     *
     * @effects order_linked_claim_routes_follow_order_scope
     *
     * @return void 반환값 없음
     */
    public function test_order_linked_claim_routes_follow_the_order_scope(): void
    {
        [$foreignOrder] = $this->approvedRefundOwnedBy($this->customer->id);
        [$ownOrder] = $this->approvedRefundOwnedBy($this->scopedAdmin->id);

        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/orders/{$foreignOrder->id}/claims")->assertForbidden();
        $this->actingAs($this->scopedAdmin)->getJson($this->base()."/orders/{$ownOrder->id}/claims")->assertOk();
    }
}
