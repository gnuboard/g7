<?php

namespace Plugins\Sirsoft\PayKginicis;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['payment', 'kginicis', 'inicis', 'pg', 'card', 'ecommerce', 'japan'],
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
                'default' => 'INIpayTest',
                'label' => ['ko' => '테스트 가맹점 ID (MID)', 'en' => 'Test Merchant ID (MID)'],
                'hint' => [
                    'ko' => 'KG 이니시스에서 발급받은 테스트 MID',
                    'en' => 'Test MID issued by KG Inicis',
                ],
            ],
            'test_sign_key' => [
                'type' => 'string',
                'default' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
                'sensitive' => true,
                'label' => ['ko' => '테스트 사인키', 'en' => 'Test Sign Key'],
                'hint' => [
                    'ko' => '결제창 서명 생성에 사용되는 키입니다.',
                    'en' => 'Key used for payment window signature generation.',
                ],
            ],
            'test_iniapi_key' => [
                'type' => 'string',
                'default' => 'ItEQKi3rY7uvDS8l',
                'sensitive' => true,
                'label' => ['ko' => '테스트 INIAPI 키', 'en' => 'Test INIAPI Key'],
                'hint' => [
                    'ko' => '취소 API 인증에 사용되는 키입니다.',
                    'en' => 'Key used for cancel API authentication.',
                ],
            ],
            'test_iniapi_iv' => [
                'type' => 'string',
                'default' => 'HYb3yQ4f65QL89==',
                'sensitive' => true,
                'label' => ['ko' => '테스트 INIAPI IV', 'en' => 'Test INIAPI IV'],
                'hint' => [
                    'ko' => '취소 API 암호화에 사용되는 초기화 벡터입니다.',
                    'en' => 'Initialization vector for cancel API encryption.',
                ],
            ],
            'live_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 가맹점 ID (MID)', 'en' => 'Live Merchant ID (MID)'],
            ],
            'live_sign_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 사인키', 'en' => 'Live Sign Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'live_iniapi_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 INIAPI 키', 'en' => 'Live INIAPI Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'live_iniapi_iv' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 INIAPI IV', 'en' => 'Live INIAPI IV'],
            ],
            'test_mobile_hash_key' => [
                'type' => 'string',
                'default' => '3CB8183A4BE283555ACC8363C0360223',
                'sensitive' => true,
                'label' => ['ko' => '테스트 모바일 해시키', 'en' => 'Test Mobile Hash Key'],
            ],
            'live_mobile_hash_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 모바일 해시키', 'en' => 'Live Mobile Hash Key'],
            ],
            'use_escrow' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '에스크로 결제 활성화', 'en' => 'Enable Escrow Payment'],
                'hint' => [
                    'ko' => '활성화 시 PC는 acceptmethod 에 useescrow 가 추가되고, 모바일은 P_RESERVED 에 useescrow=Y 가 추가됩니다.',
                    'en' => 'When enabled, acceptmethod adds useescrow on PC and P_RESERVED adds useescrow=Y on mobile.',
                ],
            ],
            'japan_enabled' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '일본 결제 활성화', 'en' => 'Enable Japan Payment'],
                'hint' => [
                    'ko' => '일본 엔(JPY) 결제를 위한 별도 MID가 필요합니다.',
                    'en' => 'Requires a separate MID for Japanese Yen (JPY) payments.',
                ],
            ],
            'japan_restrict_jpy_payment_methods' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'JPY 주문 결제수단 제한', 'en' => 'Restrict JPY Payment Methods'],
                'hint' => [
                    'ko' => '활성화하면 JPY 주문은 신용카드, PayPay, 일본 편의점결제만 CBT로 진행됩니다.',
                    'en' => 'When enabled, JPY orders can proceed through CBT only with credit card, PayPay, or Japan convenience store payment.',
                ],
            ],
            'test_japan_sign_key' => [
                'type' => 'string',
                'default' => '5AL5Djb1Ipualn0F',
                'sensitive' => true,
                'label' => ['ko' => '테스트 일본 CBT 해시키', 'en' => 'Test Japan CBT Hash Key'],
                'hint' => [
                    'ko' => 'CBT 해시 데이터 생성에 사용되는 테스트 KEY입니다.',
                    'en' => 'Test KEY used for CBT hash data generation.',
                ],
            ],
            'live_japan_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 일본 MID', 'en' => 'Live Japan MID'],
            ],
            'live_japan_sign_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 일본 CBT 해시키', 'en' => 'Live Japan CBT Hash Key'],
                'hint' => [
                    'ko' => 'CBT 해시 데이터 생성에 사용되는 라이브 KEY입니다. 외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Live KEY used for CBT hash data generation. Keep this key secret.',
                ],
            ],
            'japan_merchant_name' => [
                'type' => 'string',
                'default' => 'サンプルストア',
                'label' => ['ko' => '일본 결제 가맹점명', 'en' => 'Japan Payment Merchant Name'],
            ],
            'japan_merchant_name_kana' => [
                'type' => 'string',
                'default' => 'サンプルストア',
                'label' => ['ko' => '일본 결제 가맹점명 Kana', 'en' => 'Japan Payment Merchant Name Kana'],
            ],
            'japan_merchant_name_alphabet' => [
                'type' => 'string',
                'default' => 'Sample Store',
                'label' => ['ko' => '일본 결제 가맹점명 영문', 'en' => 'Japan Payment Merchant Name Alphabet'],
            ],
            'japan_merchant_name_short' => [
                'type' => 'string',
                'default' => 'サンプル',
                'label' => ['ko' => '일본 결제 가맹점 약칭', 'en' => 'Japan Payment Merchant Short Name'],
            ],
            'japan_contact_name' => [
                'type' => 'string',
                'default' => 'サポート窓口',
                'label' => ['ko' => '일본 결제 문의처명', 'en' => 'Japan Payment Contact Name'],
            ],
            'japan_contact_email' => [
                'type' => 'string',
                'default' => 'support@example.com',
                'label' => ['ko' => '일본 결제 문의 이메일', 'en' => 'Japan Payment Contact Email'],
            ],
            'japan_contact_phone' => [
                'type' => 'string',
                'default' => '0120-123-456',
                'label' => ['ko' => '일본 결제 문의 전화번호', 'en' => 'Japan Payment Contact Phone'],
            ],
            'japan_contact_opening_hours' => [
                'type' => 'string',
                'default' => '10:00-18:00',
                'label' => ['ko' => '일본 결제 문의 영업시간', 'en' => 'Japan Payment Contact Hours'],
            ],
            'redirect_success_url' => [
                'type' => 'string',
                'default' => '/shop/orders/{orderId}/complete',
                'label' => ['ko' => '결제 성공 리다이렉트 URL', 'en' => 'Payment Success Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로(/shop/...) 또는 전체 URL(https://...) 모두 가능합니다. {orderId}는 주문번호로 자동 치환됩니다.',
                    'en' => 'Supports relative paths or full URLs. {orderId} will be replaced with the actual order number.',
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
            'easy_pay_allow_with_other_pg' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '타 PG와 사용가능함', 'en' => 'Allow with Other PG'],
            ],
            'easy_pay_samsung_pay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'KG이니시스 삼성페이 사용', 'en' => 'Enable Samsung Pay (KG Inicis)'],
            ],
            'easy_pay_naverpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'KG이니시스 네이버페이 사용', 'en' => 'Enable Naver Pay (KG Inicis)'],
            ],
            'easy_pay_show_brand_button' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '간편결제 브랜드 버튼 표시', 'en' => 'Show Easy Pay Branded Buttons'],
                'hint' => [
                    'ko' => '활성화하면 주문서 결제수단에서 KG 이니시스 간편결제 버튼을 브랜드 아이콘과 짧은 설명으로 표시합니다.',
                    'en' => 'When enabled, checkout payment methods show KG Inicis easy pay brand icons and shorter descriptions.',
                ],
            ],
            'easy_pay_lpay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'KG이니시스 L.pay 사용', 'en' => 'Enable L.pay (KG Inicis)'],
            ],
            'easy_pay_kakaopay' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => 'KG이니시스 카카오페이 사용', 'en' => 'Enable Kakao Pay (KG Inicis)'],
            ],
            'use_credit_point' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '신용카드 포인트 사용', 'en' => 'Use Credit Card Points'],
            ],
        ];
    }

    public function getConfigValues(): array
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
            'test_mobile_hash_key' => '3CB8183A4BE283555ACC8363C0360223',
            'live_mobile_hash_key' => '',
            'use_escrow' => false,
            'japan_enabled' => false,
            'japan_restrict_jpy_payment_methods' => false,
            'test_japan_sign_key' => '5AL5Djb1Ipualn0F',
            'live_japan_mid' => '',
            'live_japan_sign_key' => '',
            'japan_merchant_name' => 'サンプルストア',
            'japan_merchant_name_kana' => 'サンプルストア',
            'japan_merchant_name_alphabet' => 'Sample Store',
            'japan_merchant_name_short' => 'サンプル',
            'japan_contact_name' => 'サポート窓口',
            'japan_contact_email' => 'support@example.com',
            'japan_contact_phone' => '0120-123-456',
            'japan_contact_opening_hours' => '10:00-18:00',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
            'easy_pay_allow_with_other_pg' => false,
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_show_brand_button' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'use_credit_point' => false,
        ];

        return array_merge($defaults, $this->getJsonConfigDefaults());
    }

    /**
     * config/settings/defaults.json 의 defaults 섹션을 런타임 기본값에도 반영한다.
     *
     * 플러그인 설치 초기값은 PluginManager 가 defaults.json 을 사용하지만,
     * 저장 파일이 없는 환경의 PluginSettingsService fallback 은 getConfigValues() 를
     * 사용하므로 두 경로의 기본값이 갈라지지 않도록 맞춘다.
     */
    private function getJsonConfigDefaults(): array
    {
        $path = $this->getSettingsDefaultsPath();
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return [];
        }

        $defaults = $data['defaults'] ?? [];

        return is_array($defaults) ? $defaults : [];
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
            Listeners\EnsureAdminOrderDetailTestModeLayoutListener::class,
            Listeners\EnsureAdminOrderDetailPaymentQueryLayoutListener::class,
            Listeners\RestoreLayoutExtensionsAfterUpdateListener::class,
            Listeners\ValidateCbtSettingsListener::class,
        ];
    }

    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-pay_kginicis.payment.before_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 서버 승인 API 호출 전',
                    'en' => 'Before KG Inicis server authorization API call',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.after_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 서버 승인 완료 후',
                    'en' => 'After KG Inicis server authorization completed',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.before_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 결제 취소 API 호출 전 (본인인증 등 확장 지점)',
                    'en' => 'Before KG Inicis cancel API call (extension point for re-auth, etc.)',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.after_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 결제 취소 완료 후',
                    'en' => 'After KG Inicis cancel completed',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.before_cbt_refund',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 일본 CBT 결제 취소 API 호출 전',
                    'en' => 'Before KG Inicis Japan CBT refund API call',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.after_cbt_refund',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 일본 CBT 결제 취소 완료 후',
                    'en' => 'After KG Inicis Japan CBT refund completed',
                ],
            ],
        ];
    }
}
