<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;

/**
 * 상품 옵션 모델
 */
class ProductOption extends Model
{
    use HasFactory;

    /**
     * 활동 로그 추적 대상 필드 정의
     *
     * @var array<string, array>
     */
    public static array $activityLogFields = [
        'option_name' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.option_name', 'type' => 'json'],
        'sku' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sku', 'type' => 'string'],
        'selling_price' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.selling_price', 'type' => 'currency'],
        'list_price' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.list_price', 'type' => 'currency'],
        'price_adjustment' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.price_adjustment', 'type' => 'currency'],
        'stock_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.stock_quantity', 'type' => 'number'],
        'safe_stock_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.safe_stock_quantity', 'type' => 'number'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected static function newFactory()
    {
        return ProductOptionFactory::new();
    }

    protected $table = 'ecommerce_product_options';

    protected $fillable = [
        'product_id',
        'option_code',
        'option_values',
        'option_name',
        'price_adjustment',
        'list_price',
        'selling_price',
        'currency_code',
        'stock_quantity',
        'safe_stock_quantity',
        'weight',
        'volume',
        'mileage_value',
        'mileage_type',
        'is_default',
        'is_active',
        'sku',
        'sort_order',
    ];

    protected $casts = [
        'option_values' => 'array',
        'option_name' => 'array',
        'price_adjustment' => 'integer',
        'stock_quantity' => 'integer',
        'safe_stock_quantity' => 'integer',
        'weight' => 'decimal:2',
        'volume' => 'decimal:2',
        'mileage_value' => 'decimal:2',
        'mileage_type' => 'string',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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
     * 최종 판매가 계산 (상품 판매가 + 옵션 조정액)
     *
     * @return int
     */
    public function getFinalPrice(): int
    {
        return $this->product->selling_price + $this->price_adjustment;
    }

    /**
     * 최종 정가 계산 (상품 정가 + 옵션 조정액)
     *
     * @return int
     */
    public function getListPrice(): int
    {
        return $this->product->list_price + $this->price_adjustment;
    }

    /**
     * 최종 판매가 계산 (상품 판매가 + 옵션 조정액) - getFinalPrice의 별칭
     *
     * @return int
     */
    public function getSellingPrice(): int
    {
        return $this->getFinalPrice();
    }

    /**
     * 안전재고 이하 여부
     *
     * @return bool
     */
    public function isBelowSafeStock(): bool
    {
        return $this->stock_quantity <= $this->safe_stock_quantity;
    }

    /**
     * 현재 로케일의 옵션명 반환
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 로컬라이즈된 옵션명
     */
    public function getLocalizedOptionName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->option_name;

        // 문자열인 경우 그대로 반환 (하위 호환성)
        if (is_string($name)) {
            return $name;
        }

        if (! is_array($name)) {
            return '';
        }

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 현재 로케일의 옵션값 반환 (객체 형식)
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return array 로컬라이즈된 옵션값 {"색상": "빨강", "사이즈": "M"}
     */
    public function getLocalizedOptionValues(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $values = $this->option_values;

        if (! is_array($values) || empty($values)) {
            return [];
        }

        // 새 구조: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}]
        if (isset($values[0]['key'])) {
            $result = [];
            foreach ($values as $item) {
                $key = $item['key'] ?? [];
                $value = $item['value'] ?? [];

                $localizedKey = is_array($key) ? ($key[$locale] ?? $key[config('app.fallback_locale', 'ko')] ?? array_values($key)[0] ?? '') : $key;
                $localizedValue = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? array_values($value)[0] ?? '') : $value;

                if ($localizedKey !== '') {
                    $result[$localizedKey] = $localizedValue;
                }
            }

            return $result;
        }

        // 기존 구조: {"색상": "빨강"} (하위 호환성)
        return $values;
    }

    /**
     * 옵션 조합명 생성 (다국어)
     *
     * @return array 다국어 옵션명 {"ko": "빨강/L", "en": "Red/L"}
     */
    public function generateOptionName(): array
    {
        if (empty($this->option_values)) {
            return [];
        }

        $values = $this->option_values;

        // 새 구조: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}]
        if (isset($values[0]['key'])) {
            $locales = config('app.supported_locales', ['ko', 'en']);
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];
                foreach ($values as $item) {
                    $value = $item['value'] ?? [];
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? '') : $value;
                    if ($localizedValue !== '') {
                        $parts[] = $localizedValue;
                    }
                }
                $result[$locale] = implode('/', $parts);
            }

            return $result;
        }

        // 기존 구조: {"색상": "빨강"} (하위 호환성) - ko로만 반환
        return ['ko' => implode('/', array_values($values))];
    }

    /**
     * 옵션 조합명 생성 (단일 문자열, 하위 호환성)
     *
     * @param  string|null  $locale  로케일
     * @return string 옵션명 문자열
     */
    public function generateOptionNameString(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->generateOptionName();

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 주문 스냅샷용 배열 반환
     *
     * 주문 생성 시점의 옵션 정보를 스냅샷으로 저장하기 위한 데이터
     *
     * @return array 스냅샷 데이터
     */
    public function toSnapshotArray(): array
    {
        return [
            'id' => $this->id,
            'option_code' => $this->option_code,
            'option_values' => $this->option_values,
            'option_name' => $this->option_name,
            'price_adjustment' => $this->price_adjustment,
            'list_price' => $this->list_price,
            'selling_price' => $this->selling_price,
            'currency_code' => $this->currency_code,
            'stock_quantity' => $this->stock_quantity,
            'weight' => $this->weight,
            'volume' => $this->volume,
            'sku' => $this->sku,
            'is_default' => $this->is_default,
            'mileage_value' => $this->mileage_value,
            'mileage_type' => $this->mileage_type,
        ];
    }
}
