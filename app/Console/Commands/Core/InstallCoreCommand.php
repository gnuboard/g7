<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use Illuminate\Console\Command;

/**
 * 터미널 설치 명령(`php g7-install`)의 artisan 별칭.
 *
 * 설치 본체는 vendor 없이도 돌아야 해서 루트의 순수 PHP 스크립트 `g7-install` 에 있다. vendor 가 이미 있는
 * 환경에서 artisan 으로도 부를 수 있게, 이 명령은 받은 인자를 그대로 본체에 넘기고 그 종료 코드를 돌려준다.
 * 표준 입출력을 그대로 물려주므로 대화형 질문도 같은 터미널에서 이어진다. 확인 질문은 본체가 하므로 여기서는 묻지 않는다.
 */
class InstallCoreCommand extends Command
{
    use LocalizesDescription;

    /**
     * 명령 시그니처 — 옵션은 본체가 해석한다(ignoreValidationErrors).
     *
     * @var string
     */
    protected $signature = 'core:install';

    /**
     * 옵션 검증을 끄고 본체에 그대로 넘긴다.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * 본체 스크립트를 같은 PHP 로 실행하고 종료 코드를 그대로 돌려준다.
     *
     * @return int 본체 종료 코드
     */
    public function handle(): int
    {
        $script = $this->scriptPath();
        if (! is_file($script)) {
            $this->error(__('common.console.core_install.script_missing', ['path' => $script]));

            return self::FAILURE;
        }

        // audit:allow spawn-after-vendor-swap-clears-package-manifest 설치 별칭은 vendor 를 교체하지 않는다 — 본체가 설치 단계에서 직접 캐시를 정리한다
        // audit:allow update-child-app-version-reconcile 코어 업데이트 트리가 아니다 — 설치 본체는 Laravel 을 부팅하지 않는 순수 PHP 다
        $process = proc_open(
            [PHP_BINARY, $script, ...$this->forwardedArguments()],
            $this->descriptors(),
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            $this->error(__('common.console.core_install.process_failed'));

            return self::FAILURE;
        }

        return proc_close($process);
    }

    /**
     * 설치 본체 스크립트 경로.
     *
     * @return string 절대 경로
     */
    protected function scriptPath(): string
    {
        return base_path('g7-install');
    }

    /**
     * 본체에 물려줄 표준 입출력 — 대화형 질문이 같은 터미널에서 이어지도록 이 프로세스의 것을 그대로 쓴다.
     *
     * @return array<int, mixed> proc_open 디스크립터 (0 입력 · 1 출력 · 2 오류)
     */
    protected function descriptors(): array
    {
        return [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    }

    /**
     * 원래 명령줄에서 `core:install` 뒤의 인자.
     *
     * @return array<int, string> 넘길 인자
     */
    protected function forwardedArguments(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $index = array_search($this->getName(), $argv, true);

        return $index === false ? [] : array_values(array_slice($argv, $index + 1));
    }
}
