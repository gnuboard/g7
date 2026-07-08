<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;

/**
 * 마일리지 거래(원장) Repository 구현체
 */
class MileageTransactionRepository implements MileageTransactionRepositoryInterface
{
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
        $lot->remaining_amount = (float) $lot->remaining_amount - $amount;
        $lot->save();
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
        $lot->amount = (float) $lot->amount + $delta;
        $lot->remaining_amount = (float) $lot->remaining_amount + $delta;
        $lot->save();
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
        // 상관 서브쿼리는 self-reference + alias 가 빌더 prefix 처리와 충돌하므로 raw 로 일관 작성한다
        // (prefix 는 실제 테이블명에 한 번만 직접 적용).
        $prefixedTable = DB::connection()->getTablePrefix().(new MileageTransaction)->getTable();
        $expiredType = MileageTransactionTypeEnum::EXPIRED->value;
        $expiredSumSub = "(select COALESCE(SUM(ABS(exp.amount)), 0) from `{$prefixedTable}` as exp"
            ." where exp.type = '{$expiredType}' and exp.source_transaction_id = `{$prefixedTable}`.id)";

        $query = MileageTransaction::query()
            ->with(['user', 'grantedByUser', 'order'])
            ->addSelect('*')
            ->addSelect(DB::raw("{$expiredSumSub} as expired_amount"));

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

        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
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

        $this->applySort($query, $filters['sort'] ?? 'created_at_desc');

        return $query->paginate($perPage);
    }

    /**
     * 정렬 조건을 쿼리에 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $sort  정렬 슬러그
     */
    protected function applySort($query, string $sort): void
    {
        switch ($sort) {
            case 'created_at_asc':
                $query->orderBy('created_at', 'asc')->orderBy('id', 'asc');
                break;
            case 'amount_desc':
                $query->orderByDesc('amount')->orderByDesc('id');
                break;
            case 'amount_asc':
                $query->orderBy('amount', 'asc')->orderBy('id', 'asc');
                break;
            case 'created_at_desc':
            default:
                $query->orderByDesc('created_at')->orderByDesc('id');
                break;
        }
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

        return $query->orderByDesc('id')->paginate($perPage);
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

        $query = DB::table('ecommerce_order_options as opt')
            ->join('ecommerce_orders as ord', 'opt.order_id', '=', 'ord.id')
            ->whereNotNull('ord.user_id')
            ->where('opt.option_status', $triggerStatus)
            ->whereNotNull("opt.{$triggerColumn}")
            ->where("opt.{$triggerColumn}", '<=', $threshold)
            ->where('opt.subtotal_earned_points_amount', '>', 0)
            // 금액 델타 멱등: 목표 적립액보다 기적립 purchase_earn 합계가 적은 옵션을 대상으로 삼는다.
            // (나눠 확정·병합으로 목표액이 늘어난 옵션의 잔여분까지 스케줄러가 포착)
            // 빌더 서브쿼리로 표현 — raw SQL/테이블 별칭은 prefix 자동 적용을 받지 못하므로 별칭 없이 컬럼만 참조.
            ->where('opt.subtotal_earned_points_amount', '>', function ($sub) {
                $sub->from('ecommerce_mileage_transactions')
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
    public function getActiveLotsForUser(int $userId): Collection
    {
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
