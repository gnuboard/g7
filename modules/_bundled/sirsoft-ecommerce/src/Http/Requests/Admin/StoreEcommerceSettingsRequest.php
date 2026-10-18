<?php

// audit:allow api-doc-coverage reason: develop-7.1.0 리베이스 후속 — 주석의 메서드 위치 참조만 바꿨다. 검증 규칙·요청 계약 불변

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use App\Services\DriverRegistryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesSiteLocale;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Rules\ClaimReasonCodeFormat;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;

/**
 * 이커머스 설정 저장 요청 검증
 */
class StoreEcommerceSettingsRequest extends FormRequest
{
    use ResolvesSiteLocale;

    /**
     * 저장 요청이 실을 수 있는 설정 구역(탭) 키 — `_tab` 허용값이자 「저장할 구역이 하나라도 있는가」 판정 기준.
     *
     * @var array<int, string>
     */
    private const SECTIONS = ['basic_info', 'language_currency', 'seo', 'order_settings', 'claim', 'shipping', 'review_settings', 'notification_definitions', 'notifications', 'inquiry', 'mileage', 'privacy_retention'];

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력 데이터 정규화
     *
     * HTML number 입력의 DOM 값은 문자열이고, Laravel 의 integer/numeric 규칙은 숫자 문자열을
     * 통과시키되 캐스트하지 않는다. 캐스트하지 않으면 문자열이 그대로 설정 파일에 영속되어,
     * 이후 Carbon 같은 strict 타입 경계에서 TypeError 가 발생한다(무통장입금 주문 500 실패).
     *
     * 대상 필드는 rules() 에서 파생한다 — 손으로 열거하면 규칙이 추가될 때 누락된다.
     * 설정 응답이 표시용으로만 싣는 `basic_info.public_asset_disk_unavailable` 은 여기서 걷어 내 저장되지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();
        $changed = false;

        foreach ($this->rules() as $field => $fieldRules) {
            $castType = $this->numericCastTypeFor($fieldRules);
            if ($castType === null) {
                continue;
            }

            $changed = $this->castNumericPath($data, explode('.', $field), $castType) || $changed;
        }

        $changed = $this->fillOmittedManagedExchangeRates($data) || $changed;

        // 쓸 수 없게 된 공개 자산 디스크 안내는 설정 응답이 화면에 싣는 표시용 값이라 저장하지 않는다
        // (EcommerceSettingsAdminService::withPublicAssetCatalog). 화면은 받은 기본정보를 통째로 돌려보낸다.
        if (is_array($data['basic_info'] ?? null) && array_key_exists('public_asset_disk_unavailable', $data['basic_info'])) {
            unset($data['basic_info']['public_asset_disk_unavailable']);
            $changed = true;
        }

        if ($changed) {
            $this->replace($data);
        }
    }

    /**
     * 요청에서 빠진 관리 환율을 저장값으로 채웁니다.
     *
     * 환율 제공자가 관리하는 통화의 환율 칸은 화면에서 잠겨 있어 운영자가 바꿀 수 없으므로, 화면은
     * 저장할 때 그 환율을 보내지 않는다. 화면이 받아 둔 값을 그대로 다시 보내면 그 사이 제공자가
     * 환율을 갱신한 경우 저장값과 달라져 저장 전체가 「관리 환율 변경」 422 로 막히기 때문이다.
     * 빠진 값만 저장값으로 채우고, 값을 명시해 바꾸려는 요청은 종전대로 거절한다
     * (validateManagedExchangeRatesUnchanged).
     *
     * @param  array<string, mixed>  $data  요청 입력 (참조로 갱신)
     * @return bool 채운 값이 있으면 true
     */
    private function fillOmittedManagedExchangeRates(array &$data): bool
    {
        $currencies = $data['language_currency']['currencies'] ?? null;
        if (! is_array($currencies)) {
            return false;
        }

        $service = app(EcommerceSettingsService::class);
        $provider = $service->getActiveRateProvider();
        if ($provider === null) {
            return false;
        }

        $managedCodes = $provider['managed_codes'] ?? [];
        $savedByCode = array_column($service->getSettings('language_currency')['currencies'] ?? [], null, 'code');

        $changed = false;
        foreach ($currencies as $index => $currency) {
            $code = is_array($currency) ? ($currency['code'] ?? null) : null;
            if (! is_string($code)
                || array_key_exists('exchange_rate', $currency)
                || ! in_array($code, $managedCodes, true)
                || ! isset($savedByCode[$code])) {
                continue;
            }

            $data['language_currency']['currencies'][$index]['exchange_rate'] = $savedByCode[$code]['exchange_rate'] ?? null;
            $changed = true;
        }

        return $changed;
    }

    /**
     * 검증 규칙 목록에서 숫자 캐스트 종류를 판정합니다.
     *
     * @param  mixed  $fieldRules  단일 필드의 검증 규칙
     * @return string|null 'integer' | 'numeric' | null(캐스트 대상 아님)
     */
    private function numericCastTypeFor(mixed $fieldRules): ?string
    {
        // `Rule::forEach()` 같은 규칙 객체는 문자열로 변환되지 않는다 — 숫자 캐스트 대상이 아니다.
        if (! is_array($fieldRules) && ! is_string($fieldRules)) {
            return null;
        }

        $list = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

        $castType = null;
        foreach ($list as $rule) {
            if (! is_string($rule)) {
                continue;
            }
            if ($rule === 'integer') {
                return 'integer';
            }
            if ($rule === 'numeric') {
                $castType = 'numeric';
            }
        }

        return $castType;
    }

    /**
     * 도트 경로(와일드카드 `*` 포함)를 따라가며 숫자 문자열을 캐스트합니다.
     *
     * @param  array  $data  대상 데이터 (참조로 변경)
     * @param  array<int, string>  $segments  경로 세그먼트
     * @param  string  $castType  'integer' | 'numeric'
     * @return bool 값이 하나라도 변경되었으면 true
     */
    private function castNumericPath(array &$data, array $segments, string $castType): bool
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            $changed = false;
            foreach ($data as $key => $unused) {
                if (is_array($data[$key])) {
                    $changed = $this->castNumericPath($data[$key], $segments, $castType) || $changed;
                }
            }

            return $changed;
        }

        if (! array_key_exists($segment, $data)) {
            return false;
        }

        if ($segments !== []) {
            if (! is_array($data[$segment])) {
                return false;
            }

            return $this->castNumericPath($data[$segment], $segments, $castType);
        }

        $cast = $this->castNumericValue($data[$segment], $castType);
        if ($cast === null) {
            return false;
        }

        $data[$segment] = $cast;

        return true;
    }

    /**
     * 단일 값을 숫자로 캐스트합니다.
     *
     * 검증을 느슨하게 만들지 않기 위해, integer 필드는 정수 표기 문자열만 캐스트한다
     * (예: "3.7" 은 캐스트하지 않아 integer 규칙에서 그대로 실패해야 한다).
     *
     * @param  mixed  $value  원본 값
     * @param  string  $castType  'integer' | 'numeric'
     * @return int|float|null 캐스트 결과 (대상이 아니면 null)
     */
    private function castNumericValue(mixed $value, string $castType): int|float|null
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if ($castType === 'integer') {
            return preg_match('/^[+-]?\d+$/', $trimmed) === 1 ? (int) $trimmed : null;
        }

        if (! is_numeric($trimmed)) {
            return null;
        }

        // numeric 필드는 정수 표기를 int 로 유지해 저장 round-trip 타입 오염을 막는다.
        return preg_match('/^[+-]?\d+$/', $trimmed) === 1 ? (int) $trimmed : (float) $trimmed;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locale = $this->requiredNameLocale();

        return [
            '_tab' => ['sometimes', 'string', 'in:'.implode(',', self::SECTIONS)],

            'notifications' => ['sometimes', 'array'],
            'notifications.channels' => ['sometimes', 'array'],
            'notifications.channels.*.id' => ['required_with:notifications.channels', 'string', 'max:50'],
            'notifications.channels.*.is_active' => ['required_with:notifications.channels', 'boolean'],
            'notifications.channels.*.sort_order' => ['nullable', 'integer', 'min:0'],

            'basic_info' => ['sometimes', 'array'],
            'basic_info.shop_name' => ['required_with:basic_info', 'string', 'max:255'],
            'basic_info.route_path' => ['exclude_if:basic_info.no_route,true', 'required_with:basic_info', 'string', 'max:100'],
            'basic_info.no_route' => ['nullable', 'boolean'],
            'basic_info.company_name' => ['nullable', 'string', 'max:255'],
            'basic_info.business_number_1' => ['nullable', 'string', 'max:3'],
            'basic_info.business_number_2' => ['nullable', 'string', 'max:2'],
            'basic_info.business_number_3' => ['nullable', 'string', 'max:5'],
            'basic_info.ceo_name' => ['nullable', 'string', 'max:100'],
            'basic_info.business_type' => ['nullable', 'string', 'max:100'],
            'basic_info.business_category' => ['nullable', 'string', 'max:255'],
            'basic_info.zipcode' => ['nullable', 'string', 'max:10'],
            'basic_info.base_address' => ['nullable', 'string', 'max:500'],
            'basic_info.detail_address' => ['nullable', 'string', 'max:255'],
            'basic_info.phone_1' => ['nullable', 'string', 'max:4'],
            'basic_info.phone_2' => ['nullable', 'string', 'max:4'],
            'basic_info.phone_3' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_1' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_2' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_3' => ['nullable', 'string', 'max:4'],
            'basic_info.email_id' => ['nullable', 'string', 'max:100'],
            'basic_info.email_domain' => ['nullable', 'string', 'max:100'],
            'basic_info.privacy_officer' => ['nullable', 'string', 'max:100'],
            'basic_info.privacy_officer_email' => ['nullable', 'email', 'max:255'],
            'basic_info.mail_order_number' => ['nullable', 'string', 'max:100'],
            'basic_info.telecom_number' => ['nullable', 'string', 'max:100'],
            // 공개 자산 디스크 — 빈 값은 코어 공개 자산 디스크 설정을 따른다.
            // 선택지가 코어 카탈로그 + 플러그인 훅 등록 디스크로 동적이라 정적 in 불가 —
            // 코어 SaveSettingsRequest 와 동일하게 카탈로그 조회 closure 로 검증한다.
            // (런타임 resolvePublicAssetDisk() 의 스트리밍 폴백은 최후 안전망이지, 저장
            //  시점의 오타를 성공 응답으로 통과시켜도 된다는 뜻이 아니다 — 운영자에게는
            //  "저장됐는데 CDN 이 안 붙는" 무증상 상태로만 보인다.)
            'basic_info.public_asset_disk' => ['nullable', 'string', 'max:100', $this->publicAssetDiskRule()],

            'language_currency' => ['sometimes', 'array'],
            // default_language 제거 (A1-⑤, D-LANG): 사이트 언어는 코어 일반설정으로 일원화. 모듈 orphan 필드.
            'language_currency.default_currency' => ['nullable', 'string', 'max:10'],
            'language_currency.currencies' => ['nullable', 'array'],
            'language_currency.rate_provider' => ['nullable', 'string', 'max:50'],
            'language_currency.currencies.*.code' => ['required_with:language_currency.currencies', 'string', 'regex:/^[A-Z]{3}$/'],
            'language_currency.currencies.*.name' => ['required_with:language_currency.currencies', 'array'],
            'language_currency.currencies.*.name.*' => ['string', 'max:100'],
            'language_currency.currencies.*.symbol' => ['nullable', 'string', 'max:8'],
            'language_currency.currencies.*.exchange_rate' => ['nullable', 'numeric', 'min:0'],
            // base_unit = 그 통화가 기본일 때 환율 입력의 분모(1단위 금액). 소액통화만 묶음(KRW=1000, JPY=100).
            'language_currency.currencies.*.base_unit' => ['nullable', 'integer', 'min:1'],
            'language_currency.currencies.*.rounding_unit' => ['nullable', 'string'],
            'language_currency.currencies.*.rounding_method' => ['nullable', 'string', 'in:floor,round,ceil'],
            'language_currency.currencies.*.decimal_places' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.decimal_places_min', 0), 'max:'.config('sirsoft-ecommerce.limits.decimal_places_max', 8)],
            'language_currency.currencies.*.is_default' => ['nullable', 'boolean'],
            // 통화별 언어(가입 시 통화 추정용 매핑, A4). 후보 = 시스템 지원 로케일.
            'language_currency.currencies.*.locales' => ['nullable', 'array'],
            'language_currency.currencies.*.locales.*' => ['string', 'in:'.implode(',', config('app.supported_locales', ['ko', 'en']))],

            'seo' => ['sometimes', 'array'],
            'seo.meta_category_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_category_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_search_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_search_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_product_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_product_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_shop_index_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_shop_index_description' => ['nullable', 'string', 'max:1000'],
            'seo.seo_category' => ['nullable', 'boolean'],
            'seo.seo_search_result' => ['nullable', 'boolean'],
            'seo.seo_product_detail' => ['nullable', 'boolean'],
            'seo.seo_shop_index' => ['nullable', 'boolean'],

            // inquiry 섹션
            'inquiry' => ['sometimes', 'array'],
            'inquiry.board_slug' => ['nullable', 'string', 'max:255'],

            // order_settings 섹션
            'order_settings' => ['sometimes', 'array'],
            'order_settings.default_pg_provider' => ['nullable', 'string', 'max:50'],
            // 현금영수증 — 발급 프로바이더는 결제 PG 와 독립 선택한다 (빈 문자열 = 미사용).
            // 규칙을 명시하지 않으면 validated() 가 키를 떨궈 저장이 조용히 무효화된다.
            'order_settings.cash_receipt_provider' => ['nullable', 'string', 'max:50'],
            'order_settings.cash_receipt_self_issue' => ['nullable', 'boolean'],
            'order_settings.shipping_fee_tax_policy' => ['nullable', 'string', 'in:'.implode(',', ShippingFeeTaxPolicy::values())],
            'order_settings.payment_methods' => ['nullable', 'array'],
            'order_settings.payment_methods.*.id' => ['required_with:order_settings.payment_methods', 'string', 'max:50'],
            'order_settings.payment_methods.*.pg_provider' => ['nullable', 'string', 'max:50'],
            'order_settings.payment_methods.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'order_settings.payment_methods.*.is_active' => ['nullable', 'boolean'],
            'order_settings.payment_methods.*.min_order_amount' => ['nullable', 'integer', 'min:0'],
            'order_settings.payment_methods.*.stock_deduction_timing' => ['nullable', 'string', 'in:order_placed,payment_complete,none'],
            'order_settings.payment_methods.*.mileage_deduction_timing' => ['nullable', 'string', 'in:order_placed,payment_complete'],
            'order_settings.banks' => ['nullable', 'array'],
            'order_settings.banks.*.code' => ['required_with:order_settings.banks', 'string', 'max:10'],
            'order_settings.banks.*.name' => ['required_with:order_settings.banks', 'array'],
            "order_settings.banks.*.name.{$locale}" => ['required_with:order_settings.banks', 'string', 'max:100'],
            'order_settings.banks.*.name.*' => ['nullable', 'string', 'max:100'],
            'order_settings.bank_accounts' => ['nullable', 'array'],
            'order_settings.bank_accounts.*.bank_code' => ['required_with:order_settings.bank_accounts', 'string', 'max:10'],
            'order_settings.bank_accounts.*.account_number' => ['required_with:order_settings.bank_accounts', 'string', 'max:50'],
            'order_settings.bank_accounts.*.account_holder' => ['required_with:order_settings.bank_accounts', 'string', 'max:100'],
            'order_settings.bank_accounts.*.is_active' => ['nullable', 'boolean'],
            'order_settings.bank_accounts.*.is_default' => ['nullable', 'boolean'],
            'order_settings.auto_cancel_expired' => ['nullable', 'boolean'],
            // 0 은 now()->addDays(0) = 즉시 만료라 실질적으로 사용할 수 없는 값이다 → UI(min:1)가 옳다
            // nullable 금지: 숫자칸에 비숫자를 넣으면 브라우저가 값을 "" 로 비우고,
            // ConvertEmptyStringsToNull 로 null 이 되어 "저장되었습니다" 를 띄운 채 값만 사라진다.
            // 이후 소비처의 숨은 기본값(3일)으로 동작해 화면 표시와 실제 동작이 어긋난다.
            // sometimes 필수: rules() 는 탭 구분 없이 적용되므로 무조건 required 로 두면
            // 이 키를 보내지 않는 다른 탭(마일리지 등) 저장이 통째로 막힌다. 키가 온 경우에만 필수.
            'order_settings.auto_cancel_days' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.auto_cancel_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.auto_cancel_days_max', 30)],
            'order_settings.pending_order_expire_minutes' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.pending_order_expire_minutes_min', 0), 'max:'.config('sirsoft-ecommerce.limits.pending_order_expire_minutes_max', 20160)],
            'order_settings.cart_expiry_days' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.cart_expiry_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.cart_expiry_days_max', 365)],
            'order_settings.stock_restore_on_cancel' => ['nullable', 'boolean'],

            // 개인정보 보유기간 (이슈 #636) — 종결 주문의 개인정보를 보유기간 경과 후 익명화·삭제한다.
            // 되돌릴 수 없는 파기라 하한(전자상거래법 5년)을 서버가 강제한다.
            'privacy_retention' => ['sometimes', 'array'],
            'privacy_retention.enabled' => ['nullable', 'boolean'],
            'privacy_retention.retention_years' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.privacy_retention_years_min', 5), 'max:'.config('sirsoft-ecommerce.limits.privacy_retention_years_max', 20)],
            'privacy_retention.mode' => ['sometimes', 'required', 'string', 'in:anonymize,delete'],

            'order_settings.cancellable_statuses' => ['sometimes', 'array'],
            'order_settings.cancellable_statuses.*' => ['string', Rule::in(self::claimInstantCancelStatuses())],
            'order_settings.confirmable_statuses' => ['nullable', 'array'],
            'order_settings.confirmable_statuses.*' => ['string', 'in:payment_complete,shipping_hold,preparing,shipping_ready,shipping,delivered'],

            // claim 섹션 — 사유 카탈로그 3종(exchange/return/refund)은 DB 동기화 대상이고,
            // 나머지 스칼라 축은 defaults.json(1.5.0)과 같은 형태로 JSON 에 저장된다.
            // 어휘는 전부 Enum 단일 출처를 읽는다 — 리터럴을 늘어놓으면 Enum 이 늘 때
            // 화면 옵션과 게이트가 갈려 그 값으로 저장한 설정이 422 로만 드러난다.
            'claim' => ['sometimes', 'array'],

            // 신청 가능 상태 축 — 4축 모두 **주문옵션(상품) 상태**로 판정한다.
            // (UserClaimService::claimableOptions() 와 ClaimQuantityGuard::statusValueForGate()
            //  가 $option->option_status 를 이 목록과 대조한다. 주문 단위가 아니다 —
            //  부분 배송·부분 취소로 옵션 상태가 섞인 주문에서 둘은 다른 답을 낸다.)
            //
            // 배열 6축도 `nullable` 이 아니라 `sometimes` 다 — 아래 정수 3축과 같은 이유다.
            // null 은 규칙을 통과해 저장되고 array_merge(defaults, saved) 에서 기본값을 이겨,
            // 소비처의 `(array) null = []` 가 그 기능을 통째로 닫는다(교환·반품·취소·환불
            // 신청이 전건 거절되고 추가결제 수단이 하나도 없다). 화면은 배열만 보내므로
            // API·업그레이드 경로로만 들어오는데, 무너지는 방향이 조용해서 드러나지 않는다.
            // 빈 배열 `[]`("전부 내림")은 그대로 저장된다 — 그건 정당한 운영 의도다.
            // Enum 전체가 아니라 축마다 화면이 실제로 제공하는 상태로 좁힌다 — 형제
            // `order_settings.confirmable_statuses` 와 같은 방식이다. Enum 전체를 허용하면
            // `instant_cancel_statuses:["cancelled","returned"]` 같은 조합이 200 으로 저장되고,
            // `OrderRefundRequestService::branchForStatus()` 가 이 목록을 옵션 상태와 2차 축소
            // 없이 직접 비교하므로 이미 종결된 옵션이 취소 분기로 들어간다.
            'claim.exchange_requestable_statuses' => ['sometimes', 'array'],
            'claim.exchange_requestable_statuses.*' => ['string', Rule::in(self::claimRequestableStatuses())],
            'claim.return_requestable_statuses' => ['sometimes', 'array'],
            'claim.return_requestable_statuses.*' => ['string', Rule::in(self::claimRequestableStatuses())],
            'claim.instant_cancel_statuses' => ['sometimes', 'array'],
            'claim.instant_cancel_statuses.*' => ['string', Rule::in(self::claimInstantCancelStatuses())],
            'claim.refund_request_statuses' => ['sometimes', 'array'],
            'claim.refund_request_statuses.*' => ['string', Rule::in(self::claimRefundRequestStatuses())],

            // 청구 방식 / 추가결제 수단
            'claim.allowed_charge_methods' => ['sometimes', 'array'],
            'claim.allowed_charge_methods.*' => ['string', Rule::in(ClaimChargeMethodEnum::values())],
            'claim.charge_payment_methods' => ['sometimes', 'array'],
            // 확장 결제수단(간편결제)도 클레임 추가결제 수단으로 지정할 수 있어야 한다. 이 축의
            // 화이트리스트 단일 출처는 `PaymentMethodResolver::allValidIds()`(builtin + 카탈로그)
            // 이며 `CreateOrderRequest`·`OrderListRequest` 와 같은 파일의 order_settings 규칙이
            // 이미 그것을 쓴다. builtin Enum 으로 좁히면 일반 주문에서는 1급인 수단이 이 설정에서만
            // 거부되고, 저장값을 그대로 Rule::in 에 싣는 소비처(ValidatesClaimPaymentMethod)까지
            // 함께 막혀 그 수단의 클레임 추가결제 경로가 통째로 닫힌다.
            'claim.charge_payment_methods.*' => ['string', Rule::in(app(PaymentMethodResolver::class)->allValidIds())],

            // 자동 승인.
            // 불리언 3축도 `nullable` 이 아니라 `sometimes` 다 — 위 배열 6축·아래 정수 3축과 같은
            // 이유다. null 은 규칙을 통과해 저장되고 array_merge(defaults, saved) 에서 기본값을
            // 이긴다. 지금은 소비처가 `(bool) getSetting(..., false)` 로 방어하고 있어 증상이
            // 없지만, 그건 **소비처 방어에 기댄 안전**이라 한 곳만 바뀌어도 조용히 무너진다.
            'claim.auto_approve_zero_charge' => ['sometimes', 'boolean'],
            'claim.auto_approve_after_payment' => ['sometimes', 'boolean'],
            'claim.auto_approve_refund_request' => ['sometimes', 'boolean'],

            // 자동승인 대상 상태 (D8) — on/off 키와 **둘 다** 유지한다. 토글만 있으면 "출고 전만
            // 자동승인" 같은 운영을 표현할 수 없어, 운영자가 토글을 끄고 전건을 손으로 승인하거나
            // 켜고 배송중 건까지 자동승인하는 양자택일이 된다. 배열 축의 `sometimes` 이유는 위
            // 신청 가능 상태 4축과 같다.
            'claim.auto_approve_refund_statuses' => ['sometimes', 'array'],
            'claim.auto_approve_refund_statuses.*' => ['string', Rule::in(self::claimAutoApproveRefundStatuses())],

            // 환불 자동 승인 결제수단 (P16·P17) — 사람이 송금해야 끝나는 수단(무통장·가상계좌 등)은
            // 목록 밖이라 결제완료 상품이라도 `requested` 에서 멈춘다. 화이트리스트 출처는 위
            // charge_payment_methods 와 같은 `allValidIds()` 다. 여기만 builtin Enum 으로 좁히면
            // 플러그인이 등록한 즉시 환불 간편결제를 운영자가 목록에 넣을 수 없어, 그 수단의
            // 환불이 전부 승인 대기로 쌓인다.
            'claim.auto_approve_refund_payment_methods' => ['sometimes', 'array'],
            'claim.auto_approve_refund_payment_methods.*' => ['string', Rule::in(app(PaymentMethodResolver::class)->allValidIds())],

            // 운영자 결정 이력 (P17) — 화면이 보내지 않고 저장 직전에 서버가 채운다. 클라이언트가
            // 값을 실어 보내면 422 다. 카탈로그 안 ID 로만 좁혀 받던 종전 규칙은 운영자가 한 번도
            // 결정하지 않은 수단을 "결정됨" 으로 심어 플러그인 선언 수단의 자동 합류를 막을 수 있었다.
            // 빈 배열은 이 규칙을 통과하지만 저장 조립(EcommerceSettingsAdminService)이 걷어 낸다.
            'claim.auto_approve_refund_payment_methods_decided' => ['prohibited'],

            // 첨부 이미지 / 신청 가능 기간.
            // nullable 금지 — 형제 `order_settings.auto_cancel_days` 와 같은 이유다. null 은
            // min: 을 우회해 저장되는데, getAllSettings() 의 array_merge(defaults, saved) 에서
            // 저장된 null 이 기본값을 이겨 소비처가 (int) null = 0 을 본다. request_period_days 는
            // `$days <= 0` 에서 조기 반환하므로 **신청 기간 제한이 통째로 꺼지고**,
            // max_images·image_max_size_kb 는 max(1, 0) 으로 떨어져 첨부가 사실상 거부된다.
            // 셋 다 오류·로그가 남지 않는다.
            'claim.max_images' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.claim_max_images_min', 1), 'max:'.config('sirsoft-ecommerce.limits.claim_max_images_max', 20)],
            'claim.image_max_size_kb' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.claim_image_max_size_kb_min', 128), 'max:'.config('sirsoft-ecommerce.limits.claim_image_max_size_kb_max', 20480)],
            'claim.request_period_days' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.claim_request_period_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.claim_request_period_days_max', 365)],

            // 선교환 (D1·D2) — 켜고 끄는 불리언과 헌 상품 반송 기한(일). 기한은 null 을 막는다(위 정수 3축과
            // 같은 이유 — 저장된 null 이 기본값을 이겨 기한이 0 일이 된다).
            'claim.advance_exchange_enabled' => ['sometimes', 'boolean'],
            'claim.advance_exchange_collection_due_days' => ['sometimes', 'required', 'integer', 'min:'.config('sirsoft-ecommerce.limits.advance_exchange_collection_due_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.advance_exchange_collection_due_days_max', 90)],

            // 완료 처리 기본값 (D26 — 모달 초기 선택값).
            // 이 3축은 무너지는 **방향**까지 나쁘다. 소비처
            // `AdminClaimService::completionDefaults()` 는 `$defaults[...] ?? true` 라 저장된 null 이
            // `??` 를 발동시켜 **true** 가 된다 — 운영자가 꺼 둔 재고 복구·환불신청서 접수가
            // 모달에 켜진 채 열리고 그대로 실행된다(그 메서드 주석이 경계하는 방향 그 자체다).
            // 그래서 null 을 저장 자체에서 막는다.
            // 하위 키는 `required_with` 로 묶는다. `sometimes` 만으로는 **키 부재**가 통과하는데,
            // 카테고리 병합이 얕아(`array_merge`) 저장본에 맵이 있고 키만 없으면 defaults.json
            // 으로 폴백하지 않고 소비처의 `?? true` 를 만난다 — null 을 막은 것과 같은 피해가
            // 키를 빼는 것만으로 재현된다. 맵을 보내면 두 키를 모두 밝히도록 강제한다.
            // `required_with` 는 상대 필드가 **비어 있으면**(빈 맵 `{}`) 발동하지 않고, 그 빈 맵은
            // `validated()` 에서 부모 키째 탈락해 저장본에서 사라진다 — 얕은 병합이 defaults 를
            // 되살려 소비처 `?? true` 를 만나는 같은 피해다. 그래서 맵 자체에 두 키를 요구한다.
            'claim.complete_defaults' => ['sometimes', 'array', 'required_array_keys:create_refund_request,restore_stock'],
            'claim.complete_defaults.create_refund_request' => ['required_with:claim.complete_defaults', 'boolean'],
            'claim.complete_defaults.restore_stock' => ['required_with:claim.complete_defaults', 'boolean'],

            // 사유 카탈로그 — 유형이 같은 스키마(D23)를 쓴다. 한 유형만 강도가 다르면
            // 그 유형이 우회로가 되므로 규칙을 한 곳에서 파생한다. 유형 목록도 Enum 단일
            // 출처다 — 손으로 나열하면 유형이 늘 때 규칙이 없는 유형이 생기고, validated()
            // 가 그 섹션을 통째로 버려 응답은 200 인데 아무것도 저장되지 않는다.
            ...$this->claimReasonRulesForAllTypes(),

            // review_settings 섹션
            'review_settings' => ['sometimes', 'array'],
            'review_settings.write_deadline_days' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.write_deadline_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.write_deadline_days_max', 365)],
            'review_settings.max_images' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.max_images_min', 0), 'max:'.config('sirsoft-ecommerce.limits.max_images_max', 20)],
            'review_settings.max_image_size_mb' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.max_image_size_mb_min', 1), 'max:'.config('sirsoft-ecommerce.limits.max_image_size_mb_max', 50)],

            // mileage 섹션
            'mileage' => ['sometimes', 'array'],
            'mileage.enabled' => ['required_with:mileage', 'boolean'],
            'mileage.default_earn_rate' => ['nullable', 'numeric', 'min:'.config('sirsoft-ecommerce.limits.default_earn_rate_min', 0), 'max:'.config('sirsoft-ecommerce.limits.default_earn_rate_max', 100)],
            // 마일리지 차감 시점은 결제수단별(order_settings.payment_methods.*.mileage_deduction_timing)로 관리한다.
            'mileage.earn_trigger' => ['nullable', 'string', 'in:delivered,confirmed'],
            'mileage.earn_delay_days' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.earn_delay_days_min', 0), 'max:'.config('sirsoft-ecommerce.limits.earn_delay_days_max', 365)],
            'mileage.currency_rules' => ['nullable', 'array'],
            // 통화 코드: ISO 4217 형식(3자리 영문 대문자) 강제
            'mileage.currency_rules.*.currency_code' => ['required_with:mileage.currency_rules', 'string', 'regex:/^[A-Z]{3}$/'],
            // 1점당 금액: 0이면 마일리지가 금전 가치 0 → 사용 불가. 최소 0 초과(0.001 단위까지 허용)
            'mileage.currency_rules.*.point_value' => ['nullable', 'numeric', 'min:'.config('sirsoft-ecommerce.limits.point_value_min', 0.001)],
            'mileage.currency_rules.*.min_use_amount' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.min_use_amount_min', 0)],
            'mileage.currency_rules.*.use_unit' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.use_unit_min', 1)],
            'mileage.currency_rules.*.max_use_type' => ['nullable', 'string', 'in:percent,fixed'],
            'mileage.currency_rules.*.max_use_percent' => ['nullable', 'numeric', 'min:'.config('sirsoft-ecommerce.limits.max_use_percent_min', 0), 'max:'.config('sirsoft-ecommerce.limits.max_use_percent_max', 100)],
            // 최대 사용한도(고정금액): 비합리적 거액 방지를 위해 10억 상한
            'mileage.currency_rules.*.max_use_value' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.max_use_value_min', 0), 'max:'.config('sirsoft-ecommerce.limits.max_use_value_max', 1000000000)],
            // 적립 절사 기준 — 통화 환산 절사(language_currency.currencies.*.rounding_*)와 별개다.
            // 그쪽은 외화 표시 전용이라 기본 통화에는 적용되지 않는 반면, 적립은 기본 통화 원장에
            // 확정 기록되는 값이므로 자기 규칙을 갖는다. 어휘는 MileageRounding 이 SSoT.
            'mileage.currency_rules.*.earn_rounding_unit' => ['nullable', 'string', 'in:'.implode(',', MileageRounding::UNITS)],
            'mileage.currency_rules.*.earn_rounding_method' => ['nullable', 'string', 'in:'.implode(',', MileageRounding::METHODS)],
            'mileage.expiry_enabled' => ['nullable', 'boolean'],
            'mileage.expiry_days' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.expiry_days_min', 1), 'max:'.config('sirsoft-ecommerce.limits.expiry_days_max', 3650)],
            'mileage.expiry_notification_enabled' => ['nullable', 'boolean'],
            'mileage.expiry_notification_days_before' => ['nullable', 'integer', 'min:'.config('sirsoft-ecommerce.limits.expiry_notification_days_before_min', 1), 'max:'.config('sirsoft-ecommerce.limits.expiry_notification_days_before_max', 365)],

            // shipping 섹션
            'shipping' => ['sometimes', 'array'],
            'shipping.default_country' => ['nullable', 'string', 'max:10'],
            'shipping.available_countries' => ['nullable', 'array'],
            'shipping.available_countries.*.code' => ['required_with:shipping.available_countries', 'string', 'max:10'],
            'shipping.available_countries.*.name' => ['required_with:shipping.available_countries', 'array'],
            'shipping.available_countries.*.name.*' => ['string', 'max:100'],
            'shipping.available_countries.*.is_active' => ['nullable', 'boolean'],
            'shipping.international_shipping_enabled' => ['nullable', 'boolean'],
            'shipping.free_shipping_threshold' => ['nullable', 'integer', 'min:0'],
            'shipping.free_shipping_enabled' => ['nullable', 'boolean'],
            'shipping.address_validation_enabled' => ['nullable', 'boolean'],
            'shipping.address_api_provider' => ['nullable', 'string', 'max:50'],

            // shipping.carriers 섹션 (DB 동기화 대상)
            'shipping.carriers' => ['nullable', 'array'],
            // `id` 는 "기존 행" 판정의 유일한 근거다(이름의 사이트 기본 언어 필수 면제). 실재하지
            // 않는 id 를 통과시키면 면제만 받고 sync 는 신규 create 로 흘러 이름 없는 행이 생긴다.
            'shipping.carriers.*.id' => ['nullable', 'integer', Rule::exists(ShippingCarrier::class, 'id')],
            // 형식 검사는 **신규 행**에만 건다. 이 탭은 저장할 때마다 카탈로그 전체를 되보내므로,
            // 규칙이 좁혀지기 전에 만들어진 코드가 한 건이라도 있으면 운영자가 손대지 않은 그 행
            // 때문에 탭 전체가 영구히 422 가 된다 — 같은 라운드가 클레임 사유 코드·이름과
            // 배송사·배송유형 이름에 이미 적용한 판정을 코드 축에도 맞춘다.
            'shipping.carriers.*.code' => Rule::forEach(fn ($value, string $attribute) => $this->catalogCodeRules($attribute, 'shipping.carriers')),
            'shipping.carriers.*.name' => Rule::forEach(fn ($value, string $attribute) => $this->translatableNameRules($attribute, 'shipping.carriers')),
            'shipping.carriers.*.type' => ['required_with:shipping.carriers', 'string', 'in:domestic,international'],
            'shipping.carriers.*.tracking_url' => ['nullable', 'string', 'max:500'],
            'shipping.carriers.*.is_active' => ['required_with:shipping.carriers', 'boolean'],
            'shipping.carriers.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // shipping.types 섹션 (DB 동기화 대상)
            'shipping.types' => ['nullable', 'array'],
            'shipping.types.*.id' => ['nullable', 'integer', Rule::exists(ShippingType::class, 'id')],
            'shipping.types.*.code' => Rule::forEach(fn ($value, string $attribute) => $this->catalogCodeRules($attribute, 'shipping.types')),
            'shipping.types.*.name' => Rule::forEach(fn ($value, string $attribute) => $this->translatableNameRules($attribute, 'shipping.types')),
            'shipping.types.*.category' => ['required_with:shipping.types', 'string', 'in:domestic,international,other'],
            'shipping.types.*.is_active' => ['required_with:shipping.types', 'boolean'],
            'shipping.types.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 공개 자산 디스크 존재 검증 규칙을 만듭니다.
     *
     * 선택지는 코어 3종 + 플러그인이 훅으로 등록한 디스크라 런타임에만 확정되므로
     * 정적 `Rule::in` 을 쓸 수 없습니다. 코어 `SaveSettingsRequest` 와 동일하게
     * 카탈로그 게터(SSoT)를 조회하는 closure 로 검증해 두 표면의 강도를 맞춥니다.
     *
     * @return \Closure 검증 closure
     */
    protected function publicAssetDiskRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! app(DriverRegistryService::class)->isDriverAvailable('public_asset', $value)) {
                $fail(__('sirsoft-ecommerce::validation.public_asset_disk_invalid'));
            }
        };
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('sirsoft-ecommerce::validation.attributes');

        return array_merge(is_array($attributes) ? $attributes : [], [
            // 개인정보 보유기간 (이슈 #636) — 키별로 명시해 오류 문구에 필드 키가 노출되지 않게 한다
            'privacy_retention' => __('sirsoft-ecommerce::validation.privacy_retention_attributes.section'),
            'privacy_retention.enabled' => __('sirsoft-ecommerce::validation.privacy_retention_attributes.enabled'),
            'privacy_retention.retention_years' => __('sirsoft-ecommerce::validation.privacy_retention_attributes.retention_years'),
            'privacy_retention.mode' => __('sirsoft-ecommerce::validation.privacy_retention_attributes.mode'),
            // 클레임 정책 (이슈 #599) — 환경설정 화면 라벨과 같은 문구로 키별 명시
            'order_settings.cancellable_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.cancellable_statuses'),
            'order_settings.cancellable_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.cancellable_statuses'),
            'claim.exchange_requestable_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.exchange_requestable_statuses'),
            'claim.exchange_requestable_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.exchange_requestable_statuses'),
            'claim.return_requestable_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.return_requestable_statuses'),
            'claim.return_requestable_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.return_requestable_statuses'),
            'claim.instant_cancel_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.instant_cancel_statuses'),
            'claim.instant_cancel_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.instant_cancel_statuses'),
            'claim.refund_request_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.refund_request_statuses'),
            'claim.refund_request_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.refund_request_statuses'),
            'claim.allowed_charge_methods' => __('sirsoft-ecommerce::validation.claim_settings_attributes.allowed_charge_methods'),
            'claim.allowed_charge_methods.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.allowed_charge_methods'),
            'claim.charge_payment_methods' => __('sirsoft-ecommerce::validation.claim_settings_attributes.charge_payment_methods'),
            'claim.charge_payment_methods.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.charge_payment_methods'),
            'claim.auto_approve_zero_charge' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_zero_charge'),
            'claim.auto_approve_after_payment' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_after_payment'),
            'claim.auto_approve_refund_request' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_request'),
            'claim.auto_approve_refund_statuses' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_statuses'),
            'claim.auto_approve_refund_statuses.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_statuses'),
            'claim.auto_approve_refund_payment_methods' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_payment_methods'),
            'claim.auto_approve_refund_payment_methods.*' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_payment_methods'),
            'claim.auto_approve_refund_payment_methods_decided' => __('sirsoft-ecommerce::validation.claim_settings_attributes.auto_approve_refund_payment_methods_decided'),
            'claim.max_images' => __('sirsoft-ecommerce::validation.claim_settings_attributes.max_images'),
            'claim.image_max_size_kb' => __('sirsoft-ecommerce::validation.claim_settings_attributes.image_max_size_kb'),
            'claim.request_period_days' => __('sirsoft-ecommerce::validation.claim_settings_attributes.request_period_days'),
            'claim.advance_exchange_enabled' => __('sirsoft-ecommerce::validation.claim_settings_attributes.advance_exchange_enabled'),
            'claim.advance_exchange_collection_due_days' => __('sirsoft-ecommerce::validation.claim_settings_attributes.advance_exchange_collection_due_days'),
            'claim.complete_defaults' => __('sirsoft-ecommerce::validation.claim_settings_attributes.complete_defaults'),
            'claim.complete_defaults.create_refund_request' => __('sirsoft-ecommerce::validation.claim_settings_attributes.complete_defaults_create_refund_request'),
            'claim.complete_defaults.restore_stock' => __('sirsoft-ecommerce::validation.claim_settings_attributes.complete_defaults_restore_stock'),
        ]);
    }

    /**
     * 추가 검증 로직 설정
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateHasSettingsSection($validator);
            $this->validateRateProviderRegistered($validator);
            $this->validateManagedExchangeRatesUnchanged($validator);
            $this->validateBaseCurrencyChangeable($validator);
            $this->validateUniqueCurrencyCodes($validator);
            $this->validateCurrencyNames($validator);
            $this->validateAtLeastOneActivePaymentMethod($validator);
            $this->validatePgRequiredForActivation($validator);
            $this->validateBankAccountDefaults($validator);
            $this->validateDbankRequiresUsableAccount($validator);
            $this->validateUniqueCountryCodes($validator);
            $this->validateCountryNames($validator);
            $this->validateDefaultCountryExists($validator);
            $this->validateUniqueCarrierCodes($validator);
            $this->validateCarrierCodeSwaps($validator);
            $this->validateCarrierNames($validator);
            $this->validateUniqueShippingTypeCodes($validator);
            $this->validateShippingTypeCodeSwaps($validator);
            $this->validateShippingTypeNames($validator);
            foreach (ClaimReasonTypeEnum::cases() as $claimReasonType) {
                $this->validateUniqueClaimReasonCodes($validator, $claimReasonType->value);
                $this->validateClaimReasonCodeSwaps($validator, $claimReasonType->value);
                $this->validateClaimReasonNames($validator, $claimReasonType->value);
            }
            $this->validateMileageCurrencyRules($validator);
            $this->validateMileageEarnRate($validator);
        });
    }

    /**
     * 저장할 설정 구역이 하나라도 실렸는지 검증합니다.
     *
     * 구역이 하나도 없으면 저장할 것이 없는데도 「설정이 저장되었습니다」로 응답했다 — 화면의 본문 식이 평가에
     * 실패해 식 원문 문자열이 본문으로 나갔을 때 운영자는 저장된 줄 알았다(#703 실측). 빈 요청은 거절한다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateHasSettingsSection(Validator $validator): void
    {
        foreach (self::SECTIONS as $section) {
            if (is_array($this->input($section))) {
                return;
            }
        }

        $validator->errors()->add('_tab', __('sirsoft-ecommerce::validation.settings.empty_payload'));
    }

    /**
     * 새로 선택한 환율 제공자의 등록 여부를 검증합니다.
     * 비활성화된 제공자의 기존 선택값은 그대로 보존합니다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateRateProviderRegistered(Validator $validator): void
    {
        $selected = $this->input('language_currency.rate_provider');
        $service = app(EcommerceSettingsService::class);
        // 비활성 제공자의 기존 선택값은 보존합니다. 새로운 미등록 값만 거절합니다.
        if (! $selected || $selected === $service->getSetting('language_currency.rate_provider')) {
            return;
        }
        if (! in_array($selected, array_column($service->getRegisteredRateProviders(), 'id'), true)) {
            $validator->errors()->add('language_currency.rate_provider', __('sirsoft-ecommerce::validation.exchange_rate_provider_unavailable'));
        }
    }

    /** 기존 관리 환율 변경만 막고 통화 구성·표시 설정 변경은 허용합니다.
     * @param  Validator  $validator  validator 입력값
     * @return void 반환값 없음
     */
    protected function validateManagedExchangeRatesUnchanged(Validator $validator): void
    {
        $service = app(EcommerceSettingsService::class);
        $provider = $service->getActiveRateProvider();
        if ($provider === null || ! is_array($this->input('language_currency.currencies'))) {
            return;
        }
        $saved = $service->getSettings('language_currency');
        $oldByCode = array_column($saved['currencies'] ?? [], null, 'code');
        $base = $this->input('language_currency.default_currency', $saved['default_currency'] ?? null);
        foreach ($this->input('language_currency.currencies') as $index => $currency) {
            if (! is_array($currency) || ! is_string($currency['code'] ?? null)) {
                continue; // 잘못된 행 구조는 기본 rules에서 422로 처리합니다.
            }
            $code = $currency['code'] ?? null;
            if ($code === $base || ! isset($oldByCode[$code]) || ! in_array($code, $provider['managed_codes'] ?? [], true)) {
                continue;
            }
            if (abs((float) ($oldByCode[$code]['exchange_rate'] ?? 0) - (float) ($currency['exchange_rate'] ?? 0)) > 1e-9) {
                $validator->errors()->add("language_currency.currencies.{$index}.exchange_rate", __('sirsoft-ecommerce::validation.custom.language_currency.currencies.exchange_rate_managed', [
                    'currency' => $code,
                    'provider' => $provider['name'] ?? $provider['id'],
                ]));
            }
        }
    }

    /**
     * 상품·주문이 있을 때 기본통화 변경을 거절합니다.
     * 소프트삭제된 상품·주문도 과거 금액의 기준이므로 변경 가능 판정에 포함합니다.
     *
     * @param  Validator  $validator  validator 입력값
     * @return void 반환값 없음
     */
    protected function validateBaseCurrencyChangeable(Validator $validator): void
    {
        $newDefault = $this->input('language_currency.default_currency');
        if ($newDefault === null) {
            return; // 통화 설정 미전달 — 변경 아님
        }

        $current = app(EcommerceSettingsService::class)->getSetting('language_currency.default_currency');
        if ($newDefault === $current) {
            return; // 동일 통화 재저장 — 변경 아님
        }

        // 상품/주문 1건이라도 있으면 base 변경 불가 (영구 잠금, 소프트삭제 포함)
        $hasData = app(ProductRepositoryInterface::class)->existsAny()
            || app(OrderRepositoryInterface::class)->existsAny();

        if ($hasData) {
            $validator->errors()->add(
                'language_currency.default_currency',
                __('sirsoft-ecommerce::validation.custom.language_currency.base_locked_after_data')
            );
        }
    }

    /**
     * 마일리지 활성화 시 기본 적립률 검증
     *
     * 마일리지 사용(enabled)을 켰는데 기본 적립률이 0%면 적립이 전혀 발생하지 않아
     * 활성화 의미가 없으므로 거부한다. (비활성 상태에서는 0% 허용)
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateMileageEarnRate(Validator $validator): void
    {
        if (! $this->has('mileage')) {
            return;
        }

        $enabled = $this->boolean('mileage.enabled');
        $rate = $this->input('mileage.default_earn_rate');

        if ($enabled && $rate !== null && (float) $rate <= 0) {
            $validator->errors()->add(
                'mileage.default_earn_rate',
                __('sirsoft-ecommerce::validation.mileage.earn_rate_required_when_enabled')
            );
        }
    }

    /**
     * 마일리지 통화 규칙 검증
     *
     * 1) 통화 코드 중복 금지
     * 2) 첫 행은 기본 통화여야 함 (기본 통화 행 보장)
     * 3) 등록된 통화(language_currency.currencies)만 허용 (정합성)
     *
     * 형식(ISO 4217)은 rules() 의 regex 로 선검증되며, 여기서는 도메인 정합성만 다룬다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateMileageCurrencyRules(Validator $validator): void
    {
        $rules = $this->input('mileage.currency_rules', []);

        if (empty($rules) || ! is_array($rules)) {
            return;
        }

        [$defaultCurrency, $registeredCodes] = $this->resolveRegisteredCurrencies();

        $codes = [];
        foreach ($rules as $index => $rule) {
            if (! isset($rule['currency_code']) || ! is_string($rule['currency_code'])) {
                continue;
            }

            $code = strtoupper(trim($rule['currency_code']));

            // 형식 위반(regex 미통과)은 rules() 에서 이미 에러 추가됨 → 도메인 검증 스킵
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                $codes[] = $code;

                continue;
            }

            // 1) 중복
            if (in_array($code, $codes, true)) {
                $validator->errors()->add(
                    "mileage.currency_rules.{$index}.currency_code",
                    __('sirsoft-ecommerce::validation.mileage.duplicate_currency')
                );
            }

            // 2) 첫 행 = 기본 통화
            if ($index === 0 && $defaultCurrency !== null && $code !== $defaultCurrency) {
                $validator->errors()->add(
                    'mileage.currency_rules.0.currency_code',
                    __('sirsoft-ecommerce::validation.mileage.first_must_be_default', ['currency' => $defaultCurrency])
                );
            }

            // 3) 등록 통화 정합성 (등록 목록이 있을 때만)
            if (! empty($registeredCodes) && ! in_array($code, $registeredCodes, true)) {
                $validator->errors()->add(
                    "mileage.currency_rules.{$index}.currency_code",
                    __('sirsoft-ecommerce::validation.mileage.currency_not_registered', ['currency' => $code])
                );
            }

            $codes[] = $code;
        }
    }

    /**
     * 검증 기준이 되는 등록 통화 정보를 해석합니다.
     *
     * 같은 요청에 language_currency 가 함께 오면 그 값을, 아니면 현재 저장된 설정을 기준으로 한다
     * (탭별 저장 시 마일리지 탭은 현재 통화 설정을 기준으로 정합성 검증).
     *
     * @return array{0: ?string, 1: array<int, string>} [기본통화코드, 등록통화코드목록]
     */
    protected function resolveRegisteredCurrencies(): array
    {
        $defaultCurrency = $this->input('language_currency.default_currency');
        $currencies = $this->input('language_currency.currencies');

        // 요청에 language_currency 가 없으면 현재 저장 설정에서 조회
        if ($defaultCurrency === null || ! is_array($currencies)) {
            $settings = app(EcommerceSettingsService::class);
            $defaultCurrency = $defaultCurrency ?? $settings->getSetting('language_currency.default_currency');
            $currencies = is_array($currencies) ? $currencies : ($settings->getSetting('language_currency.currencies') ?? []);
        }

        $codes = [];
        foreach ((array) $currencies as $currency) {
            if (isset($currency['code']) && is_string($currency['code'])) {
                $codes[] = strtoupper(trim($currency['code']));
            }
        }

        $defaultCurrency = is_string($defaultCurrency) ? strtoupper(trim($defaultCurrency)) : null;

        return [$defaultCurrency, $codes];
    }

    /**
     * 통화 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateUniqueCurrencyCodes(Validator $validator): void
    {
        $currencies = $this->input('language_currency.currencies', []);

        if (empty($currencies) || ! is_array($currencies)) {
            return;
        }

        $codes = [];
        foreach ($currencies as $index => $currency) {
            if (! isset($currency['code']) || ! is_string($currency['code'])) {
                continue;
            }

            $code = strtoupper(trim($currency['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 통화명 필수 검증 - 최소 하나의 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateCurrencyNames(Validator $validator): void
    {
        $currencies = $this->input('language_currency.currencies', []);

        if (empty($currencies) || ! is_array($currencies)) {
            return;
        }

        foreach ($currencies as $index => $currency) {
            $name = $currency['name'] ?? [];

            // name이 배열이 아니거나 빈 배열인 경우
            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name_required')
                );

                continue;
            }

            // 모든 로케일의 이름이 빈 문자열인지 확인
            $hasValidName = false;
            foreach ($name as $localeName) {
                if (is_string($localeName) && trim($localeName) !== '') {
                    $hasValidName = true;
                    break;
                }
            }

            if (! $hasValidName) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name_required')
                );
            }
        }
    }

    /**
     * 결제수단 최소 1개 활성화 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateAtLeastOneActivePaymentMethod(Validator $validator): void
    {
        $paymentMethods = $this->input('order_settings.payment_methods', []);

        if (empty($paymentMethods) || ! is_array($paymentMethods)) {
            return;
        }

        $hasActive = false;
        foreach ($paymentMethods as $method) {
            if (! empty($method['is_active'])) {
                $hasActive = true;
                break;
            }
        }

        if (! $hasActive) {
            $validator->errors()->add(
                'order_settings.payment_methods',
                __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.at_least_one_active')
            );
        }
    }

    /**
     * PG가 필요한 결제수단 활성화 시 PG사 선택 필수 검증
     *
     * needsPgProvider()인 결제수단이 is_active: true인 경우,
     * 해당 결제수단의 pg_provider 또는 default_pg_provider가 설정되어 있어야 합니다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validatePgRequiredForActivation(Validator $validator): void
    {
        $methods = $this->input('order_settings.payment_methods', []);
        $defaultPg = $this->input('order_settings.default_pg_provider');

        if (empty($methods) || ! is_array($methods)) {
            return;
        }

        $resolver = app(PaymentMethodResolver::class);

        foreach ($methods as $index => $method) {
            $id = $method['id'] ?? '';
            $isActive = $method['is_active'] ?? false;
            $pgProvider = $method['pg_provider'] ?? null;

            if ($id === '') {
                continue;
            }

            // PG 고정 수단(간편결제 등)은 플러그인이 자기 PG 로 고정하므로 관리자가 선택할 수 없다.
            // 검증 대상에서 제외하지 않으면 저장 자체가 막힌다.
            if ($resolver->isPgLocked($id)) {
                continue;
            }

            // 확장 결제수단도 카탈로그 선언으로 PG 필요 여부가 판정된다.
            // enum tryFrom() 으로 판정하면 확장수단이 null → 검증이 통째로 스킵됐다(#475).
            if ($resolver->needsPgProvider($id) && $isActive && ! $pgProvider && ! $defaultPg) {
                $validator->errors()->add(
                    "order_settings.payment_methods.{$index}.pg_provider",
                    __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.pg_required_for_activation')
                );
            }
        }
    }

    /**
     * 무통장입금 활성화 시 사용할 수 있는 입금 계좌 필수 검증
     *
     * 무통장입금(dbank)을 켠 상태로 사용 중인 계좌가 하나도 없으면 주문서가 고를 계좌가 없어
     * 결제하기가 「입금 은행을 선택해주세요」 로만 끝난다. 이번 요청에 실린 값이 있으면 그것을,
     * 없으면 저장값을 기준으로 판정한다(결제수단만 또는 계좌만 보내는 저장도 같은 결과가 되도록).
     * 주문설정을 싣지 않은 다른 탭 저장에는 관여하지 않는다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateDbankRequiresUsableAccount(Validator $validator): void
    {
        if (! $this->has('order_settings.payment_methods') && ! $this->has('order_settings.bank_accounts')) {
            return;
        }

        $service = app(EcommerceSettingsService::class);
        $saved = $service->getSettings('order_settings');

        $methods = $this->has('order_settings.payment_methods')
            ? $this->input('order_settings.payment_methods')
            : ($saved['payment_methods'] ?? []);
        $dbankActive = false;
        foreach (is_array($methods) ? $methods : [] as $method) {
            if (is_array($method) && ($method['id'] ?? null) === PaymentMethodEnum::DBANK->value && ! empty($method['is_active'])) {
                $dbankActive = true;
                break;
            }
        }
        if (! $dbankActive) {
            return;
        }

        $accounts = $this->has('order_settings.bank_accounts')
            ? $this->input('order_settings.bank_accounts')
            : ($saved['bank_accounts'] ?? []);

        if (! $service->hasUsableBankAccount($accounts)) {
            $validator->errors()->add(
                'order_settings.bank_accounts',
                __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.dbank_requires_active_account')
            );
        }
    }

    /**
     * 무통장 계좌 기본+사용 설정 검증
     *
     * 계좌가 존재하는 경우 하나 이상은 기본 선택 + 사용 선택 상태여야 합니다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateBankAccountDefaults(Validator $validator): void
    {
        $bankAccounts = $this->input('order_settings.bank_accounts', []);

        if (empty($bankAccounts) || ! is_array($bankAccounts)) {
            return;
        }

        $hasActiveDefault = false;
        foreach ($bankAccounts as $account) {
            if (! empty($account['is_active']) && ! empty($account['is_default'])) {
                $hasActiveDefault = true;
                break;
            }
        }

        if (! $hasActiveDefault) {
            $validator->errors()->add(
                'order_settings.bank_accounts',
                __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.at_least_one_active_default')
            );
        }
    }

    /**
     * 배송가능국가 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateUniqueCountryCodes(Validator $validator): void
    {
        $countries = $this->input('shipping.available_countries', []);

        if (empty($countries) || ! is_array($countries)) {
            return;
        }

        $codes = [];
        foreach ($countries as $index => $country) {
            if (! isset($country['code']) || ! is_string($country['code'])) {
                continue;
            }

            $code = strtoupper(trim($country['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송가능국가 이름 필수 검증 - 최소 하나의 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateCountryNames(Validator $validator): void
    {
        $countries = $this->input('shipping.available_countries', []);

        if (empty($countries) || ! is_array($countries)) {
            return;
        }

        foreach ($countries as $index => $country) {
            $name = $country['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name_required')
                );

                continue;
            }

            $hasValidName = false;
            foreach ($name as $localeName) {
                if (is_string($localeName) && trim($localeName) !== '') {
                    $hasValidName = true;
                    break;
                }
            }

            if (! $hasValidName) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name_required')
                );
            }
        }
    }

    /**
     * 기본 국가가 배송가능국가 목록에 존재하는지 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateDefaultCountryExists(Validator $validator): void
    {
        $defaultCountry = $this->input('shipping.default_country');
        $countries = $this->input('shipping.available_countries', []);

        // 사후 검증은 규칙이 실패해도 돈다 — 문자열이 아닌 값은 규칙이 이미 422 로 막았다.
        if (empty($defaultCountry) || ! is_string($defaultCountry) || empty($countries) || ! is_array($countries)) {
            return;
        }

        $codes = array_map(
            fn ($c) => is_array($c) && is_string($c['code'] ?? null) ? strtoupper(trim($c['code'])) : '',
            $countries
        );

        if (! in_array(strtoupper(trim($defaultCountry)), $codes)) {
            $validator->errors()->add(
                'shipping.default_country',
                __('sirsoft-ecommerce::validation.custom.shipping.default_country.must_exist_in_countries')
            );
        }
    }

    /**
     * 다국어 이름 규칙 — 신규 행은 사이트 기본 언어 필수, 기존 행(`id` 보유)은 길이·형식만.
     *
     * 카탈로그(배송사·배송유형·클레임 사유 3종)는 저장할 때마다 전체를 되보낸다. 다른 로케일에서
     * 만들어 사이트 기본 언어 이름이 빈 기존 행이 한 건이라도 있으면 손대지 않은 행이 섹션 전체를 막는다 —
     * 기존 코드를 형식 검사에서 면제하는 `ClaimReasonCodeFormat` 과 같은 이유로 이름도 기존 행을 면제한다.
     *
     * @param  string  $attribute  현재 검증 중인 속성 경로 (`{section}.{index}.name`)
     * @param  string  $section  카탈로그 섹션 경로 (`shipping.carriers` 등)
     * @return array<int, mixed> 검증 규칙
     */
    protected function translatableNameRules(string $attribute, string $section): array
    {
        $idAttribute = preg_replace('/\.name$/', '.id', $attribute);
        $isExistingRow = ! empty($this->input($idAttribute));

        return $isExistingRow
            ? ["required_with:{$section}", 'array', new TranslatableField(maxLength: 100)]
            : ["required_with:{$section}", 'array', new LocaleRequiredTranslatable(maxLength: 100)];
    }

    /**
     * 카탈로그 행이 기존 행(`id` 보유)인지 판정합니다 — after 훅의 사이트 기본 언어 이름 검사 면제 기준.
     *
     * @param  array<string, mixed>  $row  카탈로그 행
     * @return bool 기존 행이면 true
     */
    protected function isExistingCatalogRow(array $row): bool
    {
        return ! empty($row['id']);
    }

    /**
     * 배송사·배송유형 코드의 규칙을 만듭니다 — 기존 행은 형식 검사를 면제합니다.
     *
     * 클레임 사유 코드가 `ClaimReasonCodeFormat` 의 면제 목록으로 하는 것과 같은 판정이다.
     * 이 탭은 저장할 때마다 카탈로그 전체를 되보내므로, 형식 규칙이 좁혀진 뒤에는 이미
     * 저장된 코드 한 건이 운영자가 손대지 않은 채로 탭 전체를 영구히 422 로 만든다.
     * 기존 행(`id` 보유)이라도 **코드를 바꾸면** 그 값은 신규 취급이라 형식을 다시 받는다.
     *
     * @param  string  $attribute  검증 대상 속성 경로 (예: shipping.carriers.0.code)
     * @param  string  $section  섹션 경로 (required_with 대상)
     * @return array<int, mixed> 검증 규칙
     */
    protected function catalogCodeRules(string $attribute, string $section): array
    {
        $base = ["required_with:{$section}", 'string', 'max:50'];

        $idAttribute = preg_replace('/\.code$/', '.id', $attribute);
        $id = $this->input($idAttribute);

        if (! empty($id)) {
            // 저장소 인터페이스를 경유한다 — 요청 안에서 모델을 직접 조회하지 않는다
            // (형제 `existingClaimReasonCodes()` 와 같은 규율).
            $repository = $section === 'shipping.carriers'
                ? app(ShippingCarrierRepositoryInterface::class)
                : app(ShippingTypeRepositoryInterface::class);
            $current = $repository->findById((int) $id)?->code;

            // 손대지 않은 기존 코드만 면제한다 — 바꾼 값은 아래 형식 규칙을 받는다. 규칙 계산 중이라 검증 전
            // 값이므로 문자열이 아니면 형 변환하지 않는다(형식 오류는 `string` 규칙이 422 로 돌려준다).
            $submitted = $this->input($attribute);

            if ($current !== null && is_string($submitted) && (string) $current === $submitted) {
                return $base;
            }
        }

        return [...$base, 'regex:/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/'];
    }

    /**
     * 배송사 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateUniqueCarrierCodes(Validator $validator): void
    {
        $carriers = $this->input('shipping.carriers', []);

        if (empty($carriers) || ! is_array($carriers)) {
            return;
        }

        $codes = [];
        foreach ($carriers as $index => $carrier) {
            // 사후 검증은 규칙이 실패해도 돈다 — 문자열이 아닌 코드는 `string` 규칙이 이미 422 로 막았다.
            if (! isset($carrier['code']) || ! is_string($carrier['code'])) {
                continue;
            }

            $code = strtolower(trim($carrier['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송사명 필수 검증 - ko 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateCarrierNames(Validator $validator): void
    {
        $carriers = $this->input('shipping.carriers', []);

        if (empty($carriers) || ! is_array($carriers)) {
            return;
        }

        foreach ($carriers as $index => $carrier) {
            $name = $carrier['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.name_required')
                );

                continue;
            }

            if ($this->isExistingCatalogRow($carrier)) {
                continue;
            }

            $locale = $this->requiredNameLocale();
            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.name.{$locale}",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.name_required')
                );
            }
        }
    }

    /**
     * 배송유형 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateUniqueShippingTypeCodes(Validator $validator): void
    {
        $types = $this->input('shipping.types', []);

        if (empty($types) || ! is_array($types)) {
            return;
        }

        $codes = [];
        foreach ($types as $index => $type) {
            if (! isset($type['code']) || ! is_string($type['code'])) {
                continue;
            }

            $code = strtolower(trim($type['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.types.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송유형명 필수 검증 - ko 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 반환값 없음
     */
    protected function validateShippingTypeNames(Validator $validator): void
    {
        $types = $this->input('shipping.types', []);

        if (empty($types) || ! is_array($types)) {
            return;
        }

        foreach ($types as $index => $type) {
            $name = $type['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.types.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.name_required')
                );

                continue;
            }

            if ($this->isExistingCatalogRow($type)) {
                continue;
            }

            $locale = $this->requiredNameLocale();
            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "shipping.types.{$index}.name.{$locale}",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.name_required')
                );
            }
        }
    }

    /**
     * 교환·반품을 신청할 수 있는 주문 상태의 허용 어휘를 돌려줍니다.
     *
     * 설정 화면(`_claim_policy_section.json`)의 선택지와 같은 집합이다. 화면 옵션과 게이트가
     * 갈리면 한쪽이 우회로가 되므로 `ClaimSettingsStatusVocabularyTest` 가 두 목록의
     * 일치를 고정한다.
     *
     * @return array<int, string> 허용 상태 값 목록
     */
    public static function claimRequestableStatuses(): array
    {
        return [
            OrderStatusEnum::PAYMENT_COMPLETE->value,
            OrderStatusEnum::PREPARING->value,
            OrderStatusEnum::SHIPPING_READY->value,
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
            OrderStatusEnum::CONFIRMED->value,
        ];
    }

    /**
     * 접수 후 승인·완료까지 자동 전이되는 주문옵션 상태의 허용 어휘를 돌려줍니다.
     *
     * 이름은 설정 키(`claim.instant_cancel_statuses`)를 따르지만 뜻은 D2 로 바뀌었다 — 이 상태의
     * 상품도 환불신청서를 만들고, 다른 것은 승인·완료까지 자동으로 진행한다는 점뿐이다.
     *
     * 미결제 2종(`pending_order`·`pending_payment`)이 허용값에 들어 있다(D10). 이 축은
     * `order_settings.cancellable_statuses` 의 별칭이고, `Order::isCancellable()` 은 미결제를
     * 목록과 무관하게 **항상** 허용하므로 주문 단위 취소 판정은 달라지지 않는다 — 달라지는 것은
     * 상품 단위 신청 자격(`branchForStatus`)이며, 그것이 D10 이 여는 축이다.
     *
     * @return array<int, string> 허용 상태 값 목록
     */
    public static function claimInstantCancelStatuses(): array
    {
        return [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::PAYMENT_COMPLETE->value,
            OrderStatusEnum::SHIPPING_HOLD->value,
            OrderStatusEnum::PREPARING->value,
            OrderStatusEnum::SHIPPING_READY->value,
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
            OrderStatusEnum::CONFIRMED->value,
        ];
    }

    /**
     * 환불신청서로 접수되는 주문옵션 상태의 허용 어휘를 돌려줍니다.
     *
     * @return array<int, string> 허용 상태 값 목록
     */
    public static function claimRefundRequestStatuses(): array
    {
        return [
            OrderStatusEnum::PAYMENT_COMPLETE->value,
            OrderStatusEnum::SHIPPING_HOLD->value,
            OrderStatusEnum::PREPARING->value,
            OrderStatusEnum::SHIPPING_READY->value,
            OrderStatusEnum::SHIPPING->value,
        ];
    }

    /**
     * 접수된 환불신청서를 자동 승인할 주문옵션 상태의 허용 어휘를 돌려줍니다. (D8)
     *
     * 환불신청 가능 상태와 같은 집합이다 — 자동승인은 "접수된 신청서" 에만 걸리므로 접수될 수
     * 없는 상태를 여기에 허용하면 영영 발동하지 않는 설정이 저장된다.
     *
     * @return array<int, string> 허용 상태 값 목록
     */
    public static function claimAutoApproveRefundStatuses(): array
    {
        return self::claimRefundRequestStatuses();
    }

    /**
     * 클레임 사유 카탈로그 전 유형의 검증 규칙을 만듭니다. (D23)
     *
     * 유형은 같은 스키마를 쓴다. 유형마다 규칙을 따로 적으면 한 유형만 강도가 낮아졌을 때
     * 그 경로가 우회로가 되므로 한 곳에서 파생한다. 유형 목록은 ClaimReasonTypeEnum 이
     * 단일 출처다 — 손으로 나열하면 유형이 늘 때 규칙 없는 유형이 생기고, validated() 가
     * 그 섹션을 버려 응답 200·화면 "저장됨" 인데 아무것도 반영되지 않는다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    protected function claimReasonRulesForAllTypes(): array
    {
        $rules = [];

        foreach (ClaimReasonTypeEnum::cases() as $type) {
            $rules += $this->claimReasonRules($type->value);
        }

        return $rules;
    }

    /**
     * 한 유형의 사유 카탈로그 검증 규칙을 만듭니다.
     *
     * @param  string  $type  사유 유형 (ClaimReasonTypeEnum 값)
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    protected function claimReasonRules(string $type): array
    {
        $key = "claim.{$type}_reasons";

        return [
            $key => ['nullable', 'array'],
            // 삭제는 휴지통으로 지목한 id 목록으로만 — 페이로드에 없는 행은 삭제가 아니다(S0-D2).
            // 실재 검사는 걸지 않는다: 화면이 저장 뒤에도 목록을 들고 있어 이미 지워진 id 가
            // 다시 오면 사유 없이 422 가 되고, 서비스가 그 유형에 실재하는 id 만 고른다.
            "{$key}_deleted" => ['nullable', 'array'],
            "{$key}_deleted.*" => ['integer'],
            // `id` 는 아래 이름 규칙의 "기존 행" 판정 근거이자 sync 의 update/create 분기 키다.
            // 실재하지 않는 id(또는 다른 유형의 id)를 통과시키면 이름 면제만 받고 sync 는 else
            // 분기(create)로 흘러 **사이트 기본 언어 이름이 없는 사유가 새로 생긴다**. 유형 스코프까지
            // 건다 — 교환 id 를 반품 페이로드에 실으면 그 역시 create 다.
            "{$key}.*.id" => ['nullable', 'integer', Rule::exists(ClaimReason::class, 'id')->where('type', $type)],
            // 형식은 ClaimReasonCodeFormat 단일 출처다 — 상세 REST 경로와 같은 규칙을 쓴다.
            // 이 탭은 저장할 때마다 카탈로그 **전체**를 되보내므로, 이미 저장된 코드는 형식
            // 검사를 면제한다. 면제가 없으면 규칙이 좁혀지기 전에 만들어진 코드 한 건 때문에
            // 운영자가 손대지 않은 행이 탭 전체를 영구히 422 로 만든다.
            "{$key}.*.code" => ["required_with:{$key}", 'string', 'max:50', new ClaimReasonCodeFormat($this->existingClaimReasonCodes($type))],
            // 이름의 사이트 기본 언어 필수는 **신규 행**에만 건다. 이 탭은 카탈로그 전체를 되보내므로,
            // 다른 로케일에서 만든 기존 행(사이트 기본 언어 이름이 빈 행)이 한 건이라도 있으면 운영자가
            // 손대지 않은 그 행 때문에 탭 전체가 422 가 된다 — 코드 형식이 기존 코드를 면제하는
            // 것과 같은 이유다. 기존 행은 길이·형식만 검사한다 (`id` 가 있으면 기존 행이다).
            "{$key}.*.name" => Rule::forEach(fn ($value, string $attribute) => $this->translatableNameRules($attribute, $key)),
            "{$key}.*.fault_type" => ["required_with:{$key}", 'string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            // `nullable` 이 아니라 `sometimes` 다. 소비처 `ClaimReasonService::syncReasons()` 가
            // `?? true` 라 저장된 null 이 **true** 로 뒤집히고, 운영자가 내려 둔 사유가 저장
            // 한 번에 구매자 선택지로 되살아난다 — 무너지는 방향이 "끈 것을 켜진 것으로" 라
            // 형제 `claim.*` 불리언 3축과 같은 강도로 맞춘다(8차 N3 와 동일 판정).
            // 키 부재까지 거절한다 (`required_with`). `sometimes` 는 null 만 막고, 키를 빼면 소비처의
            // `?? true` 가 내려 둔 사유를 켜진 채 되살린다 — 같은 파일의 `complete_defaults` 와 같은 강도.
            "{$key}.*.is_user_selectable" => ["required_with:{$key}", 'boolean'],
            "{$key}.*.is_active" => ["required_with:{$key}", 'boolean'],
            "{$key}.*.sort_order" => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 해당 유형에 이미 저장된 사유 코드를 반환합니다. (형식 검사 면제 대상)
     *
     * 저장소 인터페이스를 경유한다 — 규칙 안에서 모델을 직접 조회하지 않는다.
     *
     * @param  string  $type  사유 유형 (ClaimReasonTypeEnum 값)
     * @return array<int, string> 이미 저장된 코드
     */
    protected function existingClaimReasonCodes(string $type): array
    {
        return app(ClaimReasonRepositoryInterface::class)->getCodesByType($type);
    }

    /**
     * 클레임 사유 코드 중복 검증 (교환/반품/환불 공통)
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @return void 반환값 없음
     */
    protected function validateUniqueClaimReasonCodes(Validator $validator, string $type): void
    {
        $key = "claim.{$type}_reasons";
        $reasons = $this->input($key, []);

        if (empty($reasons) || ! is_array($reasons)) {
            return;
        }

        $codes = [];
        foreach ($reasons as $index => $reason) {
            if (! isset($reason['code']) || ! is_string($reason['code'])) {
                continue;
            }

            $code = strtolower(trim($reason['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "{$key}.{$index}.code",
                    __("sirsoft-ecommerce::validation.custom.claim.{$type}_reasons.duplicate_code")
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 기존 행끼리 코드를 맞바꾸는 저장을 거절합니다 (교환/반품/환불 공통)
     *
     * 페이로드 안에서는 코드가 유일해도, 기존 행 A 가 **아직 다른 기존 행 B 가 쓰고 있는**
     * 코드로 바뀌면 `syncReasons()` 가 행 단위로 update 하다 DB `unique(type, code)` 에서
     * 죽는다. 그 시점엔 설정 파일(정책 13축)이 이미 기록된 뒤라 스칼라만 반영된 반쪽 상태가
     * 남고, 예외도 `ClaimReasonException` 이 아니라 500 이다 — 쓰기 전 선행 판정
     * (`assertSyncDeletable`)이 막으려던 바로 그 상태다. 그래서 파일 쓰기보다 앞선 이 자리에서
     * 422 로 거절한다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용한다(삭제가 먼저다).
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @return void 반환값 없음
     */
    protected function validateClaimReasonCodeSwaps(Validator $validator, string $type): void
    {
        $key = "claim.{$type}_reasons";

        $this->rejectCodeSwaps(
            $validator,
            $key,
            $this->input($key, []),
            fn (array $codes) => app(ClaimReasonRepositoryInterface::class)->findByCodes($type, $codes),
            __("sirsoft-ecommerce::validation.custom.claim.{$type}_reasons.code_swap")
        );
    }

    /**
     * 기존 배송사끼리 코드를 맞바꾸는 저장을 거절합니다
     *
     * 클레임 사유와 같은 결함군이다 — `ecommerce_shipping_carriers.code` 도 UNIQUE 이고
     * `syncCarriers()` 는 설정 파일 쓰기 **뒤** 행 단위로 update 한다. 클레임과 달리 기존 행의
     * 코드 칸이 화면에서 자유 편집이라 API 가 아니라 설정 화면에서 그대로 재현된다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 입력값을 검사하고 검증 오류를 등록
     */
    protected function validateCarrierCodeSwaps(Validator $validator): void
    {
        $this->rejectCodeSwaps(
            $validator,
            'shipping.carriers',
            $this->input('shipping.carriers', []),
            fn (array $codes) => app(ShippingCarrierRepositoryInterface::class)->findByCodes($codes),
            __('sirsoft-ecommerce::validation.custom.shipping.carriers.code_swap')
        );
    }

    /**
     * 기존 배송유형끼리 코드를 맞바꾸는 저장을 거절합니다
     *
     * 배송사·클레임 사유와 같은 결함군이다 — `ecommerce_shipping_types.code` 도 UNIQUE 이고
     * `syncShippingTypes()` 는 설정 파일 쓰기 **뒤** 행 단위로 update 한다. 페이로드 안 유일성은
     * 통과하는데 DB unique 에서 죽으므로, 그 시점엔 shipping 설정 파일이 이미 기록된 뒤라
     * 스칼라만 반영된 반쪽 상태 + 500 이 남는다. 그래서 파일 쓰기보다 앞선 이 자리에서 422 로
     * 거절한다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용한다(삭제가 먼저다).
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @return void 입력값을 검사하고 검증 오류를 등록
     */
    protected function validateShippingTypeCodeSwaps(Validator $validator): void
    {
        $this->rejectCodeSwaps(
            $validator,
            'shipping.types',
            $this->input('shipping.types', []),
            fn (array $codes) => app(ShippingTypeRepositoryInterface::class)->findByCodes($codes),
            __('sirsoft-ecommerce::validation.custom.shipping.types.code_swap')
        );
    }

    /**
     * 코드 맞바꾸기 판정 본체 (클레임 사유 3종·배송사 공용)
     *
     * 충돌 = 이 저장의 어떤 행(기존이든 신규든)이 쓰려는 코드를, **이 저장에 남는 다른 기존 행**이
     * 아직 쥐고 있는 경우. 신규 행(`id` 없음)도 대상이다 — 신규 행이 "이 저장에서 코드가 바뀌는
     * 기존 행" 의 현재 코드를 가져가면 `syncReasons()` 가 페이로드 순서대로 create 하다 같은
     * unique 에서 죽는다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용한다(삭제가 먼저다).
     *
     * 대조는 양쪽 다 소문자로 한다 — 형식 규칙이 **이미 저장된 코드를 면제**하므로 DB 에는
     * 대문자 코드가 남아 있을 수 있고, MySQL CI 콜레이션의 `whereIn` 은 그 행을 돌려주는데
     * 엄격 비교는 놓친다(감지 실패 → unique 위반은 CI 로 터진다).
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @param  string  $key  섹션 키 (`claim.exchange_reasons` / `shipping.carriers`)
     * @param  mixed  $rows  요청 행 배열
     * @param  \Closure(array<int, string>): Collection  $findByCodes  코드 목록으로 현재 행을 찾는 조회
     * @param  string  $message  거절 메시지 (번역 완료)
     * @return void 입력값을 검사하고 검증 오류를 등록
     */
    private function rejectCodeSwaps(Validator $validator, string $key, mixed $rows, \Closure $findByCodes, string $message): void
    {
        if (empty($rows) || ! is_array($rows)) {
            return;
        }

        $incoming = [];
        $presentIds = [];
        foreach ($rows as $index => $row) {
            // 사후 검증은 규칙이 실패해도 돈다 — 문자열이 아닌 코드는 `string` 규칙이 이미 422 로 막았다.
            if (! is_array($row) || ! isset($row['code']) || ! is_string($row['code'])) {
                continue;
            }

            $id = ! empty($row['id']) ? (int) $row['id'] : null;
            $incoming[] = ['index' => $index, 'id' => $id, 'code' => strtolower(trim($row['code']))];
            if ($id !== null) {
                $presentIds[$id] = true;
            }
        }

        if ($incoming === [] || $presentIds === []) {
            return;
        }

        // 저장소 인터페이스를 경유한다 — 규칙 안에서 모델을 직접 조회하지 않는다.
        $holders = [];
        foreach ($findByCodes(array_column($incoming, 'code')) as $current) {
            $holders[strtolower((string) $current->code)] = (int) $current->id;
        }

        foreach ($incoming as $row) {
            $holderId = $holders[$row['code']] ?? null;

            // 그 코드를 지금 쥔 행이 없거나, 자기 자신이거나, 이 저장에서 지워지는 행이면 충돌이 아니다.
            if ($holderId === null || $holderId === $row['id'] || ! isset($presentIds[$holderId])) {
                continue;
            }

            $validator->errors()->add("{$key}.{$row['index']}.code", $message);
        }
    }

    /**
     * 클레임 사유명 필수 검증 - 사이트 기본 언어에 이름이 있어야 함 (교환/반품/환불 공통)
     *
     * @param  Validator  $validator  Validator 인스턴스
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @return void 입력값을 검사하고 검증 오류를 등록
     */
    protected function validateClaimReasonNames(Validator $validator, string $type): void
    {
        $key = "claim.{$type}_reasons";
        $reasons = $this->input($key, []);

        if (empty($reasons) || ! is_array($reasons)) {
            return;
        }

        $locale = $this->requiredNameLocale();

        foreach ($reasons as $index => $reason) {
            $name = $reason['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "{$key}.{$index}.name",
                    __("sirsoft-ecommerce::validation.custom.claim.{$type}_reasons.name_required")
                );

                continue;
            }

            if ($this->isExistingCatalogRow($reason)) {
                continue;
            }

            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "{$key}.{$index}.name.{$locale}",
                    __("sirsoft-ecommerce::validation.custom.claim.{$type}_reasons.name_required")
                );
            }
        }
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $locale = $this->requiredNameLocale();

        return [
            // basic_info 섹션
            'basic_info.shop_name.required' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.required'),
            'basic_info.shop_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.string'),
            'basic_info.shop_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.max'),
            'basic_info.route_path.required' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.required'),
            'basic_info.route_path.string' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.string'),
            'basic_info.route_path.max' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.max'),
            'basic_info.no_route.boolean' => __('sirsoft-ecommerce::validation.custom.basic_info.no_route.boolean'),
            'basic_info.company_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.company_name.string'),
            'basic_info.company_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.company_name.max'),
            'basic_info.business_number_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.business_number_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.business_number_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.ceo_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.ceo_name.string'),
            'basic_info.ceo_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.ceo_name.max'),
            'basic_info.business_type.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_type.string'),
            'basic_info.business_type.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_type.max'),
            'basic_info.business_category.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_category.string'),
            'basic_info.business_category.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_category.max'),
            'basic_info.zipcode.string' => __('sirsoft-ecommerce::validation.custom.basic_info.zipcode.string'),
            'basic_info.zipcode.max' => __('sirsoft-ecommerce::validation.custom.basic_info.zipcode.max'),
            'basic_info.base_address.string' => __('sirsoft-ecommerce::validation.custom.basic_info.base_address.string'),
            'basic_info.base_address.max' => __('sirsoft-ecommerce::validation.custom.basic_info.base_address.max'),
            'basic_info.detail_address.string' => __('sirsoft-ecommerce::validation.custom.basic_info.detail_address.string'),
            'basic_info.detail_address.max' => __('sirsoft-ecommerce::validation.custom.basic_info.detail_address.max'),
            'basic_info.phone_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.phone_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.phone_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.fax_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.fax_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.fax_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.email_id.string' => __('sirsoft-ecommerce::validation.custom.basic_info.email_id.string'),
            'basic_info.email_id.max' => __('sirsoft-ecommerce::validation.custom.basic_info.email_id.max'),
            'basic_info.email_domain.string' => __('sirsoft-ecommerce::validation.custom.basic_info.email_domain.string'),
            'basic_info.email_domain.max' => __('sirsoft-ecommerce::validation.custom.basic_info.email_domain.max'),
            'basic_info.privacy_officer.string' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer.string'),
            'basic_info.privacy_officer.max' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer.max'),
            'basic_info.privacy_officer_email.email' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer_email.email'),
            'basic_info.privacy_officer_email.max' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer_email.max'),
            'basic_info.mail_order_number.string' => __('sirsoft-ecommerce::validation.custom.basic_info.mail_order_number.string'),
            'basic_info.mail_order_number.max' => __('sirsoft-ecommerce::validation.custom.basic_info.mail_order_number.max'),
            'basic_info.telecom_number.string' => __('sirsoft-ecommerce::validation.custom.basic_info.telecom_number.string'),
            'basic_info.telecom_number.max' => __('sirsoft-ecommerce::validation.custom.basic_info.telecom_number.max'),

            // language_currency 섹션 (default_language 제거 — A1-⑤, D-LANG)
            'language_currency.default_currency.string' => __('sirsoft-ecommerce::validation.custom.language_currency.default_currency.string'),
            'language_currency.default_currency.max' => __('sirsoft-ecommerce::validation.custom.language_currency.default_currency.max'),
            'language_currency.currencies.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.required_with'),
            'language_currency.currencies.*.code.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.string'),
            'language_currency.currencies.*.code.regex' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.regex'),

            // mileage 통화 규칙 섹션
            'mileage.currency_rules.*.currency_code.required_with' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.currency_code.required_with'),
            'mileage.currency_rules.*.currency_code.regex' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.currency_code.regex'),
            'mileage.currency_rules.*.point_value.numeric' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.point_value.numeric'),
            'mileage.currency_rules.*.point_value.min' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.point_value.min'),
            'mileage.currency_rules.*.max_use_value.integer' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.integer'),
            'mileage.currency_rules.*.max_use_value.min' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.min'),
            'mileage.currency_rules.*.max_use_value.max' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.max'),
            'language_currency.currencies.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.required_with'),
            'language_currency.currencies.*.name.array' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.array'),
            'language_currency.currencies.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.string'),
            'language_currency.currencies.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.max'),
            'language_currency.currencies.*.exchange_rate.numeric' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.exchange_rate.numeric'),
            'language_currency.currencies.*.exchange_rate.min' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.exchange_rate.min'),
            'language_currency.currencies.*.rounding_unit.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_unit.string'),
            'language_currency.currencies.*.rounding_method.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_method.string'),
            'language_currency.currencies.*.rounding_method.in' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_method.in'),
            'language_currency.currencies.*.decimal_places.integer' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.integer'),
            'language_currency.currencies.*.decimal_places.min' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.min'),
            'language_currency.currencies.*.decimal_places.max' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.max'),
            'language_currency.currencies.*.is_default.boolean' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.is_default.boolean'),

            // seo 섹션
            'seo.meta_category_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_title.string'),
            'seo.meta_category_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_title.max'),
            'seo.meta_category_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_description.string'),
            'seo.meta_category_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_description.max'),
            'seo.meta_search_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_title.string'),
            'seo.meta_search_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_title.max'),
            'seo.meta_search_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_description.string'),
            'seo.meta_search_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_description.max'),
            'seo.meta_product_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_title.string'),
            'seo.meta_product_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_title.max'),
            'seo.meta_product_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_description.string'),
            'seo.meta_product_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_description.max'),
            'seo.seo_category.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_category.boolean'),
            'seo.seo_search_result.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_search_result.boolean'),
            'seo.seo_product_detail.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_product_detail.boolean'),
            'seo.meta_shop_index_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_title.string'),
            'seo.meta_shop_index_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_title.max'),
            'seo.meta_shop_index_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_description.string'),
            'seo.meta_shop_index_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_description.max'),
            'seo.seo_shop_index.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_shop_index.boolean'),

            // order_settings 섹션 - banks
            'order_settings.banks.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.banks.code.required_with'),
            'order_settings.banks.*.code.string' => __('sirsoft-ecommerce::validation.custom.banks.code.string'),
            'order_settings.banks.*.code.max' => __('sirsoft-ecommerce::validation.custom.banks.code.max'),
            'order_settings.banks.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            'order_settings.banks.*.name.array' => __('sirsoft-ecommerce::validation.custom.banks.name.array'),
            "order_settings.banks.*.name.{$locale}.required_with" => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            "order_settings.banks.*.name.{$locale}.string" => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            "order_settings.banks.*.name.{$locale}.max" => __('sirsoft-ecommerce::validation.custom.banks.name.max'),
            'order_settings.banks.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            'order_settings.banks.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.banks.name.max'),

            // order_settings 섹션 - payment_methods
            'order_settings.payment_methods.*.id.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.id.required_with'),
            'order_settings.payment_methods.*.id.string' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.id.string'),
            'order_settings.payment_methods.*.sort_order.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.sort_order.integer'),
            'order_settings.payment_methods.*.sort_order.min' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.sort_order.min'),
            'order_settings.payment_methods.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.is_active.boolean'),
            'order_settings.payment_methods.*.min_order_amount.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.min_order_amount.integer'),
            'order_settings.payment_methods.*.min_order_amount.min' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.min_order_amount.min'),
            'order_settings.payment_methods.*.stock_deduction_timing.string' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.stock_deduction_timing.string'),
            'order_settings.payment_methods.*.stock_deduction_timing.in' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.stock_deduction_timing.in'),
            'order_settings.bank_accounts.*.bank_code.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.bank_code.required_with'),
            'order_settings.bank_accounts.*.account_number.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.required_with'),
            'order_settings.bank_accounts.*.account_holder.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.required_with'),
            'order_settings.bank_accounts.*.bank_code.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.bank_code.string'),
            'order_settings.bank_accounts.*.account_number.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.string'),
            'order_settings.bank_accounts.*.account_number.max' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.max'),
            'order_settings.bank_accounts.*.account_holder.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.string'),
            'order_settings.bank_accounts.*.account_holder.max' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.max'),
            'order_settings.bank_accounts.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.is_active.boolean'),
            'order_settings.bank_accounts.*.is_default.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.is_default.boolean'),
            'order_settings.auto_cancel_expired.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_expired.boolean'),
            'order_settings.auto_cancel_days.required' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.required'),
            'order_settings.auto_cancel_days.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.integer'),
            'order_settings.auto_cancel_days.min' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.min'),
            'order_settings.auto_cancel_days.max' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.max'),
            'order_settings.pending_order_expire_minutes.required' => __('sirsoft-ecommerce::validation.custom.order_settings.pending_order_expire_minutes.required'),
            'order_settings.pending_order_expire_minutes.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.pending_order_expire_minutes.integer'),
            'order_settings.pending_order_expire_minutes.min' => __('sirsoft-ecommerce::validation.custom.order_settings.pending_order_expire_minutes.min'),
            'order_settings.pending_order_expire_minutes.max' => __('sirsoft-ecommerce::validation.custom.order_settings.pending_order_expire_minutes.max'),
            'order_settings.cart_expiry_days.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.integer'),
            'order_settings.cart_expiry_days.min' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.min'),
            'order_settings.cart_expiry_days.max' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.max'),
            'order_settings.stock_restore_on_cancel.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.stock_restore_on_cancel.boolean'),

            // shipping 섹션
            'shipping.default_country.string' => __('sirsoft-ecommerce::validation.custom.shipping.default_country.string'),
            'shipping.default_country.max' => __('sirsoft-ecommerce::validation.custom.shipping.default_country.max'),
            'shipping.available_countries.array' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.array'),
            'shipping.available_countries.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.required_with'),
            'shipping.available_countries.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.string'),
            'shipping.available_countries.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.max'),
            'shipping.available_countries.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.required_with'),
            'shipping.available_countries.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.array'),
            'shipping.available_countries.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.string'),
            'shipping.available_countries.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.max'),
            'shipping.available_countries.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.is_active.boolean'),
            'shipping.international_shipping_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.international_shipping_enabled.boolean'),
            'shipping.free_shipping_threshold.integer' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_threshold.integer'),
            'shipping.free_shipping_threshold.min' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_threshold.min'),
            'shipping.free_shipping_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_enabled.boolean'),
            'shipping.address_validation_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.address_validation_enabled.boolean'),
            'shipping.address_api_provider.string' => __('sirsoft-ecommerce::validation.custom.shipping.address_api_provider.string'),
            'shipping.address_api_provider.max' => __('sirsoft-ecommerce::validation.custom.shipping.address_api_provider.max'),

            // shipping.carriers 섹션
            'shipping.carriers.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.required_with'),
            'shipping.carriers.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.string'),
            'shipping.carriers.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.max'),
            'shipping.carriers.*.code.regex' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.regex'),
            'shipping.carriers.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.name.required_with'),
            'shipping.carriers.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.name.array'),
            'shipping.carriers.*.type.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.type.required_with'),
            'shipping.carriers.*.type.in' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.type.in'),
            'shipping.carriers.*.tracking_url.string' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.tracking_url.string'),
            'shipping.carriers.*.tracking_url.max' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.tracking_url.max'),
            'shipping.carriers.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.is_active.boolean'),

            // shipping.types 섹션
            'shipping.types.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.required_with'),
            'shipping.types.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.string'),
            'shipping.types.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.max'),
            'shipping.types.*.code.regex' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.regex'),
            'shipping.types.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.name.required_with'),
            'shipping.types.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.types.name.array'),
            'shipping.types.*.category.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.category.required_with'),
            'shipping.types.*.category.in' => __('sirsoft-ecommerce::validation.custom.shipping.types.category.in'),
            'shipping.types.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.types.is_active.boolean'),
        ];
    }

    /**
     * 검증된 데이터에서 카테고리 설정만 추출
     *
     * 최상위 레벨 오염 데이터(email_id, email_domain 등)를 제외하고
     * 유효한 카테고리만 반환합니다.
     *
     * @return array<string, array>
     */
    public function validatedSettings(): array
    {
        $validated = $this->validated();
        $validCategories = ['basic_info', 'language_currency', 'seo', 'order_settings', 'claim', 'shipping', 'review_settings', 'inquiry', 'notifications', 'mileage', 'privacy_retention'];

        return array_filter(
            $validated,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );
    }
}
