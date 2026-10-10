<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\TranslatableField;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 수정 요청
 */
class UpdateProductRequest extends StoreProductRequest
{
    /**
     * 유효성 검사 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // product_code unique 검증에서 현재 상품 제외
        $product = $this->route('product');
        $productId = $product?->id ?? $product;
        $rules['product_code'] = ['required', 'string', 'max:50'];

        // 허용 문자 규칙은 코드를 바꿀 때만 적용한다 — 수정 화면은 코드 입력칸이 비활성인 채 폼 전체를 보내므로,
        // 규칙 이전에 저장된 코드(한글·공백 등)의 상품도 다른 항목은 계속 수정할 수 있어야 한다.
        // 현재 코드: {product} 경로는 바인딩된 모델, by-code 경로는 {code} 인자 자체
        $currentCode = $product instanceof Product ? $product->product_code : $this->route('code');
        if ($this->input('product_code') !== $currentCode) {
            $rules['product_code'][] = 'regex:'.self::PRODUCT_CODE_PATTERN;
        }

        $rules['product_code'][] = Rule::unique(Product::class, 'product_code')->ignore($productId);

        // 수정 시 필드 일부 optional 처리
        $optionalFields = [
            'name',
            'list_price',
            'selling_price',
            'stock_quantity',
            'sales_status',
            'display_status',
            'tax_status',
            'category_ids',
            'options',
        ];

        foreach ($optionalFields as $field) {
            if (isset($rules[$field]) && is_array($rules[$field])) {
                // required를 sometimes로 변경
                $rules[$field] = array_map(
                    fn ($v) => $v === 'required' ? 'sometimes' : $v,
                    $rules[$field]
                );

                // sometimes가 없으면 추가
                if (! in_array('sometimes', $rules[$field])) {
                    array_unshift($rules[$field], 'sometimes');
                }
            }
        }

        $rules = $this->exemptUnchangedTranslatableNames($rules, $this->resolveProduct($product));

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.update_validation_rules', $rules, $this);
    }

    /**
     * 경로의 상품을 모델로 돌려줍니다 — {product} 바인딩(모델·id)과 by-code 경로({code}) 모두.
     *
     * @param  mixed  $product  {product} 경로 인자(모델 또는 id)
     * @return Product|null 상품(없으면 null)
     */
    private function resolveProduct(mixed $product): ?Product
    {
        if ($product instanceof Product) {
            return $product;
        }
        if (is_numeric($product)) {
            return Product::query()->find((int) $product);
        }
        $code = $this->route('code');

        return is_string($code) ? Product::query()->where('product_code', $code)->first() : null;
    }

    /**
     * 저장된 값과 같은 다국어 이름은 사이트 기본 언어 필수에서 면제합니다 (길이·형식만 검사).
     *
     * 필수 언어가 사이트 기본 언어로 바뀌기 전에 다른 언어로만 저장된 옵션 그룹 이름 등은 수정 화면에
     * 고칠 칸이 없는데도 저장할 때마다 막혔다(제품 결정 2026-10-08 — 기존 행 필수 면제, 설정 카탈로그의
     * `translatableNameRules` 와 같은 방식). 옵션 그룹·추가옵션은 행 id 가 없어 「같은 상품에 같은 값이
     * 이미 저장돼 있는가」로 기존 값을 가린다. 바꾸거나 새로 넣은 이름은 종전대로 필수다.
     *
     * @param  array<string, mixed>  $rules  검증 규칙
     * @param  Product|null  $product  수정 대상 상품
     * @return array<string, mixed> 면제를 반영한 규칙
     */
    private function exemptUnchangedTranslatableNames(array $rules, ?Product $product): array
    {
        if ($product === null) {
            return $rules;
        }
        $product->loadMissing(['options', 'additionalOptions.values', 'notice']);

        $noticeItems = $product->notice?->values ?? [];
        $stored = [
            'option_groups' => array_map(fn ($g) => $g['name'] ?? null, $product->option_groups ?? []),
            'options' => $product->options->pluck('option_name')->all(),
            'additional_options' => $product->additionalOptions->pluck('name')->all(),
            'additional_values' => $product->additionalOptions->flatMap(fn ($o) => $o->values->pluck('name'))->all(),
            'notice_names' => array_map(fn ($i) => $i['name'] ?? null, $noticeItems),
            'notice_contents' => array_map(fn ($i) => $i['content'] ?? null, $noticeItems),
        ];
        $stored = array_map(fn (array $values) => array_filter(array_map([$this, 'normalizeTranslatable'], $values)), $stored);

        $exempt = function (string $key, mixed $value, string $kind, string $presence, int $maxLength) use (&$rules, $stored): void {
            $normalized = $this->normalizeTranslatable($value);
            if ($normalized !== null && in_array($normalized, $stored[$kind], true)) {
                $rules[$key] = [$presence, 'array', new TranslatableField(maxLength: $maxLength, required: true)];
            }
        };

        foreach ((array) $this->input('option_groups', []) as $i => $group) {
            $exempt("option_groups.{$i}.name", $group['name'] ?? null, 'option_groups', 'required', 100);
        }
        foreach ((array) $this->input('options', []) as $i => $option) {
            $exempt("options.{$i}.option_name", $option['option_name'] ?? null, 'options', 'required_with:options', 200);
        }
        foreach ((array) $this->input('additional_options', []) as $i => $additional) {
            $exempt("additional_options.{$i}.name", $additional['name'] ?? null, 'additional_options', 'required_with:additional_options', 100);
            foreach ((array) ($additional['values'] ?? []) as $j => $value) {
                $exempt("additional_options.{$i}.values.{$j}.name", $value['name'] ?? null, 'additional_values', 'required', 100);
            }
        }
        foreach ((array) $this->input('notice_items', []) as $i => $item) {
            $exempt("notice_items.{$i}.name", $item['name'] ?? null, 'notice_names', 'required', 100);
            $exempt("notice_items.{$i}.content", $item['content'] ?? null, 'notice_contents', 'required', 500);
        }

        return $rules;
    }

    /**
     * 다국어 값을 비교용 문자열로 바꿉니다 — 빈 칸은 버리고 언어 순서를 맞춘다.
     *
     * @param  mixed  $value  다국어 값(언어 → 문자열)
     * @return string|null 비교용 문자열(채워진 언어가 없으면 null)
     */
    private function normalizeTranslatable(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }
        $filled = array_filter($value, fn ($v) => is_string($v) && trim($v) !== '');
        if ($filled === []) {
            return null;
        }
        $filled = array_map('trim', $filled);
        ksort($filled);

        return json_encode($filled, JSON_UNESCAPED_UNICODE);
    }
}
