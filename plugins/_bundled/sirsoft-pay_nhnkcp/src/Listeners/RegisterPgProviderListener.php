<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\SiteUrl;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLookup;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    /**
     * 이커머스 결제 조회 계약을 주입받습니다.
     *
     * @param  PaymentLookup  $payments  이커머스 결제 조회 계약 (쇼핑몰 기본 PG)
     */
    public function __construct(
        private readonly PaymentLookup $payments,
    ) {}

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
            // 직접 띄우는 우회가 필요해진다.
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
            // 보안: PG 로 보내는 결과 수신 주소(Ret_URL) — 브라우저 현재 주소가 아니라 사이트 주소로 만든 절대 URL.
            'callback_urls' => [
                'callback' => SiteUrl::absolute('/plugins/sirsoft-pay_nhnkcp/payment/callback'),
                'close_report' => SiteUrl::absolute('/plugins/sirsoft-pay_nhnkcp/payment/close-report'),
            ],
            'enabled_easy_pays' => $enabledEasyPays,
            'vbank_expire_days' => (int) ($settings['vbank_expire_days'] ?? 3),
            'use_escrow' => $useEscrow,
            'escrow_client_id' => $escrowClientId,
        ]);
    }

    /**
     * 쇼핑몰 주문 설정의 기본 PG 가 NHN KCP 인지 판정합니다.
     *
     * 쇼핑몰 주문 설정은 이커머스 결제 계약(`PaymentLookup::defaultPgProvider`)으로 읽는다 — 저장 위치·형식은 이커머스가 정한다.
     *
     * @return bool 주문 설정의 기본 PG 가 nhnkcp 이면 true (설정이 없으면 false)
     */
    private function isNhnKcpDefaultPg(): bool
    {
        return $this->payments->defaultPgProvider() === 'nhnkcp';
    }

    /**
     * NHN KCP 플러그인 설정을 읽습니다.
     *
     * @return array<string, mixed> 플러그인 설정
     */
    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
