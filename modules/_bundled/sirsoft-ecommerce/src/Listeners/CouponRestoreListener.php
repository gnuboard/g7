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
            // 부분취소(및 전체취소 트랜잭션 내부)에서 OrderCancellationService 가 발화하는
            // 명시적 복원 ID 훅. 스냅샷 파싱 없이 전달받은 ID 만 used→available 복원한다.
            'sirsoft-ecommerce.coupon.restore' => [
                'method' => 'restoreCouponsByIds',
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

            $restoredCount = $this->restoreIssues($couponIssueIds, $order);

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
     * 명시적으로 전달된 쿠폰 발급 ID 목록을 복원합니다. (부분취소)
     *
     * OrderCancellationService::restoreCoupons() 가 발화하는 coupon.restore 훅 핸들러.
     * 전체취소(after_cancel) 의 스냅샷 추출과 달리, 탈락 쿠폰 ID 를 직접 전달받는다.
     *
     * @param  Order  $order  취소/조정된 주문
     * @param  int[]  $couponIssueIds  복원 대상 쿠폰 발급 ID 배열
     * @return void
     */
    public function restoreCouponsByIds(Order $order, array $couponIssueIds): void
    {
        try {
            $couponIssueIds = array_values(array_unique(array_map('intval', $couponIssueIds)));

            if (empty($couponIssueIds)) {
                return;
            }

            $restoredCount = $this->restoreIssues($couponIssueIds, $order);

            Log::info('CouponRestoreListener: 부분취소 쿠폰 복원 완료', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_coupons' => count($couponIssueIds),
                'restored_count' => $restoredCount,
            ]);
        } catch (\Exception $e) {
            Log::error('CouponRestoreListener: 부분취소 쿠폰 복원 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 쿠폰 발급 ID 목록을 used→available(만료 시 expired) 로 복원합니다.
     *
     * @param  int[]  $couponIssueIds  복원 대상 쿠폰 발급 ID 배열
     * @param  Order  $order  주문 (로깅용)
     * @return int 실제 available 복원 건수
     */
    protected function restoreIssues(array $couponIssueIds, Order $order): int
    {
        $restoredCount = 0;

        foreach ($couponIssueIds as $issueId) {
            $couponIssue = $this->couponIssueRepository->findById($issueId);

            if ($couponIssue === null) {
                continue;
            }

            // 이미 사용됨 상태인 경우만 복원 (멱등성)
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

        return $restoredCount;
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

        if (empty($snapshot) || ! is_array($snapshot)) {
            return [];
        }

        // 주문 스냅샷의 표준 평탄 키 coupon_issue_ids 만 신뢰한다 (PromotionsSummary::toArray SSoT).
        $issueIds = [];
        foreach ($snapshot['coupon_issue_ids'] ?? [] as $issueId) {
            if ((int) $issueId > 0) {
                $issueIds[] = (int) $issueId;
            }
        }

        return array_values(array_unique($issueIds));
    }
}
