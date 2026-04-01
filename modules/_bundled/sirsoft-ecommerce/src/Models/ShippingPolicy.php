<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 배송정책 모델
 *
 * 국가별 설정은 countrySettings 관계를 통해 관리됩니다.
 */
class ShippingPolicy extends Model
{
    use HasFactory;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'is_default' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_default', 'type' => 'boolean'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected $table = 'ecommerce_shipping_policies';

    protected $fillable = [
        'name',
        'is_default',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'name' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 국가별 배송 설정
     */
    public function countrySettings(): HasMany
    {
        return $this->hasMany(ShippingPolicyCountrySetting::class);
    }

    /**
     * 현재 로케일의 정책명 반환
     *
     * @param  string|null  $locale  로케일
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name['ko'] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 배송비 요약 텍스트 반환 (국가별 설정 기반)
     */
    public function getFeeSummary(): string
    {
        $settings = $this->relationLoaded('countrySettings')
            ? $this->countrySettings
            : $this->countrySettings()->where('is_active', true)->get();

        if ($settings->isEmpty()) {
            return '';
        }

        $summaries = $settings->map(function (ShippingPolicyCountrySetting $setting) {
            return $setting->country_code.': '.$setting->getFeeSummary();
        });

        return $summaries->implode(' | ');
    }

    /**
     * 배송비 상세 정보 반환 (국가별 설정 기반)
     */
    public function getDetailedFeeInfo(): array
    {
        $settings = $this->relationLoaded('countrySettings')
            ? $this->countrySettings
            : $this->countrySettings()->where('is_active', true)->get();

        return $settings->map(function (ShippingPolicyCountrySetting $setting) {
            return [
                'country_code' => $setting->country_code,
                ...$setting->getDetailedFeeInfo(),
            ];
        })->toArray();
    }

    /**
     * 배송국가 코드를 국기로 변환 (countrySettings 기반)
     *
     * @param  int  $limit  최대 표시 개수
     */
    public function getCountriesWithFlags(int $limit = 3): string
    {
        $settings = $this->relationLoaded('countrySettings')
            ? $this->countrySettings
            : $this->countrySettings()->where('is_active', true)->get();

        $countryCodes = $settings->pluck('country_code')->toArray();

        $flags = [
            'KR' => "\u{1F1F0}\u{1F1F7}",
            'US' => "\u{1F1FA}\u{1F1F8}",
            'CN' => "\u{1F1E8}\u{1F1F3}",
            'JP' => "\u{1F1EF}\u{1F1F5}",
        ];

        $displayed = array_slice($countryCodes, 0, $limit);
        $remaining = count($countryCodes) - $limit;

        $result = implode('', array_map(fn ($code) => $flags[$code] ?? $code, $displayed));

        if ($remaining > 0) {
            $result .= " +{$remaining}";
        }

        return $result;
    }

    /**
     * 특정 국가의 설정을 조회합니다.
     *
     * @param  string  $countryCode  국가코드
     */
    public function getCountrySetting(string $countryCode): ?ShippingPolicyCountrySetting
    {
        $settings = $this->relationLoaded('countrySettings')
            ? $this->countrySettings
            : $this->countrySettings()->get();

        return $settings
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * 우편번호가 도서산간 지역인지 확인하고 추가배송비를 반환합니다.
     * (KR countrySettings에서 조회)
     *
     * @param  string|null  $zipcode  우편번호
     * @return int 추가배송비 (도서산간 아닌 경우 0)
     */
    public function getExtraFeeForZipcode(?string $zipcode): int
    {
        $krSetting = $this->getCountrySetting('KR');

        if (! $krSetting) {
            return 0;
        }

        return $krSetting->getExtraFeeForZipcode($zipcode);
    }

    /**
     * 우편번호가 도서산간 지역인지 확인합니다.
     *
     * @param  string|null  $zipcode  우편번호
     * @return bool 도서산간 지역 여부
     */
    public function isRemoteArea(?string $zipcode): bool
    {
        return $this->getExtraFeeForZipcode($zipcode) > 0;
    }

    /**
     * 활성 정책 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 배송방법 필터 스코프 (countrySettings 기반)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithShippingMethods($query, array $methods)
    {
        if (empty($methods)) {
            return $query;
        }

        return $query->whereHas('countrySettings', function ($sub) use ($methods) {
            $sub->whereIn('shipping_method', $methods);
        });
    }

    /**
     * 부과정책 필터 스코프 (countrySettings 기반)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithChargePolicies($query, array $policies)
    {
        if (empty($policies)) {
            return $query;
        }

        return $query->whereHas('countrySettings', function ($sub) use ($policies) {
            $sub->whereIn('charge_policy', $policies);
        });
    }

    /**
     * 정책명 검색 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearchByName($query, string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $locales = config('app.translatable_locales', ['ko', 'en']);
            foreach ($locales as $index => $locale) {
                if ($index === 0) {
                    $q->where("name->{$locale}", 'like', "%{$search}%");
                } else {
                    $q->orWhere("name->{$locale}", 'like', "%{$search}%");
                }
            }
        });
    }

    /**
     * 정렬 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByField($query, string $sortBy = 'created_at', string $sortOrder = 'desc')
    {
        $allowedFields = ['id', 'name', 'is_active', 'sort_order', 'created_at', 'updated_at'];

        if (! in_array($sortBy, $allowedFields)) {
            $sortBy = 'created_at';
        }

        return $query->orderBy($sortBy, $sortOrder);
    }
}
