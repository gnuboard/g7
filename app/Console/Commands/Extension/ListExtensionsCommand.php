<?php

namespace App\Console\Commands\Extension;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Services\Extension\ExtensionInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Lang;

/**
 * 설치·번들 확장의 상태(설치·활성 여부, DB·활성 폴더·번들 판, 저장된 업데이트 가능 여부)를 출력합니다.
 *
 * 비대화형 실행(cron·스크립트)이 `*:install`·`*:activate` 를 먼저 쳐 보고 종료 코드로 상태를 추측하지 않도록, 읽기 전용
 * 조회 창구를 둔다. DB·캐시·파일을 쓰지 않고 GitHub 도 조회하지 않는다(`*:check-updates` 는 DB 를 갱신한다).
 * `--json` 출력 형식은 `schema` 번호로 고정한다.
 *
 * @since 7.2.0
 */
class ListExtensionsCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'extension:list
        {--type=all : 종류 (all|module|plugin|template|language-pack)}
        {--json : 결과를 JSON 으로 출력 (schema 1)}';

    /**
     * 상태를 읽어 출력합니다.
     *
     * @param  ExtensionInventoryService  $inventory  확장 상태 조회 서비스
     * @return int 종료 코드 — 0 성공, 1 설치 전(확장 테이블 없음), 2 알 수 없는 종류
     */
    public function handle(ExtensionInventoryService $inventory): int
    {
        $type = (string) $this->option('type');

        if ($type !== 'all' && ! in_array($type, ExtensionInventoryService::TYPES, true)) {
            $this->error(__('extensions.commands.list.invalid_type', ['type' => $type]));

            return self::INVALID;
        }

        if (! $inventory->isInstalled()) {
            $message = (string) __('extensions.commands.list.not_installed');

            if ($this->option('json')) {
                $this->line((string) json_encode(['schema' => ExtensionInventoryService::SCHEMA, 'error' => 'not_installed', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('❌ '.$message);
            }

            return self::FAILURE;
        }

        $snapshot = $inventory->snapshot($type === 'all' ? null : $type);

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderTable($snapshot);

        return self::SUCCESS;
    }

    /**
     * 사람이 읽는 표로 출력합니다.
     *
     * @param  array{schema: int, core_version: string|null, core_version_on_disk: string|null, items: list<array<string, mixed>>}  $snapshot  상태
     * @return void 반환값 없음
     */
    private function renderTable(array $snapshot): void
    {
        $this->info(__('extensions.commands.list.core_versions', [
            'running' => $snapshot['core_version'] ?? '-',
            'disk' => $snapshot['core_version_on_disk'] ?? '-',
        ]));

        if ($snapshot['items'] === []) {
            $this->line(__('extensions.commands.list.empty'));

            return;
        }

        $this->table(
            __('extensions.commands.list.columns'),
            array_map(fn (array $item) => [
                $this->label('type_labels', $item['type']).($item['template_type'] !== null ? " ({$item['template_type']})" : ''),
                $item['identifier'],
                $this->label('status_labels', $item['status']),
                $item['version'] ?? '-',
                $item['active_dir_version'] ?? '-',
                $item['bundled_version'] ?? '-',
                match ($item['update_available']) {
                    true => __('extensions.commands.list.yes'),
                    false => __('extensions.commands.list.no'),
                    default => '-',
                },
                $item['vendor_mode'] !== null ? $this->label('vendor_mode_labels', $item['vendor_mode']) : '-',
                $item['layout_modified_count'] ?? '-',
            ], $snapshot['items']),
        );

        $this->line(__('extensions.commands.list.summary', [
            'count' => count($snapshot['items']),
            'installed' => count(array_filter($snapshot['items'], fn (array $item) => $item['status'] !== 'not_installed')),
        ]));
    }

    /**
     * 사람이 읽는 표에 쓸 값 이름을 돌려줍니다 — JSON 출력은 원래 값을 그대로 쓴다.
     *
     * @param  string  $group  이름 묶음 (type_labels|status_labels|vendor_mode_labels)
     * @param  string  $value  원래 값
     * @return string 이름 (번역이 없으면 원래 값)
     */
    private function label(string $group, string $value): string
    {
        $key = "extensions.commands.list.{$group}.{$value}";

        return Lang::has($key) ? (string) __($key) : $value;
    }
}
