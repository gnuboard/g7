<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Plugins\Sirsoft\MessageBizppurio\Enums\DispatchChannel;

/**
 * 비즈뿌리오 이벤트↔알림톡 템플릿 연결(설정) 모델.
 *
 * "어느 알림에 어느 알림톡 템플릿을 쓸지 + 대체발송 여부"를 저장한다.
 * 알림 설정 알림톡 탭 편집 모달(계획서 §6-2)이 우리 API 로 저장하고,
 * 알림톡 채널 드라이버(Phase 6)가 이 연결을 조회해 발송 대상 템플릿을 결정한다.
 *
 * @property int $id
 * @property string $notification_type
 * @property string $channel
 * @property string $template_code
 * @property string $template_name
 * @property bool $fallback_sms_enabled
 * @property bool $is_active
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class BizppurioNotificationBinding extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'bizppurio_notification_bindings';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'notification_type',
        'channel',
        'template_code',
        'template_name',
        'fallback_sms_enabled',
        'is_active',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DispatchChannel::class,
            'fallback_sms_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 활성 연동만 조회하는 스코프.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 알림 유형으로 조회하는 스코프 (발송 시 template_code 해석).
     *
     * @param  Builder  $query
     * @param  string  $notificationType  코어 notification_definitions.type
     * @return Builder
     */
    public function scopeByNotificationType(Builder $query, string $notificationType): Builder
    {
        return $query->where('notification_type', $notificationType);
    }
}
