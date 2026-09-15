<?php

namespace Tests\Unit\Upgrade;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 번들 확장 업그레이드 마이그레이션의 파일 쓰기 소유권 상속 패리티 (#651 B3).
 *
 * 업그레이드 스텝은 sudo 코어 업데이트 안에서 root 로 실행될 수 있다. `File::put()` 으로 설정 파일을
 * 쓰는 스텝 중 일부(gdpr 02·ckeditor5·pay_*)는 `inheritOwnershipFromParent` 를 부르고 일부는 부르지
 * 않아 진영이 갈렸다 — 지금은 전부 기존 파일 갱신이라 inode 소유자가 보존되지만, "없으면 생성" 으로
 * 바뀌는 순간 root 소유 파일이 남는다.
 *
 * 모집단은 **파일시스템에서 파생**한다 — 8개 파일을 열거하면 9번째 마이그레이션이 사각이 된다.
 */
class BundledUpgradeMigrationOwnershipParityTest extends TestCase
{
    /**
     * `File::put(` 뒤 5줄 안에 상속 호출이 없는 마이그레이션 파일을 모읍니다.
     *
     * @return array<int, string> 위반 파일 (base_path 상대)
     */
    private function collectViolations(): array
    {
        $pattern = base_path('{modules,plugins}/_bundled/*/upgrades');
        $violations = [];
        $population = 0;

        foreach (glob($pattern, GLOB_BRACE) ?: [] as $upgradesDir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($upgradesDir, \FilesystemIterator::SKIP_DOTS));

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

                foreach ($lines as $index => $line) {
                    if (! str_contains($line, 'File::put(')) {
                        continue;
                    }

                    $population++;
                    $window = implode("\n", array_slice($lines, $index, 6));

                    if (! str_contains($window, 'inheritOwnershipFromParent')) {
                        $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)).':'.($index + 1);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $population, '모집단이 비었다 — 번들 확장 upgrades 에서 File::put 을 찾지 못했다 (판정식 회귀 의심)');

        return $violations;
    }

    /**
     * 모든 `File::put(` 은 5줄 안에 `inheritOwnershipFromParent` 를 동반한다.
     *
     * @effects bundled_upgrade_migrations_inherit_ownership
     */
    #[Test]
    public function 번들_업그레이드_마이그레이션의_파일_쓰기는_전부_소유권을_상속한다(): void
    {
        $violations = $this->collectViolations();

        $this->assertSame(
            [],
            $violations,
            "File::put 뒤 소유권 상속이 없는 업그레이드 마이그레이션:\n - ".implode("\n - ", $violations)
        );
    }
}
