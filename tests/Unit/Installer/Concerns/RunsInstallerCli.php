<?php

namespace Tests\Unit\Installer\Concerns;

/**
 * 터미널 설치(`g7_install_cli_main()`)를 임시 BASE_PATH 서브프로세스에서 실행한다.
 *
 * `RunsInstallerSubprocess` 의 script 모드를 쓴다 — 실제 저장소 루트(.env·storage)를 건드리지 않는다.
 * 환경에 묶인 단계(요구사항 판정·DB 연결·설치 작업)는 CLI 파일들이 `function_exists` 로 감싸 정의하므로,
 * `$prelude` 에서 같은 이름의 함수를 먼저 정의해 주입한다(본체 규칙은 그대로 탄다).
 */
trait RunsInstallerCli
{
    use RunsInstallerSubprocess;

    /**
     * 터미널 설치 본체를 실행한다.
     *
     * @param  array<int, string>  $args  인자 (스크립트 이름 제외)
     * @param  array<string, string>  $env  환경변수 (본체에 그대로 넘긴다 — 실제 환경은 쓰지 않는다)
     * @param  string  $prelude  본체 로드 전에 실행할 PHP (주입용 함수 정의 등)
     * @return array{exit: int|null, events: array<int, array<string, mixed>>, raw: string} 종료 코드·JSON 이벤트·원문
     */
    protected function runCli(array $args, array $env = [], string $prelude = ''): array
    {
        $argv = var_export(array_merge(['g7-install'], $args), true);
        $envExport = var_export($env, true);

        $script = $prelude."\n"
            ."require_once \$projectRoot.'/public/install/includes/cli/app.php';\n"
            ."\$__code = g7_install_cli_main({$argv}, {$envExport});\n"
            ."echo \"\\n__CLI_EXIT__=\".\$__code.\"\\n\";\n";

        return $this->parseCliResult($this->runInstallerScript($script));
    }

    /**
     * 본체를 읽어 들인 뒤 임의 코드를 실행한다 (단위 함수 검사용).
     *
     * @param  string  $body  실행할 PHP (결과는 `echo '__RESULT__='.json_encode(...)` 로 낸다)
     * @param  string  $prelude  본체 로드 전에 실행할 PHP
     * @return array{exit: int|null, events: array<int, array<string, mixed>>, raw: string, result: mixed} 실행 결과
     */
    protected function runCliCode(string $body, string $prelude = ''): array
    {
        $script = $prelude."\n"
            ."require_once \$projectRoot.'/public/install/includes/cli/app.php';\n"
            .'$GLOBALS[\'translations\'] = loadTranslations(\'ko\');'."\n"
            .$body."\n";

        $result = $this->parseCliResult($this->runInstallerScript($script));
        $result['result'] = preg_match('/__RESULT__=(.*)$/m', $result['raw'], $m) ? json_decode($m[1], true) : null;

        return $result;
    }

    /**
     * 설치 완료 표식을 둔다 (`storage/app/g7_installed`).
     *
     * @return void 반환값 없음
     */
    protected function markInstalled(): void
    {
        file_put_contents($this->installerTempBase.'/storage/app/g7_installed', date('c'));
    }

    /**
     * 임시 BASE_PATH 에 파일을 쓴다 (상위 디렉터리 생성 포함).
     *
     * @param  string  $relative  BASE_PATH 기준 경로
     * @param  string  $content  내용
     * @return string 절대 경로
     */
    protected function putBaseFile(string $relative, string $content = 'x'): string
    {
        $path = $this->installerTempBase.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * JSON 출력의 이벤트 중 하나를 고른다.
     *
     * @param  array<int, array<string, mixed>>  $events  이벤트
     * @param  string  $name  이벤트 이름
     * @return array<int, array<string, mixed>> 해당 이벤트
     */
    protected function eventsNamed(array $events, string $name): array
    {
        return array_values(array_filter($events, static fn (array $e) => ($e['event'] ?? null) === $name));
    }

    /**
     * 하네스 결과에서 종료 코드와 JSON 줄을 뽑는다.
     *
     * @param  array{raw: string}  $run  하네스 결과
     * @return array{exit: int|null, events: array<int, array<string, mixed>>, raw: string} 정리한 결과
     */
    private function parseCliResult(array $run): array
    {
        $raw = (string) $run['raw'];
        $events = [];
        // \R 는 u 플래그 없이 쓰면 0x85(NEL)도 줄바꿈으로 보아 한글 UTF-8 바이트 중간에서 줄을 자른다.
        foreach (explode("\n", str_replace("\r\n", "\n", $raw)) as $line) {
            if (str_starts_with($line, '{"event"')) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        }

        return [
            'exit' => preg_match('/__CLI_EXIT__=(\d+)/', $raw, $m) ? (int) $m[1] : null,
            'events' => $events,
            'raw' => $raw,
        ];
    }
}
