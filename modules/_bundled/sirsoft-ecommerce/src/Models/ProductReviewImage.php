<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductReviewImageFactory;

/**
 * 상품 리뷰 이미지 모델
 */
class ProductReviewImage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ecommerce_product_review_images';

    /**
     * @return ProductReviewImageFactory
     */
    protected static function newFactory(): ProductReviewImageFactory
    {
        return ProductReviewImageFactory::new();
    }

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
        'review_id',
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
        'is_thumbnail' => 'boolean',
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * 리뷰 관계
     *
     * @return BelongsTo
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'review_id');
    }

    /**
     * 업로더 관계
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 다운로드 URL 반환 (API 서빙 URL)
     *
     * @return string
     */
    public function getDownloadUrlAttribute(): string
    {
        return '/api/modules/sirsoft-ecommerce/review-image/'.$this->hash;
    }
}
