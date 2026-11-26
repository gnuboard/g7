<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponOperationException;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ConcurrencyErrors;
use Modules\Sirsoft\Ecommerce\Traits\ReappliesPermissionScope;

/**
 * 쿠폰 서비스
 */
class CouponService
{
    use ReappliesPermissionScope;

    /**
     * 쿠폰 서비스를 생성합니다.
     *
     * @param  CouponRepositoryInterface  $repository  쿠폰 Repository
     * @param  UserCouponService  $userCouponService  회원 쿠폰 서비스
     * @param  CouponIssueRepositoryInterface  $issueRepository  쿠폰 발급 Repository
     * @param  CouponNotificationDefinitionService  $notificationDefinitions  쿠폰별 발급 알림 종류 관리
     */
    public function __construct(
        protected CouponRepositoryInterface $repository,
        protected UserCouponService $userCouponService,
        protected CouponIssueRepositoryInterface $issueRepository,
        protected CouponNotificationDefinitionService $notificationDefinitions
    ) {}

    /**
     * 쿠폰 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 쿠폰 페이지네이터
     */
    public function getCoupons(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.coupon.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_list_query', $filters);

        $coupons = $this->repository->paginate($filters, $perPage);

        // 필터 훅 - 결과 데이터 변형
        $coupons = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_list_result', $coupons, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.coupon.after_list', $coupons, $filters);

        return $coupons;
    }

    /**
     * 쿠폰 상세 조회
     *
     * @param  int  $id  쿠폰 ID
     * @return Coupon|null 쿠폰 또는 null
     */
    public function getCoupon(int $id): ?Coupon
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_show', $id);

        $coupon = $this->repository->findById($id, [
            'creator',
            'includedProducts',
            'excludedProducts',
            'includedCategories',
            'excludedCategories',
        ]);

        if ($coupon) {
            // 상세 라우트는 `{id}` 라 미들웨어 스코프 검사가 돌지 않는다 — 목록과 같은 `promotion-coupon.read` 범위를 재적용
            $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.read');

            // 필터 훅 - 조회 결과 변형
            $coupon = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_show_result', $coupon);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.coupon.after_show', $coupon);
        }

        return $coupon;
    }

    /**
     * 쿠폰 생성
     *
     * @param  array  $data  쿠폰 데이터
     * @return Coupon 생성된 쿠폰
     */
    public function createCoupon(array $data): Coupon
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.coupon.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_create_data', $data);

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();

        // 상품/카테고리 데이터 분리
        $products = $data['products'] ?? [];
        $categories = $data['categories'] ?? [];
        unset($data['products'], $data['categories']);

        $coupon = DB::transaction(function () use ($data, $products, $categories) {
            // 쿠폰 생성
            $coupon = $this->repository->create($data);

            // 적용 상품 동기화
            if (! empty($products)) {
                $this->repository->syncProducts($coupon, $products);
            }

            // 적용 카테고리 동기화
            if (! empty($categories)) {
                $this->repository->syncCategories($coupon, $categories);
            }

            return $coupon->fresh([
                'creator',
                'includedProducts',
                'excludedProducts',
                'includedCategories',
                'excludedCategories',
            ]);
        });

        // 발급 알림을 켰으면 이 쿠폰의 알림 종류를 만든다
        $this->syncNotificationDefinition($coupon);

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.coupon.after_create', $coupon, $data);

        return $coupon;
    }

    /**
     * 쿠폰 수정
     *
     * @param  int  $id  쿠폰 ID
     * @param  array  $data  수정할 데이터
     * @return Coupon 수정된 쿠폰
     */
    public function updateCoupon(int $id, array $data): Coupon
    {
        $coupon = $this->repository->findById($id);

        if (! $coupon) {
            throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_not_found');
        }

        $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.update');

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_update', $id, $data);

        // 수정 전 스냅샷 캡처
        $snapshot = $coupon->toActivitySnapshot();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.coupon.filter_update_data', $data, $id);

        // 상품/카테고리 데이터 분리
        $products = $data['products'] ?? null;
        $categories = $data['categories'] ?? null;
        unset($data['products'], $data['categories']);

        $coupon = DB::transaction(function () use ($coupon, $data, $products, $categories) {
            // 쿠폰 수정
            $coupon = $this->repository->update($coupon->id, $data);

            // 적용 상품 동기화 (null이 아닌 경우에만)
            if ($products !== null) {
                $this->repository->syncProducts($coupon, $products);
            }

            // 적용 카테고리 동기화 (null이 아닌 경우에만)
            if ($categories !== null) {
                $this->repository->syncCategories($coupon, $categories);
            }

            return $coupon->fresh([
                'creator',
                'includedProducts',
                'excludedProducts',
                'includedCategories',
                'excludedCategories',
            ]);
        });

        // 알림 사용·쿠폰명 변경을 알림 종류에 반영한다
        $this->syncNotificationDefinition($coupon);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_update', $coupon, $data, $snapshot);

        return $coupon;
    }

    /**
     * 쿠폰 삭제
     *
     * @param  int  $id  쿠폰 ID
     * @return array 삭제 결과 정보
     */
    public function deleteCoupon(int $id): array
    {
        $coupon = $this->repository->findById($id);

        if (! $coupon) {
            throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_not_found');
        }

        $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.delete');

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_delete', $coupon);

        DB::transaction(function () use ($coupon) {
            // 연결된 상품/카테고리 관계 제거
            $coupon->products()->detach();
            $coupon->categories()->detach();

            // 쿠폰 삭제 (SoftDelete)
            $this->repository->delete($coupon->id);
        });

        // 쿠폰 전용 알림 종류와 템플릿을 함께 지운다
        try {
            $this->notificationDefinitions->remove((int) $coupon->id);
        } catch (\Throwable $e) {
            Log::warning('쿠폰 알림 종류 삭제 실패', ['coupon_id' => $coupon->id, 'message' => $e->getMessage()]);
        }

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_delete', $coupon->id);

        return [
            'coupon_id' => $coupon->id,
        ];
    }

    /**
     * 쿠폰의 발급 알림 종류를 맞춥니다. 실패해도 쿠폰 저장은 되돌리지 않는다(알림 설정은 다시 저장하면 맞춰진다).
     *
     * @param  Coupon  $coupon  저장된 쿠폰
     * @return void 반환값 없음
     */
    private function syncNotificationDefinition(Coupon $coupon): void
    {
        try {
            $this->notificationDefinitions->sync($coupon);
        } catch (\Throwable $e) {
            Log::warning('쿠폰 알림 종류 동기화 실패', ['coupon_id' => $coupon->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 일괄 발급상태 변경
     *
     * @param  array  $ids  쿠폰 ID 배열
     * @param  string  $issueStatus  발급상태
     * @return int 변경된 레코드 수
     */
    public function bulkUpdateIssueStatus(array $ids, string $issueStatus): int
    {
        // 스코프 검사와 스냅샷이 같은 조회를 공유한다 (Model 직접 호출 제거 겸용).
        $targets = $this->repository->findByIdsKeyed($ids);

        $this->assertAllWithinScope($targets, 'sirsoft-ecommerce.promotion-coupon.update');

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_bulk_status', $ids, $issueStatus);

        // 수정 전 스냅샷 캡처
        $snapshots = $targets->keyBy('id')->map->toActivitySnapshot()->all();

        $count = DB::transaction(function () use ($ids, $issueStatus) {
            return $this->repository->bulkUpdateIssueStatus($ids, $issueStatus);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_bulk_status', $ids, $issueStatus, $count, $snapshots);

        return $count;
    }

    /**
     * 관리자가 지정한 회원들에게 쿠폰을 직접 발급합니다.
     *
     * 회원별로 per_user_limit 을 검증해 발급하며, 위반 회원은 건너뛰고 사유를 누적합니다
     * (전체 롤백 아님). 발급 성공 1건마다 after_direct_issue 훅을 발화해 활동로그를 남깁니다.
     *
     * @param  int  $couponId  쿠폰 ID
     * @param  int[]  $userIds  발급 대상 회원 ID 배열
     * @return array{issued: int, skipped: array<int, array{user_id: int, reason: string}>}
     *
     * @throws \Exception 쿠폰 미존재 또는 발급 불가 상태(전원 차단) 시
     */
    public function issueDirectly(int $couponId, array $userIds): array
    {
        $coupon = $this->repository->findById($couponId);

        if (! $coupon) {
            throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_not_found');
        }

        $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.update');

        HookManager::doAction('sirsoft-ecommerce.coupon.before_direct_issue', $couponId, $userIds);

        $result = DB::transaction(function () use ($couponId, $userIds) {
            $coupon = $this->repository->findByIdForUpdate($couponId);

            if (! $coupon) {
                throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_not_found');
            }

            // 쿠폰 자체 발급 가능 여부는 일괄 검증(불가 시 전원 차단)
            $this->userCouponService->assertIssuable($coupon);

            $issued = 0;
            $skipped = [];

            foreach (array_values(array_unique($userIds)) as $userId) {
                $userId = (int) $userId;

                try {
                    // 회원별 세이브포인트(B8) — 발급 행 생성 뒤 발급 수 증가나 훅이 실패하면 그 회원의 발급만 되돌린다.
                    // 없으면 발급 행은 커밋되는데 건너뜀으로 보고되고 발급 수는 늘지 않아, 한도를 넘겨 발급될 수 있었다.
                    DB::transaction(function () use ($coupon, $userId) {
                        $couponIssue = $this->userCouponService->issueDirectlyToUser($coupon, $userId);

                        // 발급 성공 1건당 활동로그 (per-item)
                        HookManager::doAction('sirsoft-ecommerce.coupon.after_direct_issue', $coupon, $couponIssue, $userId);
                    });
                    $issued++;
                } catch (\Exception $e) {
                    ConcurrencyErrors::rethrowIfConcurrencyError($e);
                    // 사유는 코드(reason)와 문구(message)로 나눠 싣는다 — 쿠폰 일괄 받기와 같은 사유 코드 체계
                    $skipped[] = [
                        'user_id' => $userId,
                        'reason' => $e instanceof CouponNotIssuableException ? $e->getReason() : 'operation_failed',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return ['issued' => $issued, 'skipped' => $skipped];
        });

        HookManager::doAction('sirsoft-ecommerce.coupon.after_direct_issue_batch', $couponId, $result);

        return $result;
    }

    /**
     * 발급된 쿠폰 1건을 취소합니다. (미사용 건만 가능)
     *
     * 사용가능(available) 상태의 발급 건만 cancelled 로 전환하고 쿠폰의 issued_count 를
     * 1 감소시켜 발급 수량을 복원합니다. 이미 사용/만료/취소된 건은 예외를 던집니다.
     *
     * @param  int  $couponId  쿠폰 ID (URL 정합성 검증용)
     * @param  int  $issueId  취소할 발급 내역 ID
     * @return CouponIssue 취소 처리된 발급 내역
     *
     * @throws \Exception 발급 내역 미존재·쿠폰 불일치·미사용 상태 아님일 때
     */
    public function cancelIssue(int $couponId, int $issueId): CouponIssue
    {
        // 발급 취소는 쿠폰의 `promotion-coupon.update` 범위 안이어야 한다(라우트가 `{id}` 라 미들웨어가 건너뜀).
        // 쿠폰이 없으면 아래 발급 내역 대조가 기존 not_found 계약으로 답한다.
        $coupon = $this->repository->findById($couponId);
        if ($coupon) {
            $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.update');
        }

        return DB::transaction(function () use ($couponId, $issueId) {
            $issue = $this->issueRepository->findById($issueId);

            if (! $issue || $issue->coupon_id !== $couponId) {
                throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_issue_not_found');
            }

            // 미사용(available) 건만 취소 가능 — 사용/만료/취소된 건은 차단
            if ($issue->status !== CouponIssueRecordStatus::AVAILABLE) {
                throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_issue_not_cancellable');
            }

            $cancelled = $this->cancelIssueRecord($issue);

            // 조회와 취소 사이에 사용된 건 — 조건부 갱신이 0건이었다
            if ($cancelled === null) {
                throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_issue_not_cancellable');
            }

            return $cancelled;
        });
    }

    /**
     * 발급 내역 1건을 발급 취소합니다. (사용가능 상태일 때만)
     *
     * 관리자 발급 취소와 첫구매 쿠폰 회수가 함께 쓰는 본체다. `status=available` 조건부 UPDATE 1회로 바꾸므로
     * 같은 순간 사용(USED 전환)과 겹쳐도 사용된 쿠폰을 취소하지 않는다 — 그때는 0건이라 null 을 돌려준다.
     * 성공하면 쿠폰의 발급 수를 1 줄이고 `before/after_issue_cancel` 훅을 발화한다.
     *
     * 권한 범위 검사는 호출자 책임이다(관리자 경로는 `cancelIssue()` 가 한다). 트랜잭션은 호출 측이 연다.
     *
     * @param  CouponIssue  $issue  취소할 발급 내역
     * @param  array<string, mixed>  $extra  상태와 함께 바꿀 값 (회수는 `auto_issue_key => null` 로 다음 주문을 허용)
     * @return CouponIssue|null 취소된 발급 내역 (이미 사용가능 상태가 아니면 null)
     */
    public function cancelIssueRecord(CouponIssue $issue, array $extra = []): ?CouponIssue
    {
        HookManager::doAction('sirsoft-ecommerce.coupon.before_issue_cancel', $issue);

        $updated = $this->issueRepository->updateIfStatus($issue->id, CouponIssueRecordStatus::AVAILABLE, array_merge($extra, [
            'status' => CouponIssueRecordStatus::CANCELLED->value,
        ]));

        if ($updated === 0) {
            return null;
        }

        // 발급 수량 복원
        $this->repository->decrementIssuedCount((int) $issue->coupon_id);

        $issue = $this->issueRepository->findById($issue->id);

        // 활동로그 (per-item)
        HookManager::doAction('sirsoft-ecommerce.coupon.after_issue_cancel', $issue);

        return $issue;
    }

    /**
     * 쿠폰 발급 내역 조회
     *
     * @param  int  $couponId  쿠폰 ID
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 발급 내역 페이지네이터
     */
    public function getCouponIssues(int $couponId, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $coupon = $this->repository->findById($couponId);

        if (! $coupon) {
            throw new CouponOperationException('sirsoft-ecommerce::exceptions.coupon_not_found');
        }

        $this->assertWithinScope($coupon, 'sirsoft-ecommerce.promotion-coupon.read');

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.before_issues_list', $couponId, $filters);

        $issues = $this->repository->getIssues($couponId, $filters, $perPage);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.coupon.after_issues_list', $issues, $couponId, $filters);

        return $issues;
    }
}
