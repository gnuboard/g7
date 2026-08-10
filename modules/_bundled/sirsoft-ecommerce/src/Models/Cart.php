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
        'additional_option_selections',
        'quantity',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'product_id' => 'integer',
        'product_option_id' => 'integer',
        'additional_option_selections' => 'array',
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
     * 추가옵션 선택을 합산 판정용 정규화 해시로 변환
     *
     * 동일 상품옵션이라도 추가옵션 선택이 다르면 별개 행으로 취급하기 위해,
     * (additional_option_id => value_id) 매핑을 키 순으로 정렬한 후 해시화합니다.
     * 선택이 없으면 빈 문자열을 반환합니다.
     *
     * 직접입력(custom_text)이 다르면 같은 (옵션+선택지) 라도 별개 행으로 취급합니다(E5).
     * 서로 다른 각인 문구는 합산이 불가능하기 때문입니다.
     *
     * @param  array|null  $selections  추가옵션 선택 배열 [{additional_option_id, value_id, custom_text?}]
     * @return string 정규화된 선택 해시
     */
    public static function normalizeAdditionalOptionSelectionHash(?array $selections): string
    {
        if (empty($selections)) {
            return '';
        }

        $normalized = [];
        foreach ($selections as $selection) {
            $optionId = (int) ($selection['additional_option_id'] ?? 0);
            $valueId = (int) ($selection['value_id'] ?? 0);

            if ($optionId <= 0 || $valueId <= 0) {
                continue;
            }

            $customText = trim((string) ($selection['custom_text'] ?? ''));

            $normalized[$optionId] = $customText !== ''
                ? ['value_id' => $valueId, 'custom_text' => $customText]
                : $valueId;
        }

        if (empty($normalized)) {
            return '';
        }

        ksort($normalized);

        return md5(json_encode($normalized));
    }

    /**
     * 이 장바구니 행의 추가옵션 선택 해시
     *
     * @return string 정규화된 선택 해시
     */
    public function getAdditionalOptionSelectionHash(): string
    {
        return self::normalizeAdditionalOptionSelectionHash($this->additional_option_selections);
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
