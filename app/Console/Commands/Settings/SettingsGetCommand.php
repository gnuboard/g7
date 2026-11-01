<?php

namespace App\Console\Commands\Settings;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Exceptions\SettingsConsoleException;
use App\Services\SettingsConsoleService;
use Illuminate\Console\Command;

/**
 * 설정 값을 서버 터미널에서 조회한다 — 키 없이 부르면 전체, 범주만 주면 그 범주 전부.
 *
 * 민감 값(비밀번호·비밀 키·토큰)은 `********` 로 가린다 — `--show-secrets` 일 때만 원문(경고 한 줄).
 * 다른 값에서 계산되는 항목은 「계산값」, `.env` 가 정하는 항목은 「.env 고정」으로 표시한다.
 */
class SettingsGetCommand extends Command
{
    use LocalizesDescription;

    /**
     * 명령 시그니처.
     *
     * @var string
     */
    protected $signature = 'settings:get
        {key? : 설정 키 또는 범주 (생략하면 전체)}
        {--plugin= : 플러그인 설정 (플러그인 식별자)}
        {--module= : 모듈 설정 (모듈 식별자)}
        {--json : JSON 으로 출력}
        {--show-secrets : 민감 값을 가리지 않음}';

    /**
     * 조회해 출력한다.
     *
     * @param  SettingsConsoleService  $service  터미널 설정 조회·저장
     * @return int 종료 코드 (성공 0, 실패 1)
     */
    public function handle(SettingsConsoleService $service): int
    {
        $showSecrets = (bool) $this->option('show-secrets');

        try {
            $rows = $service->get(
                $this->argument('key') !== null ? (string) $this->argument('key') : null,
                $this->option('plugin') ?: null,
                $this->option('module') ?: null,
                $showSecrets,
            );
        } catch (SettingsConsoleException $e) {
            $this->error('❌ '.$e->getMessage());

            return self::FAILURE;
        }

        if ($showSecrets) {
            $this->warn(__('settings.console.cli.secrets_shown'));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (array $row) => $row['value'], $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->line(__('settings.console.cli.no_settings'));

            return self::SUCCESS;
        }

        $this->table(['key', 'value', ''], array_map(
            static fn (string $key, array $row): array => [
                $key,
                is_string($row['value']) ? $row['value'] : (string) json_encode($row['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string) ($row['note'] ?? ''),
            ],
            array_keys($rows),
            $rows,
        ));

        return self::SUCCESS;
    }
}
