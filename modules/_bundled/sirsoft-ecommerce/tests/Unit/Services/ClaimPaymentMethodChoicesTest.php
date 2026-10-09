<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 수단 선택지 단위 테스트
 *
 * 결제 대기 건의 수단은 접수 시점에 확정되지만, 그 뒤에 **낼 수 없게 되는 경우**가 둘 있다.
 * 마일리지 잔액이 모자라거나(F-1), 운영자가 그 수단을 `claim.charge_payment_methods` 에서
 * 내리거나(F-2). 두 경우 모두 `pay()` 가 거절하므로, 화면이 다른 수단을 고를 수 있어야
 * 사용자가 신청서를 철회하지 않고 빠져나갈 수 있다.
 *
 * 그러려면 상세 응답이 **이 신청자가 쓸 수 있는 수단 목록**을 실어야 한다. 허용 목록의 단일
 * 출처는 전역 모듈 설정(`claim.charge_payment_methods`)이고, 신청 화면이 `claimable` 응답에서
 * 읽는 것은 그 설정의 사본이다 — 상세가 데이터소스를 더 들 필요가 없다.
 *
 * 회귀 배경 (#599 3차 실측 F-1·F-2, 2026-09-22): 카탈로그에서 `point` 를 내리자 이미 `point` 로
 * 만들어진 대기 건이 「미수납 4,000원 + 결제하기」를 계속 안내하면서 누를 때마다 422 를 냈다.
 * 서버는 `{"payment_method":"card"}` 를 받아 주는데 화면에 그 버튼이 없었다.
 */
class ClaimPaymentMethodChoicesTest extends ModuleTestCase
{
    /**
     * 회원에게는 설정의 허용 수단이 그대로 선택지가 되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_method_choices_follow_the_setting
     */
    public function test_회원_선택지는_설정의_허용_수단을_따른다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card', 'point']);
        app(EcommerceSettingsService::class)->clearCache();

        $this->assertSame(
            ['card', 'point'],
            app(ClaimPaymentService::class)->availablePaymentMethodsFor(1),
        );
    }

    /**
     * 운영자가 내린 수단은 선택지에서 빠지는지 검증합니다.
     *
     * 이 목록이 설정을 따라가지 않으면 화면이 서버가 거절할 수단을 계속 권하게 된다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_method_choices_follow_the_setting
     */
    public function test_카탈로그에서_내린_수단은_선택지에_없다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card']);
        app(EcommerceSettingsService::class)->clearCache();

        $this->assertSame(
            ['card'],
            app(ClaimPaymentService::class)->availablePaymentMethodsFor(1),
        );
    }

    /**
     * 비회원에게는 마일리지가 선택지에 오르지 않는지 검증합니다.
     *
     * 비회원은 마일리지 계정이 없어 `assertPaymentMethodAvailableToRequester()` 가 거절한다 —
     * 선택지에 올리면 고를 수 있는데 누르면 막히는 칸이 된다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_method_choices_exclude_mileage_for_guests
     */
    public function test_비회원_선택지에는_마일리지가_없다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card', 'point']);
        app(EcommerceSettingsService::class)->clearCache();

        $choices = app(ClaimPaymentService::class)->availablePaymentMethodsFor(null);

        $this->assertNotContains(PaymentMethodEnum::POINT->value, $choices, '비회원에게 낼 수 없는 수단을 권한다');
        $this->assertContains(PaymentMethodEnum::CARD->value, $choices);
    }

    /**
     * 신청 화면 설정이 허용 수단마다 표시명을 함께 싣는지 검증합니다.
     *
     * 화면은 수단 값만 받으면 `point` 가 아닌 것을 모두 「카드·간편결제」로 그렸다. 운영자가
     * 계좌이체 등을 허용하면 구매자는 카드로 낸다고 읽고 고르는데 실제로는 다른 수단이 열린다.
     *
     * 회귀 배경 (S1-D14, 2026-10-07 실측): `claim.charge_payment_methods` 를 `['card','bank']` 로
     * 두자 두 라디오가 모두 「카드·간편결제」로 보였다.
     *
     * @return void 반환값 없음
     *
     * @effects claim_payment_method_choices_carry_their_own_labels
     */
    public function test_신청_설정은_허용_수단마다_표시명을_싣는다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card', 'bank']);
        app(EcommerceSettingsService::class)->clearCache();

        $settings = app(UserClaimService::class)->publicSettings();
        $labels = $settings['charge_payment_method_labels'] ?? null;

        $this->assertIsArray($labels, '수단 표시명이 실리지 않는다');
        $this->assertSame(['card', 'bank'], array_keys($labels));
        $this->assertSame(app(PaymentMethodResolver::class)->label('bank'), $labels['bank']);
        $this->assertNotSame($labels['card'], $labels['bank'], '서로 다른 수단이 같은 이름으로 보인다');
    }
}
