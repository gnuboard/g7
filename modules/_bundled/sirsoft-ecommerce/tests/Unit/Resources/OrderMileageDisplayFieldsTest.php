<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Http\Resources\GuestOrderResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserOrderListResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 마일리지 표시 필드 정합성 테스트
 *
 * 이슈 #44 전수조사: 마일리지 시스템 도입 전 완성된 주문 화면들이
 * 기대하는 마일리지 사용/적립/환불 키를 백엔드 응답(Summary DTO / 목록 Resource)이
 * 실제로 노출하는지 검증한다. 누락 시 화면에 마일리지가 "--"/미표시로 회귀한다.
 */
class OrderMileageDisplayFieldsTest extends ModuleTestCase
{
    /**
     * Summary DTO 가 마일리지 사용액 포맷(points_used_formatted)을 노출하는지 확인
     *
     * 결함: 체크아웃 요약(_checkout_summary) line 332 가 points_used_formatted 를 바인딩하나
     * Summary::toArray() 가 points_used(값)만 만들고 포맷 키를 누락 → 사용액이 "--" 로 표시.
     */
    public function test_summary_exposes_points_used_formatted(): void
    {
        // Given: 사용 마일리지 3000, 결제전 252000, 최종 249000 인 합계
        $summary = new Summary(
            subtotal: 252000,
            pointsUsed: 3000,
            paymentAmount: 252000,
            finalAmount: 249000,
        );

        // When: 배열 직렬화
        $result = $summary->toArray();

        // Then: 사용액/결제예정액 포맷 키가 노출
        $this->assertArrayHasKey('points_used_formatted', $result);
        $this->assertEquals('3,000원', $result['points_used_formatted']);
        $this->assertArrayHasKey('payment_amount_formatted', $result);
        $this->assertEquals('252,000원', $result['payment_amount_formatted']);
        // 적립 포맷(P 단위)은 기존대로 유지
        $this->assertEquals('0P', $result['mileage_formatted']);
    }

    /**
     * 마일리지 미사용 주문도 points_used_formatted 가 0원으로 노출되는지 확인 (폴백 안전)
     */
    public function test_summary_points_used_formatted_zero_when_unused(): void
    {
        $summary = new Summary(subtotal: 100000, paymentAmount: 100000, finalAmount: 100000);

        $result = $summary->toArray();

        $this->assertEquals('0원', $result['points_used_formatted']);
    }

    /**
     * OrderListResource(관리자 목록)가 마일리지 사용/적립 필드를 노출하는지 확인
     */
    public function test_admin_order_list_resource_exposes_mileage_fields(): void
    {
        // Given: 마일리지 사용 5000, 적립 2400
        $order = OrderFactory::new()->create([
            'total_points_used_amount' => 5000,
            'total_earned_points_amount' => 2400,
        ]);

        // When: 목록 리소스 변환
        $resource = (new OrderListResource($order))->resolve();

        // Then: 사용/적립 + 포맷 노출
        $this->assertEquals(5000, $resource['total_points_used_amount']);
        $this->assertEquals('5,000원', $resource['total_points_used_amount_formatted']);
        $this->assertEquals(2400, $resource['total_earned_points_amount']);
        $this->assertEquals('2,400원', $resource['total_earned_points_amount_formatted']);
    }

    /**
     * UserOrderListResource(마이페이지 목록)가 마일리지 사용/적립 필드를 노출하는지 확인
     */
    public function test_user_order_list_resource_exposes_mileage_fields(): void
    {
        // Given: 마일리지 사용 1000, 적립 990
        $order = OrderFactory::new()->create([
            'total_points_used_amount' => 1000,
            'total_earned_points_amount' => 990,
        ]);
        $order->load('options');

        // When: 목록 리소스 변환
        $resource = (new UserOrderListResource($order))->resolve();

        // Then: 사용/적립 + 포맷 노출
        $this->assertEquals(1000, $resource['total_points_used_amount']);
        $this->assertEquals('1,000원', $resource['total_points_used_amount_formatted']);
        $this->assertEquals(990, $resource['total_earned_points_amount']);
        $this->assertEquals('990원', $resource['total_earned_points_amount_formatted']);
    }

    /**
     * GuestOrderResource(비회원 주문상세)가 마일리지 사용/적립/환불 필드를 노출하는지 확인
     *
     * 결함: 비회원 주문상세는 회원과 동일 결제정보 partial 을 쓰나 GuestOrderResource 가
     * 마일리지 필드를 전혀 노출하지 않아 비회원 화면에 마일리지가 미표시.
     */
    public function test_guest_order_resource_exposes_mileage_and_refund_fields(): void
    {
        // Given: 사용 2000, 적립 1500, 환불 마일리지 800 인 주문 + 옵션 1개
        $order = OrderFactory::new()->create([
            'total_points_used_amount' => 2000,
            'total_earned_points_amount' => 1500,
            'total_refunded_points_amount' => 800,
        ]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'unit_price' => 10000,
            'quantity' => 1,
            'subtotal_price' => 10000,
        ]);
        $order->load('options');

        // When: 비회원 리소스 변환
        $resource = (new GuestOrderResource($order))->resolve();

        // Then: 사용/적립/환불 마일리지 + 포맷 노출 (회원 OrderResource 와 패리티)
        $this->assertEquals(2000, $resource['total_points_used_amount']);
        $this->assertEquals('2,000원', $resource['total_points_used_amount_formatted']);
        $this->assertEquals(1500, $resource['total_earned_points_amount']);
        $this->assertEquals('1,500원', $resource['total_earned_points_amount_formatted']);
        $this->assertEquals(800, $resource['total_refunded_points_amount']);
        $this->assertEquals('800원', $resource['total_refunded_points_amount_formatted']);
    }
}
