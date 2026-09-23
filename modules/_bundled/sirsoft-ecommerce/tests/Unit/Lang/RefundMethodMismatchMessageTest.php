<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 환불수단 거절 안내 문구의 단일 출처 (S0-D3 후속 · #599 validate P6)
 *
 * [환불하기] 실행 게이트(`ExecuteRefundRequest`)는 한때 결제행과 어긋난 수단을
 * `validation.claims.refund_method.payment_mismatch`(`:expected` 에 수단 라벨을 끼우는 문구)로
 * 막았다. 2026-09-23 결정으로 실행 게이트가 [금액 수정]과 같은 선택지를 쓰게 되면서 거절 문구도
 * 금액 수정 경로와 같은 `exceptions.claim_refund_method_unavailable` 로 모였고, 옛 키는 어디서도
 * 읽지 않는 죽은 키가 됐다.
 *
 * 이 테스트는 그 뒤의 계약을 잠근다 — 두 경로가 **같은 키**로 거절하고, 옛 키는 세 언어 어디에도
 * 남지 않는다(남아 있으면 번역 관리 대상만 늘고, 누군가 다시 가져다 쓰면 두 경로의 문구가 갈린다).
 */
class RefundMethodMismatchMessageTest extends ModuleTestCase
{
    /**
     * 실행 게이트와 금액 수정 경로가 같은 거절 문구 키를 쓴다.
     *
     * 수정 전에도 통과한다 — 게이트 통일 이후의 계약을 잠그는 회귀 가드다.
     *
     * @scenario surface=rejection_copy_source
     *
     * @effects refund_method_rejection_uses_one_message_key_on_both_paths
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_refund_method_rejection_uses_one_message_key_on_both_paths(): void
    {
        $key = 'sirsoft-ecommerce::exceptions.claim_refund_method_unavailable';

        $this->assertSame($key, OrderClaimException::refundMethodUnavailable()->getMessageKey());

        $gate = (string) file_get_contents($this->getModuleBasePath().'/src/Http/Requests/Admin/ExecuteRefundRequest.php');
        $this->assertStringContainsString("__('{$key}')", $gate, '실행 게이트가 금액 수정 경로와 다른 문구로 거절합니다');
    }

    /**
     * 읽는 곳이 없어진 옛 불일치 문구 키가 ko·en·ja 어디에도 남지 않는다.
     *
     * 수정 전 실패 이유: 실행 게이트가 거절 문구를 바꾼 뒤에도 `payment_mismatch` 가 세 언어 파일에
     * 남아 있었다(읽는 코드 0곳).
     *
     * @scenario surface=retired_key
     *
     * @effects retired_payment_mismatch_key_is_removed_from_every_locale
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_retired_payment_mismatch_key_is_removed_from_every_locale(): void
    {
        $base = $this->getModuleBasePath();
        $files = [
            'ko' => $base.'/src/lang/ko/validation.php',
            'en' => $base.'/src/lang/en/validation.php',
            'ja' => base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja/validation.php'),
        ];

        foreach ($files as $locale => $path) {
            $lang = include $path;

            $this->assertArrayNotHasKey(
                'payment_mismatch',
                $lang['claims']['refund_method'] ?? [],
                "{$locale}: 읽는 곳이 없는 payment_mismatch 키가 남아 있습니다"
            );
        }
    }
}
