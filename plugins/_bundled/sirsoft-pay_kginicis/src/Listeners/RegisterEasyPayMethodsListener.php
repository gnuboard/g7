<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * KG 이니시스 간편결제 (국내 간편결제 + 일본 CBT PayPay/편의점) 를 이커머스 결제수단 목록에 등록한다.
 *
 * 코어 sirsoft-ecommerce.settings.filter_available_payment_methods 필터 훅을 구독해
 * builtin 결제수단 배열의 'phone' (휴대폰결제) 항목 뒤, 'point' (포인트결제) 항목 앞에
 * KG 이니시스 전용 결제수단을 삽입한다. 각 entry 의 defaults.pg_provider 는 null — 코어/이커머스의
 * "PG 선택 불필요" 상태로 표시되며, 기본 PG 사 설정과 무관하게 KG 이니시스 결제창이
 * 열린다 (orderResponseInterceptor 가 'kginicis_*' prefix 를 인식하여 KG 흐름으로 강제).
 *
 * 국내 결제수단 ID 는 requestPayment handler 의 EasyPayMethod / DirectShowOpt 매핑과 일치:
 *   - kginicis_samsung_pay  → 'onlyssp' / 'd_samsungpay=Y'
 *   - kginicis_naverpay     → 'onlynaverpay' / 'd_npay=Y'
 *   - kginicis_lpay         → 'onlylpay'
 *   - kginicis_kakaopay     → 'onlykakaopay'
 */
class RegisterEasyPayMethodsListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const DOMESTIC_EASY_PAY_SETTINGS = [
        'kginicis_samsung_pay' => 'easy_pay_samsung_pay',
        'kginicis_naverpay' => 'easy_pay_naverpay',
        'kginicis_lpay' => 'easy_pay_lpay',
        'kginicis_kakaopay' => 'easy_pay_kakaopay',
    ];

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectEasyPayMethods',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 결제수단 목록에 KG 이니시스 전용 결제수단 inject.
     *
     * @param  array  $methods  builtin 결제수단 배열 (코어 EcommerceSettingsService::getBuiltinPaymentMethods)
     * @return array  KG 이니시스 entry 가 phone~point 사이에 삽입된 배열
     */
    public function injectEasyPayMethods(array $methods): array
    {
        $kgInicisMethods = [];

        $kgInicisMethods[] = $this->buildDomesticEntry(
            id: 'kginicis_samsung_pay',
            nameKey: 'sirsoft-pay_kginicis::payment_methods.samsung_pay.name',
            descriptionKey: 'sirsoft-pay_kginicis::payment_methods.samsung_pay.description',
            icon: 'mobile-screen-button',
        );
        $kgInicisMethods[] = $this->buildNaverPayEntry();
        $kgInicisMethods[] = $this->buildDomesticEntry(
            id: 'kginicis_lpay',
            nameKey: 'sirsoft-pay_kginicis::payment_methods.lpay.name',
            descriptionKey: 'sirsoft-pay_kginicis::payment_methods.lpay.description',
            icon: 'mobile-screen-button',
        );
        $kgInicisMethods[] = $this->buildDomesticEntry(
            id: 'kginicis_kakaopay',
            nameKey: 'sirsoft-pay_kginicis::payment_methods.kakaopay.name',
            descriptionKey: 'sirsoft-pay_kginicis::payment_methods.kakaopay.description',
            icon: 'mobile-screen-button',
        );

        if ($this->settingEnabled('japan_enabled')) {
            $kgInicisMethods[] = $this->buildEntry(
                id: 'kginicis_japan_paypay',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.japan_paypay.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.japan_paypay.description',
                icon: 'wallet',
            );
            $kgInicisMethods[] = $this->buildEntry(
                id: 'kginicis_japan_cvs',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.japan_cvs.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.japan_cvs.description',
                icon: 'store',
            );
        }

        // 'phone' 뒤, 'point' 앞에 삽입. 둘 중 하나라도 없으면 끝에 append.
        $insertAfter = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $insertAfter = $index;
                break;
            }
        }

        if ($insertAfter === null) {
            return array_merge($methods, $kgInicisMethods);
        }

        return array_merge(
            array_slice($methods, 0, $insertAfter + 1),
            $kgInicisMethods,
            array_slice($methods, $insertAfter + 1),
        );
    }

    /**
     * 국내 간편결제 entry 는 항상 등록해 이커머스 주문설정에서 켜고 끌 수 있게 한다.
     * 기존 플러그인 설정값은 신규 저장값이 없을 때 사용할 기본 활성 상태로만 반영한다.
     */
    private function buildDomesticEntry(string $id, string $nameKey, string $descriptionKey, string $icon): array
    {
        return $this->buildEntry(
            id: $id,
            nameKey: $nameKey,
            descriptionKey: $descriptionKey,
            icon: $icon,
            isActive: $this->domesticMethodEnabled($id),
        );
    }

    /**
     * 브랜드 버튼 옵션은 표시 메타데이터만 바꾸고 결제수단 ID는 유지한다.
     */
    private function buildNaverPayEntry(): array
    {
        return $this->buildEntry(
            id: 'kginicis_naverpay',
            nameKey: 'sirsoft-pay_kginicis::payment_methods.naverpay.name',
            descriptionKey: $this->usesBrandButton()
                ? 'sirsoft-pay_kginicis::payment_methods.naverpay_brand.description'
                : 'sirsoft-pay_kginicis::payment_methods.naverpay.description',
            icon: 'wallet',
            isActive: $this->domesticMethodEnabled('kginicis_naverpay'),
        );
    }

    /**
     * 간편결제 버튼에 브랜드 중심 설명을 사용할지 반환합니다.
     *
     * @return bool
     */
    private function usesBrandButton(): bool
    {
        return $this->settingEnabled('easy_pay_show_brand_button');
    }

    /**
     * 기존 플러그인 설정의 국내 간편결제 활성값을 읽습니다.
     *
     * @param  string  $id
     * @return bool
     */
    private function domesticMethodEnabled(string $id): bool
    {
        $settingKey = self::DOMESTIC_EASY_PAY_SETTINGS[$id] ?? null;

        return $settingKey !== null && $this->settingEnabled($settingKey);
    }

    /**
     * KG 이니시스 플러그인 boolean 설정을 조회합니다.
     *
     * @param  string  $key  설정 키
     * @return bool
     */
    private function settingEnabled(string $key): bool
    {
        if (! \function_exists('plugin_setting')) {
            return false;
        }

        return (bool) \plugin_setting(self::PLUGIN_IDENTIFIER, $key, false);
    }

    /**
     * 결제수단 entry 1건 빌더 — EcommerceSettingsService 의 getBuiltinPaymentMethods 와 동일 형식.
     */
    private function buildEntry(string $id, string $nameKey, string $descriptionKey, string $icon, bool $isActive = false): array
    {
        return [
            'id' => $id,
            'name' => [
                'ko' => __($nameKey, [], 'ko'),
                'en' => __($nameKey, [], 'en'),
            ],
            'description' => [
                'ko' => __($descriptionKey, [], 'ko'),
                'en' => __($descriptionKey, [], 'en'),
            ],
            'icon' => $icon,
            'source' => 'plugin:sirsoft-pay_kginicis',
            'defaults' => [
                // PG 선택 불필요 — orderResponseInterceptor 가 prefix 'kginicis_' 를 인식해
                // 기본 PG 사 설정과 무관하게 KG 이니시스 결제 흐름으로 강제.
                'pg_provider' => null,
                'is_active' => $isActive,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
            ],
        ];
    }
}
