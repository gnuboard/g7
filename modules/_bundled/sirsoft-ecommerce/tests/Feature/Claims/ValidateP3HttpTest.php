<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Support\GuestRoleResolver;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\AdminUserAddressController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ClaimImageDownloadController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\ClaimController as UserClaimController;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use RuntimeException;

/**
 * HTTP 경계의 권한·요청 빈도·오류 노출·응답 문구 결함 수정 검증 (#599 validate P3 — 06 #2·#3·#5·#6·#9·minor)
 *
 * 검증 축:
 * - 비회원 클레임 라우트가 guest 역할의 `user-claims.*` 권한을 판정한다(#3).
 * - 이름 있는 리미터로 비회원 클레임 블록·첨부 업로드·조회 인증의 카운터가 갈린다(#2).
 * - 공개 면 응답에 PG 원문이 실리지 않고, 관리자 면 500 에는 원인 요약이 실린다(#5 · minor).
 * - 관리자 [주문취소] 응답 문구가 신청서 접수와 실제 취소를 가른다(#6).
 * - 회원 컨트롤러가 주문 Repository 를 들지 않고, 주문 스코프 라우트가 숫자 id 만 받는다(#9 · minor).
 * - 사유 카탈로그가 클레임 권한으로도 열리고, 공개 서빙이 회원 컨트롤러에서 분리된다(minor).
 */
class ValidateP3HttpTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    /**
     * 통화·사유를 심고 PG 환불 훅을 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        GuestRoleResolver::flush();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);
    }

    /**
     * 통화 설정과 guest 역할 캐시를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        GuestRoleResolver::flush();
        parent::tearDown();
    }

    /**
     * 비회원 주문과 유효 조회 토큰을 만듭니다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @return array{0: Order, 1: string} [주문, 토큰]
     */
    protected function guestOrderWithToken(OrderStatusEnum $status = OrderStatusEnum::DELIVERED): array
    {
        $order = $status === OrderStatusEnum::DELIVERED ? $this->deliveredClaimOrder() : $this->paidClaimOrder();
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::PHONE]);

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.9')['token'];

        return [$order->fresh(['options', 'shippingAddress']), $token];
    }

    /**
     * 비회원 주문 API 접두 경로를 돌려줍니다.
     *
     * @param  Order  $order  대상 주문
     * @return string API 접두 경로
     */
    protected function guestBase(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}";
    }

    /**
     * guest 역할에서 권한 하나를 뺍니다. (블랙컨슈머 차단용 축)
     *
     * @param  string  $identifier  권한 식별자
     * @return void 반환값 없음
     */
    protected function revokeGuestPermission(string $identifier): void
    {
        $permission = Permission::where('identifier', $identifier)->firstOrFail();
        Role::where('identifier', 'guest')->firstOrFail()->permissions()->detach($permission->id);
        GuestRoleResolver::flush();
    }

    /**
     * guest 역할에서 클레임 권한을 빼면 비회원 클레임 창구도 닫히는지 검증합니다.
     *
     * 수정 전: 비회원 클레임 라우트에 `permission:user,user-claims.*` 가 없어 권한을 빼도 200 이다.
     *
     * @scenario defect=guest_claim_routes_honor_guest_role_claim_permissions
     *
     * @effects guest_claim_routes_honor_guest_role_claim_permissions
     *
     * @return void 반환값 없음
     */
    public function test_guest_claim_routes_honor_the_guest_role_permissions(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->guestBase($order).'/claimable-options', ['X-Guest-Order-Token' => $token])->assertOk();

        $this->revokeGuestPermission('sirsoft-ecommerce.user-claims.read');
        $this->revokeGuestPermission('sirsoft-ecommerce.user-claims.request');

        $this->getJson($this->guestBase($order).'/claimable-options', ['X-Guest-Order-Token' => $token])->assertUnauthorized();
        $this->postJson($this->guestBase($order).'/returns', [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ], ['X-Guest-Order-Token' => $token])->assertUnauthorized();
    }

    /**
     * 비회원 클레임 블록의 요청이 첨부 업로드·조회 인증의 상한을 소모하지 않는지 검증합니다.
     *
     * 수정 전: 이름 없는 `throttle:N,1` 셋이 IP 하나를 키로 한 카운터를 나눠 써, 목록을 20번 부른 뒤
     * 업로드·조회 인증이 429 가 된다.
     *
     * @scenario defect=guest_claim_rate_limit_counters_are_separated
     *
     * @effects guest_claim_rate_limit_counters_are_separated
     *
     * @return void 반환값 없음
     */
    public function test_guest_claim_block_requests_do_not_consume_other_limits(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        for ($i = 0; $i < 21; $i++) {
            $this->getJson($this->guestBase($order).'/claims', ['X-Guest-Order-Token' => $token])->assertOk();
        }

        // 파일 없이 부르면 검증 422 — 요점은 429 가 아니라는 것이다.
        $upload = $this->postJson($this->guestBase($order).'/claims/images', [], ['X-Guest-Order-Token' => $token]);
        $this->assertNotSame(429, $upload->getStatusCode(), '블록 요청이 업로드 상한을 소모했다');

        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => 'NO_SUCH_ORDER',
            'orderer_phone' => self::PHONE,
            'guest_lookup_password' => 'guest1234',
        ]);
        $this->assertNotSame(429, $verify->getStatusCode(), '블록 요청이 조회 인증 상한을 소모했다');
    }

    /**
     * 비회원 취소·환불 신청이 PG 거절로 막힐 때 응답에 PG 원문이 실리지 않는지 검증합니다.
     *
     * 수정 전: `errors.detail` 에 PG 가 돌려준 원문(`PG_RAW_SECRET`)이 그대로 실린다.
     *
     * @scenario defect=public_claim_errors_hide_pg_raw_message
     *
     * @effects public_claim_errors_hide_pg_raw_message
     *
     * @return void 반환값 없음
     */
    public function test_public_refund_request_does_not_expose_the_pg_raw_message(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => false, 'transaction_id' => null, 'error_code' => 'E999', 'error_message' => 'PG_RAW_SECRET',
        ], 100);

        [$order, $token] = $this->guestOrderWithToken(OrderStatusEnum::PAYMENT_COMPLETE);

        $response = $this->postJson($this->guestBase($order).'/refund-requests', [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ], ['X-Guest-Order-Token' => $token]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('PG_RAW_SECRET', $response->getContent());
    }

    /**
     * 관리자 클레임 면의 500 응답에는 원인 요약이 실리고, 공개 면에는 실리지 않는지 검증합니다.
     *
     * 수정 전: 관리자 면도 공개 면과 같은 매핑을 써 `errors` 가 비어 있다(운영자는 서버 로그 없이 조치 불가).
     *
     * @scenario defect=admin_claim_server_errors_carry_detail
     *
     * @effects admin_claim_server_errors_carry_detail
     *
     * @return void 반환값 없음
     */
    public function test_admin_claim_server_error_carries_detail_but_public_does_not(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->mock(AdminClaimService::class, function ($mock) {
            $mock->shouldReceive('findForDetailByNumber')->andThrow(new RuntimeException('admin-boom-detail'));
        });

        $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/claims/RF-ANY')
            ->assertStatus(500)
            ->assertJsonPath('errors.detail', 'admin-boom-detail');

        $user = $this->createUser();
        $this->mock(UserClaimService::class, function ($mock) {
            $mock->shouldReceive('findOwned')->andThrow(new RuntimeException('public-boom-detail'));
        });

        $public = $this->actingAs($user)->getJson('/api/modules/sirsoft-ecommerce/user/claims/RF-ANY');
        $public->assertStatus(500);
        $this->assertStringNotContainsString('public-boom-detail', $public->getContent());
    }

    /**
     * 관리자 [주문취소]가 환불신청서만 만들었을 때 "주문이 취소되었습니다" 가 아니라 접수 문구로 답하는지 검증합니다.
     *
     * 수정 전: 자동 전이를 끈 기본 운영에서도 `messages.orders.cancelled` 로 답한다.
     *
     * @scenario defect=admin_cancel_message_distinguishes_request_from_cancellation
     *
     * @effects admin_cancel_message_distinguishes_request_from_cancellation
     *
     * @return void 반환값 없음
     */
    public function test_admin_cancel_message_distinguishes_refund_request_from_cancellation(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->paidClaimOrder();

        $response = $this->actingAs($admin)->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/cancel", [
            'type' => 'full',
            'reason' => 'changed_mind',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.claim.cancelled', false)
            ->assertJsonPath('message', __('sirsoft-ecommerce::messages.orders.cancel_refund_requested'));
    }

    /**
     * 관리자 [주문취소]가 자동 전이로 실제 취소까지 갔을 때는 취소 문구로 답하는지 검증합니다. (대조군)
     *
     * @scenario defect=admin_cancel_message_distinguishes_request_from_cancellation
     *
     * @effects admin_cancel_message_distinguishes_request_from_cancellation
     *
     * @return void 반환값 없음
     */
    public function test_admin_cancel_message_reports_cancellation_when_completed(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update', 'sirsoft-ecommerce.claims.execute-refund']);
        $order = $this->paidClaimOrder();

        $this->actingAs($admin)->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/cancel", [
            'type' => 'full',
            'reason' => 'changed_mind',
            'auto_advance_refund' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.claim.cancelled', true)
            ->assertJsonPath('message', __('sirsoft-ecommerce::messages.orders.cancelled'));
    }

    /**
     * 회원 주문 스코프 클레임 목록이 숫자 id 만 받는지 검증합니다.
     *
     * 수정 전: `whereNumber('id')` 가 없어 `abc` 가 `int $id` 로 들어가다 500 이 된다.
     *
     * @scenario defect=user_order_claims_route_accepts_numeric_ids_only
     *
     * @effects user_order_claims_route_accepts_numeric_ids_only
     *
     * @return void 반환값 없음
     */
    public function test_user_order_claims_route_rejects_non_numeric_ids(): void
    {
        $this->actingAs($this->createUser())
            ->getJson('/api/modules/sirsoft-ecommerce/user/orders/abc/claims')
            ->assertNotFound();
    }

    /**
     * 회원 컨트롤러가 주문 Repository 를 직접 들지 않고, 남의 주문은 여전히 404 인지 검증합니다.
     *
     * 수정 전: 생성자가 `OrderRepositoryInterface` 를 받고 `user_id` 비교를 두 액션이 각자 한다.
     *
     * @scenario defect=user_claim_order_ownership_is_judged_in_one_place
     *
     * @effects user_claim_order_ownership_is_judged_in_one_place
     *
     * @return void 반환값 없음
     */
    public function test_user_claim_controller_delegates_order_ownership(): void
    {
        $params = array_map(
            fn (\ReflectionParameter $p) => (string) $p->getType(),
            (new \ReflectionMethod(UserClaimController::class, '__construct'))->getParameters(),
        );
        $this->assertNotContains(OrderRepositoryInterface::class, $params);

        $owner = $this->createUser();
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $owner->id])->save();

        $this->actingAs($this->createUser())
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->assertNotFound();
        $this->actingAs($this->createUser())
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claims")
            ->assertNotFound();
        $this->actingAs($owner)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->assertOk();
    }

    /**
     * 클레임 권한만 가진 운영자도 사유 카탈로그(Select 선택지)를 읽을 수 있는지 검증합니다.
     *
     * 수정 전: `settings.read` 만 받아 클레임 목록 진입마다 403 이 난다.
     *
     * @scenario defect=claim_reason_catalog_opens_to_claim_readers
     *
     * @effects claim_reason_catalog_opens_to_claim_readers
     *
     * @return void 반환값 없음
     */
    public function test_claim_readers_can_read_the_active_reason_catalog(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/claim-reasons/active?type=refund')
            ->assertOk();
    }

    /**
     * 로그인 없이 열리는 첨부 서빙이 회원 전용 컨트롤러에서 분리됐는지, 관리자 배송지 목록 컨트롤러가
     * 필터 해석 규칙을 들지 않는지 검증합니다.
     *
     * 수정 전: 서빙이 `User\ClaimImageController::download` 에 있고, 배송지 컨트롤러가 코어 사용자
     * Repository 를 직접 들고 uuid 해석을 한다.
     *
     * @scenario defect=public_download_and_admin_filters_live_outside_controllers
     *
     * @effects public_download_and_admin_filters_live_outside_controllers
     *
     * @return void 반환값 없음
     */
    public function test_download_is_served_by_a_public_controller_and_address_filters_live_in_the_service(): void
    {
        $route = Route::getRoutes()->getByName('api.modules.sirsoft-ecommerce.claim-image.download');
        $this->assertNotNull($route);
        $this->assertSame(ClaimImageDownloadController::class.'@download', $route->getActionName());

        $params = array_map(
            fn (\ReflectionParameter $p) => (string) $p->getType(),
            (new \ReflectionMethod(AdminUserAddressController::class, '__construct'))->getParameters(),
        );
        $this->assertNotContains(UserRepositoryInterface::class, $params);
    }
}
