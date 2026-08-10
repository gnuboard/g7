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
                    'ko' => '{shopBase}는 상점 주소 설정을 따라 자동으로 채워집니다. 전체 URL 을 직접 넣어도 됩니다. 오류 정보는 쿼리 파라미터로 자동 추가됩니다.',
                    'en' => '{shopBase} is filled in from the storefront address setting. You may also enter a full URL. Error details are appended as query parameters.',
                ],
            ],

            // 결제 방식 — 주문서형(결제수단을 우리 체크아웃에서 선택) vs 결제창형(토스 통합결제창)
            'order_sheet_mode' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '주문서형 결제', 'en' => 'Order-sheet Mode'],
                'hint' => [
                    'ko' => '켜면 체크아웃에서 결제수단을 직접 선택합니다. 끄면 토스 통합결제창(카드) 하나로 처리됩니다.',
                    'en' => 'When ON, payment methods are chosen at checkout. When OFF, a single TossPayments integrated window (card) is used.',
                ],
            ],

            // 주문서형에서 노출할 결제수단 토글 (order_sheet_mode 가 true 일 때만 유효)
            'method_card' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '카드', 'en' => 'Card'],
            ],
            'method_virtual_account' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '가상계좌', 'en' => 'Virtual Account'],
            ],
            'method_transfer' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '계좌이체', 'en' => 'Bank Transfer'],
            ],
            'method_mobile_phone' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '휴대폰', 'en' => 'Mobile Phone'],
            ],
            'method_tosspay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '토스페이', 'en' => 'TossPay'],
            ],
            'method_kakaopay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '카카오페이', 'en' => 'KakaoPay'],
            ],
            'method_naverpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '네이버페이', 'en' => 'NaverPay'],
            ],
            'method_payco' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '페이코', 'en' => 'PAYCO'],
            ],
            'method_samsungpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '삼성페이', 'en' => 'Samsung Pay'],
            ],

            // 가상계좌 옵션
            'vbank_valid_hours' => [
                'type' => 'integer',
                'default' => 24,
                'label' => ['ko' => '가상계좌 입금기한(시간)', 'en' => 'Virtual Account Valid Hours'],
                'hint' => [
                    'ko' => '가상계좌 발급 후 입금 가능한 시간입니다. 최대 2160시간(90일).',
                    'en' => 'Hours available for deposit after issuing a virtual account. Max 2160 (90 days).',
                ],
            ],
            'vbank_cash_receipt_type' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '가상계좌 현금영수증 유형', 'en' => 'Virtual Account Cash Receipt Type'],
                'hint' => [
                    'ko' => '가상계좌 발급 시 토스가 자동 발급할 현금영수증 유형입니다. 비워두면 발급하지 않습니다.',
                    'en' => 'Cash receipt type TossPayments auto-issues when a virtual account is created. Leave blank to skip.',
                ],
            ],

            // 에스크로 — 3-상태 (가상계좌·계좌이체에만 적용)
            'use_escrow' => [
                'type' => 'string',
                'default' => 'off',
                'label' => ['ko' => '에스크로 사용', 'en' => 'Use Escrow'],
                'hint' => [
                    'ko' => '가상계좌·계좌이체 결제에만 적용됩니다. 구매자 선택은 결제창에서 구매자가 직접 결정합니다.',
                    'en' => 'Applies to virtual account and bank transfer only. "Buyer choice" lets the buyer decide in the payment window.',
                ],
            ],

            // 가상계좌 입금통보(DEPOSIT_CALLBACK) 웹훅 secret 대조 강제
            'webhook_secret_verify' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '웹훅 secret 검증', 'en' => 'Webhook Secret Verification'],
                'hint' => [
                    'ko' => '가상계좌 입금통보 웹훅의 secret 을 결제 승인 응답과 대조해 위조 요청을 차단합니다.',
                    'en' => 'Verifies the deposit webhook secret against the payment confirmation response to block forged requests.',
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
            'redirect_success_url' => '{shopBase}/orders/{orderId}/complete',
            'redirect_fail_url' => '{shopBase}/checkout',
            'order_sheet_mode' => false,
            'method_card' => true,
            'method_virtual_account' => false,
            'method_transfer' => false,
            'method_mobile_phone' => false,
            'method_tosspay' => false,
            'method_kakaopay' => false,
            'method_naverpay' => false,
            'method_payco' => false,
            'method_samsungpay' => false,
            'vbank_valid_hours' => 24,
            'vbank_cash_receipt_type' => '',
            'use_escrow' => 'off',
            'webhook_secret_verify' => true,
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
            Listeners\RegisterTossPaymentMethodsListener::class,
            Listeners\RegisterCashReceiptProviderListener::class,
            Listeners\AdjustEcommercePaymentMethodsLayoutListener::class,
            Listeners\PaymentRefundListener::class,
            Listeners\ValidateTossSettingsListener::class,
            Listeners\RestoreLayoutExtensionsAfterUpdateListener::class,
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
            [
                'name' => 'sirsoft-tosspayments.payment.before_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => '토스페이먼츠 결제 취소 API 호출 전',
                    'en' => 'Before TossPayments cancel API call',
                ],
            ],
            [
                'name' => 'sirsoft-tosspayments.payment.after_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => '토스페이먼츠 결제 취소 완료 후',
                    'en' => 'After TossPayments cancel API completed',
                ],
            ],
        ];
    }
}
