<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;

/**
 * 주문 취소 시 쿠폰 복원 리스너
 *
 * 주문 취소 시 사용된 쿠폰의 상태를 복원하여 재사용 가능하게 합니다.
 * promotions_applied_snapshot에서 coupon_issue_id를 추출하고
 * 해당 쿠폰 발급 레코드의 상태를 used → available로 변경합니다.
 */
class CouponRestoreListener implements HookListenerInterface
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
            'sirsoft-ecommerce.order.after_cancel' => [
                'method' => 'restoreCoupons',
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
     * 주문 취소 시 사용된 쿠폰을 복원합니다.
     *
     * @param  Order  $order  취소된 주문
     * @return void
     */
    public function restoreCoupons(Order $order): void
    {
        try {
            $couponIssueIds = $this->extractCouponIssueIds($order);

            if (empty($couponIssueIds)) {
                return;
            }

            $restoredCount = 0;

            foreach ($couponIssueIds as $issueId) {
                $couponIssue = $this->couponIssueRepository->findById($issueId);

                if ($couponIssue === null) {
                    continue;
                }

                // 이미 사용됨 상태인 경우만 복원
                if ($couponIssue->status !== CouponIssueRecordStatus::USED) {
                    continue;
                }

                // 만료 확인: 만료된 쿠폰은 expired 상태로 변경
                if ($couponIssue->expired_at !== null && $couponIssue->expired_at->isPast()) {
                    $this->couponIssueRepository->update($issueId, [
                        'status' => CouponIssueRecordStatus::EXPIRED,
                        'used_at' => null,
                    ]);

                    Log::info('CouponRestoreListener: 만료된 쿠폰 상태 변경', [
                        'coupon_issue_id' => $issueId,
                        'order_id' => $order->id,
                        'new_status' => CouponIssueRecordStatus::EXPIRED->value,
                    ]);

                    continue;
                }

                // 사용 가능 상태로 복원
                $this->couponIssueRepository->update($issueId, [
                    'status' => CouponIssueRecordStatus::AVAILABLE,
                    'used_at' => null,
                ]);

                $restoredCount++;
            }

            Log::info('CouponRestoreListener: 주문 취소 쿠폰 복원 완료', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_coupons' => count($couponIssueIds),
                'restored_count' => $restoredCount,
            ]);
        } catch (\Exception $e) {
            Log::error('CouponRestoreListener: 쿠폰 복원 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 주문의 적용 프로모션 스냅샷에서 쿠폰 발급 ID 목록을 추출합니다.
     *
     * @param  Order  $order  주문 모델
     * @return int[] 쿠폰 발급 ID 배열
     */
    protected function extractCouponIssueIds(Order $order): array
    {
        $snapshot = $order->promotions_applied_snapshot;

        if (empty($snapshot)) {
            return [];
        }

        $issueIds = [];

        // product_coupons, shipping_coupons, order_coupons 섹션에서 추출
        $sections = ['product_coupons', 'shipping_coupons', 'order_coupons'];

        foreach ($sections as $section) {
            if (! isset($snapshot[$section]) || ! is_array($snapshot[$section])) {
                continue;
            }

            foreach ($snapshot[$section] as $coupon) {
                if (isset($coupon['coupon_issue_id']) && $coupon['coupon_issue_id'] > 0) {
                    $issueIds[] = (int) $coupon['coupon_issue_id'];
                }
            }
        }

        return array_unique($issueIds);
    }
}
