<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ClaimTrackingGroups::overdueOf() 의 회수 지연 일수 테스트
 *
 * 지연 일수는 알림(`overdue_days`)·관리자 목록·사용자 상세가 함께 쓰는 값이다 — 사이트 날짜로 센다(#160).
 */
class ClaimTrackingGroupsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정과 고정 시각을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 교환품을 먼저 보낸(발송중) 선교환을 만듭니다.
     *
     * @return OrderExchange 발송중 선교환
     */
    private function redeliveringAdvance(): OrderExchange
    {
        $service = app(OrderExchangeService::class);
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);

        return $service->transition($exchange->fresh(), ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'TZ-OVERDUE-1',
        ])->fresh(['exchangeOptions']);
    }

    /**
     * 기한 서울 11-03 23:00, 지금 서울 11-05 01:00 → 사이트 날짜로 2일 지연.
     *
     * UTC 날짜(11-03 → 11-04)로 세면 1일이 나와 알림·화면이 하루 적게 말한다.
     *
     * @scenario surface=overdue_days
     *
     * @effects overdue_days_count_site_days
     *
     * @return void 반환값 없음
     */
    public function test_overdue_days_are_counted_in_site_days(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
        $exchange = $this->redeliveringAdvance();
        $exchange->forceFill(['collection_due_at' => Carbon::parse('2026-11-03 14:00:00', 'UTC')])->save();

        Carbon::setTestNow(Carbon::parse('2026-11-04 16:00:00', 'UTC'));
        $overdue = ClaimTrackingGroups::overdueOf($exchange->fresh(['exchangeOptions']));

        $this->assertTrue($overdue['is_overdue']);
        $this->assertSame(2, $overdue['days']);
    }
}
