<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 장바구니 상품 구매불가 예외
 *
 * 체크아웃 시 재고부족 또는 판매상태 문제가 있는 상품이 있을 때 발생합니다.
 */
class CartUnavailableException extends Exception
{
    /**
     * @param  string  $message  예외 메시지
     * @param  array  $unavailableItems  구매불가 상품 목록
     */
    public function __construct(
        string $message,
        private array $unavailableItems = []
    ) {
        parent::__construct($message);
    }

    /**
     * 구매불가 상품 목록 반환
     *
     * @return array 구매불가 상품 배열
     *               [
     *               [
     *               'cart_id' => int,
     *               'product_id' => int,
     *               'product_option_id' => int,
     *               'name' => string,
     *               'option' => string|null,
     *               'thumbnail' => string|null,
     *               'quantity' => int,
     *               'stock' => int,
     *               'reason' => 'stock'|'status'|'restricted'|'min_qty'|'max_qty',  // 재고부족, 판매상태, 구매대상제한, 최소수량미달, 최대수량초과
     *               ],
     *               ...
     *               ]
     */
    public function getUnavailableItems(): array
    {
        return $this->unavailableItems;
    }

    /**
     * 재고 부족 상품이 있는지 확인
     *
     * @return bool 재고 부족 상품 존재 여부
     */
    public function hasStockIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'stock') {
                return true;
            }
        }

        return false;
    }

    /**
     * 판매상태 문제 상품이 있는지 확인
     *
     * @return bool 판매상태 문제 상품 존재 여부
     */
    public function hasStatusIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'status') {
                return true;
            }
        }

        return false;
    }

    /**
     * 구매 대상 제한(역할) 문제 상품이 있는지 확인
     *
     * @return bool 구매 대상 제한 상품 존재 여부
     */
    public function hasRestrictionIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'restricted') {
                return true;
            }
        }

        return false;
    }

    /**
     * 최소 구매수량 미달 상품이 있는지 확인
     *
     * @return bool 최소 구매수량 미달 상품 존재 여부
     */
    public function hasMinQtyIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'min_qty') {
                return true;
            }
        }

        return false;
    }

    /**
     * 최대 구매수량 초과 상품이 있는지 확인
     *
     * @return bool 최대 구매수량 초과 상품 존재 여부
     */
    public function hasMaxQtyIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'max_qty') {
                return true;
            }
        }

        return false;
    }

    /**
     * 로깅용 전체 데이터 반환
     *
     * @return array 구매불가 사유별 플래그를 포함한 로깅용 데이터
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'unavailable_items' => $this->unavailableItems,
            'item_count' => count($this->unavailableItems),
            'has_stock_issue' => $this->hasStockIssue(),
            'has_status_issue' => $this->hasStatusIssue(),
            'has_restriction_issue' => $this->hasRestrictionIssue(),
            'has_min_qty_issue' => $this->hasMinQtyIssue(),
            'has_max_qty_issue' => $this->hasMaxQtyIssue(),
        ];
    }

    /**
     * 사용자에게 보여줄 구체 사유 메시지를 반환합니다.
     *
     * 첫 번째 구매불가 항목의 reason 에 맞춰 한도/상품명을 치환한 안내를 생성합니다.
     * (재고/판매상태/구매대상제한/최소·최대 구매수량). 항목이 없거나 reason 미상이면
     * 일반 메시지(cart_unavailable)로 폴백합니다.
     *
     * @return string 사용자용 안내 메시지
     */
    public function getUserMessage(): string
    {
        $item = $this->unavailableItems[0] ?? null;

        if (! $item) {
            return __('sirsoft-ecommerce::exceptions.cart_unavailable');
        }

        $name = (string) ($item['name'] ?? '');
        $prefix = $name !== '' ? $name.': ' : '';

        $detail = match ($item['reason'] ?? '') {
            'min_qty' => __('sirsoft-ecommerce::exceptions.min_purchase_qty_not_met', [
                'limit' => $item['limit'] ?? 0,
                'requested' => $item['requested'] ?? ($item['quantity'] ?? 0),
            ]),
            'max_qty' => __('sirsoft-ecommerce::exceptions.max_purchase_qty_exceeded', [
                'limit' => $item['limit'] ?? 0,
                'requested' => $item['requested'] ?? ($item['quantity'] ?? 0),
            ]),
            'status' => __('sirsoft-ecommerce::exceptions.product_unavailable'),
            'restricted' => __('sirsoft-ecommerce::exceptions.purchase_not_allowed'),
            'country_not_shippable' => __('sirsoft-ecommerce::exceptions.country_not_shippable'),
            'stock' => __('sirsoft-ecommerce::exceptions.stock_exceeded', [
                'requested' => $item['quantity'] ?? 0,
                'available' => $item['stock'] ?? 0,
            ]),
            'additional_option_invalid' => __('sirsoft-ecommerce::exceptions.additional_option_invalid'),
            'additional_option_required' => __('sirsoft-ecommerce::exceptions.additional_option_required', [
                'name' => $name,
            ]),
            'additional_option_custom_text_required' => __('sirsoft-ecommerce::exceptions.additional_option_custom_text_required', [
                'name' => $name,
            ]),
            default => __('sirsoft-ecommerce::exceptions.cart_unavailable'),
        };

        // 추가옵션 reason 은 메시지에 그룹명(:name)을 이미 포함하므로 상품명 prefix 를 생략
        $skipPrefixReasons = [
            'additional_option_invalid',
            'additional_option_required',
            'additional_option_custom_text_required',
        ];

        if (in_array($item['reason'] ?? '', $skipPrefixReasons, true)) {
            return $detail;
        }

        return $prefix.$detail;
    }

    /**
     * 구매불가 상품 목록으로 예외 생성 (팩토리 메서드)
     *
     * @param  array  $items  구매불가 상품 목록
     * @return static 구매불가 예외 인스턴스
     */
    public static function fromItems(array $items): static
    {
        $message = __('sirsoft-ecommerce::exceptions.cart_unavailable');

        return new static($message, $items);
    }
}
