<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponAlreadyUsedException;
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
                // 호출자(주문 생성) 트랜잭션 안에서 실행되어야 한다. Action 훅 기본값은 큐 작업
                // 래핑 + afterCommit 이라, 그대로 두면 쿠폰 차감이 주문 커밋 뒤에 실행되어
                // 차감이 실패해도 주문을 되돌릴 수 없다 (1회 제한 쿠폰 다중 사용).
                'sync' => true,
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
                $issueId = (int) $issueId;

                // AVAILABLE 판정과 차감을 한 UPDATE 문에서 원자적으로 수행한다.
                // 조회 후 갱신하면 동시에 확정되는 두 주문이 모두 AVAILABLE 을 읽어
                // 각자 USED 로 덮어써 1회 제한 쿠폰이 여러 주문에 사용된다.
                $affected = $this->couponIssueRepository->updateIfStatus(
                    $issueId,
                    CouponIssueRecordStatus::AVAILABLE,
                    [
                        'status' => CouponIssueRecordStatus::USED,
                        'used_at' => now(),
                        'order_id' => $order->id,
                    ]
                );

                if ($affected > 0) {
                    $usedCount++;

                    continue;
                }

                $current = $this->couponIssueRepository->findById($issueId);

                // 존재하지 않는 발급 ID 는 종전대로 skip
                if ($current === null) {
                    continue;
                }

                // 같은 주문의 재발화(재시도/훅 중복 발화)는 멱등하게 skip
                if ($current->status === CouponIssueRecordStatus::USED
                    && (int) $current->order_id === (int) $order->id) {
                    continue;
                }

                // 다른 주문이 선점했거나 사용 가능 상태가 아니다. 이 주문은 이미 할인 금액이
                // 확정된 상태이므로 검출만으로는 부족하고, 예외를 전파해 주문 트랜잭션 자체를
                // 롤백해야 쿠폰이 중복 사용되지 않는다.
                Log::warning('CouponUseListener: 쿠폰 선점 실패 — 주문 롤백', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number ?? null,
                    'coupon_issue_id' => $issueId,
                    'current_status' => $current->status?->value,
                    'current_order_id' => $current->order_id,
                ]);

                throw new CouponAlreadyUsedException($issueId);
            }

            Log::info('CouponUseListener: 주문 쿠폰 사용 차감 완료', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_coupons' => count(array_unique($appliedCouponIds)),
                'used_count' => $usedCount,
            ]);
        } catch (CouponAlreadyUsedException $e) {
            // 도메인 실패는 삼키지 않고 전파한다 (주문 트랜잭션 롤백 트리거)
            throw $e;
        } catch (\Exception $e) {
            Log::error('CouponUseListener: 쿠폰 사용 차감 실패', [
                'order_id' => $order->id,
                'order_number' => $order->order_number ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
