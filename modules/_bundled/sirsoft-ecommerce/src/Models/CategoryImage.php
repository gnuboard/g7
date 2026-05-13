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
     * 이 이미지가 속한 카테고리 관계 (category_id FK).
     *
     * @return BelongsTo 카테고리 관계
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * 이 이미지를 업로드한 사용자 관계 (created_by FK).
     *
     * @return BelongsTo 업로더 사용자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 현재 로케일의 대체 텍스트(alt)를 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string|null 로케일별 alt 텍스트, alt_text 가 비어있으면 null
     */
    public function getLocalizedAltText(?string $locale = null): ?string
    {
        if (empty($this->alt_text)) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();
        $altText = $this->alt_text;

        return $altText[$locale] ?? $altText[config('app.fallback_locale', 'ko')] ?? null;
    }

    /**
     * `download_url` 가상 attribute — hash 기반 카테고리 이미지 서빙 API URL.
     *
     * @return string `/api/modules/sirsoft-ecommerce/category-image/{hash}` 형식 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/modules/sirsoft-ecommerce/category-image/'.$this->hash;
    }
}
