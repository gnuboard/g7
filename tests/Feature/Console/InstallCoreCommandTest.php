<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\InstallCoreCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `php artisan core:install` 이 본체(`g7-install`)에 인자를 그대로 넘기고 종료 코드를 돌려주는지 검증한다.
 *
 * 본체 대신 받은 인자를 파일에 적고 정해진 코드로 끝나는 가짜 스크립트를 쓴다 — 실제 설치를 돌리지 않는다.
 */
class InstallCoreCommandTest extends TestCase
{
    /** 가짜 본체 경로 */
    private string $fakeScript = '';

    /** 가짜 본체가 인자를 적는 파일 */
    private string $argsFile = '';

    /**
     * 가짜 본체를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/g7-core-install-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        $this->argsFile = $dir.'/args.json';
        $this->fakeScript = $dir.'/g7-install';
        file_put_contents($this->fakeScript, '<?php file_put_contents('.var_export($this->argsFile, true).', json_encode(array_slice($argv, 1))); exit(5);');
    }

    /**
     * 가짜 본체 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        @unlink($this->argsFile);
        @unlink($this->fakeScript);
        @rmdir(dirname($this->fakeScript));

        parent::tearDown();
    }

    /**
     * 가짜 본체를 쓰는 명령 인스턴스.
     *
     * @param  string  $script  본체 경로
     * @return InstallCoreCommand 명령
     */
    private function command(string $script): InstallCoreCommand
    {
        $command = new class($script) extends InstallCoreCommand
        {
            /**
             * 가짜 본체 경로를 받는다.
             *
             * @param  string  $script  본체 경로
             */
            public function __construct(private string $script)
            {
                parent::__construct();
            }

            /**
             * 가짜 본체 경로.
             *
             * @return string 경로
             */
            protected function scriptPath(): string
            {
                return $this->script;
            }

            /**
             * 시험 실행기의 STDOUT 을 넘기지 않는다 — 넘기면 Windows 에서 자식 종료 뒤 실행기 출력이 끊기거나
             * 로그 파일 앞부분을 덮어써, 묶음 실행이 요약 없이 종료 코드 0 으로 끝난다.
             *
             * @return array<int, mixed> 널 장치 디스크립터
             */
            protected function descriptors(): array
            {
                $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

                return [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
            }
        };
        $command->setLaravel($this->app);

        return $command;
    }

    /**
     * `core:install` 뒤 인자를 그대로 넘기고 본체 종료 코드를 돌려준다.
     *
     * @effects core_install_forwards_arguments_and_exit_code
     *
     * @return void 반환값 없음
     */
    public function test_forwards_arguments_and_exit_code(): void
    {
        $previous = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'core:install', '--locale=en', '-n', '--modules=sirsoft-board'];

        try {
            $code = $this->command($this->fakeScript)->run(new ArrayInput([]), new BufferedOutput);
        } finally {
            $_SERVER['argv'] = $previous;
        }

        $this->assertSame(5, $code);
        $this->assertSame(['--locale=en', '-n', '--modules=sirsoft-board'], json_decode((string) file_get_contents($this->argsFile), true));
    }

    /**
     * 본체가 없으면 실패 코드로 끝난다.
     *
     * @effects core_install_fails_without_script
     *
     * @return void 반환값 없음
     */
    public function test_fails_when_script_is_missing(): void
    {
        $output = new BufferedOutput;
        $code = $this->command($this->fakeScript.'-missing')->run(new ArrayInput([]), $output);

        $this->assertSame(1, $code);
    }

    /**
     * artisan 에 등록되어 있다.
     *
     * @effects core_install_is_registered
     *
     * @return void 반환값 없음
     */
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('core:install', Artisan::all());
    }
}
