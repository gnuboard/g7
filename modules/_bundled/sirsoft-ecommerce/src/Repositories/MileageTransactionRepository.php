<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Models\User;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Support\Query\PaginationLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;

/**
 * 마일리지 거래(원장) Repository 구현체
 */
class MileageTransactionRepository implements MileageTransactionRepositoryInterface
{
    use PaginatesWithDeferredJoin;

    /**
     * 적립(잔액 증가) 유형 목록
     *
     * @var array<int, string>
     */
    private const EARN_TYPES = [
        'purchase_earn',
        'admin_earn',
        'refund_restore',
        'order_cancel_restore',
    ];

    /**
     * {@inheritdoc}
     */
    public function getBalance(int $userId): float
    {
        return (float) MileageTransaction::query()
            ->where('user_id', $userId)
            ->active()
            ->sum('remaining_amount');
    }

    /**
     * {@inheritdoc}
     */
    public function getBalanceByCurrency(int $userId, string $currency): float
    {
        return (float) MileageTransaction::query()
            ->forUserCurrency($userId, $currency)
            ->active()
            ->sum('remaining_amount');
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveLotsForUpdate(int $userId, string $currency): Collection
    {
        // 잠금 순서 고정 — 항상 트랜잭션 내에서 호출 (만료 임박 순)
        // audit:allow query-unbounded-get reason: 차감 대상 lot 을 만료 임박 순으로 소진해야
        // 하므로 잔액이 있는 활성 lot 전량이 한 트랜잭션에 필요하다. 상한을 걸면 뒤쪽 lot 이
        // 차감에서 누락돼 잔액이 틀어진다. 모수는 한 사용자·한 통화의 미소진 lot 으로 한정된다
        return MileageTransaction::query()
            ->forUserCurrency($userId, $currency)
            ->active()
            ->orderByRaw('expires_at IS NULL, expires_at ASC')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function createTransaction(array $data): MileageTransaction
    {
        return MileageTransaction::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function decrementRemaining(MileageTransaction $lot, float $amount): void
    {
        // 값을 PHP 에서 빼고 모델 전체를 저장하면, 스냅샷을 읽은 뒤 다른 요청이 반영한
        // 증감이 통째로 사라진다. 컬럼 연산으로 위임해 커밋된 값에서 차감한다.
        MileageTransaction::query()->where('id', $lot->id)->decrement('remaining_amount', $amount);

        $lot->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function existsEarnForOption(int $orderOptionId): bool
    {
        return MileageTransaction::query()
            ->where('order_option_id', $orderOptionId)
            ->whereIn('type', self::EARN_TYPES)
            ->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function sumPurchaseEarnedForOption(int $orderOptionId): float
    {
        return (float) MileageTransaction::query()
            ->where('order_option_id', $orderOptionId)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->sum('amount');
    }

    /**
     * {@inheritdoc}
     */
    public function transferPurchaseEarnByOrderOptionId(int $fromOrderOptionId, int $toOrderOptionId): int
    {
        return MileageTransaction::query()
            ->where('order_option_id', $fromOrderOptionId)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->update(['order_option_id' => $toOrderOptionId]);
    }

    /**
     * {@inheritdoc}
     */
    public function incrementEarnLotAmount(MileageTransaction $lot, float $delta): void
    {
        // 스냅샷 기준 재계산 대신 컬럼 연산 — 그 사이 반영된 다른 증감을 덮어쓰지 않는다.
        MileageTransaction::query()->where('id', $lot->id)->incrementEach([
            'amount' => $delta,
            'remaining_amount' => $delta,
        ]);

        // 호출부가 이 모델을 그대로 반환·기록하므로 반영된 값으로 되읽는다
        $lot->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function resolveUserIdByUuid(string $uuid): ?int
    {
        $id = User::query()->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * {@inheritdoc}
     */
    public function existsRestoreForCancel(int $orderCancelId): bool
    {
        return MileageTransaction::query()
            ->where('order_cancel_id', $orderCancelId)
            ->whereIn('type', [
                MileageTransactionTypeEnum::REFUND_RESTORE->value,
                MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value,
            ])
            ->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function linkUsageToCancel(int $orderId, int $orderCancelId): int
    {
        // 해당 주문의 사용 거래 중 아직 취소에 묶이지 않은 건에 cancel_id 를 채워
        // 복원 거래와 동일 order_cancel_id 로 연결되게 한다 (getLinkedTransactions 정합).
        return MileageTransaction::query()
            ->where('order_id', $orderId)
            ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
            ->whereNull('order_cancel_id')
            ->update(['order_cancel_id' => $orderCancelId]);
    }

    /**
     * {@inheritdoc}
     */
    public function paginateWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        // 적립 lot 의 소멸 합 eager 집계 (N+1 회피) — 이 lot 을 source 로 하는 expired 거래 amount 합(음수).
        // 소멸분 합계는 같은 테이블을 다시 보는 상관 서브쿼리다. 별칭은 빌더가 만들고
        // (grammar 가 프리픽스를 별칭에도 붙인다), raw 는 집계 함수 한 곳만 남긴다.
        // 서브쿼리 FROM 이 한 테이블뿐이라 집계 대상 컬럼은 한정 없이 써도 모호하지 않다.
        $table = (new MileageTransaction)->getTable();

        $expiredAmount = DB::table("{$table} as exp")
            ->select(DB::raw('COALESCE(SUM(ABS(amount)), 0)'))
            ->where('exp.type', MileageTransactionTypeEnum::EXPIRED->value)
            ->whereColumn('exp.source_transaction_id', "{$table}.id");

        // 관계·집계·정렬은 쿼리에 붙이지 않는다 — 지연 조인 계약상 여기에는 필터/where 만 둔다.
        // 관계는 relations: 인자로, 소멸합 집계는 outerUsing 으로 outer 에서만 붙인다
        // (inner 에 두면 건너뛸 행 전체에 상관 서브쿼리가 돌아 깊은 OFFSET 비용이 그대로 남는다).
        $query = MileageTransaction::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // 거래유형: UI 4분류 슬러그(earn/use/expire/adjust)를 8종 enum으로 역매핑
        if (! empty($filters['type'])) {
            $query->whereIn('type', $this->typesForCategory($filters['type']));
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        // 기간 필터 — whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 못 쓰게 만든다.
        // 같은 결과를 내는 범위 조건으로 바꿔 created_at 인덱스를 살린다
        // (종료일은 그날 23:59:59.999999 까지 포함해야 whereDate 와 동일한 경계를 갖는다).
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }

        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        // 검색: search_field 별 대상 컬럼/관계 분기 (member/member_id/email/order)
        if (! empty($filters['search_keyword'])) {
            $keyword = $filters['search_keyword'];
            $field = $filters['search_field'] ?? 'member';

            $query->where(function ($outer) use ($field, $keyword) {
                switch ($field) {
                    case 'member_id':
                        $outer->where('user_id', $keyword);
                        break;
                    case 'email':
                        $outer->whereHas('user', function ($q) use ($keyword) {
                            $q->where('email', 'like', "%{$keyword}%");
                        });
                        break;
                    case 'order':
                        $outer->whereHas('order', function ($q) use ($keyword) {
                            $q->where('order_number', 'like', "%{$keyword}%");
                        });
                        break;
                    case 'member':
                    default:
                        $outer->whereHas('user', function ($q) use ($keyword) {
                            $q->where('name', 'like', "%{$keyword}%");
                        });
                        break;
                }
            });
        }

        // 적립/사용 이력은 계속 쌓이므로 지연 조인으로 뒤쪽 페이지 비용을 고정한다.
        //
        // columns 를 ['*'] 로 두는 이유: 목록 응답(MileageTransactionResource)이 금액·잔액·
        // 만료·메모·연결 주문까지 거의 모든 컬럼을 그대로 노출해 뺄 컬럼이 없다. 이 목록의
        // 이득은 컬럼 프루닝이 아니라 "넓은 컬럼을 읽는 행 수를 이번 페이지로 고정" 하는 쪽이다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $this->sortSpec($filters['sort'] ?? 'created_at_desc'),
            perPage: $perPage,
            relations: ['user', 'grantedByUser', 'order'],
            // 소멸합 집계는 결과 집합을 좁히지 않으므로 outer 에서만 실행한다.
            // `addSelect('*')` 를 함께 부르는 이유: outer 의 select 목록이 한 번 설정되면
            // `get(['*'])` 가 그것을 보존하므로, 명시하지 않으면 본 컬럼이 통째로 빠진다.
            outerUsing: fn (Builder $outer) => $outer
                ->addSelect('*')
                ->addSelect(['expired_amount' => $expiredAmount]),
            resultCap: PaginationLimits::resultCap('admin.mileage_transactions'),
        );
    }

    /**
     * 정렬 조건을 쿼리에 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $sort  정렬 슬러그
     */
    protected function applySort($query, string $sort): void
    {
        foreach ($this->sortSpec($sort) as $spec) {
            $query->orderBy($spec['column'], $spec['direction']);
        }
    }

    /**
     * 정렬 슬러그를 정렬 스펙으로 해석합니다.
     *
     * 지연 조인은 정렬이 적용되지 않은 쿼리와 정렬 스펙을 따로 받으므로, 쿼리에 직접
     * `orderBy` 를 붙이는 대신 스펙을 돌려준다. 선택지가 닫힌 슬러그 집합이라 요청 값이
     * 그대로 컬럼명으로 새지 않는다.
     *
     * @param  string  $sort  정렬 슬러그
     * @return array<int, array{column: string, direction: string}> 정렬 스펙
     */
    protected function sortSpec(string $sort): array
    {
        return match ($sort) {
            'created_at_asc' => [['column' => 'created_at', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            'amount_desc' => [['column' => 'amount', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
            'amount_asc' => [['column' => 'amount', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            default => [['column' => 'created_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
        };
    }

    /**
     * {@inheritdoc}
     */
    public function paginateForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = MileageTransaction::query()->where('user_id', $userId);

        if (! empty($filters['category'])) {
            $query->whereIn('type', $this->typesForCategory($filters['category']));
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        // 회원 마일리지 내역 — 계속 쌓이는 이력이라 지연 조인으로 뒤쪽 페이지 비용을 고정한다
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'id', 'direction' => 'desc']],
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('admin.mileage_transactions'),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiringLots(Carbon $now, ?int $limit = null): Collection
    {
        $query = MileageTransaction::query()
            ->expiringBefore($now)
            ->orderBy('id', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * {@inheritdoc}
     */
    public function markExpired(MileageTransaction $lot, Carbon $now): void
    {
        $lot->remaining_amount = 0;
        $lot->expired_at = $now;
        $lot->save();
    }

    /**
     * {@inheritdoc}
     */
    public function updateAdminFields(MileageTransaction $transaction, array $fields): MileageTransaction
    {
        if (array_key_exists('memo', $fields)) {
            $transaction->memo = $fields['memo'];
        }
        if (array_key_exists('expires_at', $fields)) {
            $transaction->expires_at = $fields['expires_at'];
        }
        $transaction->save();

        return $transaction;
    }

    /**
     * {@inheritdoc}
     */
    public function getLinkedTransactions(MileageTransaction $transaction): Collection
    {
        return MileageTransaction::query()
            ->with(['user', 'grantedByUser', 'order'])
            ->where(function ($q) use ($transaction) {
                // 이 거래가 소비한 원본 적립건 + 이 적립건을 소비한 차감 거래
                $q->where('id', $transaction->source_transaction_id)
                    ->orWhere('source_transaction_id', $transaction->id);

                // 동일 주문취소로 연결된 거래
                if ($transaction->order_cancel_id !== null) {
                    $q->orWhere('order_cancel_id', $transaction->order_cancel_id);
                }
            })
            ->where('id', '!=', $transaction->id)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?MileageTransaction
    {
        return MileageTransaction::query()->find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function getEarnableOptions(string $triggerColumn, string $triggerStatus, int $delayDays, Carbon $now, ?int $limit = null): \Illuminate\Support\Collection
    {
        $threshold = $now->copy()->subDays($delayDays);

        // 테이블명은 모델에서 얻는다 (문자열 하드코딩 금지)
        $query = DB::table((new OrderOption)->getTable().' as opt')
            ->join((new Order)->getTable().' as ord', 'opt.order_id', '=', 'ord.id')
            ->whereNotNull('ord.user_id')
            ->where('opt.option_status', $triggerStatus)
            ->whereNotNull("opt.{$triggerColumn}")
            ->where("opt.{$triggerColumn}", '<=', $threshold)
            ->where('opt.subtotal_earned_points_amount', '>', 0)
            // 금액 델타 멱등: 목표 적립액보다 기적립 purchase_earn 합계가 적은 옵션을 대상으로 삼는다.
            // (나눠 확정·병합으로 목표액이 늘어난 옵션의 잔여분까지 스케줄러가 포착)
            // 빌더 서브쿼리로 표현 — raw SQL/테이블 별칭은 prefix 자동 적용을 받지 못하므로 별칭 없이 컬럼만 참조.
            ->where('opt.subtotal_earned_points_amount', '>', function ($sub) {
                $sub->from((new MileageTransaction)->getTable())
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('order_option_id', 'opt.id')
                    ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value);
            })
            ->orderBy('opt.id', 'asc')
            ->select([
                'opt.id as option_id',
                'opt.order_id as order_id',
                'ord.user_id as user_id',
            ]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findEarnLotForOption(int $orderOptionId): ?MileageTransaction
    {
        return MileageTransaction::query()
            ->where('order_option_id', $orderOptionId)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findEarnLotForOptionForUpdate(int $orderOptionId): ?MileageTransaction
    {
        // 잠금은 트랜잭션 안에서만 의미가 있다 — 적립/회수 갱신 트랜잭션에서 호출한다
        return MileageTransaction::query()
            ->where('order_option_id', $orderOptionId)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveLotsForUser(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 사용자의 잔액 산정용 활성 lot 전량 —
        // 잔액은 미소진 lot 의 합이라 일부만 읽으면 값이 틀린다. 소진된 lot 은 active()
        // 스코프에서 빠지므로 모수가 사용 이력에 비례해 늘지 않는다
        return MileageTransaction::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function getRemainingLotsByIds(int $userId, array $lotIds): Collection
    {
        return MileageTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('id', $lotIds)
            ->where('remaining_amount', '>', 0)
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function updateExpiry(MileageTransaction $lot, Carbon $expiresAt): void
    {
        $lot->expires_at = $expiresAt;
        $lot->save();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteForUser(int $userId): int
    {
        return MileageTransaction::query()
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * 사용자 표시 분류(earn/use/expire/adjust)에 해당하는 거래 유형 목록을 반환합니다.
     *
     * @param  string  $category  표시 분류
     * @return array<int, string> 거래 유형 값 목록
     */
    private function typesForCategory(string $category): array
    {
        $map = [];
        foreach (MileageTransactionTypeEnum::cases() as $case) {
            $map[$case->userDisplayCategory()][] = $case->value;
        }

        return $map[$category] ?? [];
    }
}
