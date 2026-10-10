<?php

namespace Tests\Unit\Installer\Concerns;

/**
 * 인스톨러 코드를 임시 앱 루트로 격리된 PHP 프로세스에서 실행한다.
 *
 * 실 프로젝트의 installer-state.json·runtime.php·.env 를 절대 건드리지 않도록, 모든 파일 쓰기는
 * 시스템 temp 아래 임시 루트에서만 일어난다(하네스가 temp 밖 경로를 거부). 한 프로세스에서 1회만
 * 정의되는 BASE_PATH 상수 문제를 서브프로세스로 원천 회피한다.
 */
trait RunsInstallerSubprocess
{
    /** 이번 테스트의 임시 앱 루트 */
    private ?string $installerTempBase = null;

    /**
     * 임시 앱 루트를 만든다 (storage/app·logs·installer, 세션 디렉터리 포함).
     *
     * @return string 임시 앱 루트 절대경로
     */
    protected function makeInstallerTempBase(): string
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-installer-sub-'.bin2hex(random_bytes(6));

        foreach (['storage/app', 'storage/logs', 'storage/installer', 'sessions'] as $dir) {
            @mkdir($base.'/'.$dir, 0777, true);
        }

        $this->installerTempBase = $base;

        return $base;
    }

    /**
     * 임시 앱 루트를 지운다 (tearDown 에서 호출).
     *
     * @return void 반환값 없음
     */
    protected function removeInstallerTempBase(): void
    {
        if ($this->installerTempBase !== null && is_dir($this->installerTempBase)) {
            $this->deleteTree($this->installerTempBase);
        }

        $this->installerTempBase = null;
    }

    /**
     * 임시 루트의 state.json 을 쓴다.
     *
     * @param  array<string, mixed>  $state  저장할 state
     * @return void 반환값 없음
     */
    protected function writeInstallerState(array $state): void
    {
        file_put_contents($this->installerTempBase.'/storage/installer-state.json', json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * 임시 루트의 state.json 을 읽는다.
     *
     * @return array<string, mixed>|null 디스크의 state (없으면 null)
     */
    protected function readInstallerState(): ?array
    {
        $path = $this->installerTempBase.'/storage/installer-state.json';

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }

    /**
     * 임시 루트의 runtime.php 를 쓴다.
     *
     * @param  array<string, mixed>  $runtime  runtime 배열
     * @return void 반환값 없음
     */
    protected function writeInstallerRuntimeFile(array $runtime): void
    {
        file_put_contents(
            $this->installerTempBase.'/storage/installer/runtime.php',
            "<?php\n\nreturn ".var_export($runtime, true).";\n"
        );
    }

    /**
     * 임시 루트의 runtime.php 를 읽는다.
     *
     * @return array<string, mixed>|null runtime 배열
     */
    protected function readInstallerRuntimeFile(): ?array
    {
        $path = $this->installerTempBase.'/storage/installer/runtime.php';
        if (! is_file($path)) {
            return null;
        }

        // opcache 가 켜진 환경에서도 방금 쓴 내용을 읽도록 문자열로 평가한다
        return eval('?>'.file_get_contents($path));
    }

    /**
     * 인스톨러 API 엔드포인트를 서브프로세스로 실행한다.
     *
     * @param  string  $endpoint  public/install/api 기준 파일명 (예: install-process.php)
     * @param  array<string, mixed>  $spec  하네스 spec (method/get/post/headers/session_id/session)
     * @return array{status: int, body: array<string, mixed>|null, raw: string, session: array<string, mixed>} 상태 코드·JSON 본문·원문 출력·종료 시 세션
     */
    protected function runInstallerEndpoint(string $endpoint, array $spec = []): array
    {
        $spec['mode'] = 'endpoint';
        $spec['endpoint'] = dirname(__DIR__, 4).'/public/install/api/'.$endpoint;

        return $this->runInstallerHarness($spec);
    }

    /**
     * 인스톨러 include 를 로드한 서브프로세스에서 PHP 코드를 실행한다.
     *
     * @param  string  $script  실행할 PHP 코드 (<?php 없이)
     * @param  array<string, mixed>  $spec  추가 spec (session_id/session)
     * @return array{status: int, body: array<string, mixed>|null, raw: string, session: array<string, mixed>} 상태 코드·JSON 본문·원문 출력·종료 시 세션
     */
    protected function runInstallerScript(string $script, array $spec = []): array
    {
        $spec['mode'] = 'script';
        $spec['script'] = $script;

        return $this->runInstallerHarness($spec);
    }

    /**
     * 하네스 공통 실행.
     *
     * @param  array<string, mixed>  $spec  하네스 spec
     * @return array{status: int, body: array<string, mixed>|null, raw: string, session: array<string, mixed>} 상태 코드·JSON 본문·원문 출력·종료 시 세션
     */
    private function runInstallerHarness(array $spec): array
    {
        $spec['base_path'] = $this->installerTempBase;
        $specPath = $this->installerTempBase.'/spec-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($specPath, json_encode($spec));

        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__).'/stubs/installer_subprocess_harness.php', $specPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($specPath);

        $status = preg_match('/__HARNESS_STATUS__=(\d+)/', $stdout, $m) ? (int) $m[1] : 0;
        $session = preg_match('/__HARNESS_SESSION__=(.*)$/m', $stdout, $s) ? (json_decode($s[1], true) ?: []) : [];
        $body = trim((string) preg_replace('/\n__HARNESS_STATUS__=.*$/s', '', $stdout));
        // 폴링 응답의 64KB 패딩 등 뒤따르는 공백을 제거한 뒤 첫 JSON 만 해석
        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $stdout.$stderr,
            'session' => $session,
        ];
    }

    /**
     * 디렉터리를 재귀 삭제한다.
     *
     * @param  string  $dir  디렉터리
     * @return void 반환값 없음
     */
    private function deleteTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
