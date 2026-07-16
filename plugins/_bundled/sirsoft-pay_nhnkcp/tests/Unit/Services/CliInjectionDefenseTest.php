<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

/**
 * Phase 3 PoC — NHN KCP CLI command injection 침투 시뮬레이션
 *
 * 본 테스트는 NhnKcpApiService::approvePayment 를 통해 흘러가는 사용자
 * 제어 가능 인수에 셸 메타문자/제어문자를 주입했을 때 실제 CLI 가
 * 실행되기 전에 NhnKcpApiException 으로 차단되는지 검증한다.
 *
 * 공격 시나리오:
 *   1. encData 인수에 큰따옴표 + 명령 주입 시퀀스 삽입
 *   2. encInfo 인수에 백틱 명령 치환 시퀀스 삽입
 *   3. ordrIdxx 인수에 줄바꿈 (LF) 삽입 — 새 명령 인젝션 시도
 *   4. custIp 인수에 NUL 바이트 삽입 — argv 절단 시도
 *
 * 기대 결과: 모든 케이스에서 assertSafeCliValue 가 NhnKcpApiException
 * 을 throw 하여 exec() 호출이 일어나지 않는다.
 *
 * 정상 케이스: alphanumeric / URL / IP / base64-like 값은 통과.
 */
class CliInjectionDefenseTest extends TestCase
{
    /**
     * Reflection 으로 private assertSafeCliValue 를 직접 호출.
     * Laravel/Container 의존성 없이 순수 단위 테스트로 진행.
     */
    private function invokeAssertSafeCliValue(string $value, string $key = 'test_field'): void
    {
        // siteCd / siteKey 등의 NULL 체크를 우회하기 위해 ReflectionClass 로 인스턴스 생성
        $reflection = new \ReflectionClass(NhnKcpApiService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('assertSafeCliValue');
        $method->setAccessible(true);
        $method->invoke($instance, $value, $key);
    }

    /**
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_double_quote, cli_arg_throws_runtime_exception_on_unsafe, cli_no_shell_executed_on_unsafe_input
     */
    public function test_double_quote_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/quote|backtick/');

        $this->invokeAssertSafeCliValue('normalbase64data"; id > /tmp/g7_kcp_poc; #', 'enc_data');
    }

    /**
     * @scenario context=approvePayment_encInfo, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_backtick, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_backtick_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/quote|backtick/');

        $this->invokeAssertSafeCliValue('validinfo`whoami`', 'enc_info');
    }

    /**
     * @scenario context=approvePayment_ordrIdxx, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_lf_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("ORD12345\nMALICIOUS_INJECT", 'ordr_idxx');
    }

    /**
     * @scenario context=approvePayment_custIp, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_null_byte, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_null_byte_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("127.0.0.1\x00ATTACKER", 'cust_ip');
    }

    /**
     * @scenario context=approvePayment_ordrIdxx, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_tab_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("ORD12345\tINJECT", 'ordr_idxx');
    }

    /**
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_control_char_high, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_del_char_0x7f_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("validdata\x7FINJECT", 'enc_data');
    }

    /**
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_cr_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("validdata\rINJECT", 'enc_data');
    }

    /**
     * 추가 메타문자 케이스 — 큰따옴표/백틱 외 단일 변형
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     * @effects cli_arg_rejects_double_quote, cli_arg_throws_runtime_exception_on_unsafe
     */
    public function test_simple_double_quote_alone_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);

        $this->invokeAssertSafeCliValue('"', 'any');
    }

    /**
     * 정상 케이스: alnum + base64 padding 통과
     * @scenario context=approvePayment_normal, threat=cli_injection, callback_state=cli_arg_normal
     * @effects cli_arg_accepts_alnum_only, cli_exec_uses_escapeshellarg
     */
    public function test_normal_alphanumeric_passes(): void
    {
        // 예외 미발생 = 통과
        $this->invokeAssertSafeCliValue('YWJjZGVmMTIzNDU2Nzg5MA==', 'enc_data');
        $this->invokeAssertSafeCliValue('ORD20260516001', 'ordr_idxx');
        $this->invokeAssertSafeCliValue('127.0.0.1', 'cust_ip');
        $this->invokeAssertSafeCliValue('https://example.com/path', 'pa_url');
        $this->assertTrue(true, 'normal values passed sanitization');
    }

    /**
     * Korean label 도 control char 아니므로 통과 (한글은 UTF-8 multibyte 로 0x80+)
     * @scenario context=approvePayment_normal, threat=cli_injection, callback_state=cli_arg_normal
     * @effects cli_arg_accepts_normal_korean_label
     */
    public function test_korean_label_passes(): void
    {
        $this->invokeAssertSafeCliValue('상품주문', 'cancel_msg');
        $this->assertTrue(true);
    }
}
