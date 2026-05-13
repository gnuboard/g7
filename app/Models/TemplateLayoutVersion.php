<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 템플릿 레이아웃 버전 모델
 *
 * @property int $id
 * @property int $layout_id
 * @property int $version
 * @property array $content
 * @property array|null $changes_summary
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read TemplateLayout $layout
 * @property-read User|null $creator
 *
 * @method static \Illuminate\Database\Eloquent\Builder latest()
 * @method static \Illuminate\Database\Eloquent\Builder oldest()
 */
class TemplateLayoutVersion extends Model
{
    use HasFactory;
    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'layout_id',
        'version',
        'content',
        'changes_summary',
        'created_by',
    ];

    /**
     * updated_at 타임스탬프 비활성화
     *
     * @var bool
     */
    public const UPDATED_AT = null;

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'changes_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 레이아웃과의 관계
     */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(TemplateLayout::class, 'layout_id');
    }

    /**
     * 생성자와의 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 최신 버전순으로 정렬
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('version', 'desc')->orderBy('created_at', 'desc');
    }

    /**
     * 오래된 버전순으로 정렬
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOldest($query)
    {
        return $query->orderBy('version', 'asc')->orderBy('created_at', 'asc');
    }
}
