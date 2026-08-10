<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Tosspayments\Concerns\MapsTossPaymentMethods;

/**
 * PG 제공자 등록 리스너
 *
 * 이커머스 모듈의 PG 제공자 목록 훅과 클라이언트 설정 훅을 구독하여
 * 토스페이먼츠를 결제 제공자로 등록하고 프론트엔드 SDK 설정을 제공합니다.
 */
class RegisterPgProviderListener implements HookListenerInterface
{
    use MapsTossPaymentMethods;

    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array 훅 구독 설정
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
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * PG 제공자 목록에 토스페이먼츠 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array 토스페이먼츠가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'tosspayments',
            'name_key' => 'sirsoft-tosspayments::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-tosspayments::provider.name'),
            'icon' => 'credit-card',
            'supported_methods' => ['card', 'virtual_account', 'bank_transfer', 'mobile'],
            // 프론트 결제 진입 핸들러 — HandlesOrderCreation::resolvePgPaymentHandler() 가 이 키를
            // 응답의 pg_payment_handler 로 내려 템플릿이 dispatch 한다. 미선언 시 PG 분기가
            // 발화하지 못하고 결제창 없이 완료 페이지로 navigate 하던 결함을 정공법으로 해소한다.
            'payment_handler' => 'sirsoft-tosspayments.requestPayment',
        ];

        return $providers;
    }

    /**
     * PG 클라이언트 설정 제공 (프론트엔드 SDK용)
     *
     * @param  array  $config  기존 설정
     * @param  string  $provider  PG 제공자 ID
     * @return array 클라이언트 설정
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'tosspayments') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;
        $orderSheetMode = (bool) ($settings['order_sheet_mode'] ?? false);

        return array_merge($config, [
            'client_key' => $isTest
                ? ($settings['test_client_key'] ?? '')
                : ($settings['live_client_key'] ?? ''),
            'sdk_url' => 'https://js.tosspayments.com/v2/standard',
            'order_sheet_mode' => $orderSheetMode,
            // 활성 토글된 결제수단만 프론트에 내린다. 각 entry 에 SDK method / easyPay provider /
            // 코어 결제수단(core_payment_method) 을 함께 실어 프론트 하드코딩을 없앤다.
            // 결제창형(order_sheet_mode=false)에서는 빈 배열 — 통합결제창 카드 하나로 처리.
            'enabled_methods' => $orderSheetMode ? $this->buildEnabledMethodsConfig($settings) : [],
            'vbank' => [
                'valid_hours' => (int) ($settings['vbank_valid_hours'] ?? 24),
                'cash_receipt_type' => (string) ($settings['vbank_cash_receipt_type'] ?? ''),
            ],
            'use_escrow' => (string) ($settings['use_escrow'] ?? 'off'),
            'callback_urls' => [
                'success' => '/plugins/sirsoft-tosspayments/payment/success',
                'fail' => '/plugins/sirsoft-tosspayments/payment/fail',
            ],
        ]);
    }

    /**
     * 활성 토글된 toss_* 결제수단의 프론트 SDK 설정 목록을 구성합니다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정값
     * @return list<array{id:string, method:string, easy_pay_provider:?string, core_payment_method:string}>
     */
    private function buildEnabledMethodsConfig(array $settings): array
    {
        $enabled = [];
        foreach ($this->enabledTossMethodIds($settings) as $id) {
            $meta = self::TOSS_METHOD_MAP[$id];
            $enabled[] = [
                'id' => $id,
                'method' => $meta['method'],
                'easy_pay_provider' => $meta['easy_pay'],
                'core_payment_method' => $meta['core'],
            ];
        }

        return $enabled;
    }

    /**
     * 플러그인 설정 조회
     *
     * @return array 플러그인 설정값
     */
    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
