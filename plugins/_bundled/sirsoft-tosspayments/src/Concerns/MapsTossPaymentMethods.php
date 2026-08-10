<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

/**
 * 토스 주문서형 결제수단(toss_*) ↔ SDK method / easyPay provider / 코어 결제수단 매핑 SSoT.
 *
 * 세 리스너가 공유한다:
 *  - RegisterTossPaymentMethodsListener: 활성 토글된 수단만 이커머스 결제수단 목록에 entry 로 주입
 *  - RegisterPgProviderListener::getClientConfig: enabled_methods 를 프론트 SDK 설정으로 내림
 *  - AdjustEcommercePaymentMethodsLayoutListener: 전체 toss_* id 를 no-PG 리스트에 병합
 *
 * core 값은 체크아웃이 서버로 보낼 코어 PaymentMethodEnum 값이다. toss_* id 는 코어 enum 이
 * 거부하므로, 프론트는 이 core 값을 payment_method 로 전송하고 toss_* 선택값은 _local 에만
 * 유지해 SDK 호출 시 참조한다 (프론트 하드코딩 금지).
 */
trait MapsTossPaymentMethods
{
    /**
     * toss_* entry id → { setting, method, easy_pay, core }.
     *
     * @var array<string, array{setting:string, method:string, easy_pay:?string, core:string}>
     */
    private const TOSS_METHOD_MAP = [
        'toss_card' => ['setting' => 'method_card', 'method' => 'CARD', 'easy_pay' => null, 'core' => 'card'],
        'toss_virtual_account' => ['setting' => 'method_virtual_account', 'method' => 'VIRTUAL_ACCOUNT', 'easy_pay' => null, 'core' => 'vbank'],
        'toss_transfer' => ['setting' => 'method_transfer', 'method' => 'TRANSFER', 'easy_pay' => null, 'core' => 'bank'],
        'toss_mobile_phone' => ['setting' => 'method_mobile_phone', 'method' => 'MOBILE_PHONE', 'easy_pay' => null, 'core' => 'phone'],
        'toss_tosspay' => ['setting' => 'method_tosspay', 'method' => 'CARD', 'easy_pay' => '토스페이', 'core' => 'card'],
        'toss_kakaopay' => ['setting' => 'method_kakaopay', 'method' => 'CARD', 'easy_pay' => '카카오페이', 'core' => 'card'],
        'toss_naverpay' => ['setting' => 'method_naverpay', 'method' => 'CARD', 'easy_pay' => '네이버페이', 'core' => 'card'],
        'toss_payco' => ['setting' => 'method_payco', 'method' => 'CARD', 'easy_pay' => '페이코', 'core' => 'card'],
        'toss_samsungpay' => ['setting' => 'method_samsungpay', 'method' => 'CARD', 'easy_pay' => '삼성페이', 'core' => 'card'],
    ];

    /**
     * 등록 가능한 전체 toss_* 결제수단 id 목록을 선언 순서대로 반환합니다 (활성 토글 무관).
     *
     * @return list<string> 전체 toss_* id 목록
     */
    protected function allTossMethodIds(): array
    {
        return array_keys(self::TOSS_METHOD_MAP);
    }

    /**
     * 플러그인 설정에서 활성 토글된 toss_* 결제수단 id 목록을 선언 순서대로 반환합니다.
     *
     * @param  array<string, mixed>  $settings  플러그인 설정값
     * @return list<string> 활성 toss_* id 목록
     */
    protected function enabledTossMethodIds(array $settings): array
    {
        $enabled = [];
        foreach (self::TOSS_METHOD_MAP as $id => $meta) {
            if ((bool) ($settings[$meta['setting']] ?? false)) {
                $enabled[] = $id;
            }
        }

        return $enabled;
    }
}
