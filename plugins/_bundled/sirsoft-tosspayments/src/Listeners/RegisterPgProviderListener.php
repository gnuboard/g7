<?php

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
/**
 * PG 제공자 등록 리스너
 *
 * 이커머스 모듈의 PG 제공자 목록 훅과 클라이언트 설정 훅을 구독하여
 * 토스페이먼츠를 결제 제공자로 등록하고 프론트엔드 SDK 설정을 제공합니다.
 */
class RegisterPgProviderListener implements HookListenerInterface
{
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
     * @param mixed ...$args 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * PG 제공자 목록에 토스페이먼츠 등록
     *
     * @param array $providers 기존 PG 제공자 목록
     * @return array 토스페이먼츠가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'tosspayments',
            'name_key' => 'sirsoft-tosspayments::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-tosspayments::provider.name'),
            'icon' => 'credit-card',
            'supported_methods' => ['card'],
        ];

        return $providers;
    }

    /**
     * PG 클라이언트 설정 제공 (프론트엔드 SDK용)
     *
     * @param array $config 기존 설정
     * @param string $provider PG 제공자 ID
     * @return array 클라이언트 설정
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'tosspayments') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        return array_merge($config, [
            'client_key' => $isTest
                ? ($settings['test_client_key'] ?? '')
                : ($settings['live_client_key'] ?? ''),
            'sdk_url' => 'https://js.tosspayments.com/v2/standard',
            'callback_urls' => [
                'success' => '/plugins/sirsoft-tosspayments/payment/success',
                'fail' => '/plugins/sirsoft-tosspayments/payment/fail',
            ],
        ]);
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
