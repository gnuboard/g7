<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 신청서 리소스 — 신청 출처 3분류 (계획 676 · 출처 뱃지)
 *
 * 운영자는 셋을 다르게 대한다 — 고객 건은 연락처가 계정에 있고, 비회원 건은 주문서에 적힌
 * 연락처뿐이며, **운영자 대리 접수 건은 고객이 철회·수정할 수 없다.**
 *
 * **`requested_by` 하나로는 가를 수 없다** — 비회원 신청과 운영자 대리 접수가 **똑같이 null**
 * 이다(`RefundSourceTypeEnum` 독블록 §4-3-1). 그 값으로 가르면 회원 주문의 대리 접수 건이
 * 「비회원」으로 표시돼 운영자가 연락 수단을 잘못 고른다. 그래서 두 축을 쓴다 —
 * `refund_source_type=admin` 이 먼저고, 나머지는 **주문의 주인**이 가른다.
 */
class ClaimResourceRequestSourceTest extends ModuleTestCase
{
    /**
     * 리소스 배열로 변환합니다. (사유 라벨은 주입해 DB 조회를 태우지 않는다)
     *
     * @param  OrderRefund|OrderReturn  $claim  신청서
     * @return array<string, mixed> 응답 배열
     */
    private function toArray(OrderRefund|OrderReturn $claim): array
    {
        return (new ClaimResource($claim))
            ->withReasonLabels([])
            ->toArray(Request::create('/'));
    }

    /**
     * 관리자 확장 필드까지 실은 배열로 변환합니다. (요청자 링크 축)
     *
     * @param  OrderRefund|OrderReturn  $claim  신청서
     * @return array<string, mixed> 응답 배열
     */
    private function toAdminArray(OrderRefund|OrderReturn $claim): array
    {
        return (new ClaimResource($claim))
            ->withReasonLabels([])
            ->withAdminFields()
            ->toArray(Request::create('/'));
    }

    /**
     * 운영자 대리 접수 환불신청서를 만듭니다. (`OrderRefund` 는 팩토리가 없다)
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $number  환불번호
     * @return OrderRefund 신청서
     */
    private function adminFiledRefund(int $orderId, string $number): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $orderId,
            'refund_number' => $number,
            'refund_status' => 'requested',
            'refund_method' => 'bank',
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
            // 운영자가 대신 낸 신청서다 — `requested_by` 는 비회원 신청과 똑같이 null 이다.
            'requested_by' => null,
            'refund_source_type' => RefundSourceTypeEnum::ADMIN,
            'items_snapshot' => [],
        ]);
    }

    /**
     * 회원 주문의 고객 신청은 `customer` 다.
     *
     * @effects request_source_splits_customer_guest_and_admin
     */
    public function test_member_order_customer_request_is_customer(): void
    {
        $member = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $member->id]);
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id, 'requested_by' => $member->id]);

        $this->assertSame('customer', $this->toArray($return->fresh(['order']))['request_source']);
    }

    /**
     * 비회원 주문의 신청은 `guest` 다 — 판정은 **주문의 주인**이 한다.
     *
     * @effects request_source_splits_customer_guest_and_admin
     */
    public function test_guest_order_request_is_guest(): void
    {
        $order = OrderFactory::new()->create(['user_id' => null]);
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id, 'requested_by' => null]);

        $this->assertSame('guest', $this->toArray($return->fresh(['order']))['request_source']);
    }

    /**
     * **회원 주문**의 운영자 대리 접수는 `admin` 이다 — `guest` 가 아니다.
     *
     * 이 축이 이 스위트의 핵심이다. `requested_by` 는 비회원 신청과 똑같이 null 이라,
     * 그 값만 보면 **회원 주문인데 「비회원 신청」으로 표시된다.**
     *
     * @scenario refund_source=admin
     *
     * @effects request_source_splits_customer_guest_and_admin
     */
    public function test_admin_filed_refund_on_member_order_is_admin_not_guest(): void
    {
        $member = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $member->id]);
        $refund = $this->adminFiledRefund($order->id, 'RF-SRC-MEMBER');

        $this->assertSame('admin', $this->toArray($refund->fresh(['order']))['request_source']);
    }

    /**
     * **비회원 주문**의 운영자 대리 접수도 `admin` 이다 — 대리 접수가 비회원 판정을 이긴다.
     *
     * 되돌림 축: `admin` 검사를 뒤로 미루면 이 건이 `guest` 가 되어 red. 운영자는 자기가 낸
     * 신청서를 「비회원이 낸 것」으로 읽고, 철회할 수 있다고 오해한다.
     *
     * @scenario refund_source=admin
     *
     * @effects request_source_splits_customer_guest_and_admin
     */
    public function test_admin_filed_refund_on_guest_order_is_still_admin(): void
    {
        $order = OrderFactory::new()->create(['user_id' => null]);
        $refund = $this->adminFiledRefund($order->id, 'RF-SRC-GUEST');

        $this->assertSame('admin', $this->toArray($refund->fresh(['order']))['request_source']);
    }

    /**
     * 회원 주문이면 **요청자 링크 대상**이 그 주문의 주인이다. (계획 676 「요청자 링크」)
     *
     * 화면은 `requester_user_id` 가 있을 때만 이름을 링크로 그린다. 이 값이 없으면 링크가
     * 사라지는 게 아니라 **평문 이름**이 대신 선다 — 누를 곳 없는 링크를 보이면 운영자는
     * 회원이 있다고 오해한다.
     *
     * @effects requester_link_targets_the_order_owner_not_the_filer
     */
    public function test_member_order_exposes_the_order_owner_as_link_target(): void
    {
        $member = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $member->id]);
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id, 'requested_by' => $member->id]);

        $this->assertSame($member->id, $this->toAdminArray($return->fresh(['order']))['requester_user_id']);
    }

    /**
     * **운영자 대리 접수 건도 주문의 주인**으로 간다 — 접수자(`requested_by`)가 아니다.
     *
     * 이 축이 이 두 테스트의 핵심이다. 대리 접수 건은 `requested_by` 가 null 이라(위 출처
     * 축과 같은 근거), 링크를 그 값으로 걸면 **회원 주문인데 링크가 사라진다.** 운영자가
     * 대신 낸 신청서일수록 그 회원 화면으로 갈 일이 많은데, 하필 그때 길이 끊긴다.
     * 되돌림 축: `requested_by` 로 바꾸면 이 테스트가 null 을 받아 red.
     *
     * 실측 대조(2026-09-16): `RF20260915-1707536717` = `requested_by` NULL · 주문 766 의
     * `user_id` 3 — 접수자 축으로 걸었다면 이 실제 신청서에서 링크가 없었다.
     *
     * @scenario refund_source=admin
     *
     * @effects requester_link_targets_the_order_owner_not_the_filer
     */
    public function test_admin_filed_refund_still_links_to_the_member_who_owns_the_order(): void
    {
        $member = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $member->id]);
        $refund = $this->adminFiledRefund($order->id, 'RF-LINK-ADMIN');

        $data = $this->toAdminArray($refund->fresh(['order']));

        $this->assertNull($refund->requested_by, '대리 접수 건의 접수자 축은 null 이다 — 링크의 근거가 될 수 없다');
        $this->assertSame($member->id, $data['requester_user_id']);
    }

    /**
     * 비회원 주문은 링크 대상이 **없다** — 화면은 평문 이름을 그린다.
     *
     * 이 축이 없으면 「항상 주문의 user_id 를 싣는」 구현과 「아무 값이나 싣는」 구현이
     * 구분되지 않는다.
     *
     * @effects requester_link_targets_the_order_owner_not_the_filer
     */
    public function test_guest_order_has_no_link_target(): void
    {
        $order = OrderFactory::new()->create(['user_id' => null]);
        $return = OrderReturnFactory::new()->create(['order_id' => $order->id, 'requested_by' => null]);

        $data = $this->toAdminArray($return->fresh(['order']));

        $this->assertNull($data['requester_user_id']);
        $this->assertArrayHasKey('requester_name', $data, '링크가 없어도 이름은 그린다');
    }
}
