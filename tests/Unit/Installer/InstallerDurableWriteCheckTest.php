<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 설치가 만든 파일의 쓰기 실패가 성공으로 보고되지 않는다.
 *
 * 앞선 수정은 `g7_installed` 쓰기 하나에만 반환값 검사를 붙였다. 같은 파일의 형제인
 * `createSettingsJsonSSE()` 는 `mkdir` 과 `file_put_contents` 의 반환값을 **둘 다 버렸고**,
 * `storage/app` 이 쓰기 불가일 때 설정 파일이 하나도 생기지 않은 채 「설정 파일 생성 완료」가
 * 보고되고 `markTaskCompleted` 까지 진행됐다. 설치는 완주하고 사이트는 기본 설정 없이 뜬다 —
 * 오류도 경고도 로그도 남지 않는다.
 *
 * 이 검사는 **함수 본문을 중괄호로 잘라** 본다(고정 길이 창이 아니다). 그리고 「검사한다」를
 * 리터럴로 확인하는 대신 **「결과를 버리는 형태가 없다」**를 본다 — 반환값을 쓰는 방법은
 * 여러 가지(조건문·대입·부정)라 그것들을 열거하면 곧 사각이 생긴다.
 *
 * 커버 축: write_target settings_dir·settings_file·installed_flag / result_used checked·discarded
 */
class InstallerDurableWriteCheckTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 함수 본문에서 지정 함수의 반환값을 버리는 줄만 골라낸다.
     *
     * 결과를 버리는 호출 = 그 줄이 **호출로 시작해서** `;` 로 끝나는 형태.
     *
     * `if (@mkdir(...))` · `$ok = file_put_contents(...)` · `! @mkdir(...)` 는 전부 결과를 쓴다.
     *
     * @param  string  $body  함수 본문 (주석 제거됨)
     * @param  string  $callee  검사할 함수 이름
     * @return array<int, string> 결과를 버린 줄 목록
     */
    private function discardedResultCalls(string $body, string $callee): array
    {
        $discarded = [];

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^@?'.preg_quote($callee, '/').'\s*\(/', $trimmed) === 1) {
                $discarded[] = $trimmed;
            }
        }

        return $discarded;
    }

    /**
     * 설정 디렉토리 생성, 설정 파일 저장, 완료 표식 저장의 세 쓰기 지점을 공급한다.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름별 [검사할 함수, 쓰기 함수]
     */
    public static function durableWriteProvider(): array
    {
        return [
            '설정 디렉토리 생성' => ['createSettingsJsonSSE', 'mkdir'],
            '설정 파일 저장' => ['createSettingsJsonSSE', 'file_put_contents'],
            '완료 표식 저장 (형제)' => ['setInstallationCompleteSSE', 'file_put_contents'],
        ];
    }

    /**
     * 태스크 러너의 해당 함수 본문에 쓰기 함수 반환값을 버리는 줄이 하나도 없는지 확인한다.
     *
     * @scenario task=settings, task=complete_flag
     *
     * @effects installer_durable_writes_never_discard_their_result
     *
     * @param  string  $function  본문을 잘라 볼 태스크 러너 함수 이름
     * @param  string  $callee  반환값을 확인해야 하는 쓰기 함수 이름
     * @return void 반환값 없음
     */
    #[DataProvider('durableWriteProvider')]
    public function test_durable_write_results_are_not_discarded(string $function, string $callee): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php')
        );

        $body = $this->functionBody($source, $function);

        $this->assertNotSame('', $body, $function.'() 를 찾지 못했다 — 이름이 바뀌었으면 이 검사도 함께 옮긴다');

        $this->assertSame(
            [],
            $this->discardedResultCalls($body, $callee),
            $function.'() 의 '.$callee.'() 결과를 버리면, 아무것도 저장되지 않아도 「완료」가 보고된다',
        );
    }
}
