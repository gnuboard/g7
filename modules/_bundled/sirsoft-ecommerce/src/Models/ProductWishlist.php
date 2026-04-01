<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 상품 찜 모델
 */
class ProductWishlist extends Model
{
    protected $table = 'ecommerce_product_wishlists';

    protected $fillable = [
        'user_id',
        'product_id',
    ];

    /**
     * 사용자 관계
     *
     * @return BelongsTo 사용자 모델과의 관계
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
}
