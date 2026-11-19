<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 설치 마무리가 기존 `.env` 를 보존하는지 실측한다.
 *
 * `.env` 는 `.env.example` 템플릿에서 **새로 만들어져 통째로 대체**된다. 그래서 운영자가
 * 설치 전에 넣어 둔 값(메일·큐·레디스·서드파티 키)은 설치가 **성공해도** 사라지고, 되돌릴
 * 방법이 없다 — 실패가 아니라 성공 경로의 데이터 소실이라 어떤 오류도 남지 않는다.
 *
 * 쓰기 자체는 부모 디렉토리 쓰기 권한을 요구하지 않으려고 rename 이 아닌 제자리 쓰기이므로
 * (그 근거는 finalize-env.php 의 쓰기 주석이 소유한다), 보존은 **쓰기 직전 사본**으로만
 * 가능하다. 그리고 사본이 「쓰기 직전」인지는 함수 단위로는 확인되지 않는다 — 호출이 쓰기
 * 뒤에 있으면 사본은 원본이 아니라 새 내용을 담는다. 그래서 이 검사는 finalize 흐름을
 * 임시 설치 루트에서 실제로 태우고 사본의 **내용**을 원본과 대조한다.
 *
 * 커버 축: env_state pre_existing·absent / finalize_outcome backup_written·no_backup / backup_content matches_original
 *
 * 효과 요약(마커 아님 — 평문): installer_finalize_backs_up_existing_env_before_overwriting, installer_finalize_without_existing_env_writes_no_backup
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerEnvBackupTest extends TestCase
{
    private string $root = '';

    /**
     * 임시 설치 루트에 폴더, 환경 템플릿, 완료 상태 파일, 런타임 파일을 깔아 finalize 흐름을 끝까지 태울 준비를 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('exec')) {
            $this->markTestSkipped('exec 가 없어 하위 프로세스로 finalize 흐름을 태울 수 없다');
        }

        $this->root = sys_get_temp_dir().'/g7-installer-finalize-'.bin2hex(random_bytes(4));

        foreach (['/storage/logs', '/storage/installer', '/storage/app'] as $dir) {
            mkdir($this->root.$dir, 0777, true);
        }

        file_put_contents($this->root.'/.env.example', implode("\n", [
            'APP_NAME=Laravel',
            'APP_URL=http://localhost',
            'DB_WRITE_HOST=127.0.0.1',
            'DB_WRITE_PORT=3306',
            'DB_WRITE_DATABASE=g7',
            'DB_WRITE_USERNAME=root',
            'DB_WRITE_PASSWORD=',
            'DB_PREFIX=g7_',
        ])."\n");

        file_put_contents($this->root.'/storage/installer-state.json', json_encode([
            'installation_status' => 'completed',
            'completed_tasks' => ['complete_flag'],
            'current_step' => 6,
            'g7_locale' => 'ko',
            'config' => [
                'app_name' => 'T',
                'app_url' => 'http://t.test',
                'db_write_host' => '127.0.0.1',
                'db_write_database' => 'g7',
                'db_write_username' => 'root',
            ],
        ]));

        // finalize 는 runtime.php 가 없으면 「이미 끝났다」로 보고 즉시 돌아간다 — 흐름을
        // 끝까지 태우려면 그 파일이 있어야 한다.
        file_put_contents(
            $this->root.'/storage/installer/runtime.php',
            "<?php\n\nreturn ".var_export(['db' => ['write_password' => 'secret']], true).";\n"
        );
    }

    /**
     * 임시 설치 루트를 통째로 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * 주어진 폴더 아래를 재귀로 비우고 폴더 자체도 지운다.
     *
     * @param  string  $path  지울 폴더 경로(빈 값이거나 폴더가 아니면 아무것도 하지 않음)
     * @return void 반환값 없음
     */
    private function removeTree(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }

    /**
     * 임시 설치 루트에서 finalize 를 태운다.
     *
     * @return string 표준출력(표준오류 포함)
     */
    private function runFinalize(): string
    {
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/stubs/finalize_env_probe.php')
            .' '.escapeshellarg($this->root)
            .' 2>&1';

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }

    /**
     * 임시 설치 루트에 남은 환경 파일 사본 이름을 모은다.
     *
     * @return array<int, string> `.env.bak.*` 목록
     */
    private function backups(): array
    {
        return array_values(array_filter(
            scandir($this->root) ?: [],
            static fn (string $entry): bool => str_starts_with($entry, '.env.bak.'),
        ));
    }

    /**
     * 운영자가 넣어 둔 기존 환경 파일이 덮어쓰기 전에 사본으로 하나 남고, 그 사본 내용이 원본과 같은지 확인한다.
     *
     * @scenario endpoint_guard=finalize_allowed
     *
     * @effects installer_finalize_backs_up_existing_env_before_overwriting
     *
     * @return void 반환값 없음
     */
    public function test_existing_env_is_backed_up_before_being_overwritten(): void
    {
        $original = "APP_NAME=Legacy\nMAIL_MAILER=smtp\nMAIL_HOST=mail.example.test\nREDIS_PASSWORD=keepme\n";
        file_put_contents($this->root.'/.env', $original);

        $output = $this->runFinalize();

        $this->assertStringNotContainsString('Fatal error', $output, 'finalize 가 치명 종료했다: '.$output);

        // 흐름이 실제로 쓰기까지 갔는지 먼저 확인한다 — 여기서 멈추면 아래 단언이 공허해진다.
        $written = (string) file_get_contents($this->root.'/.env');
        $this->assertStringContainsString('DB_WRITE_DATABASE=g7', $written, 'finalize 가 .env 를 쓰지 않았다: '.$output);

        $backups = $this->backups();

        $this->assertCount(1, $backups, '기존 .env 의 사본이 남지 않았다 — 운영자가 넣어 둔 값이 되돌릴 수 없이 사라진다');
        $this->assertSame(
            $original,
            (string) file_get_contents($this->root.'/'.$backups[0]),
            '사본 내용이 원본과 다르다 — 보존 호출이 쓰기보다 뒤에 있으면 사본은 새 내용을 담는다'
        );
    }

    /**
     * 기존 환경 파일이 없는 첫 설치에서는 사본이 생기지 않는지 확인한다.
     *
     * @scenario endpoint_guard=finalize_allowed
     *
     * @effects installer_finalize_without_existing_env_writes_no_backup
     *
     * @return void 반환값 없음
     */
    public function test_first_install_leaves_no_backup(): void
    {
        // 첫 설치에는 보존할 원본이 없다 — 빈 사본을 만들어 두면 그것이 곧 잡음이다.
        $this->assertFileDoesNotExist($this->root.'/.env');

        $this->runFinalize();

        $this->assertSame([], $this->backups(), '원본이 없는데 사본이 생겼다');
    }
}
