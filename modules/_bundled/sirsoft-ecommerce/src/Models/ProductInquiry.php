<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductInquiryFactory;

/**
 * 상품 1:1 문의 피벗 모델
 *
 * 이커머스 상품과 외부 컨텐츠(게시판 게시글 등)를 다형성 관계로 연결합니다.
 *
 * SoftDeletes: 게시판에서 질문 글을 삭제→복원하는 경로에서 피벗도 함께
 * 복원할 수 있어야 한다 — 하드 삭제는 복원 대칭이 불가능했다(#107 후속).
 * 상품 자체가 forceDelete 되는 경로만 피벗도 forceDelete 한다.
 */
class ProductInquiry extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Factory 클래스 경로 지정
     *
     * @return string
     */
    protected static function newFactory(): Factory
    {
        return ProductInquiryFactory::new();
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
