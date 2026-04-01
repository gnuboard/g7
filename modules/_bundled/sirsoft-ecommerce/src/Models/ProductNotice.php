<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 상품정보제공고시 모델
 *
 * 템플릿은 UI용 도구일 뿐, 상품과 연관이 없으므로 template_id를 저장하지 않습니다.
 */
class ProductNotice extends Model
{
    protected $table = 'ecommerce_product_notices';

    protected $fillable = [
        'product_id',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
    ];

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
