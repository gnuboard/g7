<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 장바구니 모델
 */
class Cart extends Model
{
    protected $table = 'ecommerce_carts';

    protected $fillable = [
        'cart_key',
        'user_id',
        'product_id',
        'product_option_id',
        'quantity',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'product_option_id' => 'integer',
        'quantity' => 'integer',
    ];

    /**
     * 회원 관계
     *
     * @return BelongsTo 회원 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 상품 관계
     *
     * @return BelongsTo 상품 모델과의 관계
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 상품 옵션 관계
     *
     * @return BelongsTo 상품 옵션 모델과의 관계
     */
    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /**
     * 비회원 장바구니 여부 확인
     *
     * @return bool 비회원 장바구니 여부
     */
    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * 소계 금액 계산 (옵션 단가 × 수량)
     *
     * @return int 소계 금액
     */
    public function getSubtotal(): int
    {
        if (! $this->productOption) {
            return 0;
        }

        return (int) ($this->productOption->sale_price * $this->quantity);
    }
}
