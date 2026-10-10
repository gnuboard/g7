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
     * 다국어 JSON 컬럼 — 운영자 수정을 dot-path(`"subject.ja"` 등) sub-key 단위로 기록.
     *
     * 미선언 시 수정이 컬럼 전체 항목(`"subject"`)으로 기록되어 시더 재실행이 컬럼을
     * 통째로 얼리고(다른 로케일 갱신·신규 팩 로케일 추가 불가), 언어팩 병합의
     * 사용자 수정 보존(Case B)도 로케일 단위로 판정할 수 없다 (#597 에서 교정).
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = [
        'subject',
        'body',
    ];

    /**
     * 모델 이벤트 등록 — 템플릿 변경 시 알림 정의 캐시 자동 삭제.
     *
     * @return void 반환값 없음
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
     * 수신자 규칙이 설정되어 있는지 확인합니다.
     *
     * @return bool
     */
    public function hasRecipientConfig(): bool
    {
        return ! empty($this->recipients);
    }

    /**
     * 수정 여부 판정용 값 — 수신자 규칙은 같은 뜻의 여러 저장 모양을 한 모양으로 맞춥니다.
     *
     * 역할 값은 문자열 하나(`"admin"`)와 한 칸 목록(`["admin"]`)이 같은 규칙이고(발송 해석기가 둘 다 받는다),
     * 화면이 덧붙인 표시용 이름(`display_names`)과 꺼진 「유발자 제외」·빈 관계는 규칙의 뜻을 바꾸지 않는다.
     *
     * @param  string  $field  trackable 컬럼명
     * @param  mixed  $value  비교할 값
     * @return mixed 비교용 값
     */
    public function comparableTrackableValue(string $field, mixed $value): mixed
    {
        if ($field !== 'recipients') {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(function ($rule) {
            if (! is_array($rule)) {
                return $rule;
            }

            unset($rule['display_names']);
            if (empty($rule['exclude_trigger_user'])) {
                unset($rule['exclude_trigger_user']);
            }
            if (($rule['relation'] ?? null) === null || $rule['relation'] === '') {
                unset($rule['relation']);
            }
            if (($rule['type'] ?? null) === 'role' && is_string($rule['value'] ?? null)) {
                $rule['value'] = [$rule['value']];
            }

            return $rule;
        }, $value);
    }

    /**
     * 특정 채널의 템플릿 조회.
     *
     * @param  Builder  $query
     * @param  string  $channel
     * @return Builder
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
