<?php

namespace App\Models;

use App\Enums\NotificationLogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /**
     * @var string
     */
    protected $table = 'notification_logs';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'channel',
        'notification_type',
        'extension_type',
        'extension_identifier',
        'recipient_user_id',
        'recipient_identifier',
        'recipient_name',
        'sender_user_id',
        'subject',
        'body',
        'status',
        'error_message',
        'source',
        'sent_at',
    ];

    /**
     * 캐스팅 정의.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationLogStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * 수신자 사용자 관계.
     *
     * @return BelongsTo
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * 발송자 사용자 관계.
     *
     * @return BelongsTo
     */
    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * 특정 채널의 로그만 조회.
     *
     * @param Builder $query
     * @param string $channel
     * @return Builder
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * 특정 상태의 로그만 조회.
     *
     * @param Builder $query
     * @param NotificationLogStatus $status
     * @return Builder
     */
    public function scopeByStatus(Builder $query, NotificationLogStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * 특정 알림 타입의 로그만 조회.
     *
     * @param Builder $query
     * @param string $type
     * @return Builder
     */
    public function scopeByNotificationType(Builder $query, string $type): Builder
    {
        return $query->where('notification_type', $type);
    }
}
