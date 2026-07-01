<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 마일리지 잔액 캐시 모델 (파생 캐시)
 *
 * SSoT 는 ecommerce_mileage_transactions(원장)이며 본 모델은 표시 전용 파생 캐시.
 * 활동로그 비대상($activityLogFields 불요).
 */
class MileageBalance extends Model
{
    protected $table = 'ecommerce_mileage_balances';

    protected $fillable = [
        'user_id',
        'currency',
        'available',
        'pending',
        'total_earned',
        'total_used',
        'expiring_soon',
        'expiring_date',
        'recalculated_at',
    ];

    /**
     * 캐스트 정의
     *
     * @return array<string, string> 캐스트 맵
     */
    protected function casts(): array
    {
        return [
            'available' => 'decimal:2',
            'pending' => 'decimal:2',
            'total_earned' => 'decimal:2',
            'total_used' => 'decimal:2',
            'expiring_soon' => 'decimal:2',
            'expiring_date' => 'datetime',
            'recalculated_at' => 'datetime',
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
}
