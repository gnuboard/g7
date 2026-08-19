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
     * 장바구니 표현(CartItemResource)에 필요한 적재 관계 목록
     *
     * 목록 조회(Repository)와 담기·옵션변경 응답(Controller)이 같은 목록을 쓰도록
     * 한 곳에 둔다. `CartItemResource` 는 `relationLoaded()` 로 방어하므로, 여기서
     * 빠진 관계는 예외도 경고도 없이 응답에서 값만 사라진다 — 추가옵션이 빠지면
     * `additional_options` 가 빈 배열이 되고 그 금액이 `subtotal` 에서도 누락된다.
     *
     * 상품 이미지는 컬럼을 좁혀 싣는다. 장바구니는 상품당 **대표 이미지 1장의 URL** 만
     * 쓴다(`BaseOrderItemResource::formatProductInfo`). 대표 지정이 없으면 첫 이미지로
     * 폴백하므로 `thumbnail` 관계로 바꾸면 그 폴백이 깨진다. 그래서 관계는 그대로 두고
     * 선택 컬럼만 좁힌다 — `download_url` 은 `hash` 만 필요하고, 폴백 판정에
     * `is_thumbnail`/`sort_order`, eager load 매칭에 `id`/`product_id` 가 필요하다.
     * 파일명·경로·용량·크기 등 나머지 컬럼은 장바구니 응답 어디에도 쓰이지 않는다.
     *
     * `product.shippingPolicy.countrySettings` 는 응답에 직렬화되지 않지만 의도적으로
     * 유지한다. 배송비 계산(`OrderCalculationService`)이 이 관계를 읽고, 로드돼 있지
     * 않으면 정책 인스턴스마다 `load('countrySettings')` 로 다시 조회한다. 장바구니
     * 항목마다 정책 인스턴스가 따로 잡히므로 eager load 를 빼면 페이로드 대신 쿼리
     * 수가 늘어난다 — 결제 금액 산출 경로라 그 교환은 하지 않는다.
     *
     * 활성 설정만 싣는 방식도 검토했으나 계산기의 지연 조회 경로가 `is_active` 를
     * 거르지 않아 로드 여부에 따라 배송비가 달라지게 된다. 좁히려면 계산기 쪽 기준을
     * 먼저 통일해야 한다.
     *
     * @return array<int, string> with()/load() 에 그대로 넘길 관계 목록
     */
    public static function displayRelations(): array
    {
        return [
            'product.images:id,product_id,hash,is_thumbnail,sort_order',
            'product.additionalOptions.values',
            'product.shippingPolicy.countrySettings',
            'productOption',
        ];
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
