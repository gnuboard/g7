<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 환불수단 불일치 안내 문구의 조립 정확성 (S0-D3 회귀 · #599 통합실측 전체 공정 라운드)
 *
 * `ExecuteRefundRequest` 는 결제행과 어긋난 환불수단으로 [환불하기]를 누르면
 * `validation.claims.refund_method.payment_mismatch` 를 `:expected` 에 **수단 라벨**을
 * 끼워 돌려준다. 수단 라벨(`enums.refund_method.*`)은 셋 다 「환불」로 끝나는데
 * (「PG사 환불」·「계좌이체 환불」·「마일리지 환불」), 문구가 그 뒤에 「환불만」을 이어
 * 「이 주문의 결제수단은 PG사 환불 환불만 가능합니다」 로 조립됐다(2026-09-17 실측 ·
 * 주문 20 · RF 226 · 422 응답 본문).
 *
 * 라벨 사전과 문장 틀이 서로를 모르고 각자 「환불」을 붙인 것이 원인이라, 문장 틀만 보거나
 * 라벨만 봐서는 잡히지 않는다 — 실제 조립 결과를 라벨 전수로 잠근다.
 */
class RefundMethodMismatchMessageTest extends ModuleTestCase
{
    /**
     * 라벨 전수 × 로케일 ko·en 에서 같은 단어가 연달아 두 번 나오지 않고, 라벨은 그대로 들어 있어야 한다.
     *
     * @scenario surface=mismatch_copy
     *
     * @effects mismatch_message_contains_label_exactly_once_without_stutter
     */
    #[Test]
    public function test_mismatch_message_does_not_stutter_the_label_word_for_any_refund_method(): void
    {
        $cases = RefundMethodEnum::cases();
        $this->assertNotEmpty($cases, 'RefundMethodEnum 케이스가 비어 있다 — 아래 루프가 공전한다');

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($cases as $method) {
                $label = $method->label();
                $message = __('sirsoft-ecommerce::validation.claims.refund_method.payment_mismatch', ['expected' => $label]);

                $this->assertStringContainsString($label, $message, "{$locale}/{$method->value}: 라벨이 문구에 끼워지지 않았다");
                $this->assertDoesNotMatchRegularExpression(
                    '/(\S{2,})\s+\1(?=\S|\s|$)/u',
                    $message,
                    "{$locale}/{$method->value}: 같은 단어가 연달아 두 번 나온다 — {$message}",
                );
                $this->assertStringNotContainsString('환불 환불', $message);
            }
        }

        app()->setLocale('ko');
    }
}
