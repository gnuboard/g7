<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Support\StoragePaths;
use Illuminate\Console\Command;

/**
 * storage 경로 분류(`config/g7-storage.php`)를 출력합니다.
 *
 * 백업 대상을 고르거나, 같은 DB 를 쓰는 서버를 여러 대 둘 때 서버끼리 함께 쓸 경로와 서버마다 따로 둘 경로를
 * 정할 때 본다. 파일·설정을 바꾸지 않는다. `--json` 은 스크립트가 읽는 형식이다.
 */
class StoragePathsCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'storage:paths
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 분류 목록을 출력합니다.
     *
     * @return int 종료 코드 (언제나 0)
     */
    public function handle(): int
    {
        $categories = [];
        foreach (StoragePaths::lists() as $category => $paths) {
            $categories[$category] = array_map(static fn (string $path): array => [
                'path' => $path,
                'exists' => file_exists(storage_path($path)),
            ], $paths);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'storage_root' => storage_path(),
                'categories' => $categories,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('<options=bold>'.__('common.console.storage_paths.title').'</>');
        $this->line(__('common.console.storage_paths.storage_root', ['path' => storage_path()]));
        $this->newLine();

        $rows = [];
        foreach ($categories as $category => $entries) {
            foreach ($entries as $entry) {
                $rows[] = [
                    __('common.console.storage_paths.categories.'.$category),
                    $entry['path'],
                    $entry['exists'] ? __('common.console.storage_paths.exists_yes') : __('common.console.storage_paths.exists_no'),
                ];
            }
        }

        $this->table([
            __('common.console.storage_paths.col_category'),
            __('common.console.storage_paths.col_path'),
            __('common.console.storage_paths.col_exists'),
        ], $rows);

        $this->newLine();
        $this->line(__('common.console.storage_paths.note'));

        return self::SUCCESS;
    }
}
