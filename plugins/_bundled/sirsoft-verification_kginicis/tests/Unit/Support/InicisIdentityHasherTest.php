<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Support;

use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * InicisIdentityHasher (APP_KEY 기반 HMAC) 단위 테스트.
 *
 * - 결정성: 동일 입력 + 동일 키 → 동일 출력
 * - 키 의존: APP_KEY 가 바뀌면 출력이 바뀐다 (keyed-hash 임을 보장 — salt 없는 SHA256 회귀 차단)
 * - salt-less SHA256 과 다른 값을 낸다 (해시 방식이 실제로 전환되었음을 보장)
 */
class InicisIdentityHasherTest extends PluginTestCase
{
    public function test_hash_is_deterministic_for_same_input_and_key(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $value = 'DI-IDENTIFIER-1234567890';

        $this->assertSame(
            InicisIdentityHasher::hash($value),
            InicisIdentityHasher::hash($value),
        );
    }

    public function test_hash_uses_app_key_so_output_changes_when_key_changes(): void
    {
        $value = 'CI-IDENTIFIER-ABCDEFGHIJ';

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $withKeyA = InicisIdentityHasher::hash($value);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $withKeyB = InicisIdentityHasher::hash($value);

        $this->assertNotSame($withKeyA, $withKeyB);
    }

    public function test_hash_differs_from_plain_sha256(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $value = 'DI-IDENTIFIER-1234567890';

        $this->assertNotSame(hash('sha256', $value), InicisIdentityHasher::hash($value));
    }

    public function test_hash_returns_64_char_hex_digest(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $digest = InicisIdentityHasher::hash('DI-IDENTIFIER');

        $this->assertSame(64, strlen($digest));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
    }
}
