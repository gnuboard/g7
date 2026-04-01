<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 카테고리 이미지 모델
 */
class CategoryImage extends Model
{
    use SoftDeletes;

    protected $table = 'ecommerce_category_images';

    /**
     * 모델 부팅
     */
    protected static function boot(): void
    {
        parent::boot();

        // 생성 시 hash 자동 생성 (URL용 고유 키)
        static::creating(function (self $model) {
            if (empty($model->hash)) {
                $model->hash = self::generateHash();
            }
        });
    }

    /**
     * 고유 해시 생성 (12자리)
     */
    protected static function generateHash(): string
    {
        do {
            $hash = substr(bin2hex(random_bytes(6)), 0, 12);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    protected $fillable = [
        'category_id',
        'temp_key',
        'hash',
        'original_filename',
        'stored_filename',
        'disk',
        'path',
        'mime_type',
        'file_size',
        'width',
        'height',
        'alt_text',
        'collection',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'alt_text' => 'array',
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * 카테고리 관계
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * 업로더 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 현재 로케일의 대체 텍스트 반환
     *
     * @param  string|null  $locale  로케일
     */
    public function getLocalizedAltText(?string $locale = null): ?string
    {
        if (empty($this->alt_text)) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();
        $altText = $this->alt_text;

        return $altText[$locale] ?? $altText['ko'] ?? null;
    }

    /**
     * 다운로드 URL 반환 (API 서빙 URL)
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/modules/sirsoft-ecommerce/category-image/'.$this->hash;
    }
}
