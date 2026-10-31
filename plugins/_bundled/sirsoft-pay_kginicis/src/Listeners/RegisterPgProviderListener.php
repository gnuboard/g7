<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\SiteUrl;
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
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array 훅 이름 => 구독 설정
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
     * @return void 반환값 없음
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
            // 결제수단 id 어휘(PaymentMethodEnum)로 선언한다 — 관리자 PG 드롭다운이
            // supported_methods.includes(method.id) 로 대조하므로 다른 어휘를 쓰면
            // 그 결제수단은 PG를 지정할 수 없게 된다(무통장입금 dbank 는 PG 불필요라 제외).
            'supported_methods' => ['card', 'bank', 'vbank', 'phone'],
            // 코어의 provider-agnostic 결제 진입 dispatch — 주문 응답의 pg_payment_handler 로
            // 내려가 템플릿이 그대로 호출한다. 미선언 시 프론트가 응답을 변조해 결제창을
            // 직접 띄우는 우회가 필요해진다(#475).
            'payment_handler' => 'sirsoft-pay_kginicis.requestPayment',
        ];

        return $providers;
    }

    /**
     * KG 이니시스 결제창 진입에 필요한 클라이언트 설정을 반환합니다.
     *
     * @param  array  $config  기존 클라이언트 설정
     * @param  string  $provider  요청된 PG provider id
     * @return array KG 이니시스 설정이 병합된 클라이언트 설정 (다른 PG 면 원본 그대로)
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
            // 보안: PG 로 보내는 결과·통보 주소(returnUrl·closeUrl·P_NEXT_URL·P_NOTI_URL·notiUrl)와 같은 출처 API —
            // 브라우저 현재 주소가 아니라 사이트 주소로 만든 절대 URL 을 내려 준다.
            // 브라우저가 G7Core.api 로 직접 부르는 주소(signature·close_report·cbt_checkout_token·cbt_hash_data·
            // mobile_signature)는 API 라우트(src/routes/api.php)다 — 절대 URL 에는 ApiClient 가 /api 를 붙이지
            // 않으므로 /api 를 포함해 내려 준다(#639).
            'callback_urls' => [
                'signature' => SiteUrl::absolute('/api/plugins/sirsoft-pay_kginicis/payment/signature'),
                'close_report' => SiteUrl::absolute('/api/plugins/sirsoft-pay_kginicis/payment/close-report'),
                'callback' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/callback'),
                'close' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/close'),
                'cbt_checkout_token' => SiteUrl::absolute('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token'),
                'cbt_hash_data' => SiteUrl::absolute('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data'),
                'cbt_callback' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/cbt/callback'),
                'cbt_cvs_notify' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
                'cbt_auth_url' => $isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE,
                'mobile_signature' => SiteUrl::absolute('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature'),
                'mobile_callback' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/mobile/callback'),
                'mobile_vbank_notify' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify'),
            ],
            'japan_enabled' => $settings['japan_enabled'] ?? false,
            'japan_restrict_jpy_payment_methods' => (bool) ($settings['japan_restrict_jpy_payment_methods'] ?? false),
            'japan_configured' => $this->isJapanConfigured($settings, $isTest),
            'standard_configured' => $this->isStandardConfigured($settings, $isTest),
            'mobile_configured' => $this->isMobileConfigured($settings, $isTest),
            'use_escrow' => $settings['use_escrow'] ?? false,
            'japan_mid' => $isTest
                ? KgInicisApiService::JAPAN_TEST_MID
                : ($settings['live_japan_mid'] ?? ''),
            'cbt_extra_data' => $this->buildCbtExtraData($settings),
            'use_credit_point' => (bool) ($settings['use_credit_point'] ?? false),
            'easy_pay_show_brand_button' => (bool) ($settings['easy_pay_show_brand_button'] ?? false),
            'easy_pay_enabled_methods' => $this->enabledDomesticEasyPayMethods($settings),
        ]);
    }

    /**
     * 일본 CBT 결제창에 넘길 추가 데이터(결제 UI·결제수단·편의점·GMO 가맹점 정보)를 만듭니다.
     *
     * 편의점 입금통보 주소(notiUrl)는 요청 Host 가 아니라 사이트 주소로 만든다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @return array<string, mixed> CBT 결제창 추가 데이터
     */
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
                    // 보안: 공개 client-config 응답에 실리는 입금통보 주소 — 요청 Host 가 아니라 사이트 주소(캐시돼도 안전).
                    'notiUrl' => SiteUrl::absolute('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
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

    /**
     * 현재 모드의 일본 CBT 결제 준비 여부를 반환합니다.
     *
     * 일본 결제가 꺼져 있으면 false, 테스트 모드는 테스트 signKey, 운영 모드는 MID·signKey 가 모두 있어야 한다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @param  bool  $isTest  테스트 모드 여부
     * @return bool 일본 CBT 결제를 쓸 수 있으면 true
     */
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
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @param  bool  $isTest  테스트 모드 여부
     * @return bool MID 와 signKey 가 모두 있으면 true
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
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @param  bool  $isTest  테스트 모드 여부
     * @return bool MID 와 모바일 hashKey 가 모두 있으면 true
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

    /**
     * 설정값을 앞뒤 공백을 제거해 읽고, 비어 있으면 기본값을 돌려줍니다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정
     * @param  string  $key  설정 키
     * @param  string  $default  값이 비었을 때 쓸 기본값
     * @return string 설정값 또는 기본값
     */
    private function setting(array $settings, string $key, string $default): string
    {
        $value = trim((string) ($settings[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    /**
     * 운영 MID 접두사를 붙여 완전한 운영 MID 를 만듭니다.
     *
     * 이미 접두사로 시작하면 그대로 둔다.
     *
     * @param  string  $suffix  관리자가 입력한 운영 MID (접두사 포함 여부 무관)
     * @return string 운영 MID (입력이 비면 빈 문자열)
     */
    private function buildLiveMid(string $suffix): string
    {
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, self::LIVE_MID_PREFIX) ? $suffix : self::LIVE_MID_PREFIX.$suffix;
    }

    /**
     * KG 이니시스 플러그인 설정을 읽습니다.
     *
     * @return array<string, mixed> 플러그인 설정
     */
    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
