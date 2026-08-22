<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;

/**
 * 쿠폰 활동 로그 리스너
 *
 * 쿠폰의 생성, 수정, 삭제, 일괄 상태 변경 시
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 */
class CouponActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * @param  CouponRepositoryInterface  $couponRepository  쿠폰 bulk lookup
     */
    public function __construct(
        protected CouponRepositoryInterface $couponRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.coupon.after_create' => ['method' => 'handleAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.coupon.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.coupon.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.coupon.after_bulk_status' => ['method' => 'handleAfterBulkStatus', 'priority' => 20],
            'sirsoft-ecommerce.coupon.after_direct_issue' => ['method' => 'handleAfterDirectIssue', 'priority' => 20],
            'sirsoft-ecommerce.coupon.after_issue_cancel' => ['method' => 'handleAfterIssueCancel', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러
    // ═══════════════════════════════════════════

    /**
     * 쿠폰 생성 후 로그 기록
     *
     * @param  Coupon  $coupon  생성된 쿠폰
     * @param  array  $data  생성 데이터
     */
    public function handleAfterCreate(Coupon $coupon, array $data): void
    {
        $this->logActivity('coupon.create', [

            'loggable' => $coupon,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_create',
            'description_params' => ['coupon_id' => $coupon->id],
            'properties' => ['name' => $coupon->name ?? null],
        ]);
    }

    /**
     * 쿠폰 수정 후 로그 기록
     *
     * @param  Coupon  $coupon  수정된 쿠폰
     * @param  array  $data  수정 데이터
     * @param  array|null  $snapshot  수정 전 스냅샷 (Service에서 전달)
     */
    public function handleAfterUpdate(Coupon $coupon, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($coupon, $snapshot);

        $this->logActivity('coupon.update', [

            'loggable' => $coupon,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_update',
            'description_params' => ['coupon_id' => $coupon->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 쿠폰 삭제 후 로그 기록
     *
     * after_delete 훅은 $couponId (int)만 전달합니다.
     *
     * @param  int  $couponId  삭제된 쿠폰 ID
     */
    public function handleAfterDelete(int $couponId): void
    {
        $this->logActivity('coupon.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_delete',
            'description_params' => ['coupon_id' => $couponId],
            'properties' => ['coupon_id' => $couponId],
        ]);
    }

    /**
     * 쿠폰 직접 발급 후 per-item 로그 기록
     *
     * @param  Coupon  $coupon  발급 대상 쿠폰
     * @param  CouponIssue  $couponIssue  생성된 발급 레코드
     * @param  int  $userId  발급 대상 회원 ID
     */
    public function handleAfterDirectIssue(Coupon $coupon, CouponIssue $couponIssue, int $userId): void
    {
        $this->logActivity('coupon.direct_issue', [
            'loggable' => $couponIssue,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_direct_issue',
            'description_params' => ['coupon_id' => $coupon->id, 'user_id' => $userId],
            'properties' => [
                'coupon_id' => $coupon->id,
                'user_id' => $userId,
                'coupon_code' => $couponIssue->coupon_code,
            ],
        ]);
    }

    /**
     * 쿠폰 발급 취소 후 로그 기록
     *
     * @param  CouponIssue  $couponIssue  취소된 발급 레코드
     */
    public function handleAfterIssueCancel(CouponIssue $couponIssue): void
    {
        $this->logActivity('coupon.issue_cancel', [
            'loggable' => $couponIssue,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_issue_cancel',
            'description_params' => [
                'coupon_id' => $couponIssue->coupon_id,
                'user_id' => $couponIssue->user_id,
            ],
            'properties' => [
                'coupon_id' => $couponIssue->coupon_id,
                'user_id' => $couponIssue->user_id,
                'coupon_code' => $couponIssue->coupon_code,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Bulk 로그 기록 (after 훅, priority 20)
    // ═══════════════════════════════════════════

    /**
     * 쿠폰 일괄 상태 변경 후 per-item 로그 기록
     *
     * @param  array  $ids  대상 쿠폰 ID 목록
     * @param  mixed  $issueStatus  변경된 발급 상태 (Enum)
     * @param  int  $count  변경된 수
     * @param  array  $snapshots  수정 전 스냅샷 맵 [couponId => array] (Service에서 전달)
     */
    public function handleAfterBulkStatus(array $ids, mixed $issueStatus, int $count, array $snapshots = []): void
    {
        $coupons = $this->couponRepository->findByIdsKeyed($ids);

        foreach ($ids as $couponId) {
            $coupon = $coupons->get($couponId);
            if (! $coupon) {
                continue;
            }

            $snapshot = $snapshots[$couponId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($coupon, $snapshot) : null;

            $this->logActivity('coupon.bulk_status', [
                'loggable' => $coupon,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.coupon_bulk_status',
                'description_params' => ['count' => 1],
                'properties' => [
                    'coupon_id' => $couponId,
                    'issue_status' => $issueStatus instanceof \BackedEnum ? $issueStatus->value : $issueStatus,
                ],
                'changes' => $changes,
            ]);
        }
    }
}
