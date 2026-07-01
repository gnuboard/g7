<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;

/**
 * 마일리지 잔액 캐시 Repository 구현체 (단방향 파생 — 원장/옵션 → 캐시)
 */
class MileageBalanceRepository implements MileageBalanceRepositoryInterface
{
    /**
     * 적립(잔액 증가) 유형 값 목록
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
     * 취소 상태 값 목록 (pending 집계 제외 대상)
     *
     * 취소는 옵션 단위로 처리되어 option_status 가 'cancelled' 로만 전이된다.
     * (부분취소는 별도 상태가 아니라 일부 옵션 cancelled + 잔여 활성 옵션 공존 — partial_cancelled 제거.)
     *
     * @var array<int, string>
     */
    private const CANCELLED_STATUSES = [
        'cancelled',
    ];

    /**
     * {@inheritdoc}
     */
    public function getCachedBalance(int $userId, ?string $currency = null): array
    {
        $rows = MileageBalance::query()->where('user_id', $userId)->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[$row->currency] = [
                'available' => (float) $row->available,
                'pending' => (float) $row->pending,
                'total_earned' => (float) $row->total_earned,
                'total_used' => (float) $row->total_used,
                'expiring_soon' => (float) $row->expiring_soon,
                'expiring_date' => $row->expiring_date?->toIso8601String(),
            ];
        }

        if ($currency !== null) {
            return ($byCurrency[$currency] ?? $this->emptyBalance()) + ['by_currency' => $byCurrency];
        }

        // 전체 통화 합산 + by_currency
        $summary = $this->emptyBalance();
        $earliest = null;
        foreach ($rows as $row) {
            $summary['available'] += (float) $row->available;
            $summary['pending'] += (float) $row->pending;
            $summary['total_earned'] += (float) $row->total_earned;
            $summary['total_used'] += (float) $row->total_used;
            $summary['expiring_soon'] += (float) $row->expiring_soon;
            if ($row->expiring_date !== null && ($earliest === null || $row->expiring_date < $earliest)) {
                $earliest = $row->expiring_date;
            }
        }
        $summary['expiring_date'] = $earliest?->toIso8601String();
        $summary['by_currency'] = $byCurrency;

        return $summary;
    }

    /**
     * {@inheritdoc}
     */
    public function recalculateForUser(int $userId, ?string $currency = null): void
    {
        foreach ($this->resolveCurrencies($userId, $currency) as $cur) {
            $available = (float) MileageTransaction::query()
                ->forUserCurrency($userId, $cur)
                ->active()
                ->sum('remaining_amount');

            $totalEarned = (float) MileageTransaction::query()
                ->forUserCurrency($userId, $cur)
                ->whereIn('type', self::EARN_TYPES)
                ->sum('amount');

            $totalUsed = (float) abs((float) MileageTransaction::query()
                ->forUserCurrency($userId, $cur)
                ->whereIn('type', [
                    MileageTransactionTypeEnum::ORDER_USE->value,
                    MileageTransactionTypeEnum::ADMIN_DEDUCT->value,
                    MileageTransactionTypeEnum::EARN_CANCEL->value,
                ])
                ->sum('amount'));

            $this->upsert($userId, $cur, [
                'available' => $available,
                'total_earned' => $totalEarned,
                'total_used' => $totalUsed,
                'recalculated_at' => now(),
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function recalculatePending(int $userId, ?string $currency = null): void
    {
        foreach ($this->resolveCurrencies($userId, $currency) as $cur) {
            $pending = $this->computePending($userId, $cur);
            $this->upsert($userId, $cur, ['pending' => $pending]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function recalculateExpiringWindow(int $daysBefore): void
    {
        $windowEnd = now()->addDays($daysBefore);

        // 활성 캐시 행이 있는 (user, currency) 단위로 소멸 예정 합/최근일 재계산
        MileageBalance::query()->chunkById(500, function ($balances) use ($windowEnd) {
            foreach ($balances as $balance) {
                $soon = (float) MileageTransaction::query()
                    ->forUserCurrency($balance->user_id, $balance->currency)
                    ->where('remaining_amount', '>', 0)
                    ->whereNull('expired_at')
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), $windowEnd])
                    ->sum('remaining_amount');

                $earliest = MileageTransaction::query()
                    ->forUserCurrency($balance->user_id, $balance->currency)
                    ->active()
                    ->whereNotNull('expires_at')
                    ->min('expires_at');

                $balance->expiring_soon = $soon;
                $balance->expiring_date = $earliest;
                $balance->save();
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function recalculateAll(): void
    {
        // 원장에 거래가 있는 모든 (user, currency) 조합을 재산출
        $pairs = MileageTransaction::query()
            ->select('user_id', 'currency')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $this->recalculateForUser($pair->user_id, $pair->currency);
            $this->recalculatePending($pair->user_id, $pair->currency);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getExpiringTargets(?int $limit = null): Collection
    {
        $query = MileageBalance::query()
            ->with('user')
            ->where('expiring_soon', '>', 0)
            ->whereNotNull('expiring_date')
            ->orderBy('expiring_date', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->toBase();
    }

    /**
     * pending(적립 예정) 금액을 계산합니다.
     *
     * 미취소 + earn ledger 부재 옵션의 subtotal_earned_points_amount 합.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $currency  통화 코드
     * @return float 적립 예정 금액
     */
    private function computePending(int $userId, string $currency): float
    {
        return (float) DB::table('ecommerce_order_options as opt')
            ->join('ecommerce_orders as ord', 'opt.order_id', '=', 'ord.id')
            ->where('ord.user_id', $userId)
            ->where('ord.currency', $currency)
            ->whereNotIn('opt.option_status', self::CANCELLED_STATUSES)
            ->where('opt.subtotal_earned_points_amount', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('ecommerce_mileage_transactions as tx')
                    ->whereColumn('tx.order_option_id', 'opt.id')
                    ->whereIn('tx.type', self::EARN_TYPES);
            })
            ->sum('opt.subtotal_earned_points_amount');
    }

    /**
     * 캐시 행을 upsert 합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $currency  통화 코드
     * @param  array  $values  갱신 값
     */
    private function upsert(int $userId, string $currency, array $values): void
    {
        MileageBalance::query()->updateOrCreate(
            ['user_id' => $userId, 'currency' => $currency],
            $values,
        );
    }

    /**
     * 재계산 대상 통화 목록을 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $currency  통화 코드 (null 시 회원의 모든 통화)
     * @return array<int, string> 통화 코드 목록
     */
    private function resolveCurrencies(int $userId, ?string $currency): array
    {
        if ($currency !== null) {
            return [$currency];
        }

        $fromLedger = MileageTransaction::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('currency')
            ->all();

        $fromCache = MileageBalance::query()
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('currency')
            ->all();

        return array_values(array_unique(array_merge($fromLedger, $fromCache)));
    }

    /**
     * {@inheritdoc}
     */
    public function deleteForUser(int $userId): int
    {
        return MileageBalance::query()
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * 빈 잔액 배열을 반환합니다.
     *
     * @return array 빈 잔액 배열
     */
    private function emptyBalance(): array
    {
        return [
            'available' => 0.0,
            'pending' => 0.0,
            'total_earned' => 0.0,
            'total_used' => 0.0,
            'expiring_soon' => 0.0,
            'expiring_date' => null,
        ];
    }
}
