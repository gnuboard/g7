<?php

namespace Tests\Feature\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 새 `bootstrap/app.php` 가 업데이트 트리 안에서 부팅될 때 옛 부모가 물려준 APP_VERSION 을 디스크 값으로
 * 바로잡는지 실제 별도 PHP 프로세스로 확인합니다 (#145, #137).
 *
 * Laravel 의 `config:cache` 는 어느 프로세스에서 돌든 `bootstrap/app.php` 를 디스크에서 새로 require 해 일회용
 * 앱을 만든다. 그래서 이 파일은 옛 부모(7.0.0~7.0.10) 안의 config:cache 와 그 부모가 띄운 모든 새 코드 자식에서
 * 실행된다 — 옛 부모 코드를 고치지 않고 오염을 막을 수 있는 유일한 지점이다.
 *
 * 자식은 저장소 `.env` 를 읽기만 한다(쓰지 않음). 업데이트 트리 플래그가 있으면 기존 자가 치유 블록이 패키지
 * 매니페스트를 지우고, 다음 부팅이 다시 만든다(업데이트 자식과 같은 동작).
 *
 * 트러블슈팅 `[case:backend-54]` 의 회귀 그물이다.
 */
class UpdateAppVersionReconcileProcessTest extends TestCase
{
    /**
     * 별도 PHP 프로세스에서 `bootstrap/app.php` 를 require 한 뒤 getenv/$_SERVER 의 APP_VERSION 을 돌려받습니다.
     *
     * @param  array<string, string|null>  $overrides  자식 env 덮어쓰기 (null 이면 제거)
     * @return array{getenv: string, server: string} 자식이 본 APP_VERSION
     */
    private function bootChild(array $overrides): array
    {
        $env = getenv();
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($env[$key]);
            } else {
                $env[$key] = $value;
            }
        }
        // 테스트 프로세스의 testing 플래그가 자식 판정에 섞이지 않도록 Laravel 테스트 env 는 넘기지 않는다.
        unset($env['APP_ENV']);

        // Windows 의 escapeshellarg 는 큰따옴표를 공백으로 바꿔 `php -r` 본문이 깨지므로 임시 스크립트 파일로 실행한다.
        $tempBase = (string) tempnam(sys_get_temp_dir(), 'g7boot');
        $scriptPath = $tempBase.'.php';
        file_put_contents($scriptPath, '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; require '.var_export(base_path('bootstrap/app.php'), true).';'
            .' echo json_encode(["getenv" => (string) getenv("APP_VERSION"), "server" => (string) ($_SERVER["APP_VERSION"] ?? "")]);');

        try {
            $process = proc_open([PHP_BINARY, $scriptPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($scriptPath);
            @unlink($tempBase);
        }

        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, '자식 출력이 JSON 이 아님: '.$stdout.$stderr);

        return $decoded;
    }

    /**
     * 저장소 `.env` 의 APP_VERSION 을 읽습니다.
     *
     * @return string 디스크 버전
     */
    private function diskVersion(): string
    {
        $this->assertTrue(is_file(base_path('.env')), '저장소 .env 가 있어야 한다');
        preg_match('/^APP_VERSION=(.+)$/m', (string) file_get_contents(base_path('.env')), $m);

        return trim($m[1] ?? '', " \t\r\"'");
    }

    /**
     * 업데이트 플래그 + 낮은 상속 버전 → 부팅 직후 디스크 버전으로 바로잡힌다.
     *
     * @scenario case=flagged_stale_child
     *
     * @effects new_bootstrap_reconciles_inherited_app_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_flagged_child_with_stale_version_is_reconciled(): void
    {
        $result = $this->bootChild(['G7_UPDATE_IN_PROGRESS' => '1', 'APP_VERSION' => '7.0.0']);

        $this->assertSame($this->diskVersion(), $result['getenv']);
        $this->assertSame($this->diskVersion(), $result['server']);
    }

    /**
     * 업데이트 트리 밖에서는 손대지 않는다 (웹·크론 경로 비용 0, 동작 불변).
     *
     * @scenario case=unflagged_child
     *
     * @effects reconcile_runs_only_inside_update_tree
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_unflagged_child_is_untouched(): void
    {
        $result = $this->bootChild(['G7_UPDATE_IN_PROGRESS' => null, 'APP_VERSION' => '7.0.0']);

        $this->assertSame('7.0.0', $result['getenv']);
    }

    /**
     * 디스크보다 높은 상속 버전(업그레이드 스텝 자식의 toVersion)은 그대로 둔다.
     *
     * @scenario case=upgrade_step_child_higher
     *
     * @effects reconcile_never_lowers_upgrade_step_child_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_higher_inherited_version_is_kept(): void
    {
        $result = $this->bootChild(['G7_UPDATE_IN_PROGRESS' => '1', 'APP_VERSION' => '99.0.0']);

        $this->assertSame('99.0.0', $result['getenv']);
    }
}
