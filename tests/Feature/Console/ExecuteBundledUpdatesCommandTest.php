<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Extension\Helpers\ExtensionOperationLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Concerns\SimulatesOperationLockStore;
use Tests\TestCase;

/**
 * `core:execute-bundled-updates` 커맨드 회귀.
 *
 * 본 커맨드는 `core:update` 의 BundledExtensionUpdatePrompt 가 사용자 선택을 매니페스트로
 * 직렬화한 후 별도 PHP 프로세스에서 호출하는 진입점. fresh 코드 로드 보장으로 부모 stale
 * memory 결함을 회피.
 *
 * 검증:
 *   - 매니페스트 누락 / 부재 시 INVALID 반환
 *   - 매니페스트 JSON 파싱 실패 시 FAILURE 반환
 *   - 빈 매니페스트 처리 시 RESULT_PREFIX 페이로드 (성공 0/실패 0) 출력
 */
class ExecuteBundledUpdatesCommandTest extends TestCase
{
    use SimulatesOperationLockStore;

    private array $tempFiles = [];

    /**
     * 시험이 만든 매니페스트 파일·캐시 스토어를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreOperationLockStore();
        foreach ($this->tempFiles as $f) {
            if (File::exists($f)) {
                File::delete($f);
            }
        }
        parent::tearDown();
    }

    /**
     * 매니페스트 옵션이 없으면 INVALID 로 끝난다.
     *
     * @scenario case=manifest_option_missing
     *
     * @effects invalid_manifest_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_missing_manifest_option_returns_invalid(): void
    {
        $exit = Artisan::call('core:execute-bundled-updates');
        $this->assertSame(2, $exit, 'Command::INVALID 반환 (option 부재)');
    }

    /**
     * 없는 매니페스트 경로면 INVALID 로 끝난다.
     *
     * @scenario case=manifest_path_missing
     *
     * @effects invalid_manifest_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_nonexistent_manifest_path_returns_invalid(): void
    {
        $exit = Artisan::call('core:execute-bundled-updates', [
            '--manifest' => storage_path('app/__no_such_manifest__.json'),
        ]);
        $this->assertSame(2, $exit, 'Command::INVALID 반환 (파일 부재)');
    }

    /**
     * 매니페스트 JSON 을 읽을 수 없으면 FAILURE 로 끝난다.
     *
     * @scenario case=manifest_malformed
     *
     * @effects invalid_manifest_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_malformed_json_returns_failure(): void
    {
        $path = storage_path('app/test_bundled_manifest_'.uniqid().'.json');
        File::put($path, 'not-valid-json{{{');
        $this->tempFiles[] = $path;

        $exit = Artisan::call('core:execute-bundled-updates', [
            '--manifest' => $path,
        ]);

        $this->assertSame(1, $exit, 'Command::FAILURE 반환 (JSON 파싱 실패)');
    }

    /**
     * 빈 매니페스트는 성공 0·실패 0 결과 표식을 남기고 SUCCESS 로 끝난다.
     *
     * @scenario case=manifest_empty
     *
     * @effects empty_manifest_reports_zero_counts
     *
     * @return void 반환값 없음
     */
    public function test_empty_manifest_outputs_result_prefix_with_zero_counts(): void
    {
        $path = storage_path('app/test_bundled_manifest_'.uniqid().'.json');
        File::put($path, json_encode([
            'modules' => [],
            'plugins' => [],
            'templates' => [],
            'lang_packs' => [],
        ]));
        $this->tempFiles[] = $path;

        $exit = Artisan::call('core:execute-bundled-updates', [
            '--manifest' => $path,
        ]);
        $this->assertSame(0, $exit, '빈 매니페스트는 SUCCESS');

        $output = Artisan::output();
        $this->assertStringContainsString(
            ExecuteBundledUpdatesCommand::RESULT_PREFIX,
            $output,
            '결과 표식 라인 출력 (부모 spawn 파싱 대상)',
        );
        $this->assertStringContainsString('"success":0', $output);
        $this->assertStringContainsString('"failed":0', $output);
    }

    /**
     * 이 자식은 부모의 정리 단계가 끝난 **뒤에** 돈다. 구버전 부모(7.0.0~7.0.9)의 정리는
     * 소스 경로 안쪽만 지워 `core_{ts}/extracted` 껍데기를 남기므로, 그 껍데기를 여기서
     * 치워야 구버전에서 올라오는 업데이트도 잔존물 없이 끝난다. 파일이 있는 격리
     * 디렉토리는 건드리지 않는다.
     *
     * @scenario case=staging_sweep
     *
     * @effects execute_bundled_updates_child_sweeps_empty_staging_directories_left_by_parent
     *
     * @return void 반환값 없음
     */
    public function test_빈_매니페스트_처리_후_부모가_남긴_빈_격리_디렉토리를_치운다(): void
    {
        $base = storage_path('framework/testing/bundled-sweep-'.uniqid());
        File::ensureDirectoryExists($base.DIRECTORY_SEPARATOR.'core_20260906_095309'.DIRECTORY_SEPARATOR.'extracted');
        $live = $base.DIRECTORY_SEPARATOR.'core_20260906_120000'.DIRECTORY_SEPARATOR.'extracted';
        File::ensureDirectoryExists($live);
        File::put($live.DIRECTORY_SEPARATOR.'composer.json', '{}');
        config(['app.update.pending_path' => $base]);

        $path = storage_path('app/test_bundled_manifest_'.uniqid().'.json');
        File::put($path, json_encode(['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => []]));
        $this->tempFiles[] = $path;

        try {
            $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);

            $this->assertSame(0, $exit);
            $this->assertDirectoryDoesNotExist($base.DIRECTORY_SEPARATOR.'core_20260906_095309', '빈 껍데기는 치운다');
            $this->assertFileExists($live.DIRECTORY_SEPARATOR.'composer.json', '파일이 있는 격리 디렉토리는 남긴다');
        } finally {
            File::deleteDirectory($base);
        }
    }

    /**
     * 매니페스트 파일을 만들고 정리 목록에 올립니다.
     *
     * @param  array<string, array<int, array<string, string>>>  $manifest  매니페스트
     * @return string 파일 경로
     */
    private function writeManifest(array $manifest): string
    {
        $path = storage_path('app/test_bundled_manifest_'.uniqid().'.json');
        File::put($path, json_encode($manifest + ['modules' => [], 'plugins' => [], 'templates' => [], 'lang_packs' => []]));
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * 결과 표식 줄의 페이로드를 꺼냅니다.
     *
     * @param  string  $output  명령 출력
     * @return array<string, mixed> 페이로드
     */
    private function resultPayload(string $output): array
    {
        foreach (preg_split('/\R/', $output) as $line) {
            if (str_starts_with(trim($line), ExecuteBundledUpdatesCommand::RESULT_PREFIX)) {
                return json_decode(substr(trim($line), strlen(ExecuteBundledUpdatesCommand::RESULT_PREFIX)), true) ?? [];
            }
        }

        return [];
    }

    /**
     * 잠금 저장소 장애를 만나면 첫 항목에서 멈추고, 남은 항목 수를 한 번만 알리고 실패로 끝난다 — 같은 오류를 항목마다 반복하지 않는다(#161 D8).
     *
     * @scenario case=bulk_abort
     *
     * @effects bulk_update_aborts_at_first_lock_store_error
     *
     * @return void 반환값 없음
     */
    public function test_lock_store_down_aborts_at_first_item_and_reports_skipped(): void
    {
        $path = $this->writeManifest([
            'modules' => [['identifier' => 'acme-i161-a', 'strategy' => 'keep'], ['identifier' => 'acme-i161-b', 'strategy' => 'keep']],
            'plugins' => [['identifier' => 'acme-i161-c', 'strategy' => 'keep']],
            'templates' => [['identifier' => 'acme-i161-t', 'strategy' => 'keep']],
        ]);
        $this->breakLockStore();

        $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertSame(1, substr_count($output, __('extensions.commands.bundled_update.lock_unavailable_aborted', ['count' => 3])), '중단 안내가 한 번만 나와야 한다: '.$output);
        $this->assertStringNotContainsString('acme-i161-b', $output, '중단 뒤 다음 항목을 시도했다');
        $payload = $this->resultPayload($output);
        $this->assertSame(['success' => 0, 'failed' => 1, 'skipped' => 3, 'aborted' => true], $payload);
    }

    /**
     * 다른 작업이 진행 중인 항목(409)은 종전처럼 실패 1건으로 세고 다음 항목으로 간다 — 중단은 잠금 저장소 장애일 때만이다.
     *
     * @scenario case=bulk_abort
     *
     * @effects bulk_update_in_progress_item_fails_and_continues
     *
     * @return void 반환값 없음
     */
    public function test_in_progress_item_fails_and_continues(): void
    {
        $path = $this->writeManifest([
            'modules' => [['identifier' => 'acme-i161-busy', 'strategy' => 'keep'], ['identifier' => 'acme-i161-next', 'strategy' => 'keep']],
        ]);
        $held = Cache::lock(ExtensionOperationLock::key('module', 'acme-i161-busy'), 60);
        $this->assertTrue($held->get());

        try {
            $exit = Artisan::call('core:execute-bundled-updates', ['--manifest' => $path]);
            $output = Artisan::output();
        } finally {
            $held->release();
        }

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(__('extensions.errors.update_already_running', ['name' => 'acme-i161-busy']), $output);
        $this->assertStringContainsString('acme-i161-next', $output, '진행 중 항목 뒤 다음 항목을 건너뛰었다');
        $this->assertStringNotContainsString(__('extensions.commands.bundled_update.lock_unavailable_aborted', ['count' => 1]), $output);
        $payload = $this->resultPayload($output);
        $this->assertSame(2, $payload['failed']);
        $this->assertFalse($payload['aborted']);
        $this->assertSame(0, $payload['skipped']);
    }
}
