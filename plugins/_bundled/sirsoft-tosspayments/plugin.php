<?php

namespace Plugins\Sirsoft\Tosspayments;

use App\Extension\AbstractPlugin;

/**
 * 토스페이먼츠 PG 플러그인
 *
 * 토스페이먼츠 통합결제창(카드/간편결제) 연동을 제공합니다.
 * sirsoft-ecommerce 모듈 전용 플러그인입니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['payment', 'tosspayments', 'pg', 'card', 'ecommerce'],
        ];
    }

    /**
     * 환경설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'is_test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '테스트 모드', 'en' => 'Test Mode'],
                'hint' => [
                    'ko' => '테스트 모드에서는 실제 결제가 발생하지 않습니다.',
                    'en' => 'No real payments occur in test mode.',
                ],
            ],
            'test_client_key' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '테스트 클라이언트 키', 'en' => 'Test Client Key'],
                'hint' => [
                    'ko' => '개발자센터 > API 개별 연동 키에서 확인',
                    'en' => 'Found in Developer Center > API Keys',
                ],
            ],
            'test_secret_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '테스트 시크릿 키', 'en' => 'Test Secret Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'live_client_key' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 클라이언트 키', 'en' => 'Live Client Key'],
            ],
            'live_secret_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 시크릿 키', 'en' => 'Live Secret Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'redirect_success_url' => [
                'type' => 'string',
                'default' => '/shop/orders/{orderId}/complete',
                'label' => ['ko' => '결제 성공 리다이렉트 URL', 'en' => 'Payment Success Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로(/shop/...) 또는 전체 URL(https://...) 모두 가능합니다. {orderId}는 주문번호로 자동 치환됩니다.',
                    'en' => 'Supports relative paths (/shop/...) or full URLs (https://...). {orderId} will be replaced with the actual order number.',
                ],
            ],
            'redirect_fail_url' => [
                'type' => 'string',
                'default' => '/shop/checkout',
                'label' => ['ko' => '결제 실패 리다이렉트 URL', 'en' => 'Payment Failure Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로 또는 전체 URL 모두 가능합니다. 오류 정보는 쿼리 파라미터로 자동 추가됩니다.',
                    'en' => 'Supports relative paths or full URLs. Error details are appended as query parameters.',
                ],
            ],
        ];
    }

    /**
     * 기본 설정값 반환
     *
     * @return array 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_client_key' => '',
            'test_secret_key' => '',
            'live_client_key' => '',
            'live_secret_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];
    }

    /**
     * 훅 리스너 클래스 반환
     *
     * @return array 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            Listeners\RegisterPgProviderListener::class,
            Listeners\PaymentRefundListener::class,
        ];
    }

    /**
     * 훅 정의 반환
     *
     * @return array 훅 정의
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-tosspayments.payment.before_confirm',
                'type' => 'action',
                'description' => [
                    'ko' => '토스페이먼츠 결제 승인 API 호출 전',
                    'en' => 'Before TossPayments confirm API call',
                ],
            ],
            [
                'name' => 'sirsoft-tosspayments.payment.after_confirm',
                'type' => 'action',
                'description' => [
                    'ko' => '토스페이먼츠 결제 승인 완료 후',
                    'en' => 'After TossPayments confirm API completed',
                ],
            ],
        ];
    }
}
