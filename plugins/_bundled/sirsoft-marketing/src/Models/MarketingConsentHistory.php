<?php

namespace Plugins\Sirsoft\Marketing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 사용자 마케팅 동의 이력 모델
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel_key
 * @property string $action
 * @property string $source
 * @property string|null $ip_address
 * @property \Carbon\Carbon|null $created_at
 * @property-read User $user
 */
class MarketingConsentHistory extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'user_marketing_consent_histories';

    /**
     * updated_at 컬럼 비활성화
     *
     * @var bool
     */
    public const UPDATED_AT = null;

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'channel_key',
        'action',
        'source',
        'ip_address',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * 사용자 관계
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
