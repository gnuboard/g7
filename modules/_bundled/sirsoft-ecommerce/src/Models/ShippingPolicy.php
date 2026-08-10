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

    /**
     * 목록 경로가 국가별 설정에서 읽는 컬럼.
     *
     * 목록 화면이 그리는 10개 필드 + 그 값을 만드는 데 필요한 것만 남긴다.
     * `shipping_policy_id` 는 eager load 가 부모와 자식을 짝지을 때 쓰므로 빠지면 관계가
     * 통째로 비고, `custom_shipping_name` 은 배송방법이 `custom` 일 때 라벨의 원본이며,
     * `ranges` 는 구간제 배송비 요약이 읽는다.
     *
     * @var array<int, string>
     */
    public const LIST_COUNTRY_SETTING_COLUMNS = [
        'id',
        'shipping_policy_id',
        'country_code',
        'shipping_method',
        'custom_shipping_name',
        'currency_code',
        'charge_policy',
        'base_fee',
        'free_threshold',
        'ranges',
        'extra_fee_enabled',
        'is_active',
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
     * 이 정책에 연결된 국가별 배송 설정 관계.
     *
     * @return HasMany ShippingPolicyCountrySetting 컬렉션 관계
     */
    public function countrySettings(): HasMany
    {
        return $this->hasMany(ShippingPolicyCountrySetting::class);
    }

    /**
     * 목록 화면이 그리는 컬럼만 실은 국가별 설정 관계.
     *
     * 배송정책 목록과 상품 등록/수정의 배송정책 선택기는 국가 칩·배송방법·부과정책·배송비만
     * 그린다. 그런데 전체 컬럼을 실으면 행마다 `extra_fee_settings`(도서산간 지역 배열)와
     * `api_config`/`api_request_fields` 가 함께 딸려온다 — 화면이 그리지 않는 중첩 JSON 이
     * 정책당 국가 수만큼 응답에 실린다.
     *
     * `countrySettings` 를 좁히지 않고 **별도 관계**로 둔 이유: 배송비 계산
     * (`OrderCalculationService` → `getCountrySetting()`)은 `extra_fee_settings`·`api_config`
     * 를 실제로 읽는다. 같은 이름을 좁히면 목록 경로가 남긴 부분 로드가 계산 경로로 흘러들어
     * 배송비가 조용히 틀려진다.
     *
     * `ranges` 는 뺄 수 없다 — 구간제/단위당 정책의 배송비 요약(`getFeeSummary`)이 읽는다.
     *
     * @return HasMany 목록용 컬럼만 선택한 ShippingPolicyCountrySetting 관계
     */
    public function listCountrySettings(): HasMany
    {
        return $this->hasMany(ShippingPolicyCountrySetting::class)
            ->select(self::LIST_COUNTRY_SETTING_COLUMNS);
    }

    /**
     * 요약·목록 계산에 쓰는 활성 국가별 설정을 반환합니다.
     *
     * 로드된 관계가 있으면 그것을 쓰고, 없으면 조회한다. 어느 경로로 오든 **활성만** 남기므로
     * 같은 정책의 요약이 조회 경로에 따라 달라지지 않는다 — 목록은 비활성 배지를 그리려고
     * 비활성 행까지 싣기 때문에, 로더의 필터에 요약의 정확성을 맡길 수 없다.
     *
     * @return \Illuminate\Support\Collection 활성 국가별 설정 컬렉션
     */
    protected function resolveActiveCountrySettings()
    {
        if ($this->relationLoaded('countrySettings')) {
            return $this->countrySettings->where('is_active', true)->values();
        }

        if ($this->relationLoaded('listCountrySettings')) {
            return $this->listCountrySettings->where('is_active', true)->values();
        }

        return $this->countrySettings()->where('is_active', true)->get();
    }

    /**
     * 현재 로케일의 정책명을 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string 로케일별 정책명, 누락 시 fallback 로케일/첫 번째 키 순으로 시도
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 활성 국가별 설정을 종합하여 배송비 요약 텍스트를 생성합니다.
     *
     * 형식: `"KR: 3000원 | US: $20"` 처럼 country_code + 국가별 fee summary 를 ` | ` 로 join.
     *
     * @return string 국가별 배송비 요약 (활성 설정 없으면 빈 문자열)
     */
    public function getFeeSummary(): string
    {
        $settings = $this->resolveActiveCountrySettings();

        if ($settings->isEmpty()) {
            return '';
        }

        $summaries = $settings->map(function (ShippingPolicyCountrySetting $setting) {
            return $setting->country_code.': '.$setting->getFeeSummary();
        });

        return $summaries->implode(' | ');
    }

    /**
     * 활성 국가별 설정의 상세 배송비 정보를 배열로 반환합니다.
     *
     * 각 항목: `['country_code' => 'KR', ...국가별 상세 fee 필드...]`.
     * Frontend 가 표 형태로 표시할 때 사용.
     *
     * @return array<int, array<string, mixed>> 국가별 상세 배송비 정보 배열
     */
    public function getDetailedFeeInfo(): array
    {
        $settings = $this->resolveActiveCountrySettings();

        return $settings->map(function (ShippingPolicyCountrySetting $setting) {
            return [
                'country_code' => $setting->country_code,
                ...$setting->getDetailedFeeInfo(),
            ];
        })->toArray();
    }

    /**
     * 활성 국가 코드를 국기 이모지로 변환한 표시 문자열을 반환합니다.
     *
     * `$limit` 초과분은 `+N` 형태로 축약 (예: `🇰🇷🇺🇸🇨🇳 +2`).
     *
     * @param  int  $limit  국기로 표시할 최대 국가 개수 (초과분은 +N 표기)
     * @return string 국기 이모지 + 잔여 카운트 문자열
     */
    public function getCountriesWithFlags(int $limit = 3): string
    {
        $settings = $this->resolveActiveCountrySettings();

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
     * 특정 국가의 배송 설정을 조회합니다.
     *
     * @param  string  $countryCode  ISO 국가 코드 (예: 'KR', 'US')
     * @return ShippingPolicyCountrySetting|null 해당 국가 설정 또는 부재 시 null
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
     * countrySettings 의 shipping_method 가 주어진 값 중 하나 이상에 매치되는 정책만 반환하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<int, string>  $methods  필터링할 배송방법 코드 배열
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
     * countrySettings 의 charge_policy 가 주어진 값 중 하나 이상에 매치되는 정책만 반환하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<int, string>  $policies  필터링할 부과정책 코드 배열
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
     * 다국어 정책명(name JSON 컬럼) 의 모든 활성 locale 에서 검색어를 LIKE 매칭하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search  검색 키워드 (빈 문자열이면 노필터)
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
      *
      * @param  string  $sortBy  sort by
      * @param  string  $sortOrder  sort order
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
