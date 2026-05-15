<?php

namespace App\Console\Commands\Core;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 번들 확장(모듈/플러그인/템플릿/언어팩) 일괄 업데이트를 별도 PHP 프로세스에서 실행합니다.
 *
 * `core:update` 의 `BundledExtensionUpdatePrompt::executeBulkUpdate` 가 사용자 선택을
 * 매니페스트로 직렬화한 후 본 커맨드를 `proc_open` 으로 spawn 한다. 자식은 fresh PHP
 * 프로세스이므로 디스크에 막 적용된 신버전 코어 코드(특히 `ModuleManager` /
 * `PluginManager` / `TemplateManager` / `LanguagePackService`) 를 메모리에 로드한 상태
 * 에서 update 메서드를 호출한다.
 *
 * 부모 프로세스의 stale memory 가 신규 sync 메서드(`syncDeclarativeArtifacts` 등) 를
 * 호출하지 못하던 결함의 영구 차단 (beta.4 의 `Upgrade_7_0_0_beta_4` 사후 보정과는 별개의
 * 구조적 fix — 향후 모든 코어 업그레이드에서 동일 패턴 회귀를 차단).
 *
 * 매니페스트 형식:
 * ```json
 * {
 *   "modules":    [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "plugins":    [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "templates":  [{"identifier": "...", "strategy": "overwrite|keep"}, ...],
 *   "lang_packs": [{"identifier": "..."}, ...]
 * }
 * ```
 *
 * 출력:
 *   - stdout 에 진행 라인을 그대로 출력 (부모 콘솔이 forwarding)
 *   - 종료 직전 `[BUNDLED-RESULT] {json}` 표식으로 최종 카운트 페이로드를 1회 출력 →
 *     부모가 stdout 파싱해 결과 복원.
 */
class ExecuteBundledUpdatesCommand extends Command
{
    public const RESULT_PREFIX = '[BUNDLED-RESULT] ';

    protected $signature = 'core:execute-bundled-updates
        {--manifest= : 업데이트 매니페스트 JSON 파일 경로 (필수)}
        {--force : 강제 업데이트 플래그 (확장 매니저 update 호출에 전달)}';

    protected $description = '번들 확장 일괄 업데이트를 실행합니다 (CoreUpdateCommand 내부용 — fresh PHP 프로세스에서 호출)';

    public function handle(): int
    {
        // [DEBUG #371] 자식 2 handle 진입 시점 dump — 추후 제거
        $this->debugIssue371Dump('ExecuteBundledUpdatesCommand::handle enter');

        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! File::isFile($manifestPath)) {
            $this->error('--manifest 옵션이 필수이며 존재하는 파일이어야 합니다.');

            return self::INVALID;
        }

        // spawn 자식 진입 시 활성 모듈/플러그인의 PSR-4 매핑을 fresh 등록 — 자세한 배경은
        // ExecuteUpgradeStepsCommand::handle 의 동일 호출 주석 참조.
        // (Artisan::call 대신 직접 메서드 호출 — nested Artisan::call 이 outer 명령의
        // output buffer 를 덮어쓰는 Laravel 동작 회피)
        try {
            app(\App\Extension\ExtensionManager::class)->updateComposerAutoload();
        } catch (\Throwable $e) {
            Log::warning('bundled update spawn 자식: updateComposerAutoload 호출 실패', [
                'error' => $e->getMessage(),
            ]);
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error('매니페스트 JSON 파싱 실패: '.$manifestPath);

            return self::FAILURE;
        }

        $modules = $manifest['modules'] ?? [];
        $plugins = $manifest['plugins'] ?? [];
        $templates = $manifest['templates'] ?? [];
        $langPacks = $manifest['lang_packs'] ?? [];

        $moduleManager = app(ModuleManager::class);
        $pluginManager = app(PluginManager::class);
        $templateManager = app(TemplateManager::class);
        $langPackService = app(LanguagePackService::class);

        $success = 0;
        $failed = 0;

        $this->info('── 번들 일괄 업데이트 실행 (spawn child) ──');

        foreach ($modules as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line("→ [모듈] {$id} ({$strategy})");
            try {
                $moduleManager->updateModule($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'module', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($plugins as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line("→ [플러그인] {$id} ({$strategy})");
            try {
                $pluginManager->updatePlugin($id, true, null, VendorMode::Auto, $strategy, null, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'plugin', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($templates as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            $strategy = (string) ($entry['strategy'] ?? 'overwrite');
            if ($id === '') {
                continue;
            }
            $this->line("→ [템플릿] {$id} ({$strategy})");
            // [DEBUG #371] 템플릿 update 직전 — 추후 제거
            $this->debugIssue371Dump("before template update id={$id}");
            try {
                $templateManager->updateTemplate($id, true, null, $strategy, 'bundled');
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'template', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($langPacks as $entry) {
            $id = (string) ($entry['identifier'] ?? '');
            if ($id === '') {
                continue;
            }
            $this->line("→ [언어팩] {$id}");
            // [DEBUG #371] 언어팩 update 직전 — 추후 제거
            $this->debugIssue371Dump("before lang_pack update id={$id}");
            try {
                $pack = $langPackService->findByIdentifier($id);
                if (! $pack) {
                    throw new \RuntimeException(__('language_packs.errors.identifier_not_found', ['identifier' => $id]));
                }
                $langPackService->performUpdate($pack, true);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  실패: {$e->getMessage()}");
                Log::error('번들 일괄 업데이트 실패 (spawn)', ['type' => 'lang_pack', 'id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("업데이트 완료: 성공 {$success}, 실패 {$failed}");

        // 부모 프로세스가 결과를 복원할 수 있도록 표식 라인으로 페이로드 출력
        $this->line(self::RESULT_PREFIX.json_encode([
            'success' => $success,
            'failed' => $failed,
        ], JSON_UNESCAPED_UNICODE));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * [DEBUG #371] 추후 제거 — 자식 2 의 process ENV / Config dump.
     */
    private function debugIssue371Dump(string $tag): void
    {
        $keys = [
            'APP_ENV', 'APP_VERSION', 'INSTALLER_COMPLETED',
            'DB_WRITE_HOST', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD', 'DB_WRITE_DATABASE',
            'DB_READ_HOST', 'DB_READ_USERNAME', 'DB_READ_PASSWORD', 'DB_READ_DATABASE',
        ];
        $envParts = [];
        foreach ($keys as $key) {
            $getenvVal = getenv($key);
            $envVal = $_ENV[$key] ?? null;
            $serverVal = $_SERVER[$key] ?? null;
            $masked = function (?string $v) use ($key): string {
                if ($v === null) {
                    return '<unset>';
                }
                if (str_contains($key, 'PASSWORD')) {
                    return $v === '' ? "''" : '***len=' . strlen($v);
                }

                return "'" . $v . "'";
            };
            $envParts[] = sprintf(
                '%s={getenv=%s,$_ENV=%s,$_SERVER=%s}',
                $key,
                $masked($getenvVal === false ? null : $getenvVal),
                $masked($envVal === null ? null : (string) $envVal),
                $masked($serverVal === null ? null : (string) $serverVal),
            );
        }
        $configParts = [];
        try {
            $configParts[] = 'config.db.write.username=' . var_export(config('database.connections.mysql.write.username'), true);
            $configParts[] = 'config.db.read.username=' . var_export(config('database.connections.mysql.read.username'), true);
        } catch (\Throwable $e) {
            $configParts[] = 'config read FAILED: ' . $e->getMessage();
        }

        @file_put_contents(
            base_path('storage/logs/issue-371-debug.log'),
            sprintf("[%s] [pid=%d] [bundle-exec] %s | %s || %s\n", date('Y-m-d H:i:s'), getmypid(), $tag, implode(' | ', $envParts), implode(' | ', $configParts)),
            FILE_APPEND | LOCK_EX,
        );
    }
}
