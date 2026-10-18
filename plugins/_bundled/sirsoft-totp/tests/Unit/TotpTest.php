<?php

namespace Plugins\Sirsoft\Totp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * RFC 6238 TOTP 계산기·Base32 인코더 단위 테스트.
 *
 * 이 계산이 틀리면 인증 앱이 만든 코드가 영영 맞지 않아 아무도 로그인하지 못합니다.
 * 자체 구현이므로 **표준 문서의 시험값**으로 잠급니다 — 우리 코드끼리 대조하면 같이
 * 틀린 것을 알아낼 수 없습니다.
 *
 * 계산만 검증하므로 DB 는 쓰지 않지만, 확장 테스트는 그 확장의 베이스를 상속해야
 * 오토로드·ServiceProvider 부팅이 운영과 같아집니다 (test-extension-base-class 규정).
 *
 * @since 1.0.0
 */
class TotpTest extends PluginTestCase
{
    /** RFC 6238 Appendix B 의 시드 (ASCII "12345678901234567890") */
    private const RFC_SEED = '12345678901234567890';

    /**
     * RFC 6238 Appendix B (SHA1) 시험값.
     *
     * 문서의 기대값은 8자리이고 이 구현은 6자리이므로 뒤 6자리로 비교합니다 — 동적 절단
     * 결과의 하위 자릿수라 자릿수만 다른 같은 값입니다.
     *
     * @scenario rfc6238=appendix_b, algorithm=sha1
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_matches_the_rfc6238_test_vectors(): void
    {
        $secret = Base32::encode(self::RFC_SEED);

        $vectors = [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];

        foreach ($vectors as [$timestamp, $expected8]) {
            $this->assertSame(
                substr($expected8, -Totp::DIGITS),
                Totp::codeAt($secret, Totp::timestepAt($timestamp)),
                "RFC 6238 시험값 불일치 (t={$timestamp})"
            );
        }
    }

    /**
     * 블록 경계 전후의 모든 길이에서 Base32 인코딩·디코딩이 원본을 보존하는지 확인합니다.
     *
     * @scenario base32=roundtrip
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function base32_round_trips_every_input_length(): void
    {
        // 5바이트마다 블록 경계가 바뀌므로 경계 전후를 모두 지난다.
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', 'abcdef', 'hello world'] as $raw) {
            $this->assertSame($raw, Base32::decode(Base32::encode($raw)), "왕복 실패: [{$raw}]");
        }
    }

    /**
     * Base32 구현이 RFC 4648 표준 시험값과 일치하는지 확인합니다.
     *
     * @scenario base32=known_vector
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function base32_matches_the_rfc4648_vector(): void
    {
        // RFC 4648 §10 의 시험값 (패딩 제외 — 이 구현은 패딩을 붙이지 않는다).
        $this->assertSame('MZXW6YTBOI', Base32::encode('foobar'));
        $this->assertSame('foobar', Base32::decode('MZXW6YTBOI'));
    }

    /**
     * 사람이 옮겨 적을 때 붙는 소문자·공백·하이픈·패딩을 모두 받아들이는지 확인합니다.
     *
     * @scenario base32=lenient_input
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function base32_accepts_the_way_people_actually_type_it(): void
    {
        // 사람이 옮겨 적을 때 붙는 소문자·공백·하이픈·패딩은 전부 받아들여야 한다.
        $this->assertSame('foobar', Base32::decode('mzxw6ytboi'));
        $this->assertSame('foobar', Base32::decode('MZXW 6YTB OI'));
        $this->assertSame('foobar', Base32::decode('MZXW-6YTB-OI'));
        $this->assertSame('foobar', Base32::decode('MZXW6YTBOI======'));
    }

    /**
     * 알파벳 밖 문자를 조용히 넘기지 않고 거절하는지 확인합니다.
     *
     * @scenario base32=invalid_alphabet
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function base32_rejects_characters_outside_the_alphabet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // 1 / 8 / 0 은 RFC 4648 알파벳에 없다 (I·B·O 와 혼동을 피하려고 뺀 자리).
        Base32::decode('MZXW1YTB');
    }

    /**
     * 단말 시계가 한 칸 어긋나도 코드가 통과하는지 확인합니다.
     *
     * @scenario totp=verify, window=1
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verify_accepts_one_step_of_clock_drift_in_both_directions(): void
    {
        $secret = Base32::randomSecret();
        $now = 1_700_000_000;
        $step = Totp::timestepAt($now);

        foreach ([-1, 0, 1] as $offset) {
            $code = Totp::codeAt($secret, $step + $offset);

            $this->assertSame(
                $step + $offset,
                Totp::verify($secret, $code, window: 1, timestamp: $now),
                "허용 오차 안(offset={$offset})의 코드가 거절됐습니다."
            );
        }
    }

    /**
     * 허용 오차 밖의 코드를 거절해 탈취된 코드의 유효 시간이 늘어나지 않는지 확인합니다.
     *
     * @scenario totp=verify, window=1, drift=2
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verify_rejects_codes_beyond_the_window(): void
    {
        $secret = Base32::randomSecret();
        $now = 1_700_000_000;
        $step = Totp::timestepAt($now);

        foreach ([-2, 2] as $offset) {
            $this->assertNull(
                Totp::verify($secret, Totp::codeAt($secret, $step + $offset), window: 1, timestamp: $now),
                "허용 오차 밖(offset={$offset})의 코드가 통과했습니다 — 탈취된 코드의 유효 시간이 늘어납니다."
            );
        }
    }

    /**
     * 이미 통과한 코드를 30초 안에 재사용할 수 없는지 확인합니다.
     *
     * @scenario totp=verify, replay=blocked
     *
     * @effects reused_code_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verify_refuses_a_timestep_that_was_already_used(): void
    {
        $secret = Base32::randomSecret();
        $now = 1_700_000_000;
        $step = Totp::timestepAt($now);
        $code = Totp::codeAt($secret, $step);

        // 한 번 통과한 뒤에는 같은 코드를 30초 안에 다시 제출해도 받아들이면 안 된다.
        $this->assertSame($step, Totp::verify($secret, $code, window: 1, timestamp: $now));
        $this->assertNull(
            Totp::verify($secret, $code, window: 1, notBeforeTimestep: $step, timestamp: $now),
            '이미 사용한 timestep 의 코드가 재사용됐습니다.'
        );
    }

    /**
     * 더 최신 코드를 쓴 뒤에는 과거 코드로 되돌아갈 수 없는지 확인합니다.
     *
     * @scenario totp=verify, replay=blocked, drift=past
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verify_refuses_older_timesteps_once_a_newer_one_was_used(): void
    {
        $secret = Base32::randomSecret();
        $now = 1_700_000_000;
        $step = Totp::timestepAt($now);

        // 직전 timestep 의 코드는 window 안이지만, 더 최신 코드를 이미 썼다면 과거로 되돌아갈 수 없다.
        $this->assertNull(
            Totp::verify($secret, Totp::codeAt($secret, $step - 1), window: 1, notBeforeTimestep: $step, timestamp: $now),
            '더 최신 코드를 쓴 뒤에도 과거 코드가 통과했습니다.'
        );
    }

    /**
     * 6자리 숫자가 아닌 입력을 계산 전에 거절하는지 확인합니다.
     *
     * @scenario totp=verify, input=malformed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function verify_rejects_input_that_is_not_six_digits(): void
    {
        $secret = Base32::randomSecret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 56 78'] as $bad) {
            $this->assertNull(
                Totp::verify($secret, $bad),
                "6자리가 아닌 입력이 통과했습니다: [{$bad}]"
            );
        }
    }

    /**
     * 인증 앱이 읽는 otpauth URI 에 고정 파라미터와 발급자 표기가 모두 실리는지 확인합니다.
     *
     * @scenario totp=otpauth_uri
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_otpauth_uri_carries_the_fixed_parameters(): void
    {
        $uri = Totp::otpauthUri('JBSWY3DPEHPK3PXP', 'member@test.com', 'G7 사이트');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);

        // label 의 issuer 접두사와 issuer 파라미터가 함께 있어야 한다 — 앱마다 읽는 자리가 다르다.
        $this->assertStringContainsString(rawurlencode('G7 사이트').':'.rawurlencode('member@test.com'), $uri);
        $this->assertStringContainsString('issuer=', $uri);
    }

    /**
     * 발급되는 시크릿이 권장 길이를 채우고 중복되지 않는지 확인합니다.
     *
     * @scenario totp=secret_entropy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function generated_secrets_are_long_enough_and_not_repeated(): void
    {
        $secrets = [];

        for ($i = 0; $i < 20; $i++) {
            $secret = Base32::randomSecret();

            // 160비트 = Base32 32자 (RFC 4226 §4 권장치)
            $this->assertSame(32, strlen($secret));
            $secrets[] = $secret;
        }

        $this->assertCount(20, array_unique($secrets), '같은 시크릿이 두 번 발급됐습니다.');
    }
}
