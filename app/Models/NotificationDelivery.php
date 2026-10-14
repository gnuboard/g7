<?php

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 알림 수신자별 발송 기록 (#139)
 *
 * 알림 발송 잡이 재시도·재예약으로 다시 돌 때 이미 보낸 수신자를 건너뛰기 위한 중복 방지 행.
 * 키는 sha256(이벤트 ID ␟ 알림 유형 ␟ 채널 ␟ 수신자 키) — NotificationDeliveryGuard::deliveryKey().
 *
 * @property int $id
 * @property string $delivery_key
 * @property string $event_id
 * @property string $notification_type
 * @property string $channel
 * @property string $recipient_key
 * @property NotificationDeliveryStatus $status
 * @property int $attempts
 * @property Carbon $claimed_at
 * @property Carbon|null $completed_at
 */
class NotificationDelivery extends Model
{
    /**
     * 대량 할당 허용 컬럼
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'delivery_key',
        'event_id',
        'notification_type',
        'channel',
        'recipient_key',
        'status',
        'attempts',
        'claimed_at',
        'completed_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 캐스팅 정의
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationDeliveryStatus::class,
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
