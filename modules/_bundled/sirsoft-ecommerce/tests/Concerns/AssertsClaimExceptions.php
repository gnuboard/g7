<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;

/**
 * 클레임 도메인 예외의 **사유**까지 단언하는 헬퍼
 *
 * `OrderClaimException` 은 팩토리 18종이 모두 같은 클래스를 반환한다. 그래서
 * `expectException(OrderClaimException::class)` 만 쓰면 **어느 사유로 실패했는지 묻지 않는
 * 단언**이 된다 — 수량 초과 검증이 사유 검증으로 뒤바뀌어도 테스트는 계속 green 이고,
 * 운영자가 보는 안내 문구만 조용히 달라진다.
 *
 * 예외 메시지(번역문)가 아니라 **키**를 단언하는 이유는 로케일·치환 파라미터에 흔들리지
 * 않기 위해서다.
 */
trait AssertsClaimExceptions
{
    /**
     * 주어진 호출이 특정 사유의 클레임 예외를 던지는지 단언합니다.
     *
     * @param  string  $expectedKey  기대 사유 키 (모듈 접두 제외 — 예: `claim_quantity_invalid`)
     * @param  callable  $callback  예외를 던져야 하는 호출
     */
    protected function assertClaimException(string $expectedKey, callable $callback): void
    {
        try {
            $callback();
        } catch (OrderClaimException $e) {
            $this->assertSame(
                'sirsoft-ecommerce::exceptions.'.$expectedKey,
                $e->getMessageKey(),
                '클레임 예외가 다른 사유로 던져졌습니다.'
            );

            return;
        }

        $this->fail("클레임 예외({$expectedKey})가 던져지지 않았습니다.");
    }
}
