<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Support;

use Illuminate\Support\Facades\Cache;
use Plugins\Sirsoft\PayKginicis\Support\EscrowConfirmNonce;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 에스크로 구매결정 증표 소비의 원자성 회귀 테스트
 *
 * 종전 consume() 은 읽기와 지우기 사이가 비어 있어, 같은 증표로 동시에 들어온 두 수신이 둘 다 문맥을 읽고
 * 결제 메타에 두 번 기록할 수 있었다. 이제 증표별 잠금을 즉시 시도해 잡은 쪽만 읽고 지운다.
 *
 * 단일 프로세스라 진짜 동시 수신은 만들 수 없으므로, 다른 소비자가 잠금을 쥔 상태는 테스트가 같은 키의 잠금을
 * 먼저 잡아 재현한다. 캐시 파사드를 부분 모킹하면 CacheManager 가 깨진 전례가 있어 실제 잠금을 쓴다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/escrow-request-serialization.yaml
 */
class EscrowConfirmNonceConsumeTest extends PluginTestCase
{
    /**
     * 다른 소비자가 증표 잠금을 쥐고 있으면 null 을 돌려주고 문맥은 지우지 않는지, 잠금이 풀린 뒤에는
     * 한 번만 꺼낼 수 있는지 확인합니다.
     *
     * @scenario request=escrow_confirm_return, contention=lock_held
     *
     * @effects escrow_nonce_consume_is_exclusive
     *
     * @return void 반환값 없음
     */
    public function test_consume_returns_null_while_another_consumer_holds_the_lock(): void
    {
        $nonces = app(EscrowConfirmNonce::class);
        $nonce = $nonces->issue('StdpayESCRWINIpayTest20261113000001', 'ORD-ESCROW-NONCE-001', 7);

        $held = Cache::lock($this->nonceLockKey($nonce), 10);
        $this->assertTrue($held->get(), '테스트가 잠금을 먼저 잡지 못하면 경합을 재현하지 못한 것이다.');

        try {
            $this->assertNull($nonces->consume($nonce), '잠금을 못 잡은 소비자는 문맥을 받으면 안 된다.');
        } finally {
            $held->release();
        }

        // 잠금을 못 잡은 쪽이 문맥을 지우지 않았으므로, 잠금을 쥐었던 소비자는 그대로 꺼낼 수 있다
        $context = $nonces->consume($nonce);
        $this->assertSame([
            'tid' => 'StdpayESCRWINIpayTest20261113000001',
            'order_number' => 'ORD-ESCROW-NONCE-001',
            'user_id' => 7,
        ], $context);

        $this->assertNull($nonces->consume($nonce), '한 번 꺼낸 증표는 다시 쓸 수 없다.');
    }

    /**
     * 소비가 끝나면 증표 잠금을 풀어 다른 소비자를 오래 막지 않는지 확인합니다.
     *
     * @scenario request=escrow_confirm_return, contention=sequential
     *
     * @effects escrow_nonce_consume_is_exclusive
     *
     * @return void 반환값 없음
     */
    public function test_consume_releases_the_lock_after_reading(): void
    {
        $nonces = app(EscrowConfirmNonce::class);
        $nonce = $nonces->issue('StdpayESCRWINIpayTest20261113000002', 'ORD-ESCROW-NONCE-002', 8);

        $this->assertNotNull($nonces->consume($nonce));

        $this->assertTrue(
            Cache::lock($this->nonceLockKey($nonce), 10)->get(),
            '소비가 끝난 뒤에도 잠금이 남아 있으면 안 된다.'
        );
    }

    /**
     * 증표 소비 잠금 키를 계약 그대로 계산합니다.
     *
     * 대상 클래스의 키 계산 메서드를 부르지 않는다 — 잠금이 실제로 그 키로 걸리는지(동작)를 재고, 메서드가 없어서
     * 실패하지 않게 한다. 키는 증표 원문이 아니라 해시를 싣는다.
     *
     * @param  string  $nonce  증표
     * @return string 잠금 키
     */
    private function nonceLockKey(string $nonce): string
    {
        return 'sirsoft-pay_kginicis:escrow-nonce:'.sha1($nonce);
    }
}
