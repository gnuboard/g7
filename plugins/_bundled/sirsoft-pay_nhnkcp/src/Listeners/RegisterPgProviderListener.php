<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ExtensionStoragePath;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

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
     */
    public function handle(...$args): void {}

    /**
     * PG 제공자 목록에 NHN KCP 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array NHN KCP 가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'nhnkcp',
            'name_key' => 'sirsoft-pay_nhnkcp::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-pay_nhnkcp::provider.name'),
            'icon' => 'credit-card',
            // 결제수단 id 어휘(PaymentMethodEnum)로 선언한다 — 관리자 PG 드롭다운이
            // supported_methods.includes(method.id) 로 대조하므로 다른 어휘를 쓰면
            // 그 결제수단은 PG를 지정할 수 없게 된다(무통장입금 dbank 는 PG 불필요라 제외).
            'supported_methods' => ['card', 'bank', 'vbank', 'phone'],
            // 코어의 provider-agnostic 결제 진입 dispatch — 주문 응답의 pg_payment_handler 로
            // 내려가 템플릿이 그대로 호출한다. 미선언 시 프론트가 응답을 변조해 결제창을
            // 직접 띄우는 우회가 필요해진다(#475).
            'payment_handler' => 'sirsoft-pay_nhnkcp.requestPayment',
        ];

        return $providers;
    }

    /**
     * PG 클라이언트 설정 제공 (프론트엔드 SDK용)
     *
     * site_cd, SDK URL, 콜백 URL 을 반환. 테스트 모드는 testpay.kcp.co.kr
     * 라이브 모드는 pay.kcp.co.kr 사용.
     *
     * @param  array  $config  기존 설정
     * @param  string  $provider  PG 제공자 ID
     * @return array 클라이언트 설정 또는 입력 그대로 (다른 PG 인 경우)
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'nhnkcp') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        $liveSuffix = $settings['live_site_cd'] ?? '';
        $liveSiteCd = str_starts_with($liveSuffix, self::LIVE_SITE_CD_PREFIX)
            ? $liveSuffix
            : self::LIVE_SITE_CD_PREFIX.$liveSuffix;

        $siteCd = $isTest
            ? ($settings['test_site_cd'] ?? 'T0000')
            : $liveSiteCd;

        $sdkUrl = $isTest
            ? 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp'
            : 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

        $easyPayKeys = [
            'PAYCO' => 'easy_pay_payco',
            'NAVERPAY' => 'easy_pay_naverpay',
            'NAVERPAY POINT' => 'easy_pay_naverpay_point',
            'KAKAOPAY' => 'easy_pay_kakaopay',
            'APPLEPAY' => 'easy_pay_applepay',
        ];
        $enabledEasyPays = array_values(array_keys(array_filter($easyPayKeys, fn ($key) => (bool) ($settings[$key] ?? false))));

        // "타 PG와 사용가능함"이 꺼져 있고 현재 기본 PG가 nhnkcp가 아니면 간편결제 숨김
        $allowWithOtherPg = (bool) ($settings['easy_pay_allow_with_other_pg'] ?? false);
        if (! $allowWithOtherPg && ! $this->isNhnKcpDefaultPg()) {
            $enabledEasyPays = [];
        }

        // 간편결제 테스트 site_cd: 레거시 코드와 동일하게 S6729 사용
        // T0000(표준 테스트)은 간편결제 미지원 → [3101] 오류
        $easyPayTestSiteCd = $settings['easy_pay_test_site_cd'] ?? 'S6729';
        $easyPayClientId = $isTest ? $easyPayTestSiteCd : $liveSiteCd;

        $useEscrow = (bool) ($settings['use_escrow'] ?? false);
        $escrowTestSiteCd = trim((string) ($settings['escrow_test_site_cd'] ?? ''));
        $escrowClientId = $isTest && $escrowTestSiteCd !== '' ? $escrowTestSiteCd : $siteCd;

        return array_merge($config, [
            'client_id' => $siteCd,
            'easy_pay_client_id' => $easyPayClientId,
            'sdk_url' => $sdkUrl,
            'callback_urls' => [
                'callback' => '/plugins/sirsoft-pay_nhnkcp/payment/callback',
                'close_report' => '/plugins/sirsoft-pay_nhnkcp/payment/close-report',
            ],
            'enabled_easy_pays' => $enabledEasyPays,
            'vbank_expire_days' => (int) ($settings['vbank_expire_days'] ?? 3),
            'use_escrow' => $useEscrow,
            'escrow_client_id' => $escrowClientId,
        ]);
    }

    private function isNhnKcpDefaultPg(): bool
    {
        $path = ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/order_settings.json';

        if (! file_exists($path)) {
            return false;
        }

        $data = json_decode(file_get_contents($path), true);

        return ($data['default_pg_provider'] ?? '') === 'nhnkcp';
    }

    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
