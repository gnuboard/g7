<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Tests\PureTestCase;

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
class CliInjectionDefenseTest extends PureTestCase
{
    /**
     * Reflection 으로 private assertSafeCliValue 를 직접 호출.
     * Laravel/Container 의존성 없이 순수 단위 테스트로 진행.
     *
     * @param  string  $value  검사할 CLI 인수 값
     * @param  string  $key  인수 이름 (오류 문구에 실린다)
     * @return void 반환값 없음
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
     * 큰따옴표와 명령 주입 문자열이 든 encData 를 거절한다.
     *
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_double_quote, cli_arg_throws_runtime_exception_on_unsafe, cli_no_shell_executed_on_unsafe_input
     *
     * @return void 반환값 없음
     */
    public function test_double_quote_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/quote|backtick/');

        $this->invokeAssertSafeCliValue('normalbase64data"; id > /tmp/g7_kcp_poc; #', 'enc_data');
    }

    /**
     * 백틱 명령 치환이 든 encInfo 를 거절한다.
     *
     * @scenario context=approvePayment_encInfo, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_backtick, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_backtick_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/quote|backtick/');

        $this->invokeAssertSafeCliValue('validinfo`whoami`', 'enc_info');
    }

    /**
     * 줄바꿈(LF)이 든 주문 번호를 거절한다.
     *
     * @scenario context=approvePayment_ordrIdxx, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_lf_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("ORD12345\nMALICIOUS_INJECT", 'ordr_idxx');
    }

    /**
     * NUL 바이트가 든 고객 IP 를 거절한다.
     *
     * @scenario context=approvePayment_custIp, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_null_byte, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_null_byte_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("127.0.0.1\x00ATTACKER", 'cust_ip');
    }

    /**
     * 탭 문자가 든 주문 번호를 거절한다.
     *
     * @scenario context=approvePayment_ordrIdxx, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_tab_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("ORD12345\tINJECT", 'ordr_idxx');
    }

    /**
     * DEL(0x7F) 제어 문자가 든 encData 를 거절한다.
     *
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_control_char_high, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_del_char_0x7f_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("validdata\x7FINJECT", 'enc_data');
    }

    /**
     * 캐리지 리턴(CR)이 든 encData 를 거절한다.
     *
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_control_char_low, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_cr_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);
        $this->expectExceptionMessageMatches('/control character/');

        $this->invokeAssertSafeCliValue("validdata\rINJECT", 'enc_data');
    }

    /**
     * 추가 메타문자 케이스 — 큰따옴표/백틱 외 단일 변형
     *
     * @scenario context=approvePayment_encData, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_arg_rejects_double_quote, cli_arg_throws_runtime_exception_on_unsafe
     *
     * @return void 반환값 없음
     */
    public function test_simple_double_quote_alone_is_rejected(): void
    {
        $this->expectException(NhnKcpApiException::class);

        $this->invokeAssertSafeCliValue('"', 'any');
    }

    /**
     * 정상 케이스: alnum + base64 padding 통과
     *
     * @scenario context=approvePayment_normal, threat=cli_injection, callback_state=cli_arg_normal
     *
     * @effects cli_arg_accepts_alnum_only, cli_exec_uses_escapeshellarg
     *
     * @return void 반환값 없음
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
     *
     * @scenario context=approvePayment_normal, threat=cli_injection, callback_state=cli_arg_normal
     *
     * @effects cli_arg_accepts_normal_korean_label
     *
     * @return void 반환값 없음
     */
    public function test_korean_label_passes(): void
    {
        $this->invokeAssertSafeCliValue('상품주문', 'cancel_msg');
        $this->assertTrue(true);
    }

    /**
     * 셸 메타문자(명령 치환, 파이프, 리다이렉트, 명령 구분) 변종을 돌려줍니다.
     *
     * @return array<string, array{string}> 변종별 값
     */
    public static function shellMetacharProvider(): array
    {
        return [
            'dollar_paren' => ['ORD$(id)'],
            'pipe' => ['ORD|id'],
            'redirect' => ['ORD>/tmp/g7_kcp_poc'],
            'semicolon' => ['ORD;id'],
        ];
    }

    /**
     * 셸 메타문자는 값 검사에서 거부하지 않고, escapeshellarg 로 감싼 한 인수 안에 문자 그대로 남는다.
     *
     * 값 검사(assertSafeCliValue)는 따옴표·백틱·제어문자처럼 인용 자체를 깨는 문자만 거부한다. `$(`·`|`·`>`·`;` 는
     * CLI 인수 묶음 전체를 escapeshellarg 로 감싸(NhnKcpApiService executeCli* 의 `escapeshellarg($args)`) 셸이
     * 해석하지 못하게 한다 — 따옴표를 깰 수 없으니 인용 밖으로 나갈 수 없다.
     *
     * @param  string  $value  메타문자가 든 값
     * @return void 반환값 없음
     *
     * @scenario context=approvePayment_ordrIdxx, threat=cli_injection, callback_state=cli_arg_unsafe
     *
     * @effects cli_shell_metachar_stays_inside_quoted_argument
     */
    #[DataProvider('shellMetacharProvider')]
    public function test_shell_metachar_stays_inside_one_quoted_argument(string $value): void
    {
        $this->invokeAssertSafeCliValue($value, 'ordr_idxx');

        $args = 'site_cd=T0000,ordr_idxx='.$value.',trace_no=';
        $quoted = escapeshellarg($args);
        $quote = PHP_OS_FAMILY === 'Windows' ? '"' : "'";

        $this->assertStringStartsWith($quote, $quoted);
        $this->assertStringEndsWith($quote, $quoted);
        $this->assertStringContainsString($value, $quoted);
        // 인용 문자는 감싼 양 끝에만 있다 — 값이 인용을 끊고 나가는 지점이 없다.
        $this->assertSame(2, substr_count($quoted, $quote));
    }
}
