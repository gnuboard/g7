<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductReviewFactory;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;

/**
 * 상품 리뷰 모델
 */
class ProductReview extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return ProductReviewFactory
     */
    protected static function newFactory(): ProductReviewFactory
    {
        return ProductReviewFactory::new();
    }

    protected $table = 'ecommerce_product_reviews';

    protected $fillable = [
        'product_id',
        'order_option_id',
        'user_id',
        'rating',
        'content',
        'content_mode',
        'option_snapshot',
        'status',
        'reply_content',
        'reply_content_mode',
        'reply_admin_id',
        'replied_at',
        'reply_updated_at',
    ];

    protected $casts = [
        'status' => ReviewStatus::class,
        'rating' => 'integer',
        'replied_at' => 'datetime',
        'reply_updated_at' => 'datetime',
        'option_snapshot' => 'array',
    ];

    /**
     * 상품 관계
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 주문 옵션 관계
     *
     * @return BelongsTo
     */
    public function orderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'order_option_id');
    }

    /**
     * 작성자 관계
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 답변 등록 관리자 관계
     *
     * @return BelongsTo
     */
    public function replyAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_admin_id');
    }

    /**
     * 리뷰 이미지 관계
     *
     * @return HasMany
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductReviewImage::class, 'review_id')->orderBy('sort_order');
    }
}
