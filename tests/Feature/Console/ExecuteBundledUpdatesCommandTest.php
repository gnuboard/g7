<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (File::exists($f)) {
                File::delete($f);
            }
        }
        parent::tearDown();
    }

    public function test_missing_manifest_option_returns_invalid(): void
    {
        $exit = Artisan::call('core:execute-bundled-updates');
        $this->assertSame(2, $exit, 'Command::INVALID 반환 (option 부재)');
    }

    public function test_nonexistent_manifest_path_returns_invalid(): void
    {
        $exit = Artisan::call('core:execute-bundled-updates', [
            '--manifest' => storage_path('app/__no_such_manifest__.json'),
        ]);
        $this->assertSame(2, $exit, 'Command::INVALID 반환 (파일 부재)');
    }

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
}
