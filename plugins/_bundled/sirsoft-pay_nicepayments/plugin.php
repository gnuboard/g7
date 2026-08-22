<?php

namespace Plugins\Sirsoft\PayNicepayments;

use App\Extension\AbstractPlugin;

/**
 * 나이스페이먼츠 PG 플러그인
 *
 * 나이스페이먼츠 통합결제창(카드/계좌이체/가상계좌/휴대폰) 연동을 제공합니다.
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
            'keywords' => ['payment', 'nicepayments', 'nicepay', 'pg', 'card', 'ecommerce'],
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
            'test_mid' => [
                'type' => 'string',
                'default' => 'nicepay00m',
                'label' => ['ko' => '테스트 가맹점 ID (MID)', 'en' => 'Test Merchant ID (MID)'],
                'hint' => [
                    'ko' => '나이스페이먼츠 공용 테스트 MID입니다. 테스트 전용이며 변경하지 않아도 됩니다.',
                    'en' => 'NicePayments shared test MID. No change needed for testing.',
                ],
            ],
            'test_merchant_key' => [
                'type' => 'string',
                'default' => 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==',
                'sensitive' => true,
                'label' => ['ko' => '테스트 가맹점 키', 'en' => 'Test Merchant Key'],
                'hint' => [
                    'ko' => '나이스페이먼츠 공용 테스트 키입니다. 테스트 전용이며 변경하지 않아도 됩니다.',
                    'en' => 'NicePayments shared test key. No change needed for testing.',
                ],
            ],
            'live_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 가맹점 ID (MID)', 'en' => 'Live Merchant ID (MID)'],
            ],
            'live_merchant_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 가맹점 키', 'en' => 'Live Merchant Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'redirect_success_url' => [
                'type' => 'string',
                'default' => '{shopBase}/orders/{orderId}/complete',
                'label' => ['ko' => '결제 성공 리다이렉트 URL', 'en' => 'Payment Success Redirect URL'],
                'hint' => [
                    'ko' => '{shopBase}는 상점 주소 설정을 따라 자동으로 채워집니다. 전체 URL(https://...)을 직접 넣어도 됩니다. {orderId}는 주문번호로 자동 치환됩니다.',
                    'en' => '{shopBase} is filled in from the storefront address setting. You may also enter a full URL (https://...). {orderId} will be replaced with the actual order number.',
                ],
            ],
            'redirect_fail_url' => [
                'type' => 'string',
                'default' => '{shopBase}/checkout',
                'label' => ['ko' => '결제 실패 리다이렉트 URL', 'en' => 'Payment Failure Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로 또는 전체 URL 모두 가능합니다. 오류 정보는 쿼리 파라미터로 자동 추가됩니다.',
                    'en' => 'Supports relative paths or full URLs. Error details are appended as query parameters.',
                ],
            ],
            'use_escrow' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '에스크로 결제 사용', 'en' => 'Enable Escrow Payment'],
                'hint' => [
                    'ko' => '실물 상품 판매 시 구매자 보호를 위한 에스크로 결제를 활성화합니다. 에스크로 결제 후 배송 등록이 필요합니다.',
                    'en' => 'Enables escrow payment for buyer protection when selling physical goods. Delivery registration is required after escrow payment.',
                ],
            ],
            'easy_pay_allow_with_other_pg' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '타 PG와 사용가능함', 'en' => 'Allow with other PG'],
                'hint' => [
                    'ko' => '활성화하면, 다른 PG사가 기본 결제수단으로 설정된 경우에도 체크아웃 페이지에 나이스페이 간편결제 버튼이 표시됩니다.',
                    'en' => 'When enabled, NicePay easy pay buttons will appear on the checkout page even when another PG is set as the default payment provider.',
                ],
            ],
            'easy_pay_naverpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '네이버페이 간편결제', 'en' => 'Naver Pay'],
                'hint' => [
                    'ko' => '테스트 모드에서도 사용 가능합니다.',
                    'en' => 'Available in test mode.',
                ],
            ],
            'easy_pay_kakaopay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '카카오페이 간편결제', 'en' => 'Kakao Pay'],
            ],
            'easy_pay_samsungpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '삼성페이 간편결제', 'en' => 'Samsung Pay'],
            ],
            'easy_pay_applepay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '애플페이 간편결제', 'en' => 'Apple Pay'],
                'hint' => [
                    'ko' => 'Safari 브라우저 및 Apple 기기에서만 사용 가능합니다.',
                    'en' => 'Available on Safari browser and Apple devices only.',
                ],
            ],
            'easy_pay_payco' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'PAYCO 간편결제', 'en' => 'PAYCO'],
            ],
            'easy_pay_skpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '11pay (SK페이) 간편결제', 'en' => '11pay (SK Pay)'],
            ],
            'easy_pay_ssgpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'SSG페이 간편결제', 'en' => 'SSG Pay'],
            ],
            'easy_pay_lpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'L.pay 간편결제', 'en' => 'L.pay'],
            ],
        ];
    }

    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_mid' => 'nicepay00m',
            'test_merchant_key' => 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==',
            'live_mid' => '',
            'live_merchant_key' => '',
            'redirect_success_url' => '{shopBase}/orders/{orderId}/complete',
            'redirect_fail_url' => '{shopBase}/checkout',
            'use_escrow' => false,
            'easy_pay_allow_with_other_pg' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_kakaopay' => false,
            'easy_pay_samsungpay' => false,
            'easy_pay_applepay' => false,
            'easy_pay_payco' => false,
            'easy_pay_skpay' => false,
            'easy_pay_ssgpay' => false,
            'easy_pay_lpay' => false,
        ];
    }

    /**
     * 이 플러그인이 등록할 HTTP 미들웨어 선언을 반환합니다.
     *
     * IP 화이트리스트(VbankNotifyIpWhitelist)는 나이스페이먼츠 서버 발신 가상계좌 통보
     * webhook 라우트에만 부착합니다. 브라우저 POST 콜백(payment.callback)은 사용자 IP 라
     * 대상에서 제외 — 결제 회귀 방지. 코어 ExtensionMiddlewareGate 가 요청 시점에 실행합니다.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => Http\Middleware\VbankNotifyIpWhitelist::class,
                'groups' => ['web'],
                'targets' => [
                    'web.plugins.sirsoft-pay_nicepayments.payment.vbank-notify',
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
                'name' => 'sirsoft-pay_nicepayments.payment.before_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => '나이스페이먼츠 서버 승인 API 호출 전',
                    'en' => 'Before NicePayments server authorization API call',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nicepayments.payment.after_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => '나이스페이먼츠 서버 승인 완료 후',
                    'en' => 'After NicePayments server authorization completed',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nicepayments.payment.before_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => '나이스페이먼츠 결제 취소 API 호출 전 (본인인증 등 확장 지점)',
                    'en' => 'Before NicePayments cancel API call (extension point for re-auth, etc.)',
                ],
            ],
            [
                'name' => 'sirsoft-pay_nicepayments.payment.after_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => '나이스페이먼츠 결제 취소 완료 후',
                    'en' => 'After NicePayments cancel completed',
                ],
            ],
        ];
    }
}
