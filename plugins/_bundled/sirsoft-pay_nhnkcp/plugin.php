<?php

namespace Plugins\Sirsoft\PayNhnkcp;

use App\Extension\AbstractPlugin;

/**
 * NHN KCP PG 플러그인
 *
 * NHN KCP Standard Pay(카드/계좌이체/가상계좌/휴대폰) 연동을 제공합니다.
 * sirsoft-ecommerce 모듈 전용 플러그인입니다.
 */
class Plugin extends AbstractPlugin
{
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['payment', 'nhnkcp', 'kcp', 'pg', 'card', 'ecommerce'],
        ];
    }

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
            'test_site_cd' => [
                'type' => 'string',
                'default' => 'T0000',
                'label' => ['ko' => '테스트 사이트 코드 (site_cd)', 'en' => 'Test Site Code (site_cd)'],
                'hint' => [
                    'ko' => 'KCP에서 발급받은 테스트 사이트 코드 (기본값: T0000)',
                    'en' => 'Test site code issued by KCP (default: T0000)',
                ],
            ],
            'test_site_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '테스트 사이트 키 (site_key)', 'en' => 'Test Site Key (site_key)'],
                'hint' => [
                    'ko' => 'KCP에서 발급받은 테스트 사이트 키',
                    'en' => 'Test site key issued by KCP',
                ],
            ],
            'live_site_cd' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 사이트 코드 (site_cd)', 'en' => 'Live Site Code (site_cd)'],
                'hint' => [
                    'ko' => 'KCP에서 발급받은 라이브 사이트 코드 (SR prefix 제외하고 입력)',
                    'en' => 'Live site code issued by KCP (enter without SR prefix)',
                ],
            ],
            'live_site_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 사이트 키 (site_key)', 'en' => 'Live Site Key (site_key)'],
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
            'use_escrow' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '에스크로 결제 활성화', 'en' => 'Enable Escrow Payment'],
                'hint' => [
                    'ko' => 'KCP 에스크로 결제 사용 시 활성화. 별도 site_cd 가 필요합니다.',
                    'en' => 'Enable KCP escrow payment. A separate site_cd is required.',
                ],
            ],
            'escrow_test_site_cd' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '테스트 에스크로 사이트 코드', 'en' => 'Test Escrow Site Code'],
                'hint' => [
                    'ko' => 'KCP 에스크로 테스트 site_cd. 미입력 시 일반 test_site_cd 를 그대로 사용.',
                    'en' => 'KCP escrow test site_cd. Falls back to regular test_site_cd if empty.',
                ],
            ],
            'vbank_expire_days' => [
                'type' => 'integer',
                'default' => 3,
                'min' => 1,
                'max' => 30,
                'label' => ['ko' => '가상계좌 입금 만료(일)', 'en' => 'Virtual Account Expiry (days)'],
                'hint' => [
                    'ko' => '가상계좌 발급 후 입금 가능 기간. 모바일 vcnt_expire_term 값으로 전달.',
                    'en' => 'Days the virtual account remains payable after issuance.',
                ],
            ],
            'easy_pay_allow_with_other_pg' => ['type' => 'boolean', 'default' => false],
            'easy_pay_payco' => ['type' => 'boolean', 'default' => false],
            'easy_pay_naverpay' => ['type' => 'boolean', 'default' => false],
            'easy_pay_naverpay_point' => ['type' => 'boolean', 'default' => false],
            'easy_pay_kakaopay' => ['type' => 'boolean', 'default' => false],
            'easy_pay_applepay' => ['type' => 'boolean', 'default' => false],
        ];
    }

    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_site_cd' => 'T0000',
            'test_site_key' => '',
            'live_site_cd' => '',
            'live_site_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
            'use_escrow' => false,
            'escrow_test_site_cd' => '',
            'vbank_expire_days' => 3,
            'easy_pay_allow_with_other_pg' => false,
            'easy_pay_payco' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_naverpay_point' => false,
            'easy_pay_kakaopay' => false,
            'easy_pay_applepay' => false,
        ];
    }

    /**
     * 이 플러그인이 등록할 HTTP 미들웨어 선언을 반환합니다.
     *
     * IP 화이트리스트(RestrictKcpIp)는 KCP 서버 발신 webhook 라우트에만 부착합니다.
     * 브라우저 POST 콜백(payment.callback)은 사용자 IP 라 대상에서 제외 — 결제 회귀 방지.
     * 코어 ExtensionMiddlewareGate 가 요청 시점에 실행합니다.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => Http\Middleware\RestrictKcpIp::class,
                'groups' => ['web'],
                'targets' => [
                    'web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify',
                    'web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify',
                ],
            ],
        ];
    }

    public function getHookListeners(): array
    {
        return [
            Listeners\RegisterPgProviderListener::class,
            Listeners\PaymentRefundListener::class,
            Listeners\CancelActivityLogListener::class,
            Listeners\RegisterEasyPayMethodsListener::class,
            Listeners\AdjustEcommercePaymentMethodsLayoutListener::class,
            Listeners\EnsureAdminOrderListTestBadgeLayoutListener::class,
            Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener::class,
            Listeners\RestoreLayoutExtensionsAfterUpdateListener::class,
        ];
    }

    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-pay_nhnkcp.payment.before_confirm',
                'type' => 'action',
                'description' => [
                    'ko' => 'KCP 결제 승인 확인 전',
                    'en' => 'Before KCP payment confirmation',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nhnkcp.payment.after_confirm',
                'type' => 'action',
                'description' => [
                    'ko' => 'KCP 결제 승인 확인 완료 후',
                    'en' => 'After KCP payment confirmation completed',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nhnkcp.payment.before_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KCP 결제 취소 API 호출 전 (본인인증 등 확장 지점)',
                    'en' => 'Before KCP cancel API call (extension point for re-auth, etc.)',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nhnkcp.payment.after_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KCP 결제 취소 완료 후',
                    'en' => 'After KCP cancel completed',
                ],
            ],
        ];
    }
}
