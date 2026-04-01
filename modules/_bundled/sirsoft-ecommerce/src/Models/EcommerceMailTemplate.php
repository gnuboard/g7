<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\Concerns\MailTemplateBehavior;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 이커머스 메일 템플릿 모델
 *
 * @property int $id
 * @property string $type
 * @property array $subject
 * @property array $body
 * @property array $variables
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $updated_by
 */
class EcommerceMailTemplate extends Model
{
    use MailTemplateBehavior;

    /**
     * @var string 테이블명
     */
    protected $table = 'ecommerce_mail_templates';

    /**
     * @var array<string, mixed> 기본 속성 값
     */
    protected $attributes = [
        'variables' => '[]',
    ];

    /**
     * @var array<int, string> 대량 할당 가능 필드
     */
    protected $fillable = [
        'type',
        'subject',
        'body',
        'variables',
        'is_active',
        'is_default',
        'updated_by',
    ];

    /**
     * 마지막 수정자 관계를 반환합니다.
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
