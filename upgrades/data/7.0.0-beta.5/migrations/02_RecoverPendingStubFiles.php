<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * `{modules,plugins,templates,lang-packs}/_pending/{.gitignore,.gitkeep}` 부재 시 재생성.
 *
 * `applyDiscoveredTopLevelPaths` 결함이 부모 디렉토리 통째로 복사하며 release zip 의
 * `_pending` stub 만 남기는 상황 (부재한 `.gitignore`/`.gitkeep` 가 활성에서 손실되는 회귀)
 * 의 사후 보정.
 *
 * 멱등성: stub 파일 존재 시 skip.
 */
final class RecoverPendingStubFiles implements DataMigration
{
    /**
     * 디렉토리별 `_pending` stub 보정 대상 (디렉토리명).
     *
     * @var array<int, string>
     */
    private const PENDING_STUB_DIRS = ['modules', 'plugins', 'templates', 'lang-packs'];

    public function name(): string
    {
        return 'RecoverPendingStubFiles';
    }

    public function run(UpgradeContext $context): void
    {
        $gitignoreContent = "*\n!.gitignore\n!.gitkeep\n";

        $restored = 0;
        $skipped = 0;

        foreach (self::PENDING_STUB_DIRS as $domainDir) {
            $pendingPath = base_path($domainDir.DIRECTORY_SEPARATOR.'_pending');

            if (! File::isDirectory($pendingPath)) {
                try {
                    File::ensureDirectoryExists($pendingPath);
                    $context->logger->info(sprintf('[7.0.0-beta.5] %s/_pending 디렉토리 생성', $domainDir));
                } catch (\Throwable $e) {
                    $context->logger->warning(sprintf(
                        '[7.0.0-beta.5] %s/_pending 디렉토리 생성 실패 — %s',
                        $domainDir,
                        $e->getMessage(),
                    ));

                    continue;
                }
            }

            foreach ([['.gitignore', $gitignoreContent], ['.gitkeep', '']] as [$stubName, $stubContent]) {
                $stubPath = $pendingPath.DIRECTORY_SEPARATOR.$stubName;

                if (File::exists($stubPath)) {
                    $skipped++;

                    continue;
                }

                try {
                    File::put($stubPath, $stubContent);
                    $restored++;
                } catch (\Throwable $e) {
                    $context->logger->warning(sprintf(
                        '[7.0.0-beta.5] %s/_pending/%s 작성 실패 — %s',
                        $domainDir,
                        $stubName,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.5] _pending stub 보정 — 신규 작성 %d 건, 기존 보존 %d 건',
            $restored,
            $skipped,
        ));
    }
}
