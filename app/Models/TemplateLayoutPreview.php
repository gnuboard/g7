<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 템플릿 레이아웃 미리보기 모델
 *
 * @property int $id
 * @property string $token
 * @property int $template_id
 * @property string $layout_name
 * @property string $preview_type
 * @property int|null $extension_id
 * @property array $content
 * @property int $admin_id
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class TemplateLayoutPreview extends Model
{
    const UPDATED_AT = null;

    /**
     * @var string 테이블명
     */
    protected $table = 'template_layout_previews';

    /**
     * @var array<int, string> 대량 할당 가능 필드
     */
    protected $fillable = [
        'token',
        'template_id',
        'layout_name',
        'preview_type',
        'extension_id',
        'content',
        'admin_id',
        'expires_at',
    ];

    /**
     * 속성 캐스팅 정의
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * 템플릿 관계
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * 생성 관리자 관계
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * 만료되지 않은 미리보기만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
