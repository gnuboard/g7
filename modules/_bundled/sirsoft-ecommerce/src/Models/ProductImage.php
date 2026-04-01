<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Enums\ProductImageCollection;

/**
 * 상품 이미지 모델
 */
class ProductImage extends Model
{
    use SoftDeletes;

    protected $table = 'ecommerce_product_images';

    /**
     * 모델 부트 메서드
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
     * 고유 해시를 생성합니다.
     *
     * @return string 12자리 고유 해시
     */
    public static function generateHash(): string
    {
        do {
            $hash = substr(bin2hex(random_bytes(6)), 0, 12);
        } while (self::where('hash', $hash)->exists());

        return $hash;
    }

    protected $fillable = [
        'product_id',
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
        'is_thumbnail',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'alt_text' => 'array',
        'collection' => ProductImageCollection::class,
        'is_thumbnail' => 'boolean',
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * 상품 관계
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
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
     * 컬렉션별 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCollection($query, ProductImageCollection $collection)
    {
        return $query->where('collection', $collection);
    }

    /**
     * 대표 이미지만 조회
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeThumbnails($query)
    {
        return $query->where('is_thumbnail', true);
    }

    /**
     * 다운로드 URL 반환 (API 서빙 URL)
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/modules/sirsoft-ecommerce/product-image/'.$this->hash;
    }
}
