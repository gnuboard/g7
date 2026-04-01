<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 상품 1:1 문의 피벗 모델
 *
 * 이커머스 상품과 외부 컨텐츠(게시판 게시글 등)를 다형성 관계로 연결합니다.
 */
class ProductInquiry extends Model
{
    use HasFactory;

    /**
     * Factory 클래스 경로 지정
     *
     * @return string
     */
    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Modules\Sirsoft\Ecommerce\Database\Factories\ProductInquiryFactory::new();
    }

    protected $table = 'ecommerce_product_inquiries';

    protected $fillable = [
        'product_id',
        'inquirable_type',
        'inquirable_id',
        'user_id',
        'is_answered',
        'answered_at',
        'product_name_snapshot',
    ];

    protected $casts = [
        'is_answered' => 'boolean',
        'answered_at' => 'datetime',
        'product_name_snapshot' => 'array',
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
     * 작성자 관계 (비회원: null)
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 다형성 관계 (게시판 Post 등)
     *
     * @return MorphTo
     */
    public function inquirable(): MorphTo
    {
        return $this->morphTo();
    }
}
