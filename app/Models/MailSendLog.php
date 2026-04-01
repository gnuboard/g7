<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 메일 발송 이력 모델
 *
 * @property int $id
 * @property string $recipient_email
 * @property string|null $recipient_name
 * @property string|null $subject
 * @property string|null $template_type
 * @property ExtensionOwnerType $extension_type
 * @property string $extension_identifier
 * @property string|null $source
 * @property string $status
 * @property string|null $sender_email
 * @property string|null $sender_name
 * @property string|null $error_message
 * @property \Carbon\Carbon $sent_at
 */
class MailSendLog extends Model
{
    use HasFactory;

    /**
     * @var string 테이블명
     */
    protected $table = 'mail_send_logs';

    /**
     * @var array<int, string> 대량 할당 가능 필드
     */
    protected $fillable = [
        'sender_email',
        'sender_name',
        'recipient_email',
        'recipient_name',
        'subject',
        'body',
        'template_type',
        'extension_type',
        'extension_identifier',
        'source',
        'status',
        'error_message',
        'sent_at',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extension_type' => ExtensionOwnerType::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * 템플릿 유형별 필터 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param string|null $templateType 템플릿 유형
     * @return Builder
     */
    public function scopeByTemplateType(Builder $query, ?string $templateType): Builder
    {
        if ($templateType === null) {
            return $query->whereNull('template_type');
        }

        return $query->where('template_type', $templateType);
    }

    /**
     * 확장 타입/식별자별 필터 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param ExtensionOwnerType|null $type 확장 타입
     * @param string|null $identifier 확장 식별자
     * @return Builder
     */
    public function scopeByExtension(Builder $query, ?ExtensionOwnerType $type = null, ?string $identifier = null): Builder
    {
        if ($type !== null) {
            $query->where('extension_type', $type);
        }

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query;
    }

    /**
     * 발송 출처별 필터 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param string|null $source 발송 출처
     * @return Builder
     */
    public function scopeBySource(Builder $query, ?string $source): Builder
    {
        if ($source === null) {
            return $query->whereNull('source');
        }

        return $query->where('source', $source);
    }

    /**
     * 상태별 필터 스코프
     *
     * @param Builder $query 쿼리 빌더
     * @param string $status 상태값
     * @return Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
