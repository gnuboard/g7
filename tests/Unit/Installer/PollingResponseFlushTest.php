<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 폴링 모드 응답 즉시 flush 회귀 테스트 (PHP 8.5 + mod_fcgid/Apache)
 *
 * 시나리오: 사용자가 폴링 모드로 설치 시작 시 install-process.php 가 응답 JSON 을
 * echo 하고 워커를 인라인 실행한다. 응답이 즉시 클라이언트에 도달해야 브라우저가
 * 1초 폴링 (state-management.php?action=get) 을 시작할 수 있다.
 *
 * 회귀: PHP 8.5 환경에서 install-process.php 의 폴링 분기가 출력 버퍼 / gzip 압축
 * 비활성화 처리를 누락하여, php.ini 또는 Apache 의 compression 이 활성화된 경우
 * Content-Length 헤더와 wire body 가 불일치 → 브라우저 fetch 가 워커 종료 시점
 * (최대 10분) 까지 hang. 결과로 폴링 모니터가 시작조차 못 하고 설치 완료 시점에서야
 * 모든 진행 상황이 한꺼번에 표시됨.
 *
 * SSE 분기 (install-worker.php) 는 동일 환경에서 정상 작동하는데, 그 분기는 다음
 * 처리를 명시한다:
 *
 *   @ini_set('output_buffering', 'off')
 *   @ini_set('zlib.output_compression', false)
 *
 *   @apache_setenv('no-gzip', '1')
 *   while (ob_get_level() > 0) ob_end_clean();
 *
 * 본 테스트는 install-process.php 의 폴링 분기에 동일한 출력 처리 비활성화가 echo
 * 이전에 존재하는지 정적 검증한다. PHP 8.3 에서는 회귀하지 않으므로 정적 검사로만
 * 회귀 차단 가능 (런타임 검사는 SAPI 종속이라 PHPUnit 환경에서 불가).
 */
class PollingResponseFlushTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private string $sourcePath;

    private string $source;

    /**
     * 설치 진행 API 원문을 읽어 주석을 걷어낸 소스를 준비하고, 파일이 있고 내용이 비지 않았는지 먼저 확인한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = dirname(__DIR__, 3).'/public/install/api/install-process.php';
        $this->assertFileExists($this->sourcePath, 'install-process.php 가 존재해야 한다.');

        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        $this->source = $this->stripComments((string) file_get_contents($this->sourcePath));
        $this->assertNotSame('', $this->source);
    }

    /**
     * 폴링 분기 안에 zlib 출력 압축을 끄는 ini 설정 호출이 있는지 확인한다.
     *
     * 압축이 켜진 채 응답하면 Content-Length 와 실제 본문이 어긋나 브라우저 요청이 워커 종료까지 멈춘다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_disables_output_compression_before_echo
     *
     * @return void 반환값 없음
     */
    public function test_polling_branch_disables_zlib_output_compression(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        $this->assertMatchesRegularExpression(
            '/@?ini_set\(\s*[\'"]zlib\.output_compression[\'"]\s*,\s*(false|[\'"]\s*off\s*[\'"]|0)\s*\)/i',
            $pollingBranch,
            '폴링 분기는 echo 이전에 zlib.output_compression 을 비활성화해야 한다 — '
            .'PHP 8.5 + mod_deflate/zlib 환경에서 Content-Length 와 wire body 불일치로 fetch 가 hang.'
        );
    }

    /**
     * 폴링 분기 안에 output_buffering ini 를 off 로 설정하는 호출이 있는지 확인한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_disables_output_compression_before_echo
     *
     * @return void 반환값 없음
     */
    public function test_polling_branch_disables_output_buffering_ini(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        $this->assertMatchesRegularExpression(
            '/@?ini_set\(\s*[\'"]output_buffering[\'"]\s*,\s*[\'"]\s*off\s*[\'"]\s*\)/i',
            $pollingBranch,
            '폴링 분기는 echo 이전에 output_buffering ini 를 off 로 설정해야 한다 — '
            .'php.ini output_buffering 이 활성화된 SAPI 환경에서 응답 즉시 송신 보장.'
        );
    }

    /**
     * 폴링 분기 안에 Apache 의 no-gzip 환경값을 문자열 인자로 켜는 호출이 있는지 확인한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_disables_output_compression_before_echo
     *
     * @return void 반환값 없음
     */
    public function test_polling_branch_disables_apache_gzip(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        // 두 번째 인자는 **문자열**이어야 한다. PHP 8 의 mod_php 에서 `apache_setenv('no-gzip', 1)`
        // 은 TypeError 이고 `@` 는 Error 를 억제하지 못해 응답이 통째로 500 이 된다 (G3 P0 실측).
        // 형제 게이트 InstallerJsonEndpointErrorChannelTest 가 인스톨러 트리 전수에 문자열 인자를
        // 강제하므로, 이 자리가 int 형태를 요구하면 두 게이트가 서로를 빨갛게 만든다 — 여기서
        // 보는 것은 「분기 안에 있는가 · echo 보다 앞인가」뿐이고 인자 형태는 그 게이트가 소유한다.
        $this->assertMatchesRegularExpression(
            '/apache_setenv\(\s*[\'"]no-gzip[\'"]\s*,\s*[\'"]1[\'"]\s*\)/i',
            $pollingBranch,
            '폴링 분기는 echo 이전에 apache_setenv(no-gzip, \'1\') 를 호출해야 한다 — '
            .'Apache mod_deflate 가 응답을 압축하면 Content-Length 가 무력화됨.'
        );
    }

    /**
     * 폴링 분기가 응답 뒤에 65536 바이트 이상의 공백 채움을 내보내 mod_fcgid 의 64KB 버퍼를 넘겨 즉시 송출되게 하는지 확인한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_pads_past_fcgid_buffer_before_flush
     *
     * @return void 반환값 없음
     */
    public function test_polling_branch_emits_padding_to_force_fcgid_flush(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        $this->assertMatchesRegularExpression(
            '/str_repeat\(\s*[\'"]\s*[\'"]\s*,\s*(6553[6-9]|65[6-9]\d{2}|6[6-9]\d{3}|[789]\d{4}|[1-9]\d{5,})\s*\)/',
            $pollingBranch,
            '폴링 분기는 echo 직후 65536 바이트 이상의 padding(str_repeat(공백)) 을 출력해야 한다 — '
            .'mod_fcgid (FcgidOutputBufferSize default 64KB) 가 응답을 즉시 flush 하도록 강제. '
            .'워커가 10분간 인라인 실행되는 동안 install-process.php 응답이 클라이언트에 도달하지 못해 '
            .'PollingMonitor 가 시작조차 못 하는 회귀를 차단.'
        );
    }

    /**
     * 공백 채움이 응답 JSON 출력 뒤, flush 호출 앞에 놓였는지 위치를 비교해 확인한다.
     *
     * 본문이 먼저 나가야 하고, flush 시점에 버퍼 임계를 넘겨야 송출이 일어난다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_pads_past_fcgid_buffer_before_flush
     *
     * @return void 반환값 없음
     */
    public function test_padding_position_is_after_json_echo_and_before_flush(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        $echoPos = strpos($pollingBranch, 'echo $responseJson;');
        $this->assertNotFalse($echoPos);

        $hasMatch = preg_match(
            '/str_repeat\(\s*[\'"]\s*[\'"]\s*,\s*\d+\s*\)/',
            $pollingBranch,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $hasMatch, 'padding str_repeat 호출이 분기 내에 존재해야 한다.');
        $paddingPos = $matches[0][1];

        $flushPos = strpos($pollingBranch, '@flush()', $echoPos);
        $this->assertNotFalse($flushPos);

        $this->assertGreaterThan(
            $echoPos,
            $paddingPos,
            'padding 은 echo $responseJson 이후에 출력되어야 한다 (그래야 응답 본문이 먼저 wire 에 들어감).'
        );
        $this->assertLessThan(
            $flushPos,
            $paddingPos,
            'padding 은 flush() 이전에 출력되어야 한다 (flush 시점에 64KB 임계 도달해야 mod_fcgid 가 송출).'
        );
    }

    /**
     * Content-Length 헤더가 공백 채움을 빼고 응답 JSON 의 바이트 수만 싣는지 확인한다.
     *
     * 브라우저는 그 길이만큼 읽고 요청을 끝내며 뒤따르는 채움은 무시한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_content_length_counts_json_only
     *
     * @return void 반환값 없음
     */
    public function test_content_length_remains_json_byte_count_only(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        // padding 은 wire 에는 들어가지만 Content-Length 는 JSON 바이트 수만 명시해야 한다.
        // 그래야 브라우저가 Content-Length 만큼만 읽고 fetch resolve 후 padding 은 무시.
        $this->assertMatchesRegularExpression(
            '/header\(\s*[\'"]Content-Length:\s*[\'"]\s*\.\s*strlen\(\s*\$responseJson\s*\)\s*\)/',
            $pollingBranch,
            'Content-Length 헤더는 padding 을 제외한 $responseJson 의 바이트 수만 명시해야 한다.'
        );
    }

    /**
     * zlib 압축 해제와 no-gzip 설정이 폴링 분기 안에서 응답 JSON 출력보다 앞에 오는지 위치를 비교해 확인한다.
     *
     * @scenario installation_mode=polling
     *
     * @effects polling_response_disables_output_compression_before_echo
     *
     * @return void 반환값 없음
     */
    public function test_output_disable_calls_precede_echo_response(): void
    {
        $pollingBranch = $this->extractPollingBranch();

        $echoPos = strpos($pollingBranch, 'echo $responseJson;');
        $this->assertNotFalse($echoPos, '폴링 분기에 `echo $responseJson;` 문장이 존재해야 한다.');

        // zlib.output_compression 비활성화 위치
        $hasMatch = preg_match(
            '/@?ini_set\(\s*[\'"]zlib\.output_compression[\'"]/',
            $pollingBranch,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $hasMatch, 'zlib.output_compression 비활성화가 분기 내에 존재해야 한다.');
        $zlibPos = $matches[0][1];
        $this->assertLessThan(
            $echoPos,
            $zlibPos,
            'zlib.output_compression 비활성화는 echo 이전에 실행되어야 한다.'
        );

        // apache_setenv(no-gzip)
        $hasMatch = preg_match(
            '/apache_setenv\(\s*[\'"]no-gzip[\'"]/',
            $pollingBranch,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $hasMatch, 'apache_setenv(no-gzip) 가 분기 내에 존재해야 한다.');
        $noGzipPos = $matches[0][1];
        $this->assertLessThan(
            $echoPos,
            $noGzipPos,
            'apache_setenv(no-gzip) 는 echo 이전에 실행되어야 한다.'
        );
    }

    /**
     * install-process.php 의 `if ($installationMode === 'polling') { ... }` 본문 추출.
     *
     * 분기 시작 위치에서 중괄호 깊이를 세어 짝이 맞는 닫는 중괄호까지 잘라 낸다. 찾지 못하면 테스트를 실패시킨다.
     *
     * @return string 여는 중괄호부터 닫는 중괄호까지의 폴링 분기 본문
     */
    private function extractPollingBranch(): string
    {
        $start = strpos($this->source, "if (\$installationMode === 'polling')");
        $this->assertNotFalse($start, '폴링 분기 if 문을 찾을 수 없습니다.');

        // 매칭되는 닫는 중괄호 찾기
        $braceStart = strpos($this->source, '{', $start);
        $this->assertNotFalse($braceStart);

        $depth = 0;
        $len = strlen($this->source);
        for ($i = $braceStart; $i < $len; $i++) {
            $ch = $this->source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $braceStart, $i - $braceStart + 1);
                }
            }
        }

        $this->fail('폴링 분기의 닫는 중괄호를 찾을 수 없습니다.');
    }
}
