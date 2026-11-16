<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 재결제 복구 특성화 시나리오 — 녹화(결제사 현행 코드)와 대조(결제 계약)가 같은 사전 상태를 쓴다 (#916).
 *
 * 사전 상태는 실제 흐름(결제 실패 처리 → 결제 취소 이력)으로 만들고, 결제사 실패 기록만 각 결제사의 현행 모양대로 쓴다.
 */
final class RetryRestoreScenarios
{
    /** 실패 기록 시각 */
    public const FAILED_AT = '2026-11-15 12:00:00';

    /** 복구 시각 */
    public const RESTORED_AT = '2026-11-15 12:01:00';

    /** 녹화 파일 디렉터리 */
    public const FIXTURE_DIR = __DIR__.'/../../../Fixtures/payment-retry-restore';

    /**
     * 시나리오 목록(결제사 id => 시나리오 이름 목록)을 돌려줍니다.
     *
     * @return array<string, array<int, string>> 시나리오
     */
    public static function all(): array
    {
        return [
            'nhnkcp' => ['single', 'history_full', 'blank_values'],
            'nicepayments' => ['single', 'history_full', 'blank_values', 'failed_status'],
        ];
    }

    /**
     * 시나리오 사전 상태를 만들고 주문을 돌려줍니다(복구 시각으로 시계를 옮겨 둔다).
     *
     * @param  string  $pg  결제사 id
     * @param  string  $scenario  시나리오 이름
     * @return Order 결제 실패로 취소된 주문
     */
    public static function prepare(string $pg, string $scenario): Order
    {
        Carbon::setTestNow(self::FAILED_AT);

        $orderMeta = ['temp_order_id' => 9001];
        $paymentMeta = ['mid' => 'testmid', 'is_test_mode' => true];

        if ($scenario === 'history_full') {
            $history = [];
            for ($i = 1; $i <= 5; $i++) {
                $history[] = ['code' => 'OLD_'.$i, 'message' => '이전 실패 '.$i, 'failed_at' => '2026-11-0'.$i.'T00:00:00+09:00'];
            }
            // 기존 키가 중간에 있으면 그 자리를 지켜야 한다(키 순서 보존)
            $orderMeta = ['temp_order_id' => 9001, "{$pg}_retry_failures" => $history, "{$pg}_retry_count" => 5, 'keep_after' => '유지'];
            $paymentMeta = ['mid' => 'testmid', 'retry_count' => 2, 'retry_started_at' => '2026-11-05T00:00:00+09:00', 'is_test_mode' => true];
        }

        $order = OrderFactory::new()->create([
            'order_number' => strtoupper("RT-{$pg}-{$scenario}"),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'order_meta' => $orderMeta,
            'paid_at' => null,
        ]);
        OrderOptionFactory::new()->forOrder($order)->count(2)->create([
            'option_status' => OrderStatusEnum::PENDING_ORDER,
            'cancelled_quantity' => 0,
        ]);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'pg_provider' => $pg,
            'payment_status' => PaymentStatusEnum::READY,
            'transaction_id' => $scenario === 'blank_values' ? '' : null,
            'card_approval_number' => $scenario === 'blank_values' ? '' : null,
            'paid_at' => null,
            'cancelled_at' => null,
            'cancelled_amount' => 0,
            'mc_cancelled_amount' => null,
            'payment_meta' => $paymentMeta,
        ]);

        // 실제 흐름: 결제 실패 처리(주문 취소, 상품 취소 동기화, 실패 meta) → 결제 취소 이력
        $service = app(OrderProcessingService::class);
        $order = $service->failPayment($order->fresh(['payment']), 'USER_CANCEL', '사용자가 결제창을 닫았습니다.');
        $order = $service->recordPaymentCancellation($order->fresh(['payment']), 'USER_CANCEL', '닫힘 사유');

        self::writeProviderFailure($order, $pg, $scenario);

        if ($scenario === 'blank_values') {
            // 주문 meta 실패 메시지가 빈 문자열이면 결제 meta 값으로 넘어가지 않고 이력에서 빠진다(현행 `??` 의미)
            $meta = $order->fresh()->order_meta;
            $meta['payment_failure_message'] = '';
            DB::table($order->getTable())->where('id', $order->id)->update(['order_meta' => json_encode($meta)]);
        }

        Carbon::setTestNow(self::RESTORED_AT);

        return Order::query()->with('payment')->findOrFail($order->id);
    }

    /**
     * 결제사 현행 실패 기록 모양대로 결제 행을 씁니다.
     *
     * @param  Order  $order  주문
     * @param  string  $pg  결제사 id
     * @param  string  $scenario  시나리오 이름
     * @return void 반환값 없음
     */
    private static function writeProviderFailure(Order $order, string $pg, string $scenario): void
    {
        $payment = $order->fresh(['payment'])->payment;
        $meta = $payment->payment_meta ?? [];
        $now = now()->toIso8601String();

        if ($pg === 'nhnkcp') {
            // RecordsPaymentWindowClosure::markKcpPaymentFailureRecord 모양
            $history = $meta['failure_history'] ?? [];
            $history[] = ['code' => 'USER_CANCEL', 'message' => '사용자가 결제창을 닫았습니다.', 'stage' => 'window_closed', 'failed_at' => $now];
            $meta['failure_history'] = array_slice($history, -5);
            $meta['failure_source'] = 'nhnkcp';
            $meta['failure_code'] = 'USER_CANCEL';
            $meta['failure_message'] = $scenario === 'blank_values' ? null : '사용자가 결제창을 닫았습니다.';
            $meta['failure_stage'] = $scenario === 'blank_values' ? '' : 'window_closed';
            $meta['failed_at'] = $now;

            $payment->update(['pg_provider' => 'nhnkcp', 'payment_status' => PaymentStatusEnum::CANCELLED->value, 'payment_meta' => $meta]);

            return;
        }

        // 나이스 RecordsPaymentWindowClosure::markNicePaymentFailed 모양
        $status = $scenario === 'failed_status' ? PaymentStatusEnum::FAILED : PaymentStatusEnum::CANCELLED;
        $payment->update([
            'payment_status' => $status->value,
            'payment_meta' => array_merge($meta, [
                'failure_code' => 'USER_CANCEL',
                'failure_message' => $scenario === 'blank_values' ? 'pm 메시지' : '사용자가 결제창을 닫았습니다.',
                'failed_at' => $now,
                'failure_source' => 'nicepayments',
            ]),
        ]);
    }

    /**
     * 복구 결과를 DB 원문 그대로 읽습니다(모델 캐스트를 거치지 않음).
     *
     * @param  Order  $order  주문
     * @return array<string, mixed> 스냅샷
     */
    public static function snapshot(Order $order): array
    {
        $orderRow = DB::table($order->getTable())->where('id', $order->id)->first();
        $paymentRow = DB::table((new OrderPayment)->getTable())->where('order_id', $order->id)->first();
        $options = DB::table((new OrderOption)->getTable())
            ->where('order_id', $order->id)->orderBy('id')->pluck('option_status')->all();

        return [
            'order_status' => $orderRow->order_status,
            'order_meta' => $orderRow->order_meta,
            'is_mileage_deducted' => (int) $orderRow->is_mileage_deducted,
            'payment_status' => $paymentRow->payment_status,
            'payment_meta' => $paymentRow->payment_meta,
            'pg_provider' => $paymentRow->pg_provider,
            'paid_at' => $paymentRow->paid_at,
            'cancelled_at' => $paymentRow->cancelled_at,
            'transaction_id' => $paymentRow->transaction_id,
            'card_approval_number' => $paymentRow->card_approval_number,
            'cancel_history' => $paymentRow->cancel_history,
            'option_statuses' => $options,
        ];
    }

    /**
     * 녹화 파일 경로를 돌려줍니다.
     *
     * @param  string  $pg  결제사 id
     * @param  string  $scenario  시나리오 이름
     * @return string 경로
     */
    public static function fixturePath(string $pg, string $scenario): string
    {
        return self::FIXTURE_DIR."/{$pg}-{$scenario}.json";
    }
}
