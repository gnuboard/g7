<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponFirstPurchaseTrigger;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 쿠폰 자동발급 서비스
 *
 * 관리자가 쿠폰 등록에서 고른 「자동발급 → 회원가입·첫구매·생일」 을 실제로 발급한다.
 *
 * - 횟수는 발급 키(`signup`·`first_purchase`·`birthday:YYYY`)와 DB 유니크가 정한다 — 회원당 한도(`per_user_limit`)는 쓰지 않는다.
 * - 발급 1건은 쿠폰 행 잠금 + 유니크로 동시 요청에서도 한 번이다. 유니크 위반은 「이미 받음」 으로 흡수한다.
 * - **호출하는 쪽 흐름(가입·주문 상태 변경)은 절대 막지 않는다.** 발급 불가는 info, 그 밖의 실패는 warning 로그만 남긴다.
 */
class CouponAutoIssueService
{
    /**
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 저장소
     * @param  CouponIssueRepositoryInterface  $issueRepository  쿠폰 발급 내역 저장소
     * @param  OrderRepositoryInterface  $orderRepository  주문 저장소
     * @param  UserCouponService  $userCouponService  회원 쿠폰 서비스 (발급 본체·발급 가능 판정)
     * @param  CouponService  $couponService  쿠폰 서비스 (발급 취소 본체)
     */
    public function __construct(
        protected CouponRepositoryInterface $couponRepository,
        protected CouponIssueRepositoryInterface $issueRepository,
        protected OrderRepositoryInterface $orderRepository,
        protected UserCouponService $userCouponService,
        protected CouponService $couponService,
    ) {}

    /**
     * 자동발급 쿠폰 1장을 회원 1명에게 발급합니다.
     *
     * @param  int  $couponId  쿠폰 ID
     * @param  int  $userId  회원 ID
     * @param  CouponIssueSource  $source  자동발급 경로
     * @param  string  $autoIssueKey  발급 키
     * @param  int|null  $sourceOrderId  첫구매 원천 주문 ID
     * @param  array<string, mixed>  $context  필터·훅에 넘길 문맥 (order 등)
     * @return CouponIssue|null 발급 건 (발급하지 않았으면 null)
     */
    public function issueOne(int $couponId, int $userId, CouponIssueSource $source, string $autoIssueKey, ?int $sourceOrderId = null, array $context = []): ?CouponIssue
    {
        try {
            $issue = DB::transaction(function () use ($couponId, $userId, $source, $autoIssueKey, $sourceOrderId, $context) {
                $coupon = $this->couponRepository->findByIdForUpdate($couponId);
                if (! $coupon || $coupon->issue_method !== CouponIssueMethod::AUTO
                    || CouponIssueSource::fromCondition($coupon->issue_condition) !== $source) {
                    return null;
                }

                // 발급중단·수량·발급기간·유효기간 설정
                $this->userCouponService->assertIssuable($coupon);

                // 기간형 유효기간이 이미 끝났으면 받아도 쓸 수 없다
                if ($coupon->valid_type === 'period' && $coupon->valid_to !== null && $coupon->valid_to->isPast()) {
                    return null;
                }

                if ($this->issueRepository->existsByAutoIssueKey($coupon->id, $userId, $autoIssueKey)) {
                    return null;
                }

                $eligible = HookManager::applyFilters(
                    'sirsoft-ecommerce.coupon.filter_auto_issue_eligible',
                    true,
                    $coupon,
                    $userId,
                    $source->value,
                    $context
                );
                if ($eligible !== true) {
                    return null;
                }

                return $this->userCouponService->issueAutomaticallyToUser($coupon, $userId, $source, $autoIssueKey, $sourceOrderId);
            });
        } catch (UniqueConstraintViolationException) {
            // 같은 회원·쿠폰·키가 동시에 들어온 다른 요청이 먼저 발급했다
            return null;
        } catch (CouponNotIssuableException $e) {
            Log::info('쿠폰 자동발급 건너뜀', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'source' => $source->value,
                'reason' => $e->getReason(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('쿠폰 자동발급 실패', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'source' => $source->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($issue instanceof CouponIssue) {
            try {
                HookManager::doAction('sirsoft-ecommerce.coupon.after_auto_issue', $issue->fresh(['coupon']) ?? $issue, $source->value, $context);
            } catch (\Throwable $e) {
                Log::warning('쿠폰 자동발급 후처리 실패', ['issue_id' => $issue->id, 'message' => $e->getMessage()]);
            }
        }

        return $issue;
    }

    /**
     * 가입 쿠폰을 모두 발급합니다.
     *
     * @param  int  $userId  가입한(또는 활성화된) 회원 ID
     * @return int 발급한 장수
     */
    public function issueSignupCoupons(int $userId): int
    {
        $issued = 0;

        foreach ($this->couponRepository->getAutoIssueCouponIds(CouponIssueCondition::SIGNUP) as $couponId) {
            if ($this->issueOne($couponId, $userId, CouponIssueSource::SIGNUP, 'signup') !== null) {
                $issued++;
            }
        }

        return $issued;
    }

    /**
     * 주문이 첫구매 쿠폰의 발급 시점에 도달했는지 판정해 발급합니다.
     *
     * 주문은 언제나 **새로 읽는다**(큐 지연으로 그 사이 상태가 바뀌었을 수 있다). 쿠폰마다 「고른 상태 이상에 도달한 그 회원의
     * 주문 중 주문일이 가장 이른 주문」 을 구해 그것이 이 주문일 때만 발급한다 — 과거 구매 이력이 있는 회원은 이 규칙으로 빠진다.
     *
     * @param  int  $orderId  주문 ID
     * @return int 발급한 장수
     */
    public function evaluateFirstPurchase(int $orderId): int
    {
        $order = $this->orderRepository->find($orderId);
        if (! $order instanceof Order || $order->user_id === null) {
            return 0;
        }

        if ($this->isTerminatedOrder($order)) {
            return 0;
        }

        $issued = 0;

        foreach ($this->couponRepository->getAutoIssueCouponIds(CouponIssueCondition::FIRST_PURCHASE) as $couponId) {
            $coupon = $this->couponRepository->findById($couponId);
            if (! $coupon) {
                continue;
            }

            $earliest = $this->earliestReachingOrder((int) $order->user_id, $coupon->firstPurchaseTrigger());
            if ($earliest === null || (int) $earliest->id !== (int) $order->id) {
                continue;
            }

            if ($this->issueOne($couponId, (int) $order->user_id, CouponIssueSource::FIRST_PURCHASE, 'first_purchase', (int) $order->id, ['order_id' => (int) $order->id]) !== null) {
                $issued++;
            }
        }

        return $issued;
    }

    /**
     * 전부 취소·반품완료된 주문이 원천인 미사용 첫구매 쿠폰을 회수하고, 다음 주문을 다시 판정합니다.
     *
     * 쿠폰의 지금 발급 시점이 구매확정이면 회수하지 않는다(확정된 주문에서만 나간 쿠폰이다). 사용한 쿠폰은 그대로 둔다.
     *
     * @param  int  $orderId  주문 ID
     * @return int 회수한 장수
     */
    public function revokeFirstPurchase(int $orderId): int
    {
        $order = $this->orderRepository->find($orderId);
        if (! $order instanceof Order || $order->user_id === null || ! $this->isTerminatedOrder($order)) {
            return 0;
        }

        $revoked = 0;

        foreach ($this->issueRepository->getAvailableAutoIssuesBySourceOrder((int) $order->id) as $issue) {
            $coupon = $issue->coupon;
            if (! $coupon instanceof Coupon || ! $coupon->firstPurchaseTrigger()->revocable()) {
                continue;
            }

            try {
                $cancelled = DB::transaction(fn () => $this->couponService->cancelIssueRecord($issue, ['auto_issue_key' => null]));
            } catch (\Throwable $e) {
                Log::warning('첫구매 쿠폰 회수 실패', ['issue_id' => $issue->id, 'message' => $e->getMessage()]);

                continue;
            }

            if ($cancelled === null) {
                continue;
            }

            $revoked++;

            try {
                HookManager::doAction('sirsoft-ecommerce.coupon.after_auto_issue_revoke', $cancelled, (int) $order->id);
            } catch (\Throwable $e) {
                Log::warning('첫구매 쿠폰 회수 후처리 실패', ['issue_id' => $issue->id, 'message' => $e->getMessage()]);
            }

            // 회수했으면 다음 주문이 다시 대상이다 — 이미 시점에 도달한 주문이 있으면 곧바로 그 주문에 발급한다
            $next = $this->earliestReachingOrder((int) $order->user_id, $coupon->firstPurchaseTrigger());
            if ($next !== null && (int) $next->id !== (int) $order->id) {
                $this->issueOne((int) $coupon->id, (int) $order->user_id, CouponIssueSource::FIRST_PURCHASE, 'first_purchase', (int) $next->id, ['order_id' => (int) $next->id]);
            }
        }

        return $revoked;
    }

    /**
     * 생일 쿠폰을 회원들에게 발급합니다. (그해 1회)
     *
     * @param  array<int, int>  $userIds  회원 ID 목록
     * @param  int  $year  사이트 시간대 기준 연도
     * @param  array<int, int>  $couponIds  발급할 생일 쿠폰 ID 목록
     * @return int 발급한 장수
     */
    public function issueBirthdayCoupons(array $userIds, int $year, array $couponIds): int
    {
        $key = UserCouponService::birthdayIssueKey($year);
        $issued = 0;

        foreach ($userIds as $userId) {
            foreach ($couponIds as $couponId) {
                if ($this->issueOne((int) $couponId, (int) $userId, CouponIssueSource::BIRTHDAY, $key, null, ['year' => $year]) !== null) {
                    $issued++;
                }
            }
        }

        return $issued;
    }

    /**
     * 회원 주문 중 그 시점 이상에 처음 도달한 주문을 구합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  CouponFirstPurchaseTrigger  $trigger  발급 시점
     * @return Order|null 주문
     */
    private function earliestReachingOrder(int $userId, CouponFirstPurchaseTrigger $trigger): ?Order
    {
        return $this->orderRepository->findEarliestOrderReachingStatuses(
            $userId,
            $trigger->reachedStatusValues(),
            $trigger->countsConfirmedAt()
        );
    }

    /**
     * 주문이 전부 취소·반품완료 상태인지 판정합니다.
     *
     * @param  Order  $order  주문
     * @return bool 취소·반품완료면 true
     */
    private function isTerminatedOrder(Order $order): bool
    {
        $status = $order->order_status instanceof \BackedEnum ? $order->order_status->value : (string) $order->order_status;

        return in_array($status, [OrderStatusEnum::CANCELLED->value, OrderStatusEnum::RETURNED->value], true);
    }
}
