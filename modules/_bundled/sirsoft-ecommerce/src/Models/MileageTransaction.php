<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;

/**
 * 마일리지 거래(원장) 모델
 *
 * 잔액 SSoT = SUM(remaining_amount WHERE remaining_amount > 0 AND 미만료).
 * 거래 행은 불변(ChangeDetector 미사용 — $activityLogFields 불요).
 */
class MileageTransaction extends Model
{
    protected $table = 'ecommerce_mileage_transactions';

    protected $fillable = [
        'user_id',
        'currency',
        'type',
        'amount',
        'remaining_amount',
        'balance_after',
        'order_id',
        'order_option_id',
        'order_cancel_id',
        'source_transaction_id',
        'granted_by',
        'description',
        'memo',
        'expires_at',
        'expired_at',
        'metadata',
    ];

    /**
     * 캐스트 정의
     *
     * @return array<string, string> 캐스트 맵
     */
    protected function casts(): array
    {
        return [
            'type' => MileageTransactionTypeEnum::class,
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * 회원 관계
     *
     * @return BelongsTo 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 주문 관계
     *
     * @return BelongsTo 주문 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 주문옵션 관계 (FK 없음 — order_option_id 컬럼만 참조)
     *
     * @return BelongsTo 주문옵션 관계
     */
    public function orderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'order_option_id');
    }

    /**
     * 부여 주체(관리자) 관계 — 관리자 셀 컨텍스트 메뉴용 (eager load 로 N+1 회피)
     *
     * @return BelongsTo 부여 관리자 관계
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * 원본 적립건 관계 (차감 시 FIFO 추적)
     *
     * @return BelongsTo 원본 적립건 관계
     */
    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_transaction_id');
    }

    /**
     * 사용 가능한 적립건(lot) 스코프: 잔여금액 > 0, 미소멸, 미만료
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 적용된 쿼리 빌더
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('remaining_amount', '>', 0)
            ->whereNull('expired_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * 특정 시점 이전 만료 예정 적립건 스코프 (잔여금액 > 0, 미소멸)
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  Carbon  $before  기준 시각
     * @return Builder 적용된 쿼리 빌더
     */
    public function scopeExpiringBefore(Builder $query, $before): Builder
    {
        return $query->where('remaining_amount', '>', 0)
            ->whereNull('expired_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $before);
    }

    /**
     * 회원 + 통화 한정 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  int  $userId  회원 ID
     * @param  string  $currency  통화 코드
     * @return Builder 적용된 쿼리 빌더
     */
    public function scopeForUserCurrency(Builder $query, int $userId, string $currency): Builder
    {
        return $query->where('user_id', $userId)->where('currency', $currency);
    }
}
