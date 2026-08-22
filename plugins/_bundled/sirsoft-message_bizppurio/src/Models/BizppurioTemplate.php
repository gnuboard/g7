<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;

/**
 * 비즈뿌리오 알림 템플릿 모델 (#597).
 *
 * 알림 1건(notification_definitions.type)당 1행. 알림톡 템플릿의 카카오 등록
 * 페이로드(content)·승인 스냅샷(approved_content)·검수 상태와, 대체 SMS/SMS 단독
 * 본문(sms_body)을 저장한다. 발송 드라이버는 이 행만 보고 판정한다(DB = 발송 SSoT).
 *
 * @property int $id
 * @property string $notification_type
 * @property bool $alimtalk_enabled
 * @property string|null $template_code
 * @property string|null $sender_key
 * @property array|null $content
 * @property array|null $approved_content
 * @property BizppurioTemplateStatus $status
 * @property array|null $inspection_detail
 * @property Carbon|null $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $last_synced_at
 * @property bool $fallback_sms_enabled
 * @property array|null $sms_body
 * @property bool $sms_only
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BizppurioTemplate extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'bizppurio_templates';

    /**
     * 대량 할당 허용 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'notification_type',
        'alimtalk_enabled',
        'template_code',
        'sender_key',
        'content',
        'approved_content',
        'status',
        'inspection_detail',
        'requested_at',
        'approved_at',
        'last_synced_at',
        'fallback_sms_enabled',
        'sms_body',
        'sms_only',
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
            'alimtalk_enabled' => 'boolean',
            'content' => 'array',
            'approved_content' => 'array',
            'status' => BizppurioTemplateStatus::class,
            'inspection_detail' => 'array',
            'sms_body' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'fallback_sms_enabled' => 'boolean',
            'sms_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 알림 유형으로 조회하는 스코프 (발송 시 행 해석).
     *
     * @param  Builder  $query
     * @param  string  $notificationType  코어 notification_definitions.type
     * @return Builder
     */
    public function scopeByNotificationType(Builder $query, string $notificationType): Builder
    {
        return $query->where('notification_type', $notificationType);
    }

    /**
     * 수신자 로케일의 SMS 본문을 반환합니다 (#597 §14.3).
     *
     * sms_body 는 다국어 맵이다. 코어 NotificationContentBehavior::getLocalizedBody() 와
     * 동일한 해석 규칙을 쓴다 — 해당 로케일이 없으면 fallback_locale, 그것도 없으면 빈 문자열.
     * 알림톡 content 가 단일 언어인 것은 카카오 제약 때문이며 SMS 에는 그 제약이 없다.
     *
     * @param  string|null  $locale  수신자 로케일 (null 이면 현재 앱 로케일)
     * @return string 로케일에 맞는 본문 (없으면 빈 문자열)
     */
    public function getLocalizedSmsBody(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $body = is_array($this->sms_body) ? $this->sms_body : [];

        $resolved = $body[$locale] ?? $body[config('app.fallback_locale', 'ko')] ?? '';

        return is_string($resolved) ? $resolved : '';
    }

    /**
     * SMS 본문이 어느 로케일에든 채워져 있는지 판정합니다 (발송 게이트용).
     *
     * 게이트는 "이 알림에 문자 본문이 설정되어 있는가" 를 묻는다. 특정 수신자의 로케일에
     * 본문이 없더라도 fallback 으로 발송되므로, 한 로케일이라도 비어있지 않으면 true 다.
     * 로케일별 공백 판정은 발송 시점에 getLocalizedSmsBody() 로 다시 한다.
     *
     * @return bool 하나 이상의 로케일에 본문이 있으면 true
     */
    public function hasSmsBody(): bool
    {
        foreach (is_array($this->sms_body) ? $this->sms_body : [] as $text) {
            if (is_string($text) && trim($text) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 알림톡 발송 가능 여부를 판정합니다 (발송 게이트 §3.5).
     *
     * alimtalk_enabled + is_active + 승인 상태 + 승인 스냅샷 존재를 모두 요구하고,
     * sms_only("SMS 단독")가 켜져 있으면 차단한다.
     * 승인 취소로 status 가 draft 로 복귀하면 approved_content 가 남아 있어도 즉시 차단된다.
     *
     * sms_only 는 이 알림에서 알림톡을 쓰지 않겠다는 운영자의 명시 선언이다(§3.5). 화면 라벨이
     * "SMS 단독" 인 이상 이 판정에 반영되지 않으면 체크가 아무것도 바꾸지 못한다. 반대로 SMS
     * 발송은 sms_only 와 무관하게 sms_body 기준으로만 판정한다(SmsChannelDriver) — sms_only 는
     * 알림톡을 끄는 플래그지 SMS 를 켜는 플래그가 아니다.
     *
     * @return bool 알림톡 발송 가능하면 true
     */
    public function isAlimtalkSendable(): bool
    {
        return $this->alimtalk_enabled
            && $this->is_active
            && ! $this->sms_only
            && $this->status->isApproved()
            && is_array($this->approved_content)
            && $this->approved_content !== [];
    }
}
