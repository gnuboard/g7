<?php

namespace App\Console\Commands\Custom;

use App\Console\Commands\Traits\LocalizesDescription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * custom 예약 자리에 학습용 견본 파일을 만든다 — `php artisan custom:example board`.
 *
 * 코어 릴리스는 예약 자리에 파일을 싣지 않는다. 견본은 이 명령이 `stubs/{sample}/` 아래 원본을
 * 같은 상대 경로로 복사해(`.stub` 접미사 제거) 만든다. 만들 파일 중 하나라도 이미 있으면
 * 아무것도 쓰지 않고 그 목록을 보여 준 뒤 중단한다(덮어쓰기 선택지는 두지 않는다).
 *
 * @since 7.2.0
 */
class CustomExampleCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string
     */
    protected $signature = 'custom:example
        {sample : 만들 견본 이름 (board)}';

    /**
     * 명령을 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $sample = (string) $this->argument('sample');
        $available = $this->availableSamples();

        if (! in_array($sample, $available, true)) {
            $this->error(__('settings.custom_reserved.example.unknown_sample', [
                'sample' => $sample,
                'available' => implode(', ', $available),
            ]));

            return self::FAILURE;
        }

        $files = $this->plannedFiles($sample);

        $existing = array_values(array_filter($files, fn (string $target) => file_exists($this->targetPath($target))));
        if ($existing !== []) {
            $this->error(__('settings.custom_reserved.example.exists'));
            foreach ($existing as $target) {
                $this->line('  '.$target);
            }

            return self::FAILURE;
        }

        foreach ($files as $source => $target) {
            File::ensureDirectoryExists(dirname($this->targetPath($target)));
            File::copy($source, $this->targetPath($target));
        }

        $this->info(__('settings.custom_reserved.example.created', ['count' => count($files)]));
        foreach ($files as $target) {
            $this->line('  '.$target);
        }
        $this->line(__('settings.custom_reserved.example.next_steps'));

        return self::SUCCESS;
    }

    /**
     * 견본 원본 디렉터리 경로를 반환합니다.
     *
     * @return string 디렉터리 경로
     */
    public static function stubRoot(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'stubs';
    }

    /**
     * 견본을 만들 루트 디렉터리를 반환합니다 (프로젝트 루트).
     *
     * @return string 디렉터리 경로
     */
    protected function targetRoot(): string
    {
        return base_path();
    }

    /**
     * 대상 상대 경로의 절대 경로를 반환합니다.
     *
     * @param  string  $relative  프로젝트 루트 기준 상대 경로
     * @return string 절대 경로
     */
    private function targetPath(string $relative): string
    {
        return rtrim($this->targetRoot(), '/\\').'/'.$relative;
    }

    /**
     * 만들 수 있는 견본 이름 목록을 반환합니다.
     *
     * @return array<int, string> 견본 이름
     */
    protected function availableSamples(): array
    {
        $names = array_map('basename', File::directories(self::stubRoot()));
        sort($names);

        return $names;
    }

    /**
     * 견본 원본 → 만들 파일(프로젝트 루트 기준 상대 경로) 목록을 반환합니다.
     *
     * @param  string  $sample  견본 이름
     * @return array<string, string> 원본 절대 경로 => 대상 상대 경로
     */
    protected function plannedFiles(string $sample): array
    {
        $root = self::stubRoot().DIRECTORY_SEPARATOR.$sample;
        $files = [];

        foreach (File::allFiles($root) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $files[$file->getPathname()] = preg_replace('/\.stub$/', '', $relative);
        }

        asort($files);

        return $files;
    }
}
