<?php

namespace App\Models;

use App\Models\Concerns\HasUserOverrides;
use App\Models\Concerns\IdentityMessageContentBehavior;
use App\Services\IdentityMessageDefinitionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 본인인증 메시지 템플릿 모델.
 *
 * 정의(IdentityMessageDefinition) 1건 × 채널 N개 구조. 다국어 subject/body + 활성 토글
 * + user_overrides 보존을 제공합니다. NotificationTemplate 와 분리된 IDV 전용 모델.
 */
class IdentityMessageTemplate extends Model
{
    use HasFactory, HasUserOverrides, IdentityMessageContentBehavior;

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = [
        'subject',
        'body',
        'is_active',
    ];

    /**
     * 활동 로그 추적 필드.
     *
     * @var array<string, array<string, string>>
     */
    public static array $activityLogFields = [
        'subject' => ['label_key' => 'activity_log.fields.subject', 'type' => 'text'],
        'body' => ['label_key' => 'activity_log.fields.body', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * @var string
     */
    protected $table = 'identity_message_templates';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'definition_id',
        'channel',
        'subject',
        'body',
        'is_active',
        'is_default',
        'user_overrides',
        'updated_by',
    ];

    /**
     * 모델 이벤트 등록 — 변경 시 정의/템플릿 캐시 자동 삭제.
     */
    protected static function booted(): void
    {
        $invalidate = function () {
            try {
                app(IdentityMessageDefinitionService::class)->invalidateAllCache();
            } catch (\Throwable) {
                // 테스트/마이그레이션 환경에서 서비스 미등록 시 무시
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * 캐스팅 정의.
     *
     * IdentityMessageContentBehavior trait 가 subject/body/is_active/is_default 를 처리하므로
     * 여기서는 user_overrides 만 추가합니다.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_overrides' => 'array',
        ];
    }

    /**
     * 메시지 정의 관계.
     *
     * @return BelongsTo
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(IdentityMessageDefinition::class, 'definition_id');
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
}
