<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProvisionedProduct;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\SimpleProductSpec;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductProvisioner;
use Modules\Sirsoft\Ecommerce\Exceptions\CurrencyConversionException;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * 단순 상품 생성 공개 계약 구현 — 상품 서비스의 등록에 위임한다(상품 생성 훅이 관리자 등록과 같게 발화).
 *
 * 상품 가격은 쇼핑몰 기본 통화로만 저장된다(상품 통화 컬럼도 저장 시 기본 통화로 채워진다). 명세 통화가 다르면
 * 그 금액을 지금 환율로 기본 통화 가격으로 바꾸되, 다시 그 통화로 환산했을 때 정확히 같은 금액이 되는 값을 고른다.
 *
 * @internal 다른 확장은 {@see ProductProvisioner} 로 주입받는다.
 */
class EcommerceProductProvisioner implements ProductProvisioner
{
    /** 역산 가격 주변에서 정확히 맞는 값을 찾는 폭(기본 통화 최소 단위) */
    private const SEARCH_SPAN = 50;

    /**
     * {@inheritDoc}
     *
     * @param  SimpleProductSpec  $spec  상품 명세
     * @return ProvisionedProduct 만든 상품
     */
    public function createSimpleProduct(SimpleProductSpec $spec): ProvisionedProduct
    {
        $currencyService = app(CurrencyConversionService::class);
        $defaultCurrency = $currencyService->getDefaultCurrency();
        $currency = $spec->currency ?? $defaultCurrency;

        $sellingPrice = $this->toDefaultCurrency($spec->sellingPrice, $currency, $defaultCurrency, $currencyService);
        $listPrice = $spec->listPrice === null
            ? $sellingPrice
            : $this->toDefaultCurrency($spec->listPrice, $currency, $defaultCurrency, $currencyService);

        $data = [
            'name' => $spec->name,
            'product_code' => $spec->productCode,
            'sku' => $spec->sku,
            'selling_price' => $sellingPrice,
            'list_price' => $listPrice,
            'stock_quantity' => $spec->stockQuantity,
            'description' => $spec->description,
            'sales_status' => $spec->salesStatus,
            'display_status' => $spec->displayStatus,
            'options' => [
                [
                    'option_code' => $spec->defaultOptionCode,
                    'option_values' => [],
                    'option_name' => $spec->defaultOptionName ?? $spec->name,
                    'stock_quantity' => $spec->stockQuantity,
                    'is_default' => true,
                    'is_active' => true,
                ],
            ],
        ];

        $product = app(ProductService::class)->create(array_filter($data, fn ($value) => $value !== null));

        return new ProvisionedProduct(
            id: (int) $product->id,
            productCode: (string) $product->product_code,
            storedSellingPrice: $product->selling_price + 0,
            storedCurrency: (string) $product->currency_code,
        );
    }

    /**
     * 명세 통화 금액을 기본 통화 가격으로 바꿉니다.
     *
     * @param  int|float  $amount  명세 통화 금액
     * @param  string  $currency  명세 통화
     * @param  string  $defaultCurrency  쇼핑몰 기본 통화
     * @param  CurrencyConversionService  $service  통화 환산 서비스
     * @return int|float 기본 통화 가격
     *
     * @throws InvalidArgumentException 통화 설정에 없거나 환율이 없을 때
     */
    private function toDefaultCurrency(int|float $amount, string $currency, string $defaultCurrency, CurrencyConversionService $service): int|float
    {
        if ($currency === $defaultCurrency) {
            return $amount;
        }

        try {
            $rate = (float) ($service->convertToCurrency(0, $currency)['exchange_rate'] ?? 0);
        } catch (CurrencyConversionException $e) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.currency_rate_missing', ['currency' => $currency]), 0, $e);
        }
        if ($rate <= 0) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.currency_rate_missing', ['currency' => $currency]));
        }

        // 환산식: 외화 = (기본 통화 / 기본 통화 기준 단위) × 환율 → 역산
        $estimate = (int) round($amount * $service->getDefaultBaseUnit() / $rate);

        for ($step = 0; $step <= self::SEARCH_SPAN; $step++) {
            foreach ($step === 0 ? [$estimate] : [$estimate - $step, $estimate + $step] as $candidate) {
                if ($candidate >= 0 && (float) $service->convertToCurrency($candidate, $currency)['price'] === (float) $amount) {
                    return $candidate;
                }
            }
        }

        return $estimate;
    }
}
