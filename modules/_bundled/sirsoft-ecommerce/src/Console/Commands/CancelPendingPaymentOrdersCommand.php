<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 입금 기한 만료 주문 자동 취소 커맨드
 *
 * 무통장입금(vbank, dbank) 주문 중 입금 기한이 지난 주문을 자동 취소합니다.
 * 스케줄러에서 주기적으로 실행합니다.
 *
 * @example php artisan sirsoft-ecommerce:cancel-pending-orders
 * @example php artisan sirsoft-ecommerce:cancel-pending-orders --dry-run
 */
class CancelPendingPaymentOrdersCommand extends Command
{
    /**
     * 만료 자동취소의 사유 코드. (`ecommerce_claim_reasons` type=refund)
     *
     * 문장이 아니라 코드다 — `cancel_reason_type` 은 varchar(50) 이고 표기는 사유 카탈로그가
     * 코드로 해석한다. 이 코드는 `ClaimReasonSeeder` 가 `is_user_selectable=false` 로 심는다
     * (고객 신청 화면에 "입금 기한 만료" 를 고를 자리는 없다).
     */
    private const AUTO_CANCEL_REASON_CODE = 'auto_cancel_expired';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-ecommerce:cancel-pending-orders
                            {--dry-run : 실제 취소 없이 대상 주문만 확인}
                            {--limit=100 : 한 번에 처리할 최대 주문 수}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '입금 기한 만료된 결제대기 주문을 자동 취소합니다.';

    public function __construct(
        protected OrderRepositoryInterface $orderRepository,
        protected OrderProcessingService $orderProcessingService
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // 자동 취소 설정 확인
        if (! module_setting('sirsoft-ecommerce', 'order_settings.auto_cancel_expired', true)) {
            $this->info('자동 취소 기능이 비활성화되어 있습니다.');

            return Command::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info($isDryRun
            ? '[DRY RUN] 입금 기한 만료 주문 확인 중...'
            : '입금 기한 만료 주문 취소 처리 시작...'
        );

        try {
            // 결제창까지 갔으나 결제가 성립하지 않은 주문(PG 카드 등)의 만료 기준(분).
            // 0 이하로 두면 그 부류는 정리하지 않는다 — 운영자가 끌 수 있는 여지를 남긴다.
            $pendingOrderExpireMinutes = (int) module_setting(
                'sirsoft-ecommerce',
                'order_settings.pending_order_expire_minutes',
                1440
            );

            // 만료된 주문 조회
            $expiredOrders = $this->orderRepository->getExpiredPendingPaymentOrders(
                $limit,
                $pendingOrderExpireMinutes > 0 ? $pendingOrderExpireMinutes : null,
            );

            if ($expiredOrders->isEmpty()) {
                $this->info('처리할 만료 주문이 없습니다.');

                return Command::SUCCESS;
            }

            $this->info("대상 주문 수: {$expiredOrders->count()}건");

            $successCount = 0;
            $failCount = 0;

            foreach ($expiredOrders as $order) {
                // `payment_method` 는 순수 string 이다(#475 — 확장 결제수단 ID 허용). enum 으로
                // 보고 `?->value` 로 읽으면 "Attempt to read property on string" 경고와 함께
                // 언제나 null 이 되어, 수단 표기가 전건 `unknown` 이고 아래 match 가 한 번도
                // 맞지 않아 입금 기한 대신 주문 시각이 찍힌다(P17).
                $paymentMethodValue = (string) ($order->payment?->payment_method ?? 'unknown');
                $dueAt = match ($paymentMethodValue) {
                    PaymentMethodEnum::DBANK->value => $order->payment?->deposit_due_at,
                    PaymentMethodEnum::VBANK->value => $order->payment?->vbank_due_at,
                    // 결제창까지 갔으나 성립하지 않은 주문은 입금 기한이 없다 — 주문 시각을 보여
                    // 운영자가 어느 기준으로 정리되었는지 알 수 있게 한다.
                    default => $order->ordered_at,
                };

                $this->line("- 주문번호: {$order->order_number} ({$paymentMethodValue}, 기한: {$dueAt})");

                if ($isDryRun) {
                    $successCount++;

                    continue;
                }

                try {
                    // 주문 취소 처리 — 사유는 **코드**를 넘긴다(P17).
                    //
                    // 번역 문장을 넘기면 `order_cancels.cancel_reason_type`(varchar(50))에
                    // 그대로 들어간다. 영문 로케일의 문장은 59자라 그 칸을 넘겨 DB 오류가 나고,
                    // **만료 주문 자동취소가 전량 실패**한다 — 스케줄러 로그에만 남으므로
                    // 운영자는 미결제 주문이 쌓이는 것만 본다. 화면 표기는 사유 카탈로그가
                    // 코드로 해석하므로 문장을 저장할 이유도 없다.
                    $this->orderProcessingService->cancelOrder(
                        $order,
                        self::AUTO_CANCEL_REASON_CODE
                    );
                    $successCount++;

                    Log::info('CancelPendingPaymentOrdersCommand: 주문 자동 취소 완료', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'payment_method' => $paymentMethodValue,
                    ]);
                } catch (\Exception $e) {
                    $failCount++;

                    $this->error("  취소 실패: {$e->getMessage()}");

                    Log::error('CancelPendingPaymentOrdersCommand: 주문 자동 취소 실패', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->newLine();
            $this->info($isDryRun
                ? "[DRY RUN] 취소 대상: {$successCount}건"
                : "처리 완료 - 성공: {$successCount}건, 실패: {$failCount}건"
            );

            return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("오류 발생: {$e->getMessage()}");

            Log::error('CancelPendingPaymentOrdersCommand: 커맨드 실행 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
