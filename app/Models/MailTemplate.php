<?php

namespace App\Models;

use App\Models\Concerns\MailTemplateBehavior;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 코어 메일 템플릿 모델.
 *
 * 코어 시스템의 메일 템플릿(welcome, reset_password, password_changed)을 관리합니다.
 */
class MailTemplate extends Model
{
    use HasFactory, MailTemplateBehavior;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'subject' => ['label_key' => 'activity_log.fields.subject', 'type' => 'text'],
        'body' => ['label_key' => 'activity_log.fields.body', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
        'is_default' => ['label_key' => 'activity_log.fields.is_default', 'type' => 'boolean'],
    ];

    /**
     * @var string 테이블명
     */
    protected $table = 'mail_templates';

    /**
     * @var array<string, mixed> 기본 속성 값
     */
    protected $attributes = [
        'variables' => '[]',
    ];

    /**
     * @var array<int, string> Mass assignable 필드
     */
    protected $fillable = [
        'type',
        'subject',
        'body',
        'variables',
        'is_active',
        'is_default',
        'user_overrides',
        'updated_by',
    ];

    /**
     * @return array<string, string> 타입 캐스팅
     */
    protected function casts(): array
    {
        return [
            'user_overrides' => 'array',
        ];
    }

    /**
     * 마지막 수정자를 반환합니다.
     *
     * @return BelongsTo User 관계
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
