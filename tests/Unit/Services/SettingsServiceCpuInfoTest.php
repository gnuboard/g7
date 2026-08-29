<?php

namespace Tests\Unit\Services;

use App\Support\ProcessOutputEncoding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 시스템 정보(CPU) 수집의 인코딩 계약 테스트.
 *
 * 배경 (gnuboard/g7#62 와 동형):
 *   `getCpuInfo()` 는 `powershell … 2>&1` / `wmic … 2>&1` 의 출력을 문자열로 반환한다.
 *   `2>&1` 로 합쳐진 오류 문장은 시스템 코드페이지(한국어 Windows = CP949)로 나오므로
 *   정규화 없이 반환하면 그 값이 시스템 정보 API 응답에 실려 JsonResponse 직렬화가
 *   Malformed UTF-8 로 실패한다 — 관리자 환경설정 화면이 500 이 된다.
 *
 *   `safeSystemProbe` 는 probe 자체의 예외만 잡고 직렬화 예외는 잡지 못하므로
 *   출처에서 정규화하는 것이 유일한 차단 지점이다.
 *
 * `getCpuInfo()` 는 protected 이고 `shell_exec` 을 주입할 수 없으므로,
 * 이 테스트는 그 메서드가 의존하는 정규화 계약을 검사한다.
 */
class SettingsServiceCpuInfoTest extends TestCase
{
    /**
     * 한국어 Windows 의 powershell 오류 문장을 재현한 CP949 표본.
     */
    private const ERROR_SENTENCE = "'powershell'은(는) 내부 또는 외부 명령으로 인식되지 않습니다.";

    #[Test]
    public function cp949_shell_error_sentence_becomes_serializable(): void
    {
        $raw = (string) mb_convert_encoding(self::ERROR_SENTENCE, 'CP949', 'UTF-8');

        // 사전 조건: 이 바이트가 실제로 직렬화를 무력화해야 의미가 있다.
        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'));
        $this->assertFalse(json_encode(['cpu' => trim($raw)]));

        $normalized = ProcessOutputEncoding::normalize($raw);

        $this->assertSame(self::ERROR_SENTENCE, $normalized, '한글 오류 문장이 원문대로 복원되어야 한다.');
        $this->assertNotFalse(
            json_encode(['cpu' => trim($normalized)]),
            '정규화 후에는 시스템 정보 응답이 직렬화되어야 한다 (500 차단).'
        );
    }

    /**
     * 정규화가 기존 판정 로직을 바꾸지 않아야 한다.
     *
     * 오류 문장이 CPU 이름으로 보이는 표시 문제는 이번 범위 밖이며,
     * 영문 'error' 판정은 그대로 유지된다 (기능 축소 없음).
     */
    #[Test]
    public function normalization_preserves_existing_error_detection(): void
    {
        $englishError = "wmic : The term 'wmic' is not recognized. ".
            'FullyQualifiedErrorId : CommandNotFoundException';

        $normalized = ProcessOutputEncoding::normalize($englishError);

        $this->assertSame($englishError, $normalized, '유효 UTF-8 출력은 변형되지 않아야 한다.');
        $this->assertStringContainsString(
            'error',
            strtolower($normalized),
            '기존 error 판정이 정규화 후에도 동일하게 동작해야 한다.'
        );
    }

    /**
     * 정상적인 CPU 이름은 한 글자도 변하지 않아야 한다.
     */
    #[Test]
    public function normal_cpu_name_is_unchanged(): void
    {
        $name = 'AMD Ryzen 9 7950X 16-Core Processor';

        $this->assertSame($name, ProcessOutputEncoding::normalize($name));
    }

    /**
     * getCpuInfo() 가 정규화를 실제로 경유하는지 소스 레벨 회귀 검증.
     *
     * 정규화를 걷어내면 이 클래스의 나머지 계약이 전부 통과해도 500 이 되살아난다.
     */
    #[Test]
    public function get_cpu_info_source_routes_shell_output_through_normalizer(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/SettingsService.php');

        $start = strpos($source, 'protected function getCpuInfo()');
        $this->assertNotFalse($start, 'getCpuInfo() 를 찾을 수 없습니다.');

        $body = substr($source, $start, 2000);

        // shell_exec 출력을 소비하는 두 분기(powershell / wmic) 모두 정규화를 거쳐야 한다.
        $this->assertSame(
            2,
            substr_count($body, 'ProcessOutputEncoding::normalize('),
            'powershell·wmic 두 분기 모두 외부 출력을 정규화해야 한다 — '.
            '한쪽만 막으면 폴백 경로에서 같은 500 이 재발한다.'
        );
    }
}
