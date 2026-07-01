<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;

/**
 * 주문 생성 시 쿠폰 사용 처리 리스너 (공개#57/MP06)
 *
 * 주문 생성 트랜잭션에서 발화되는 sirsoft-ecommerce.coupon.use 훅을 구독해
 * 적용된 쿠폰 발급 레코드를 used 상태로 차감합니다.
 *
 * 이 리스너가 없으면 쿠폰이 영원히 available 로 남아 무통장입금 등에서
 * 입금 처리 전까지 1회 제한 쿠폰이 무한 재사용됩니다(공개#57 직접 원인).
 * 차감 시점은 결제수단 무관하게 주문 생성 시점이며(선차감 유지),
 * 미입금/취소 시 CouponRestoreListener 가 available 로 복원합니다.
 */
class CouponUseListener implements HookListenerInterface
{
    /**
     * @param  CouponIssueRepositoryInterface  $couponIssueRepository  쿠폰 발급 Repository
     */
    public function __construct(
        protected CouponIssueRepositoryInterface $couponIssueRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.coupon.use' => [
                'method' => 'markCouponsUsed',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 주문에 적용된 쿠폰을 used 상태로 차감합니다.
     *
     * @param  int[]  $appliedCouponIds  적용된 쿠폰 발급 ID 배열
     * @param  Order  $order  생성된 주문
     * @return void
     */
    public function markCouponsUsed(array $appliedCouponIds, Order $order): void
    {
        try {
            $usedCount = 0;

            foreach (array_unique($appliedCouponIds) as $issueId) {
                $couponIssue = $this->couponIssueRepository->findById((int) $issueId);

                if ($couponIssue === null) {
                    continue;
                }

                // 이미 사용됨/취소됨 등은 skip (멱등성 — 재발화/재시도 안전)
                if ($couponIssue->status !== CouponIssueRecordStatus::AVAILABLE) {
                    continue;
                }

                $this->couponIssueRepository->update((int) $issueId, [
                    'status' => CouponIssueRecordStatus::USED,
                    'used_at' => now(),
                    'order_id' => $order->id,
                ]);

                $usedCount++;
            }

            Log::info('CouponUseListener: 주문 쿠폰 사용 차감 완료', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_coupons' => count(array_unique($appliedCouponIds)),
                'used_count' => $usedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('CouponUseListener: 쿠폰 사용 차감 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
