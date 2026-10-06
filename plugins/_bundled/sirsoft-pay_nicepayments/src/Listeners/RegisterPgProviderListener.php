<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ExtensionStoragePath;
use App\Support\SiteUrl;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
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
     * PG 제공자 목록에 나이스페이먼츠 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array 나이스페이먼츠가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'nicepayments',
            'name_key' => 'sirsoft-pay_nicepayments::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-pay_nicepayments::provider.name'),
            'icon' => 'credit-card',
            // 결제수단 id 어휘(PaymentMethodEnum)로 선언한다 — 관리자 PG 드롭다운이
            // supported_methods.includes(method.id) 로 대조하므로 다른 어휘를 쓰면
            // 그 결제수단은 PG를 지정할 수 없게 된다(무통장입금 dbank 는 PG 불필요라 제외).
            'supported_methods' => ['card', 'bank', 'vbank', 'phone'],
            // 코어의 provider-agnostic 결제 진입 dispatch — 주문 응답의 pg_payment_handler 로
            // 내려가 템플릿이 그대로 호출한다. 미선언 시 프론트가 응답을 변조해 결제창을
            // 직접 띄우는 우회가 필요해진다(#475).
            'payment_handler' => 'sirsoft-pay_nicepayments.requestPayment',
        ];

        return $providers;
    }

    /**
     * PG 클라이언트 설정 제공 (프론트엔드 SDK용)
     *
     * MID, SDK URL, 콜백 URL, 활성화된 간편결제 목록 등을 반환. "타 PG 와 사용가능함"
     * 설정이 꺼져 있고 기본 PG 가 nicepayments 가 아니면 간편결제는 빈 배열.
     *
     * @param  array  $config  기존 설정
     * @param  string  $provider  PG 제공자 ID
     * @return array 클라이언트 설정 또는 입력 그대로 (다른 PG 인 경우)
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'nicepayments') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        $liveMid = $this->buildLiveMid((string) ($settings['live_mid'] ?? ''));

        $easyPayKeys = ['NAVERPAY' => 'easy_pay_naverpay', 'KAKAOPAY' => 'easy_pay_kakaopay', 'SAMSUNGPAY' => 'easy_pay_samsungpay', 'APPLEPAY' => 'easy_pay_applepay', 'PAYCO' => 'easy_pay_payco', 'SKPAY' => 'easy_pay_skpay', 'SSGPAY' => 'easy_pay_ssgpay', 'LPAY' => 'easy_pay_lpay'];
        $enabledEasyPays = array_values(array_keys(array_filter($easyPayKeys, fn ($key) => (bool) ($settings[$key] ?? false))));

        // "타 PG와 사용가능함"이 꺼져 있고 현재 기본 PG가 nicepayments가 아니면 간편결제 숨김
        $allowWithOtherPg = (bool) ($settings['easy_pay_allow_with_other_pg'] ?? false);
        if (! $allowWithOtherPg && ! $this->isNicepayDefaultPg()) {
            $enabledEasyPays = [];
        }

        return array_merge($config, [
            'mid' => $isTest
                ? ($settings['test_mid'] ?? '')
                : $liveMid,
            'sdk_url' => 'https://web.nicepay.co.kr/v3/webstd/js/nicepay-3.0.js',
            // 보안: PG 로 보내는 결과 수신 주소(ReturnURL) — 브라우저 현재 주소가 아니라 사이트 주소로 만든 절대 URL.
            'callback_url' => SiteUrl::absolute('/plugins/sirsoft-pay_nicepayments/payment/callback'),
            'sign_data_url' => SiteUrl::absolute('/plugins/sirsoft-pay_nicepayments/payment/sign-data'),
            // 닫힘 보고는 API 라우트(src/routes/api.php)다 — 웹 경로(/plugins/...)에는 이 라우트가 없다. 프론트는 이 값을
            // 읽지 않고 자기 API 상대 경로로 부른다(paymentCloseReport.ts) — 이 키는 client-config 응답 형태를 유지하려고 남긴다.
            'close_report_url' => SiteUrl::absolute('/api/plugins/sirsoft-pay_nicepayments/payment/close-report'),
            'useEscrow' => (bool) ($settings['use_escrow'] ?? false),
            'enabled_easy_pays' => $enabledEasyPays,
        ]);
    }

    /**
     * 쇼핑몰 주문 설정의 기본 PG 가 나이스페이먼츠인지 판정합니다.
     *
     * @return bool 주문 설정 파일의 default_pg_provider 가 nicepayments 이면 true (파일이 없으면 false)
     */
    private function isNicepayDefaultPg(): bool
    {
        $path = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';

        if (! file_exists($path)) {
            return false;
        }

        $data = json_decode(file_get_contents($path), true);

        return ($data['default_pg_provider'] ?? '') === 'nicepayments';
    }

    /**
     * 나이스페이먼츠 플러그인 설정을 읽습니다.
     *
     * @return array<string, mixed> 플러그인 설정
     */
    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }

    /**
     * 운영 MID 접두사(`SR`)를 붙여 완전한 운영 MID 를 만듭니다.
     *
     * 이미 접두사로 시작하면 그대로 둔다.
     *
     * @param  string  $suffix  관리자가 입력한 운영 MID (접두사 포함 여부 무관)
     * @return string 운영 MID (입력이 비면 빈 문자열)
     */
    private function buildLiveMid(string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, 'SR') ? $suffix : 'SR'.$suffix;
    }
}
