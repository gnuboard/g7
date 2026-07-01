<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const LIVE_MID_PREFIX = 'SIR';

    private const ESCROW_TEST_MID = 'iniescrow0';

    private const CBT_AUTH_URL_TEST = 'https://devcbt.inicis.com/cbtauth';

    private const CBT_AUTH_URL_LIVE = 'https://cbt.inicis.com/cbtauth';

    private const DOMESTIC_EASY_PAY_SETTINGS = [
        'kginicis_samsung_pay' => 'easy_pay_samsung_pay',
        'kginicis_naverpay' => 'easy_pay_naverpay',
        'kginicis_lpay' => 'easy_pay_lpay',
        'kginicis_kakaopay' => 'easy_pay_kakaopay',
    ];

/**

 * getSubscribedHooks

 *

 * @return array

 */

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.registered_pg_providers' => [
                'method' => 'registerProvider',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.payment.get_client_config' => [
                'method' => 'getClientConfig',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * PG 제공자 목록에 KG 이니시스 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array KG 이니시스가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'kginicis',
            'name_key' => 'sirsoft-pay_kginicis::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-pay_kginicis::provider.name'),
            'icon' => 'credit-card',
            'supported_methods' => ['card', 'bank_transfer', 'virtual_account', 'mobile'],
        ];

        return $providers;
    }

/**

 * getClientConfig

 *

 * @param  array  $config

 * @param  string  $provider

 * @return array

 */

    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'kginicis') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        $useEscrow = (bool) ($settings['use_escrow'] ?? false);

        return array_merge($config, [
            'mid' => $isTest
                ? ($useEscrow ? self::ESCROW_TEST_MID : ($settings['test_mid'] ?? ''))
                : $this->buildLiveMid($settings['live_mid'] ?? ''),
            'sdk_url' => $isTest
                ? 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js'
                : 'https://stdpay.inicis.com/stdjs/INIStdPay.js',
            'callback_urls' => [
                'signature'           => '/plugins/sirsoft-pay_kginicis/payment/signature',
                'close_report'        => '/plugins/sirsoft-pay_kginicis/payment/close-report',
                'callback'            => '/plugins/sirsoft-pay_kginicis/payment/callback',
                'close'               => '/plugins/sirsoft-pay_kginicis/payment/close',
                'cbt_checkout_token'  => '/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token',
                'cbt_hash_data'       => '/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                'cbt_callback'        => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                'cbt_cvs_notify'      => '/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify',
                'cbt_auth_url'        => $isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE,
                'mobile_signature'    => '/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                'mobile_callback'     => '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
                'mobile_vbank_notify' => '/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify',
            ],
            'japan_enabled'                      => $settings['japan_enabled'] ?? false,
            'japan_restrict_jpy_payment_methods' => (bool) ($settings['japan_restrict_jpy_payment_methods'] ?? false),
            'japan_configured'                   => $this->isJapanConfigured($settings, $isTest),
            'standard_configured'                => $this->isStandardConfigured($settings, $isTest),
            'mobile_configured'                  => $this->isMobileConfigured($settings, $isTest),
            'use_escrow'                         => $settings['use_escrow'] ?? false,
            'japan_mid'                          => $isTest
                ? KgInicisApiService::JAPAN_TEST_MID
                : ($settings['live_japan_mid'] ?? ''),
            'cbt_extra_data'                     => $this->buildCbtExtraData($settings),
            'use_credit_point'                   => (bool) ($settings['use_credit_point'] ?? false),
            'easy_pay_show_brand_button'         => (bool) ($settings['easy_pay_show_brand_button'] ?? false),
            'easy_pay_enabled_methods'           => $this->enabledDomesticEasyPayMethods($settings),
        ]);
    }

    private function buildCbtExtraData(array $settings): array
    {
        return [
            'paymentUI' => [
                'language' => 'JP',
                'logoUrl' => '',
                'colorTheme' => 'blue2',
            ],
            'payment' => [
                'paymethod' => ['CARD', 'CVS', 'PAYpay'],
                'card' => [
                    'payType' => ['one', 'installments'],
                    'installMonth' => [3, 5, 6, 10, 12],
                ],
                'cvs' => [
                    'notiUrl' => url('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
                    'contactInfo' => $this->setting($settings, 'japan_merchant_name_short', 'サンプル'),
                    'contactTelNum' => $this->setting($settings, 'japan_contact_phone', '0120-123-456'),
                    'contactHours' => $this->setting($settings, 'japan_contact_opening_hours', '10:00-18:00'),
                    'customerKana' => 'テスト',
                    'customerLastKana' => 'テスト',
                    'customerFirstKana' => 'タロウ',
                    'paymentTermDay' => 5,
                ],
            ],
            'gmoPayment' => [
                'merchantName' => $this->setting($settings, 'japan_merchant_name', 'サンプルストア'),
                'merchantNameKana' => $this->setting($settings, 'japan_merchant_name_kana', 'サンプルストア'),
                'merchantNameAlphabet' => $this->setting($settings, 'japan_merchant_name_alphabet', 'Sample Store'),
                'merchantNameShort' => $this->setting($settings, 'japan_merchant_name_short', 'サンプル'),
                'contactName' => $this->setting($settings, 'japan_contact_name', 'サポート窓口'),
                'contactEmail' => $this->setting($settings, 'japan_contact_email', 'support@example.com'),
                'contactPhone' => $this->setting($settings, 'japan_contact_phone', '0120-123-456'),
                'contactOpeningHours' => $this->setting($settings, 'japan_contact_opening_hours', '10:00-18:00'),
            ],
        ];
    }

    private function isJapanConfigured(array $settings, bool $isTest): bool
    {
        if (! (bool) ($settings['japan_enabled'] ?? false)) {
            return false;
        }

        if ($isTest) {
            return trim((string) ($settings['test_japan_sign_key'] ?? '')) !== '';
        }

        return trim((string) ($settings['live_japan_mid'] ?? '')) !== ''
            && trim((string) ($settings['live_japan_sign_key'] ?? '')) !== '';
    }

    /**
     * 현재 모드의 표준결제 MID/signKey 준비 여부를 반환합니다.
     *
     * @param  array<string, mixed>  $settings
     * @param  bool  $isTest
     * @return bool
     */
    private function isStandardConfigured(array $settings, bool $isTest): bool
    {
        if ($isTest) {
            return trim((string) ($settings['test_mid'] ?? '')) !== ''
                && trim((string) ($settings['test_sign_key'] ?? '')) !== '';
        }

        return trim((string) ($settings['live_mid'] ?? '')) !== ''
            && trim((string) ($settings['live_sign_key'] ?? '')) !== '';
    }

    /**
     * 현재 모드의 모바일결제 MID/hashKey 준비 여부를 반환합니다.
     *
     * @param  array<string, mixed>  $settings
     * @param  bool  $isTest
     * @return bool
     */
    private function isMobileConfigured(array $settings, bool $isTest): bool
    {
        if ($isTest) {
            return trim((string) ($settings['test_mid'] ?? '')) !== ''
                && trim((string) ($settings['test_mobile_hash_key'] ?? '')) !== '';
        }

        return trim((string) ($settings['live_mid'] ?? '')) !== ''
            && trim((string) ($settings['live_mobile_hash_key'] ?? '')) !== '';
    }

    /**
     * 국내 간편결제 중 플러그인 설정에서 활성화된 수단 ID 목록을 반환합니다.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function enabledDomesticEasyPayMethods(array $settings): array
    {
        $enabled = [];
        foreach (self::DOMESTIC_EASY_PAY_SETTINGS as $method => $settingKey) {
            if ((bool) ($settings[$settingKey] ?? false)) {
                $enabled[] = $method;
            }
        }

        return $enabled;
    }

    private function setting(array $settings, string $key, string $default): string
    {
        $value = trim((string) ($settings[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function buildLiveMid(string $suffix): string
    {
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, self::LIVE_MID_PREFIX) ? $suffix : self::LIVE_MID_PREFIX . $suffix;
    }

    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
