<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * 업데이트 종료 시 root 산출물 소유권 정상화 (실사례: 7.0.9→7.0.10 전면 500).
 *
 * sudo 업데이트가 캐시 키 인덱스·상태 캐시·병합 번들을 root 소유로 남기면
 * 웹 프로세스의 캐시 쓰기가 Permission denied 로 죽는다. 업데이트 흐름의
 * 모든 쓰기가 끝난 종료 지점에서 런타임 디렉토리를 기준 소유자(디렉토리
 * 자신의 소유자)로 재귀 정상화해 root 산출물이 남지 않게 한다.
 */
class CoreUpdateRuntimeOwnershipTest extends TestCase
{
    /**
     * 비-root 프로세스에서는 no-op 이다 (chown 자체가 불가능하고 불필요).
     */
    public function test_non_root_process_is_noop(): void
    {
        $service = app(CoreUpdateService::class);

        // 예외 없이 종료해야 한다 (Windows/비-root: posix 가드로 즉시 반환)
        $service->normalizeRuntimeOwnershipAfterRootRun();

        $this->assertTrue(true);
    }

    /**
     * 정상화 타깃에 확장 업데이트 임시 폴더와 로그 디렉토리가 포함된다 (#651 B5).
     *
     * `storage/app/temp` 의 부모는 sudo 업데이트가 최초 생성자가 되면 root 로 굳어 이후 관리자 화면의
     * 확장 업데이트가 임시 폴더를 만들지 못하고, `storage/logs` 는 restore_ownership 복원(Step 11)
     * 뒤에 만들어지는 daily 롤오버·신규 로그 파일이 root 로 남는다.
     *
     * @effects runtime_ownership_targets_cover_temp_and_logs
     */
    public function test_runtime_ownership_targets_include_temp_and_logs(): void
    {
        $method = new \ReflectionMethod(CoreUpdateService::class, 'normalizeRuntimeOwnershipAfterRootRun');
        $lines = file($method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        foreach ([
            "storage_path('framework/cache')",
            "base_path('bootstrap/cache')",
            "storage_path('app/ext-bundles')",
            "storage_path('app/temp')",
            "storage_path('logs')",
        ] as $target) {
            $this->assertStringContainsString($target, $body, "런타임 소유권 정상화 타깃에 {$target} 이 없다");
        }
    }

    /**
     * 부모(CoreUpdateCommand)·자식(ExecuteUpgradeStepsCommand) 흐름 종료부가
     * 정상화를 호출한다 — 로그 소유권 정상화(restoreUpgradeLogOwnership)와
     * 항상 짝으로 (#519 소스 훑기 선례).
     */
    public function test_update_flows_invoke_runtime_ownership_normalization(): void
    {
        foreach ([
            app_path('Console/Commands/Core/CoreUpdateCommand.php'),
            app_path('Console/Commands/Core/ExecuteUpgradeStepsCommand.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            $logCalls = substr_count($source, '$this->restoreUpgradeLogOwnership();');
            $normalizeCalls = substr_count($source, 'normalizeRuntimeOwnershipAfterRootRun');

            $this->assertGreaterThan(0, $logCalls, basename($file).': 로그 소유권 정상화 호출이 사라졌습니다');
            $this->assertGreaterThanOrEqual(
                $logCalls,
                $normalizeCalls,
                basename($file).': 흐름 종료부(restoreUpgradeLogOwnership 호출 지점)마다 런타임 소유권 정상화가 짝으로 호출되어야 합니다'
            );
        }
    }
}
