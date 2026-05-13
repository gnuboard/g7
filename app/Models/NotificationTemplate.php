<?php

namespace App\Models;

use App\Models\Concerns\HasUserOverrides;
use App\Models\Concerns\NotificationContentBehavior;
use App\Services\NotificationDefinitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    use HasFactory, HasUserOverrides, NotificationContentBehavior;

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = [
        'subject',
        'body',
        'click_url',
        'recipients',
        'is_active',
    ];

    /**
     * 모델 이벤트 등록 — 템플릿 변경 시 알림 정의 캐시 자동 삭제.
     */
    protected static function booted(): void
    {
        $invalidate = function () {
            try {
                app(NotificationDefinitionService::class)->invalidateAllCache();
            } catch (\Throwable) {
                // 테스트/마이그레이션 환경에서 서비스 미등록 시 무시
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * 활동 로그 추적 필드.
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'subject' => ['label_key' => 'activity_log.fields.subject', 'type' => 'text'],
        'body' => ['label_key' => 'activity_log.fields.body', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
        'recipients' => ['label_key' => 'activity_log.fields.recipients', 'type' => 'text'],
    ];

    /**
     * @var string
     */
    protected $table = 'notification_templates';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'definition_id',
        'channel',
        'subject',
        'body',
        'click_url',
        'recipients',
        'is_active',
        'is_default',
        'user_overrides',
        'updated_by',
    ];

    /**
     * 캐스팅 정의.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'user_overrides' => 'array',
        ];
    }

    /**
     * 알림 정의 관계.
     *
     * @return BelongsTo
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(NotificationDefinition::class, 'definition_id');
    }

    /**
     * 수정자 관계.
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 특정 채널의 템플릿 조회.
     *
     * @param Builder $query
     * @param string $channel
     * @return Builder
     */
    /**
     * 수신자 규칙이 설정되어 있는지 확인합니다.
     *
     * @return bool
     */
    public function hasRecipientConfig(): bool
    {
        return ! empty($this->recipients);
    }

    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
