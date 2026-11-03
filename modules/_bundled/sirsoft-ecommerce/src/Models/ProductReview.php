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
        'author_name',
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
     * 회원·주문 없이 등록한 후기(다른 쇼핑몰에서 이관한 후기 등)는 `user_id` 가 NULL 이라 관계가 비어 있다.
     * 그 후기의 작성자 표시는 `author_name` 컬럼이 맡는다 — {@see self::displayAuthorName()}.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 회원 없이 등록한 후기인지 반환합니다 (다른 쇼핑몰에서 이관한 후기 등).
     *
     * 회원 후기의 `user_id` 는 회원 하드 삭제 때 후기째 지워지므로(외래키 cascade) NULL 로 남는 회원 후기는 없다.
     *
     * @return bool 회원 없는 후기이면 true
     */
    public function isImported(): bool
    {
        return $this->user_id === null;
    }

    /**
     * 작성자 표시 이름을 반환합니다.
     *
     * 회원 후기는 회원 이름, 회원 없는 후기는 저장된 작성자 이름 그대로(가린 이름이면 가린 채로).
     *
     * @return string 작성자 표시 이름 (알 수 없으면 빈 문자열)
     */
    public function displayAuthorName(): string
    {
        if ($this->isImported()) {
            return (string) ($this->author_name ?? '');
        }

        return (string) ($this->user?->name ?? $this->author_name ?? '');
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
