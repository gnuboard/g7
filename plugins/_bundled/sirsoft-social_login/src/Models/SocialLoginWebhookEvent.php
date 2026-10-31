<?php

namespace Plugins\Sirsoft\SocialLogin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 제공자 웹훅 수신 1건.
 *
 * @property int $id
 * @property string $provider_key
 * @property string $event_key
 * @property string $event_type
 * @property string|null $provider_user_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $confirmation_code
 *
 * @since 1.0.0
 */
class SocialLoginWebhookEvent extends Model
{
    /** @var string 테이블명 */
    protected $table = 'social_login_webhook_events';

    /** @var bool 수신·처리 시각을 직접 관리하는 기록 테이블 */
    public $timestamps = false;

    /** @var array<int, string> 대량 할당 허용 컬럼 */
    protected $fillable = [
        'provider_key', 'event_key', 'event_type', 'provider_user_id', 'payload', 'status', 'attempts', 'error',
        'received_at', 'processed_at', 'confirmation_code',
    ];

    /**
     * 캐스트 정의.
     *
     * @return array<string, string> 컬럼 => 캐스트
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
