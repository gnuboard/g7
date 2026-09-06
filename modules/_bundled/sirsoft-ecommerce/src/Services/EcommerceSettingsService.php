<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Extension\HookManager;
use App\Support\ExtensionStoragePath;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingFeeTaxPolicy;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;

/**
 * 이커머스 모듈 환경설정 서비스
 *
 * ModuleSettingsInterface를 구현하여 모듈별 설정을 관리합니다.
 *
 * 다국어 카탈로그(결제수단/배송 가능 국가/통화) 라벨은 카탈로그 빌드 시점에 활성 언어팩으로
 * 자동 보강 — 단순 패턴: 빌드 메서드 안에서 `localize_catalog_field()` helper 직접 호출.
 */
class EcommerceSettingsService implements ModuleSettingsInterface
{
    use NormalizesSettingsData;

    /**
     * 모듈 식별자
     */
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 관리자가 삭제한 기본 제공 통화 코드 목록의 저장 키 (공개 #91)
     */
    private const REMOVED_CURRENCIES_KEY = 'removed_default_currencies';

    /**
     * 설정 기본값 (캐시)
     */
    private ?array $defaults = null;

    /**
     * 현재 설정값 (캐시)
     */
    private ?array $settings = null;

    /**
     * 모듈 설정 기본값 파일 경로 반환
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string
    {
        $path = $this->getModulePath().'/config/settings/defaults.json';

        return file_exists($path) ? $path : null;
    }

    /**
     * 설정값 조회
     *
     * @param  string  $key  설정 키 (예: 'basic_info.shop_name')
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->getAllSettings();

        return Arr::get($settings, $key, $default);
    }

    /**
     * 설정값 저장
     *
     * 벌크 저장(saveSettings)과 동일한 정규화 파이프라인을 경유한다 (공개 #114).
     * 예전에는 `Arr::set` 결과를 카테고리 파일에 통째로 덮어써서 분리 입력 필드 병합·
     * 기본 통화 동기화·defaults 스키마 정규화·결제수단 메타데이터 스냅샷·삭제 통화 기록·
     * 통화 캐시 무효화를 전부 건너뛰었고, 저장 파일이 서로 어긋난 상태로 남았다
     * (예: default_currency 는 USD 인데 통화 목록의 is_default 는 KRW).
     *
     * 위임 payload 의 기저는 **저장본**(loadCategorySettings)이다. 조회 결과
     * (getAllSettings)를 기저로 삼으면 읽기 시점 보강분(통화 symbol/flag, 결제수단
     * 병합 메타)이 영속화되고, 삭제 통화 기록(공개 #91)이 재계산되며 지워져 관리자가
     * 삭제한 통화가 부활한다.
     *
     * @param  string  $key  설정 키 (예: 'basic_info.shop_name', 카테고리 통째 지정도 허용)
     * @param  mixed  $value  저장할 값
     * @return bool 성공 여부
     */
    public function setSetting(string $key, mixed $value): bool
    {
        $parts = explode('.', $key);
        $category = array_shift($parts);

        if ($parts === []) {
            // 카테고리 통째 저장 — 배열이 아니면 저장할 카테고리 데이터가 없다
            // (기존 경로도 배열 아닌 값은 저장 대상이 되지 못했다)
            if (! is_array($value)) {
                return false;
            }

            $categoryData = $value;
        } else {
            $categoryData = $this->loadCategorySettings($category);
            Arr::set($categoryData, implode('.', $parts), $value);
        }

        // saveSettings 는 제공된 카테고리만 저장하므로 다른 카테고리 파일은 건드리지 않는다
        return $this->saveSettings([$category => $categoryData]);
    }

    /**
     * 전체 설정 조회
     *
     * @return array 모든 카테고리의 설정값
     */
    public function getAllSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        // payment → order_settings 마이그레이션 (1회)
        $this->migratePaymentToOrderSettings();

        $defaults = $this->getDefaults();
        $categories = $defaults['_meta']['categories'] ?? [];
        $defaultValues = $defaults['defaults'] ?? [];

        $settings = [];
        foreach ($categories as $category) {
            $categoryDefaults = $defaultValues[$category] ?? [];
            $savedSettings = $this->loadCategorySettings($category);
            $settings[$category] = array_merge($categoryDefaults, $savedSettings);
        }

        // 저장된 데이터를 defaults 스키마에 맞게 정규화 (하위호환성)
        $settings = $this->normalizeSettingsData($settings, $defaultValues);

        // language_currency.currencies 는 정수키 리스트라 array_merge 가 통째 교체 →
        // defaults 에 있고 저장본에 없는 통화는 code 기준으로 보충(환율은 저장본 우선 보존).
        // 관리자가 의도적으로 삭제한 게 아니라 array_merge 부작용으로 소실되던 영속성 공백 수정(U11-A).
        // 단, 관리자가 실제로 삭제한 통화는 저장 시점에 기록되므로 보충 대상에서 뺀다(공개 #91).
        if (isset($defaultValues['language_currency']['currencies'])) {
            $removedCodes = $settings['language_currency'][self::REMOVED_CURRENCIES_KEY] ?? [];

            $settings['language_currency']['currencies'] = $this->mergeCurrenciesByCode(
                $defaultValues['language_currency']['currencies'],
                $settings['language_currency']['currencies'] ?? [],
                is_array($removedCodes) ? $removedCodes : []
            );
        }

        // 보충된 통화가 defaults 의 is_default 를 그대로 들여오면 기본 통화가 복수가 된다.
        // is_default 의 SSoT 는 default_currency 이므로 병합 직후 전 항목을 재동기화한다.
        if (isset($settings['language_currency'])) {
            $settings['language_currency'] = $this->syncCurrencyDefaults($settings['language_currency']);
        }

        // 결제수단 병합 (기본 + 플러그인 필터 + 사용자 저장 설정)
        if (isset($settings['order_settings'])) {
            $settings['order_settings']['payment_methods'] = $this->getMergedPaymentMethods(
                $settings['order_settings']['payment_methods'] ?? [],
                $settings['order_settings']['default_pg_provider'] ?? null
            );
        }

        // 통화 라벨에 활성 언어팩 키 자동 보강 + symbol/flag 표준 매핑 보강
        // (A1, D-CUR-4: 셀렉터가 읽는 표시 메타 / 관리자가 직접 지정한 기호는 보존)
        if (isset($settings['language_currency']['currencies']) && is_array($settings['language_currency']['currencies'])) {
            foreach ($settings['language_currency']['currencies'] as $idx => $currency) {
                if (! empty($currency['code']) && isset($currency['name']) && is_array($currency['name'])) {
                    $settings['language_currency']['currencies'][$idx]['name'] = localize_catalog_field(
                        $currency['name'],
                        "sirsoft-ecommerce::settings.currencies.{$currency['code']}.name",
                    );
                }
                // 셀렉터(_currency_selector.json)가 참조하는 symbol/flag 보강
                // (flag 는 저장 규칙에 없는 표시 메타 — 폼이 되돌려 보내도 FormRequest 검증에서
                //  탈락해 round-trip 오염이 없다)
                if (! empty($currency['code'])) {
                    $meta = $this->currencyDisplayMeta($currency['code']);
                    // 관리자가 직접 지정한 기호는 보존, 없을 때만 표준 매핑으로 보충
                    if (empty($currency['symbol'] ?? null)) {
                        $settings['language_currency']['currencies'][$idx]['symbol'] = $meta['symbol'];
                    }
                    // flag 는 표시 전용 메타 — 항상 표준 매핑으로 채운다
                    $settings['language_currency']['currencies'][$idx]['flag'] = $meta['flag'];
                }
            }
        }

        // 배송 가능 국가 라벨에 활성 언어팩 키 자동 보강
        if (isset($settings['shipping']['available_countries']) && is_array($settings['shipping']['available_countries'])) {
            foreach ($settings['shipping']['available_countries'] as $idx => $country) {
                if (! empty($country['code']) && isset($country['name']) && is_array($country['name'])) {
                    $settings['shipping']['available_countries'][$idx]['name'] = localize_catalog_field(
                        $country['name'],
                        "sirsoft-ecommerce::settings.countries.{$country['code']}.name",
                    );
                }
            }
        }

        // 부팅이 끝나기 전 결과는 캐시하지 않는다.
        //
        // 이 결과에는 훅 카탈로그와 병합된 값이 섞여 있다 — 확장이 등록한 결제수단과 그 수단·PG 의
        // 생사 판정이다. 그런데 코어는 부팅 중(CoreServiceProvider::boot)에 config 미러를 채우려고
        // 설정을 한 번 읽고, 그 시점은 플러그인이 자기 훅을 등록하기 전이라 카탈로그가 비어 있다.
        // 서비스가 공유 인스턴스이므로(공개 #116) 그 빈 카탈로그 기준 판정을 캐시하면 요청 내내
        // 남아, 살아 있는 PG 를 지정한 결제수단이 주문서에서 통째로 사라진다.
        if (app()->isBooted()) {
            $this->settings = $settings;
        }

        return $settings;
    }

    /**
     * 카테고리별 설정 조회
     *
     * @param  string  $category  카테고리명
     * @return array 카테고리의 설정값
     */
    public function getSettings(string $category): array
    {
        $allSettings = $this->getAllSettings();

        return $allSettings[$category] ?? [];
    }

    /**
     * 공개 결제 설정 조회 (고아 결제수단 제외 + bank_accounts에 은행명 매핑 포함)
     *
     * 고아 결제수단(저장은 되어 있으나 현재 available 카탈로그에 없는 항목)은
     * 공급 확장이 사라졌거나 그 확장이 해당 수단 제공을 중단한 상태다. 저장값의
     * is_active 는 그대로 남아 있으므로 걸러내지 않으면 체크아웃이 선택 가능한
     * 결제수단으로 계속 노출한다(관리자 화면은 _orphaned 를 읽어 이미 차단).
     *
     * 수단은 살아 있는데 그 수단이 지정한 **PG 가 사라진** 경우도 같은 결함이다(A2).
     * 이쪽은 카탈로그에 남아 있어 `_orphaned` 로 걸리지 않지만, 주문 시 PG 라우팅이
     * 매칭에 실패해 결제창 없이 주문완료로 넘어간다 — `_orphaned_pg` 로 함께 차단한다.
     * 관리자 응답은 두 플래그를 그대로 유지한다(운영자가 확인하고 고쳐야 할 대상).
     *
     * @return array 고아 항목이 제거되고 은행명이 포함된 결제 설정
     */
    public function getPublicPaymentSettings(): array
    {
        $orderSettings = $this->getSettings('order_settings');

        if (isset($orderSettings['payment_methods']) && is_array($orderSettings['payment_methods'])) {
            // 비연속 키가 JSON 객체로 직렬화되지 않도록 array_values 로 재정렬
            $orderSettings['payment_methods'] = array_values(array_filter(
                $orderSettings['payment_methods'],
                fn ($method) => ! ($method['_orphaned'] ?? false) && ! ($method['_orphaned_pg'] ?? false)
            ));
        }

        // 죽은 기본 PG 는 공개 응답에서 미설정으로 정규화한다 (프론트가 그 값을 그대로 쓰지 않도록)
        $defaultPg = $orderSettings['default_pg_provider'] ?? null;
        if (is_string($defaultPg) && $defaultPg !== ''
            && ! in_array($defaultPg, $this->registeredPgProviderIds(), true)) {
            $orderSettings['default_pg_provider'] = null;
        }

        // 현금영수증 프로바이더도 같은 정규화를 거친다 (A3).
        // 체크아웃 신청 폼은 이 카테고리 raw 값을 truthy 로 읽으므로, 여기서 정규화하지 않으면
        // 제공 확장이 사라진 뒤에도 신청 폼이 계속 렌더된다.
        if (array_key_exists('cash_receipt_provider', $orderSettings)) {
            $orderSettings['cash_receipt_provider'] = $this->getCashReceiptProvider();
        }

        if (isset($orderSettings['bank_accounts'], $orderSettings['banks'])) {
            $banks = collect($orderSettings['banks']);
            $orderSettings['bank_accounts'] = array_map(function ($account) use ($banks) {
                $bank = $banks->firstWhere('code', $account['bank_code'] ?? '');
                $account['bank_name'] = $bank['name'] ?? $account['bank_code'] ?? '';

                return $account;
            }, $orderSettings['bank_accounts']);
        }

        return $orderSettings;
    }

    /**
     * 설정 저장
     *
     * @param  array  $settings  저장할 설정 배열
     * @return bool 성공 여부
     */
    public function saveSettings(array $settings): bool
    {
        $success = true;
        $defaults = $this->getDefaults();
        $defaultValues = $defaults['defaults'] ?? [];

        foreach ($settings as $category => $categorySettings) {
            if (str_starts_with($category, '_')) {
                continue; // _meta, _tab 등 메타 정보 무시
            }

            // 카테고리 값이 배열이 아닌 경우 무시 (최상위 레벨 오염 데이터 방어)
            if (! is_array($categorySettings)) {
                continue;
            }

            // 분리 입력 필드 병합 처리
            $processedSettings = $this->processSplitFields($category, $categorySettings);

            // defaults 스키마에 맞게 정규화
            $categoryDefaults = $defaultValues[$category] ?? [];
            $processedSettings = $this->normalizeCategoryData($processedSettings, $categoryDefaults);

            // order_settings: 결제수단 _cached_* 메타데이터 스냅샷
            if ($category === 'order_settings' && isset($processedSettings['payment_methods'])) {
                $processedSettings['payment_methods'] = $this->snapshotPaymentMethodMetadata(
                    $processedSettings['payment_methods']
                );
            }

            // language_currency: 관리자가 삭제한 기본 제공 통화를 서버가 도출해 기록 (공개 #91)
            if ($category === 'language_currency') {
                $processedSettings = $this->applyRemovedDefaultCurrencies($processedSettings);
            }

            if (! $this->saveCategorySettings($category, $processedSettings)) {
                $success = false;
            }
        }

        // 캐시 초기화
        $this->settings = null;

        // 상주 프로세스의 config 미러도 함께 갱신한다 (공개이슈 #109)
        g7_refresh_module_settings_config('sirsoft-ecommerce');

        // 통화 목록이 바뀌었을 수 있으므로 요청 단위 통화 캐시도 함께 비운다.
        // (리소스 계층이 이 캐시로 다통화 금액을 만들기 때문에, 비우지 않으면 같은 요청 안에서
        //  저장 전 통화 구성으로 금액이 계산된다)
        CurrencySettingsCache::clear();

        $this->flushResolvedCaches();

        return $success;
    }

    /**
     * 이미 해석된 싱글톤 서비스들의 요청 단위 캐시를 비웁니다. (공개 #116)
     *
     * 싱글톤 리졸버들은 자기 캐시를 따로 들고 있어서, 설정 서비스의 캐시만 비우면 같은 요청
     * 안에서 이미 해석된 리졸버가 저장 전 카탈로그를 계속 답한다. 저장 경로가 이 한 지점으로
     * 모여 있으므로(단건 저장도 saveSettings 에 위임) 여기서 함께 무효화한다.
     *
     * 생성자 상호 주입은 순환이라 lazy 해석하며, `resolved()` 가드로 아직 필요하지 않은
     * 서비스를 저장이 강제로 인스턴스화하지 않게 한다.
     */
    private function flushResolvedCaches(): void
    {
        if (app()->resolved(PaymentMethodResolver::class)) {
            app(PaymentMethodResolver::class)->flushCache();
        }

        if (app()->resolved(CurrencyConversionService::class)) {
            app(CurrencyConversionService::class)->clearCache();
        }
    }

    /**
     * 관리자가 삭제한 기본 제공 통화를 저장본에 기록합니다. (공개 #91)
     *
     * 조회 병합(mergeCurrenciesByCode)은 저장본에 없는 defaults 통화를 무조건 보충하는데,
     * 그 보충은 "array_merge 통째 교체로 인한 의도치 않은 소실"(U11-A)을 되돌리기 위한 것이다.
     * 관리자의 의도적 삭제와 구분할 장치가 없으면 삭제까지 되돌아가므로, 저장 시점에
     * 삭제 의도를 도출해 남긴다.
     *
     * - 클라이언트가 보낸 같은 키는 신뢰하지 않고 항상 서버가 재계산한다.
     * - `currencies` 미제출 저장(기본 통화만 변경 등)은 기존 통화 목록과 기록을 함께 이월한다.
     *   저장이 파일 통째 교체이므로 이월하지 않으면 저장본에서 두 키가 모두 사라지고, 조회
     *   병합이 defaults 를 그대로 들여와 삭제가 전부 부활한다.
     * - 기본 통화 코드는 기록 대상에서 제외한다 (기본 통화는 항상 생존해야 한다).
     *
     * @param  array  $processed  정규화까지 끝난 language_currency 저장값
     * @return array 삭제 기록이 반영된 저장값
     */
    private function applyRemovedDefaultCurrencies(array $processed): array
    {
        // 클라이언트 주입 방어 — 검증에서 떨어지지만 프로그램 직접 호출 경로도 막는다
        unset($processed[self::REMOVED_CURRENCIES_KEY]);

        $saved = $this->loadCategorySettings('language_currency');
        $defaultValues = $this->getDefaults()['defaults']['language_currency'] ?? [];

        // currencies 미제출 → 기존 통화 목록과 삭제 기록을 함께 이월
        if (! isset($processed['currencies']) || ! is_array($processed['currencies'])) {
            $carried = $saved[self::REMOVED_CURRENCIES_KEY] ?? [];
            $processed[self::REMOVED_CURRENCIES_KEY] = array_values(array_filter(
                is_array($carried) ? $carried : [],
                'is_string'
            ));

            if (isset($saved['currencies']) && is_array($saved['currencies'])) {
                $processed['currencies'] = array_values($saved['currencies']);
                // 같은 저장에서 기본 통화가 바뀌었을 수 있으므로 is_default 를 다시 맞춘다
                $processed = $this->syncCurrencyDefaults($processed);
            }

            return $processed;
        }

        // 제출된 통화 코드 집합
        $submitted = [];
        foreach ($processed['currencies'] as $currency) {
            $code = is_array($currency) ? ($currency['code'] ?? null) : null;
            if (is_string($code) && $code !== '') {
                $submitted[$code] = true;
            }
        }

        // 유효 기본 통화: 제출값 → 기존 저장본 → defaults 순
        $baseCode = $processed['default_currency']
            ?? ($saved['default_currency'] ?? ($defaultValues['default_currency'] ?? null));

        $removed = [];
        foreach ($defaultValues['currencies'] ?? [] as $defaultCurrency) {
            $code = $defaultCurrency['code'] ?? null;
            if (! is_string($code) || $code === '' || isset($submitted[$code]) || $code === $baseCode) {
                continue;
            }
            $removed[] = $code;
        }

        // 비연속 키가 JSON 객체로 직렬화되지 않도록 재정렬
        $processed[self::REMOVED_CURRENCIES_KEY] = array_values($removed);

        return $processed;
    }

    /**
     * 은행 목록만 저장합니다.
     *
     * 기존 order_settings의 다른 설정은 유지하고 banks만 교체합니다.
     *
     * 저장은 벌크 저장(saveSettings)에 위임해 정규화 파이프라인을 함께 경유한다 (공개 #114) —
     * 은행명 다국어 정규화와 결제수단 메타데이터 스냅샷이 이 경로에서도 적용된다.
     *
     * @param  array  $banks  은행 목록 배열
     * @return bool 성공 여부
     */
    public function saveBanks(array $banks): bool
    {
        $currentSettings = $this->loadCategorySettings('order_settings');
        $currentSettings['banks'] = $banks;

        return $this->saveSettings(['order_settings' => $currentSettings]);
    }

    /**
     * 프론트엔드용 설정 조회 (민감정보 제외)
     *
     * frontend_schema에 따라 민감하지 않은 설정만 반환합니다.
     *
     * @return array 프론트엔드에 노출 가능한 설정값
     */
    public function getFrontendSettings(): array
    {
        $defaults = $this->getDefaults();
        $frontendSchema = $defaults['frontend_schema'] ?? [];
        $allSettings = $this->getAllSettings();

        $frontendSettings = [];

        foreach ($frontendSchema as $category => $schema) {
            if (! ($schema['expose'] ?? false)) {
                continue;
            }

            $categorySettings = $allSettings[$category] ?? [];
            $fields = $schema['fields'] ?? [];

            if (empty($fields)) {
                // fields가 없으면 전체 카테고리 노출
                $frontendSettings[$category] = $categorySettings;

                continue;
            }

            $exposedFields = [];
            foreach ($fields as $fieldName => $fieldSchema) {
                if ($fieldSchema['expose'] ?? false) {
                    $exposedFields[$fieldName] = $categorySettings[$fieldName] ?? null;
                }
            }

            if (! empty($exposedFields)) {
                $frontendSettings[$category] = $exposedFields;
            }
        }

        // 분리 필드 확장 (프론트엔드에서 사용할 수 있도록)
        $frontendSettings = $this->expandSplitFieldsForFrontend($frontendSettings);

        // order_settings: bank_accounts에 은행명 추가 (프론트엔드 편의)
        if (isset($frontendSettings['order_settings']['bank_accounts']) && isset($allSettings['order_settings']['banks'])) {
            $banks = collect($allSettings['order_settings']['banks']);
            $frontendSettings['order_settings']['bank_accounts'] = array_map(function ($account) use ($banks) {
                $bank = $banks->firstWhere('code', $account['bank_code'] ?? '');
                $account['bank_name'] = $bank['name'] ?? $account['bank_code'] ?? '';

                return $account;
            }, $frontendSettings['order_settings']['bank_accounts']);
        }

        return $frontendSettings;
    }

    /**
     * 기본값 조회
     *
     * @return array defaults.json 내용
     */
    private function getDefaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }

        $path = $this->getSettingsDefaultsPath();
        if ($path === null) {
            return [];
        }

        $content = File::get($path);
        $this->defaults = json_decode($content, true) ?? [];

        return $this->defaults;
    }

    /**
     * 카테고리 설정 파일 경로 반환
     *
     * @param  string  $category  카테고리명
     * @return string 설정 파일 경로
     */
    private function getCategoryFilePath(string $category): string
    {
        return $this->getStoragePath().'/'.$category.'.json';
    }

    /**
     * 카테고리 설정 로드
     *
     * @param  string  $category  카테고리명
     * @return array 설정값
     */
    private function loadCategorySettings(string $category): array
    {
        $path = $this->getCategoryFilePath($category);

        if (! File::exists($path)) {
            return [];
        }

        $content = File::get($path);

        return json_decode($content, true) ?? [];
    }

    /**
     * 카테고리 설정 저장
     *
     * @param  string  $category  카테고리명
     * @param  array  $settings  설정값
     * @return bool 성공 여부
     */
    private function saveCategorySettings(string $category, array $settings): bool
    {
        $storagePath = $this->getStoragePath();

        // 디렉토리 생성
        if (! File::isDirectory($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $path = $this->getCategoryFilePath($category);
        $content = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return File::put($path, $content) !== false;
    }

    /**
     * 모듈 경로 반환
     *
     * 활성 디렉토리(modules/{identifier})가 존재하면 우선 사용하고,
     * 존재하지 않는 경우 (pre-install / 테스트 환경) _bundled 원본을 fallback 으로 사용.
     *
     * @return string 모듈 디렉토리 경로
     */
    private function getModulePath(): string
    {
        $active = base_path('modules/'.self::MODULE_IDENTIFIER);
        if (is_dir($active)) {
            return $active;
        }

        return base_path('modules/_bundled/'.self::MODULE_IDENTIFIER);
    }

    /**
     * 설정 저장 경로 반환
     *
     * 경로는 `modules` 디스크 root(`config/filesystems.php`)를 단일 출처로 삼는다. 그 root 가
     * 테스트 환경에서 운영 데이터와 격리된 경로를 가리키므로, 운영 설정(storage/app/modules/
     * .../settings)을 덮어쓰지 않기 위한 분기를 이 서비스가 따로 들고 있지 않는다 — 분기를
     * 확장마다 복사하면 한 곳만 빠뜨려도 그 확장의 테스트가 조용히 운영 파일을 건드린다.
     *
     * @return string 설정 파일 저장 디렉토리 경로
     */
    private function getStoragePath(): string
    {
        return ExtensionStoragePath::module(self::MODULE_IDENTIFIER, 'settings');
    }

    /**
     * 분리 입력 필드 병합 처리
     *
     * business_number_1, business_number_2, business_number_3 → business_number
     * phone_1, phone_2, phone_3 → phone
     * fax_1, fax_2, fax_3 → fax
     * email_id, email_domain → email
     *
     * @param  string  $category  카테고리명
     * @param  array  $settings  설정값
     * @return array 처리된 설정값
     */
    private function processSplitFields(string $category, array $settings): array
    {
        // language_currency 카테고리: default_currency와 currencies.is_default 동기화
        if ($category === 'language_currency') {
            return $this->syncCurrencyDefaults($settings);
        }

        if ($category !== 'basic_info') {
            return $settings;
        }

        // 사업자등록번호 병합
        if (isset($settings['business_number_1'])) {
            $parts = [
                $settings['business_number_1'] ?? '',
                $settings['business_number_2'] ?? '',
                $settings['business_number_3'] ?? '',
            ];
            $settings['business_number'] = implode('-', array_filter($parts));
            unset($settings['business_number_1'], $settings['business_number_2'], $settings['business_number_3']);
        }

        // 전화번호 병합
        if (isset($settings['phone_1'])) {
            $parts = [
                $settings['phone_1'] ?? '',
                $settings['phone_2'] ?? '',
                $settings['phone_3'] ?? '',
            ];
            $settings['phone'] = implode('-', array_filter($parts));
            unset($settings['phone_1'], $settings['phone_2'], $settings['phone_3']);
        }

        // 팩스번호 병합
        if (isset($settings['fax_1'])) {
            $parts = [
                $settings['fax_1'] ?? '',
                $settings['fax_2'] ?? '',
                $settings['fax_3'] ?? '',
            ];
            $settings['fax'] = implode('-', array_filter($parts));
            unset($settings['fax_1'], $settings['fax_2'], $settings['fax_3']);
        }

        // 이메일 병합
        if (isset($settings['email_id'])) {
            $id = $settings['email_id'] ?? '';
            $domain = $settings['email_domain'] ?? '';
            $settings['email'] = $id && $domain ? "{$id}@{$domain}" : '';
            unset($settings['email_id'], $settings['email_domain'], $settings['email_domain_select']);
        }

        return $settings;
    }

    /**
     * 프론트엔드용 분리 필드 확장
     *
     * business_number → business_number_1, business_number_2, business_number_3
     * phone → phone_1, phone_2, phone_3
     * fax → fax_1, fax_2, fax_3
     * email → email_id, email_domain
     *
     * @param  array  $settings  설정값
     * @return array 확장된 설정값
     */
    private function expandSplitFieldsForFrontend(array $settings): array
    {
        if (! isset($settings['basic_info'])) {
            return $settings;
        }

        $basicInfo = &$settings['basic_info'];

        // 사업자등록번호 분리
        if (isset($basicInfo['business_number']) && $basicInfo['business_number']) {
            $parts = explode('-', $basicInfo['business_number']);
            $basicInfo['business_number_1'] = $parts[0] ?? '';
            $basicInfo['business_number_2'] = $parts[1] ?? '';
            $basicInfo['business_number_3'] = $parts[2] ?? '';
        } else {
            $basicInfo['business_number_1'] = '';
            $basicInfo['business_number_2'] = '';
            $basicInfo['business_number_3'] = '';
        }

        // 전화번호 분리
        if (isset($basicInfo['phone']) && $basicInfo['phone']) {
            $parts = explode('-', $basicInfo['phone']);
            $basicInfo['phone_1'] = $parts[0] ?? '';
            $basicInfo['phone_2'] = $parts[1] ?? '';
            $basicInfo['phone_3'] = $parts[2] ?? '';
        } else {
            $basicInfo['phone_1'] = '';
            $basicInfo['phone_2'] = '';
            $basicInfo['phone_3'] = '';
        }

        // 팩스번호 분리
        if (isset($basicInfo['fax']) && $basicInfo['fax']) {
            $parts = explode('-', $basicInfo['fax']);
            $basicInfo['fax_1'] = $parts[0] ?? '';
            $basicInfo['fax_2'] = $parts[1] ?? '';
            $basicInfo['fax_3'] = $parts[2] ?? '';
        } else {
            $basicInfo['fax_1'] = '';
            $basicInfo['fax_2'] = '';
            $basicInfo['fax_3'] = '';
        }

        // 이메일 분리
        if (isset($basicInfo['email']) && $basicInfo['email']) {
            $parts = explode('@', $basicInfo['email']);
            $basicInfo['email_id'] = $parts[0] ?? '';
            $basicInfo['email_domain'] = $parts[1] ?? '';
        } else {
            $basicInfo['email_id'] = '';
            $basicInfo['email_domain'] = '';
        }

        return $settings;
    }

    // ──────────────────────────────────────────────
    // 결제수단 병합 (Centralized Payment Methods)
    // ──────────────────────────────────────────────

    /**
     * 기본 결제수단 정의 반환
     *
     * @return array 기본 내장 결제수단 배열
     */
    private function getBuiltinPaymentMethods(): array
    {
        $defaults = $this->getDefaults();
        $methods = $defaults['defaults']['order_settings']['payment_methods'] ?? [];

        return array_map(function (array $method) {
            $id = $method['id'];

            return [
                'id' => $id,
                // 활성 언어팩의 다국어 키 자동 보강 (ja 등 부재 locale 자동 채움, 운영자 편집 보존)
                'name' => localize_catalog_field(
                    $method['_cached_name'] ?? ['ko' => $id, 'en' => $id],
                    "sirsoft-ecommerce::settings.payment_methods.{$id}.name",
                ),
                'description' => localize_catalog_field(
                    $method['_cached_description'] ?? ['ko' => '', 'en' => ''],
                    "sirsoft-ecommerce::settings.payment_methods.{$id}.description",
                ),
                'icon' => $method['_cached_icon'] ?? 'circle-question',
                'source' => $method['_cached_source'] ?? 'builtin',
                'defaults' => [
                    'pg_provider' => $method['pg_provider'] ?? null,
                    // 능력(capability) 선언 — builtin 은 enum 에서 파생한다.
                    // 확장 결제수단은 PG 플러그인이 filter_available_payment_methods 훅에서
                    // 직접 선언한다 (enum case 가 없으므로).
                    'needs_pg' => $method['needs_pg'] ?? $this->builtinNeedsPg($id),
                    'pg_locked' => $method['pg_locked'] ?? false,
                    'refund_method' => $method['refund_method'] ?? $this->builtinRefundMethod($id),
                    'is_active' => $method['is_active'] ?? true,
                    'min_order_amount' => $method['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $method['stock_deduction_timing'] ?? 'payment_complete',
                    'mileage_deduction_timing' => $method['mileage_deduction_timing']
                        ?? $this->defaultMileageDeductionTiming($id),
                ],
            ];
        }, $methods);
    }

    /**
     * builtin 결제수단의 PG 필요 여부를 enum 에서 파생합니다.
     *
     * @param  string  $id  결제수단 ID
     * @return bool PG 결제창 필요 여부
     */
    private function builtinNeedsPg(string $id): bool
    {
        return PaymentMethodEnum::tryFrom($id)?->needsPgProvider() ?? true;
    }

    /**
     * builtin 결제수단의 환불 수단을 enum 에서 파생합니다.
     *
     * @param  string  $id  결제수단 ID
     * @return string 환불 수단 값 (pg / bank / points)
     */
    private function builtinRefundMethod(string $id): string
    {
        return match (PaymentMethodEnum::tryFrom($id)) {
            PaymentMethodEnum::CARD,
            PaymentMethodEnum::BANK,
            PaymentMethodEnum::VBANK,
            PaymentMethodEnum::PHONE => RefundMethodEnum::PG->value,
            PaymentMethodEnum::POINT => RefundMethodEnum::POINTS->value,
            default => RefundMethodEnum::BANK->value,
        };
    }

    /**
     * 사용 가능한 결제수단 정의 조회 (기본 + 플러그인 필터)
     *
     * @return array 결제수단 정의 배열
     */
    private function getAvailablePaymentMethods(): array
    {
        $builtins = $this->getBuiltinPaymentMethods();

        return HookManager::applyFilters(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            $builtins
        );
    }

    /**
     * 등록된 PG 제공자 목록을 조회합니다. (플러그인 훅 기반)
     *
     * PG 플러그인이 설치되면 훅을 통해 PG사 정보를 등록합니다.
     *
     * @return array PG 제공자 배열 [{id, name, icon, supported_methods}, ...]
     */
    public function getRegisteredPgProviders(): array
    {
        return HookManager::applyFilters(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            []
        );
    }

    /**
     * 등록된 현금영수증 발급 프로바이더 목록을 조회합니다. (플러그인 훅 기반)
     *
     * PG 프로바이더와 독립적으로 선택 가능하다 — KG이니시스로 결제하면서
     * 현금영수증만 토스페이먼츠로 발급하는 구성을 허용한다.
     *
     * @return array 프로바이더 배열 [{id, name, name_key, icon}, ...]
     */
    public function getRegisteredCashReceiptProviders(): array
    {
        return HookManager::applyFilters(
            'sirsoft-ecommerce.cash_receipt.registered_providers',
            []
        );
    }

    /**
     * 현재 선택된 현금영수증 발급 프로바이더 ID를 반환합니다.
     *
     * 저장값이 남아 있어도 그 프로바이더를 제공하는 확장이 없으면 **미설정으로 해석**한다(A3).
     * 플러그인을 제거해도 설정 문자열은 그대로 남는데, 그 값을 신뢰하면 체크아웃의 신청 폼과
     * 마이페이지 발급 버튼이 계속 렌더되고, 신청하면 구독자 없는 훅을 호출해 발급 실패로만
     * 조용히 기록된다.
     *
     * @return string|null 프로바이더 ID (미설정이거나 제공 확장 부재 시 null)
     */
    public function getCashReceiptProvider(): ?string
    {
        $provider = $this->getSetting('order_settings.cash_receipt_provider');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $registeredIds = array_map(
            fn ($entry) => $entry['id'] ?? null,
            $this->getRegisteredCashReceiptProviders()
        );

        return in_array($provider, $registeredIds, true) ? $provider : null;
    }

    /**
     * 자진발급 사용 여부를 반환합니다.
     *
     * 구매자가 현금영수증을 신청하지 않은 무통장 입금완료 건에 대해 사업자가
     * 국세청 지정번호로 자진발급할지 여부. 기본값은 사용 안 함.
     *
     * @return bool 자진발급 사용 여부
     */
    public function isCashReceiptSelfIssueEnabled(): bool
    {
        return (bool) $this->getSetting('order_settings.cash_receipt_self_issue', false);
    }

    /**
     * 배송비 과세 정책을 반환합니다.
     *
     * @return ShippingFeeTaxPolicy 배송비 과세 정책 (미설정 시 안분)
     */
    public function getShippingFeeTaxPolicy(): ShippingFeeTaxPolicy
    {
        $value = $this->getSetting('order_settings.shipping_fee_tax_policy');

        return ShippingFeeTaxPolicy::fromValueOrDefault(is_string($value) ? $value : null);
    }

    /**
     * 은행코드로 은행명을 조회합니다.
     *
     * 환경설정의 은행 목록(order_settings.banks)에서 현재 로케일의 표시명을 찾습니다.
     * 무통장 입금 계좌와 환불 계좌가 같은 목록을 공유하므로 여기서 단일 조회 지점을 제공합니다.
     *
     * @param  string  $bankCode  은행코드
     * @return string 은행명 (목록에 없는 코드는 코드 그대로)
     */
    public function resolveBankName(string $bankCode): string
    {
        $banks = $this->getSetting('order_settings.banks');
        $bank = collect(is_array($banks) ? $banks : [])->firstWhere('code', $bankCode);

        if (! $bank) {
            return $bankCode;
        }

        return $bank['name'][app()->getLocale()] ?? $bank['name']['ko'] ?? $bankCode;
    }

    /**
     * 특정 결제수단의 설정을 조회합니다.
     *
     * @param  string  $methodId  결제수단 ID
     * @return array|null 결제수단 설정 또는 null
     */
    public function getPaymentMethodConfig(string $methodId): ?array
    {
        $methods = $this->getSetting('order_settings.payment_methods') ?? [];

        return collect($methods)->firstWhere('id', $methodId);
    }

    /**
     * 결제수단별 재고 차감 타이밍을 조회합니다.
     *
     * @param  string  $paymentMethodId  결제수단 ID
     * @return string 재고 차감 타이밍 ('order_placed', 'payment_complete', 'none')
     */
    public function getStockDeductionTiming(string $paymentMethodId): string
    {
        $config = $this->getPaymentMethodConfig($paymentMethodId);

        return $config['stock_deduction_timing'] ?? 'payment_complete';
    }

    /**
     * 결제수단별 마일리지 차감 시점을 조회합니다. (마일리지/MP06)
     *
     * 재고(getStockDeductionTiming)와 동형으로 결제수단별 설정을 사용한다.
     * 무통장(vbank/dbank)은 입금 전 마일리지 재사용을 막기 위해 order_placed,
     * PG 카드는 결제 미완료/실패 시 선차감 손실을 막기 위해 payment_complete 가 기본이다.
     *
     * @param  string  $paymentMethodId  결제수단 ID
     * @return string 차감 시점 ('order_placed' | 'payment_complete')
     */
    public function getMileageDeductionTiming(string $paymentMethodId): string
    {
        $config = $this->getPaymentMethodConfig($paymentMethodId);

        return $config['mileage_deduction_timing']
            ?? $this->defaultMileageDeductionTiming($paymentMethodId);
    }

    /**
     * 결제수단별 마일리지 차감 시점 기본값을 반환합니다. (마일리지/MP06)
     *
     * 무통장 계열(vbank/dbank)은 입금 전 재사용 차단을 위해 order_placed,
     * 그 외(PG 카드 등)는 결제 미완료/실패 시 선차감 손실 방지를 위해 payment_complete.
     *
     * @param  string  $paymentMethodId  결제수단 ID
     * @return string order_placed | payment_complete
     */
    protected function defaultMileageDeductionTiming(string $paymentMethodId): string
    {
        return in_array($paymentMethodId, ['vbank', 'dbank'], true)
            ? 'order_placed'
            : 'payment_complete';
    }

    /**
     * 결제수단 병합 결과 조회
     *
     * 기본/플러그인 정의와 사용자 저장 설정을 병합합니다.
     *
     * @param  array  $savedMethods  사용자 저장 결제수단 배열
     * @param  string|null  $defaultPgProvider  기본 PG 제공자 (죽은 PG 판정용, 미전달 시 판정 생략)
     * @return array 병합된 결제수단 배열
     */
    public function getMergedPaymentMethods(array $savedMethods = [], ?string $defaultPgProvider = null): array
    {
        $available = $this->getAvailablePaymentMethods();

        return $this->mergePaymentMethodSettings($available, $savedMethods, $defaultPgProvider);
    }

    /**
     * 결제수단에 지정된 PG 가 현재 레지스트리에 없는지 판정합니다. (A2)
     *
     * 고아 판정(`_orphaned`)은 결제수단 ID 만 본다. builtin 수단에 특정 PG 를 지정한 뒤 그
     * PG 플러그인을 제거하면 수단 자체는 카탈로그에 남아 있어 그 필터를 통과하고, 체크아웃에
     * 선택 가능한 수단으로 노출된다. 주문하면 PG 라우팅이 매칭에 실패해 결제창 없이
     * 주문완료로 넘어간다.
     *
     * 유효 PG 의 폴백 규칙은 런타임(`OrderProcessingService::determinePgProvider()`)과
     * 동일하게 맞춘다 — 수단의 지정값이 **null 일 때만** 기본 PG 로 내려간다. 지정값이 죽은
     * 문자열이면 기본 PG 가 살아 있어도 폴백하지 않으므로, 그 경우도 차단 대상이다.
     *
     * @param  bool  $needsPg  PG 결제창이 필요한 수단인지
     * @param  string|null  $ownProvider  수단에 지정된 PG
     * @param  string|null  $defaultPgProvider  기본 PG
     * @param  array<int, string>  $registeredIds  현재 등록된 PG provider ID 목록
     * @return bool 죽은 PG 지정 여부
     */
    private function hasOrphanedPgProvider(
        bool $needsPg,
        ?string $ownProvider,
        ?string $defaultPgProvider,
        array $registeredIds
    ): bool {
        if (! $needsPg) {
            return false;
        }

        $effective = $ownProvider ?? $defaultPgProvider;

        // 양쪽 미설정은 기존 계약('none' → non-PG 강하) — 고아가 아니다
        if (! is_string($effective) || $effective === '') {
            return false;
        }

        return ! in_array($effective, $registeredIds, true);
    }

    /**
     * 현재 등록된 PG provider ID 목록을 반환합니다.
     *
     * @return array<int, string> provider ID 목록
     */
    private function registeredPgProviderIds(): array
    {
        return array_values(array_filter(
            array_map(fn ($provider) => $provider['id'] ?? null, $this->getRegisteredPgProviders()),
            fn ($id) => is_string($id) && $id !== ''
        ));
    }

    /**
     * 결제수단 정의와 사용자 설정 병합
     *
     * @param  array  $available  사용 가능한 결제수단 정의 배열
     * @param  array  $saved  사용자 저장 설정 배열
     * @param  string|null  $defaultPgProvider  기본 PG 제공자 (죽은 PG 판정용, null 이면 판정 생략)
     * @return array 병합된 결제수단 배열
     */
    private function mergePaymentMethodSettings(array $available, array $saved, ?string $defaultPgProvider = null): array
    {
        $availableById = collect($available)->keyBy('id');
        $savedById = collect($saved)->keyBy('id');

        // 레지스트리는 루프 밖에서 1회만 조회한다 (수단 수만큼 훅이 돌지 않도록)
        $registeredPgIds = $this->registeredPgProviderIds();

        $merged = [];

        // 1. 사용 가능한 결제수단: 저장된 설정과 병합
        foreach ($available as $definition) {
            $id = $definition['id'];
            $savedItem = $savedById->get($id);

            // 능력(capability) 선언은 정의(enum 파생 또는 플러그인 선언)가 SSoT — saved 로 덮지 않는다.
            // 관리자가 편집하는 값이 아니며, 플러그인이 자기 결제수단의 성격(PG 필요/환불수단/PG 고정)을
            // 바꾸면 그 선언이 즉시 반영되어야 한다.
            $needsPg = (bool) ($definition['defaults']['needs_pg'] ?? $this->builtinNeedsPg($id));
            $pgLocked = (bool) ($definition['defaults']['pg_locked'] ?? false);
            $refundMethod = $definition['defaults']['refund_method'] ?? $this->builtinRefundMethod($id);

            // PG 고정 수단(간편결제 등)은 특정 PG 에 종속되므로 관리자가 다른 PG 로 바꿀 수 없다.
            // saved 에 남은 과거 값(예: null)이 있어도 정의의 PG 를 강제한다.
            $capabilities = [
                'needs_pg' => $needsPg,
                'pg_locked' => $pgLocked,
                'refund_method' => $refundMethod,
            ];

            // iOS 전용 노출 플래그(애플페이 등) — 정의가 SSoT. 관리자가 편집하는 값이 아니며,
            // 체크아웃 레이아웃이 비-iOS 기기에서 해당 수단을 렌더하지 않는 데 쓰인다.
            if (! empty($definition['requires_ios'])) {
                $capabilities['requires_ios'] = true;
            }

            if ($savedItem) {
                $entry = array_merge([
                    'id' => $id,
                    'pg_provider' => $pgLocked
                        ? ($definition['defaults']['pg_provider'] ?? null)
                        : ($savedItem['pg_provider'] ?? null),
                    'sort_order' => $savedItem['sort_order'] ?? count($merged) + 1,
                    'is_active' => $savedItem['is_active'] ?? $definition['defaults']['is_active'] ?? true,
                    'min_order_amount' => $savedItem['min_order_amount'] ?? $definition['defaults']['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $savedItem['stock_deduction_timing'] ?? $definition['defaults']['stock_deduction_timing'] ?? 'payment_complete',
                    'mileage_deduction_timing' => $savedItem['mileage_deduction_timing'] ?? $definition['defaults']['mileage_deduction_timing'] ?? $this->defaultMileageDeductionTiming($id),
                    '_cached_name' => $definition['name'],
                    '_cached_description' => $definition['description'] ?? ['ko' => '', 'en' => ''],
                    '_cached_icon' => $definition['icon'] ?? 'circle-question',
                    '_cached_brand_mark' => $definition['brand_mark'] ?? null,
                    '_cached_source' => $definition['source'] ?? 'builtin',
                ], $capabilities);
            } else {
                // 신규 결제수단 (기본값 적용)
                $entry = array_merge([
                    'id' => $id,
                    'pg_provider' => $definition['defaults']['pg_provider'] ?? null,
                    'sort_order' => count($merged) + 1,
                    'is_active' => $definition['defaults']['is_active'] ?? false,
                    'min_order_amount' => $definition['defaults']['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $definition['defaults']['stock_deduction_timing'] ?? 'payment_complete',
                    'mileage_deduction_timing' => $definition['defaults']['mileage_deduction_timing'] ?? $this->defaultMileageDeductionTiming($id),
                    '_cached_name' => $definition['name'],
                    '_cached_description' => $definition['description'] ?? ['ko' => '', 'en' => ''],
                    '_cached_icon' => $definition['icon'] ?? 'circle-question',
                    '_cached_brand_mark' => $definition['brand_mark'] ?? null,
                    '_cached_source' => $definition['source'] ?? 'builtin',
                ], $capabilities);
            }

            // 플러그인 결제수단(toss_* 등)이 선언한 코어 결제수단 매핑을 보존한다.
            // 프론트가 주문 생성 시 이 값을 payment_method 로 전송해 코어 PaymentMethodEnum 을 만족시킨다
            // (미보존 시 원시 id 전송 → 422). 선언하지 않은 builtin/KG 는 키 자체를 두지 않는다.
            if (isset($definition['defaults']['core_payment_method'])) {
                $entry['core_payment_method'] = $definition['defaults']['core_payment_method'];
            }

            // 지정된 PG 가 현재 레지스트리에 없으면 런타임 전용 플래그를 단다 (A2).
            // 관리자 화면은 이 플래그로 배지를 띄우고, 공개 응답은 이 항목을 제거한다.
            if ($this->hasOrphanedPgProvider($needsPg, $entry['pg_provider'] ?? null, $defaultPgProvider, $registeredPgIds)) {
                $entry['_orphaned_pg'] = true;
            }

            $merged[] = $entry;
        }

        // 2. 고아 항목: 저장은 되어있지만 현재 available에 없는 결제수단
        foreach ($saved as $savedItem) {
            $id = $savedItem['id'] ?? '';
            if ($id && ! $availableById->has($id)) {
                $merged[] = array_merge($savedItem, [
                    '_orphaned' => true,
                ]);
            }
        }

        // sort_order 기준 정렬 (고아 항목은 끝에)
        usort($merged, function ($a, $b) {
            $aOrphaned = $a['_orphaned'] ?? false;
            $bOrphaned = $b['_orphaned'] ?? false;
            if ($aOrphaned !== $bOrphaned) {
                return $aOrphaned ? 1 : -1;
            }

            return ($a['sort_order'] ?? PHP_INT_MAX) - ($b['sort_order'] ?? PHP_INT_MAX);
        });

        return $merged;
    }

    /**
     * 저장 시 결제수단 _cached_* 메타데이터 스냅샷
     *
     * 현재 available 정의에서 다국어 이름/아이콘을 캐싱합니다.
     *
     * @param  array  $savedMethods  저장할 결제수단 배열
     * @return array _cached_* 필드가 갱신된 결제수단 배열
     */
    private function snapshotPaymentMethodMetadata(array $savedMethods): array
    {
        $available = $this->getAvailablePaymentMethods();
        $availableById = collect($available)->keyBy('id');

        foreach ($savedMethods as $index => $method) {
            $id = $method['id'] ?? '';
            if (isset($availableById[$id])) {
                $def = $availableById[$id];
                $savedMethods[$index]['_cached_name'] = $def['name'] ?? $method['_cached_name'] ?? null;
                $savedMethods[$index]['_cached_description'] = $def['description'] ?? $method['_cached_description'] ?? null;
                $savedMethods[$index]['_cached_icon'] = $def['icon'] ?? $method['_cached_icon'] ?? null;
                $savedMethods[$index]['_cached_brand_mark'] = $def['brand_mark'] ?? $method['_cached_brand_mark'] ?? null;
                $savedMethods[$index]['_cached_source'] = $def['source'] ?? $method['_cached_source'] ?? 'builtin';

                // 플러그인 결제수단의 코어 결제수단 매핑도 스냅샷해 재조회 응답(프론트 소비)에 남긴다.
                if (isset($def['defaults']['core_payment_method'])) {
                    $savedMethods[$index]['core_payment_method'] = $def['defaults']['core_payment_method'];
                }
            }
            // 고아 항목은 기존 _cached_* 유지

            // _orphaned / _orphaned_pg 플래그는 저장하지 않음 (런타임 전용)
            unset($savedMethods[$index]['_orphaned'], $savedMethods[$index]['_orphaned_pg']);
        }

        return $savedMethods;
    }

    // ──────────────────────────────────────────────
    // 데이터 마이그레이션
    // ──────────────────────────────────────────────

    /**
     * payment → order_settings 자동 마이그레이션 (1회)
     *
     * payment.json이 존재하고 order_settings.json이 없을 때
     * 기존 데이터를 order_settings 구조로 변환합니다.
     */
    private function migratePaymentToOrderSettings(): void
    {
        $paymentPath = $this->getCategoryFilePath('payment');
        $orderSettingsPath = $this->getCategoryFilePath('order_settings');

        // payment.json이 존재하고 order_settings.json이 없을 때만 마이그레이션
        if (! File::exists($paymentPath) || File::exists($orderSettingsPath)) {
            return;
        }

        $paymentData = json_decode(File::get($paymentPath), true) ?? [];
        $orderSettings = [];

        // banks 그대로 이전
        if (isset($paymentData['banks'])) {
            $orderSettings['banks'] = $paymentData['banks'];
        }

        // bank_accounts 이전 또는 dbank_* 단일 필드에서 변환
        if (isset($paymentData['bank_accounts'])) {
            $orderSettings['bank_accounts'] = $paymentData['bank_accounts'];
        } elseif (isset($paymentData['dbank_bank_code'])) {
            $orderSettings['bank_accounts'] = [
                [
                    'bank_code' => $paymentData['dbank_bank_code'] ?? '',
                    'account_number' => $paymentData['dbank_account_number'] ?? '',
                    'account_holder' => $paymentData['dbank_account_holder'] ?? '',
                    'is_active' => true,
                    'is_default' => true,
                ],
            ];
        }

        // 숫자/불리언 설정 이전
        $migrateFields = [
            'auto_cancel_expired', 'auto_cancel_days',
            'cart_expiry_days', 'stock_restore_on_cancel',
        ];

        foreach ($migrateFields as $field) {
            if (array_key_exists($field, $paymentData)) {
                $orderSettings[$field] = $paymentData[$field];
            }
        }

        // payment_methods는 defaults에서 가져옴 (enable_vbank 등은 무시)
        // payment.json은 백업용으로 보존 (삭제하지 않음)
        $this->saveCategorySettings('order_settings', $orderSettings);
    }

    /**
     * 캐시 초기화
     */
    public function clearCache(): void
    {
        $this->defaults = null;
        $this->settings = null;

        // 상주 프로세스의 config 미러도 함께 갱신한다 (공개이슈 #109)
        g7_refresh_module_settings_config('sirsoft-ecommerce');
    }

    /**
     * 통화 코드의 표시 메타(기호·국기)를 반환합니다. (A1 — D-CUR-4)
     *
     * settings 스키마에는 code/name/exchange_rate/rounding/decimal_places/is_default 만 있고
     * symbol/flag 가 없으므로, 셀렉터(_currency_selector.json)가 참조하는 표시 메타를 표준 매핑으로
     * 보강합니다. 미정의 코드는 symbol=코드, flag='' 폴백.
     *
     * @param  string  $code  통화 코드 (예: 'KRW')
     * @return array{symbol: string, flag: string} 기호·국기 이모지
     */
    private function currencyDisplayMeta(string $code): array
    {
        $map = [
            'KRW' => ['symbol' => '₩', 'flag' => '🇰🇷'],
            'USD' => ['symbol' => '$', 'flag' => '🇺🇸'],
            'JPY' => ['symbol' => '¥', 'flag' => '🇯🇵'],
            // CNY 는 JPY 와 동일한 ¥ 기호 충돌을 피하기 위해 元 사용 (위안화 식별성 확보)
            'CNY' => ['symbol' => '元', 'flag' => '🇨🇳'],
            'EUR' => ['symbol' => '€', 'flag' => '🇪🇺'],
            'GBP' => ['symbol' => '£', 'flag' => '🇬🇧'],
        ];

        return $map[$code] ?? ['symbol' => $code, 'flag' => ''];
    }

    /**
     * 통화 목록을 code 기준으로 병합합니다. (U11-A 영속성 공백 수정)
     *
     * language_currency.currencies 는 정수키 리스트라 PHP array_merge 가 병합이 아니라
     * 통째 교체를 수행합니다. 관리자가 일부 통화를 빼고 저장하면 defaults 의 통화가 영구
     * 소실되던 문제를, 저장본에 없는 defaults 통화를 code 기준으로 보충해 해결합니다.
     *
     * - 저장본에 있는 통화: 저장본(환율 포함) 채택 (관리자 편집 보존)
     * - 저장본에 없는 defaults 통화: defaults 항목 보충 (소실 방지)
     * - 저장본에만 있는(관리자 신규 추가) 통화: 그대로 보존
     * - 관리자가 삭제한 defaults 통화($removedCodes): 보충하지 않음 (공개 #91)
     *
     * 삭제 기록에 있어도 저장본에 그 통화가 다시 들어 있으면 저장본을 우선해 되살린다 —
     * 재추가 저장의 결과가 즉시 반영되어야 하기 때문이다(기록은 다음 저장에서 재계산된다).
     *
     * @param  array  $defaults  defaults.json 의 통화 목록
     * @param  array  $saved  저장본 통화 목록
     * @param  array  $removedCodes  관리자가 삭제한 기본 제공 통화 코드 목록
     * @return array code 기준으로 병합된 통화 목록
     */
    private function mergeCurrenciesByCode(array $defaults, array $saved, array $removedCodes = []): array
    {
        // defaults 를 code 인덱스로 매핑 (필드 보충용)
        $defaultsByCode = [];
        foreach ($defaults as $defaultCurrency) {
            $code = $defaultCurrency['code'] ?? null;
            if ($code !== null) {
                $defaultsByCode[$code] = $defaultCurrency;
            }
        }

        // 저장본을 code 인덱스로 매핑 (저장본 우선)
        $savedByCode = [];
        foreach ($saved as $currency) {
            $code = $currency['code'] ?? null;
            if ($code !== null) {
                $savedByCode[$code] = $currency;
            }
        }

        $merged = [];
        $usedCodes = [];

        // defaults 순회: 저장본에 있으면 저장본 채택, 없으면 defaults 보충
        foreach ($defaults as $defaultCurrency) {
            $code = $defaultCurrency['code'] ?? null;
            if ($code === null) {
                continue;
            }

            // 관리자가 삭제한 통화는 보충하지 않는다 (저장본에 있으면 재추가된 것이므로 채택)
            if (! isset($savedByCode[$code]) && in_array($code, $removedCodes, true)) {
                $usedCodes[$code] = true;

                continue;
            }

            $merged[] = $this->backfillBaseUnit($savedByCode[$code] ?? $defaultCurrency, $defaultsByCode[$code] ?? []);
            $usedCodes[$code] = true;
        }

        // 저장본에만 있는(관리자 신규 추가) 통화 보존
        foreach ($saved as $currency) {
            $code = $currency['code'] ?? null;
            if ($code !== null && ! isset($usedCodes[$code])) {
                $merged[] = $this->backfillBaseUnit($currency, $defaultsByCode[$code] ?? []);
            }
        }

        return $merged;
    }

    /**
     * 통화 항목에 base_unit 이 없으면 defaults 또는 폴백(KRW=1000, JPY=100, 그 외=1)으로 보충합니다.
     *
     * 기존 저장본(base_unit 미저장)이 설정 화면에서 base_unit 을 표시·편집할 수 있도록 보강합니다.
     * 런타임 환산은 CurrencyConversionService 폴백으로도 안전하나, 영속본 일관성을 위해 보충합니다.
     *
     * @param  array  $currency  통화 항목
     * @param  array  $default  같은 code 의 defaults 항목
     * @return array base_unit 이 보충된 통화 항목
     */
    private function backfillBaseUnit(array $currency, array $default): array
    {
        if (isset($currency['base_unit'])) {
            return $currency;
        }

        $fallback = ['KRW' => 1000, 'JPY' => 100];
        $code = $currency['code'] ?? '';
        $currency['base_unit'] = $default['base_unit'] ?? ($fallback[$code] ?? 1);

        return $currency;
    }

    /**
     * 기본 통화 설정 동기화
     *
     * default_currency 값에 따라 currencies 배열의 is_default 플래그를 동기화합니다.
     * 기본 통화의 exchange_rate는 null로 설정됩니다.
     *
     * @param  array  $settings  language_currency 설정값
     * @return array 동기화된 설정값
     */
    private function syncCurrencyDefaults(array $settings): array
    {
        $defaultCurrency = $settings['default_currency'] ?? null;
        $currencies = $settings['currencies'] ?? [];

        if (! $defaultCurrency || empty($currencies)) {
            return $settings;
        }

        // 각 통화의 is_default 플래그를 default_currency 값에 맞게 업데이트
        foreach ($currencies as $index => $currency) {
            $isDefault = ($currency['code'] ?? '') === $defaultCurrency;
            $currencies[$index]['is_default'] = $isDefault;

            // 기본 통화는 환율이 필요 없음 (자기 자신 기준)
            if ($isDefault) {
                $currencies[$index]['exchange_rate'] = null;
            }
        }

        $settings['currencies'] = $currencies;

        return $settings;
    }
}
