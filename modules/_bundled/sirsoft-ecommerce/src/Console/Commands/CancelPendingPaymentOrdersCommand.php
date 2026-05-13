<?php

namespace Modules\Sirsoft\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
            // 만료된 주문 조회
            $expiredOrders = $this->orderRepository->getExpiredPendingPaymentOrders($limit);

            if ($expiredOrders->isEmpty()) {
                $this->info('처리할 만료 주문이 없습니다.');

                return Command::SUCCESS;
            }

            $this->info("대상 주문 수: {$expiredOrders->count()}건");

            $successCount = 0;
            $failCount = 0;

            foreach ($expiredOrders as $order) {
                $paymentMethodEnum = $order->payment?->payment_method;
                $paymentMethodValue = $paymentMethodEnum?->value ?? 'unknown';
                $dueAt = $paymentMethodValue === 'bank'
                    ? $order->payment?->deposit_due_at
                    : $order->payment?->vbank_due_at;

                $this->line("- 주문번호: {$order->order_number} ({$paymentMethodValue}, 기한: {$dueAt})");

                if ($isDryRun) {
                    $successCount++;

                    continue;
                }

                try {
                    // 주문 취소 처리
                    $this->orderProcessingService->cancelOrder(
                        $order,
                        __('sirsoft-ecommerce::messages.order.auto_cancel_expired_reason')
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
