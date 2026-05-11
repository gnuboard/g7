<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\ModuleSettingsInterface;
use App\Extension\HookManager;
use App\Traits\NormalizesSettingsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

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
     * @param  string  $key  설정 키
     * @param  mixed  $value  저장할 값
     * @return bool 성공 여부
     */
    public function setSetting(string $key, mixed $value): bool
    {
        $settings = $this->getAllSettings();
        Arr::set($settings, $key, $value);

        // 카테고리 추출
        $parts = explode('.', $key);
        $category = $parts[0];

        $result = $this->saveCategorySettings($category, $settings[$category] ?? []);

        // 파일 저장 후 캐시 초기화 (다음 조회 시 재계산)
        $this->settings = null;

        return $result;
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

        // 결제수단 병합 (기본 + 플러그인 필터 + 사용자 저장 설정)
        if (isset($settings['order_settings'])) {
            $settings['order_settings']['payment_methods'] = $this->getMergedPaymentMethods(
                $settings['order_settings']['payment_methods'] ?? []
            );
        }

        // 통화 라벨에 활성 언어팩 키 자동 보강
        if (isset($settings['language_currency']['currencies']) && is_array($settings['language_currency']['currencies'])) {
            foreach ($settings['language_currency']['currencies'] as $idx => $currency) {
                if (! empty($currency['code']) && isset($currency['name']) && is_array($currency['name'])) {
                    $settings['language_currency']['currencies'][$idx]['name'] = localize_catalog_field(
                        $currency['name'],
                        "sirsoft-ecommerce::settings.currencies.{$currency['code']}.name",
                    );
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

        $this->settings = $settings;

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
     * 공개 결제 설정 조회 (bank_accounts에 은행명 매핑 포함)
     *
     * @return array 은행명이 포함된 결제 설정
     */
    public function getPublicPaymentSettings(): array
    {
        $orderSettings = $this->getSettings('order_settings');

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

            if (! $this->saveCategorySettings($category, $processedSettings)) {
                $success = false;
            }
        }

        // 캐시 초기화
        $this->settings = null;

        return $success;
    }

    /**
     * 은행 목록만 저장합니다.
     *
     * 기존 order_settings의 다른 설정은 유지하고 banks만 교체합니다.
     *
     * @param  array  $banks  은행 목록 배열
     * @return bool 성공 여부
     */
    public function saveBanks(array $banks): bool
    {
        $currentSettings = $this->loadCategorySettings('order_settings');
        $currentSettings['banks'] = $banks;

        $result = $this->saveCategorySettings('order_settings', $currentSettings);

        // 캐시 초기화
        $this->settings = null;

        return $result;
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
     * @return string 설정 파일 저장 디렉토리 경로
     */
    private function getStoragePath(): string
    {
        return storage_path('app/modules/'.self::MODULE_IDENTIFIER.'/settings');
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
                    'is_active' => $method['is_active'] ?? true,
                    'min_order_amount' => $method['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $method['stock_deduction_timing'] ?? 'payment_complete',
                ],
            ];
        }, $methods);
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
     * @param string $paymentMethodId 결제수단 ID
     * @return string 재고 차감 타이밍 ('order_placed', 'payment_complete', 'none')
     */
    public function getStockDeductionTiming(string $paymentMethodId): string
    {
        $config = $this->getPaymentMethodConfig($paymentMethodId);

        return $config['stock_deduction_timing'] ?? 'payment_complete';
    }

    /**
     * 결제수단 병합 결과 조회
     *
     * 기본/플러그인 정의와 사용자 저장 설정을 병합합니다.
     *
     * @param  array  $savedMethods  사용자 저장 결제수단 배열
     * @return array 병합된 결제수단 배열
     */
    public function getMergedPaymentMethods(array $savedMethods = []): array
    {
        $available = $this->getAvailablePaymentMethods();

        return $this->mergePaymentMethodSettings($available, $savedMethods);
    }

    /**
     * 결제수단 정의와 사용자 설정 병합
     *
     * @param  array  $available  사용 가능한 결제수단 정의 배열
     * @param  array  $saved  사용자 저장 설정 배열
     * @return array 병합된 결제수단 배열
     */
    private function mergePaymentMethodSettings(array $available, array $saved): array
    {
        $availableById = collect($available)->keyBy('id');
        $savedById = collect($saved)->keyBy('id');

        $merged = [];

        // 1. 사용 가능한 결제수단: 저장된 설정과 병합
        foreach ($available as $definition) {
            $id = $definition['id'];
            $savedItem = $savedById->get($id);

            if ($savedItem) {
                $merged[] = [
                    'id' => $id,
                    'pg_provider' => $savedItem['pg_provider'] ?? null,
                    'sort_order' => $savedItem['sort_order'] ?? count($merged) + 1,
                    'is_active' => $savedItem['is_active'] ?? $definition['defaults']['is_active'] ?? true,
                    'min_order_amount' => $savedItem['min_order_amount'] ?? $definition['defaults']['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $savedItem['stock_deduction_timing'] ?? $definition['defaults']['stock_deduction_timing'] ?? 'payment_complete',
                    '_cached_name' => $definition['name'],
                    '_cached_description' => $definition['description'] ?? ['ko' => '', 'en' => ''],
                    '_cached_icon' => $definition['icon'] ?? 'circle-question',
                    '_cached_source' => $definition['source'] ?? 'builtin',
                ];
            } else {
                // 신규 결제수단 (기본값 적용)
                $merged[] = [
                    'id' => $id,
                    'pg_provider' => $definition['defaults']['pg_provider'] ?? null,
                    'sort_order' => count($merged) + 1,
                    'is_active' => $definition['defaults']['is_active'] ?? false,
                    'min_order_amount' => $definition['defaults']['min_order_amount'] ?? 0,
                    'stock_deduction_timing' => $definition['defaults']['stock_deduction_timing'] ?? 'payment_complete',
                    '_cached_name' => $definition['name'],
                    '_cached_description' => $definition['description'] ?? ['ko' => '', 'en' => ''],
                    '_cached_icon' => $definition['icon'] ?? 'circle-question',
                    '_cached_source' => $definition['source'] ?? 'builtin',
                ];
            }
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
                $savedMethods[$index]['_cached_source'] = $def['source'] ?? $method['_cached_source'] ?? 'builtin';
            }
            // 고아 항목은 기존 _cached_* 유지

            // _orphaned 플래그는 저장하지 않음 (런타임 전용)
            unset($savedMethods[$index]['_orphaned']);
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
     *
     * @return void
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
            'vbank_due_days', 'dbank_due_days',
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
