<?php

namespace App\Console\Commands\Vendor\Concerns;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundleResult;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Support\Facades\File;

/**
 * Vendor 번들 빌드/검증 액션 공통 로직.
 *
 * core:vendor-bundle / module:vendor-bundle / plugin:vendor-bundle 및
 * 일괄 빌드 vendor-bundle:build-all 명령어들이 본 trait 을 공유한다.
 */
trait RunsVendorBundleAction
{
    /**
     * 빌드 또는 stale 체크 수행.
     *
     * @param  array<int, array{type: string, identifier: ?string}>  $targets
     * @param  bool  $force  해시 체크 무시하고 강제 재빌드
     * @param  bool  $check  실제 빌드 없이 stale 여부만 확인 (true 면 stale 시 종료코드 1)
     */
    protected function runBuildAction(VendorBundler $bundler, array $targets, bool $force, bool $check): int
    {
        if (empty($targets)) {
            $this->error('빌드 대상이 없습니다.');

            return self::FAILURE;
        }

        $this->line($check ? 'Vendor Bundle Status Check' : 'Vendor Bundle Build');
        $this->line(str_repeat('─', 66));
        $this->newLine();

        $hasStale = false;
        $results = [];

        foreach ($targets as $target) {
            try {
                if ($check) {
                    if ($this->performCheck($bundler, $target)) {
                        $hasStale = true;
                    }

                    continue;
                }

                $results[] = $this->performBuild($bundler, $target, $force);
            } catch (VendorInstallException $e) {
                $this->error(sprintf('✗ %s: %s', $this->formatTargetLabel($target), $e->getMessage()));
            } catch (\Throwable $e) {
                $this->error(sprintf('✗ %s: %s', $this->formatTargetLabel($target), $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 66));

        if ($check) {
            return $hasStale ? self::FAILURE : self::SUCCESS;
        }

        $this->printBuildSummary($results);

        return self::SUCCESS;
    }

    /**
     * 무결성 검증 수행.
     *
     * @param  array<int, array{type: string, identifier: ?string}>  $targets
     */
    protected function runVerifyAction(VendorIntegrityChecker $checker, array $targets): int
    {
        if (empty($targets)) {
            $this->error('검증 대상이 없습니다.');

            return self::FAILURE;
        }

        $this->line('Vendor Bundle Integrity Verification');
        $this->line(str_repeat('─', 66));
        $this->newLine();

        $failures = 0;
        foreach ($targets as $target) {
            $label = $this->formatTargetLabel($target);
            $outputPath = $this->resolveOutputPath($target);

            if (! is_dir($outputPath)) {
                $this->warn("- $label: 출력 경로 없음");

                continue;
            }

            $zipPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
            if (! file_exists($zipPath)) {
                $this->line("- $label: 번들 없음 (스킵)");

                continue;
            }

            $result = $checker->verify($outputPath);

            if ($result->valid) {
                $packageCount = $result->meta['package_count'] ?? 0;
                $zipSize = @filesize($zipPath) ?: 0;
                $this->line(sprintf(
                    '✓ %s: OK (%.1f MB, %d packages)',
                    $label,
                    $zipSize / 1024 / 1024,
                    $packageCount,
                ));
            } else {
                $failures++;
                $this->error(sprintf('✗ %s: FAILED', $label));
                foreach ($result->errorMessages() as $message) {
                    $this->line('  - '.$message);
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 66));

        if ($failures > 0) {
            $this->error("검증 실패: $failures 개");

            return self::FAILURE;
        }

        $this->info('모든 번들이 무결성 검증을 통과했습니다.');

        return self::SUCCESS;
    }

    /**
     * `_bundled` 디렉토리에서 모든 모듈/플러그인 식별자 목록 반환.
     *
     * @param  string  $type  'module' | 'plugin'
     * @return array<int, string>
     */
    protected function discoverBundledIdentifiers(string $type): array
    {
        $base = base_path($type === 'module' ? 'modules/_bundled' : 'plugins/_bundled');
        if (! is_dir($base)) {
            return [];
        }

        $identifiers = [];
        foreach (File::directories($base) as $dir) {
            if (is_file($dir.DIRECTORY_SEPARATOR.'composer.json')) {
                $identifiers[] = basename($dir);
            }
        }

        return $identifiers;
    }

    private function performBuild(VendorBundler $bundler, array $target, bool $force): VendorBundleResult
    {
        $label = $this->formatTargetLabel($target);
        $this->line("▶ $label 빌드 중...");

        $result = $target['type'] === 'core'
            ? $bundler->buildForCore($force)
            : $bundler->buildForExtension($target['type'], $target['identifier'], $force);

        if ($result->skipped && $result->reason === 'no-external-dependencies') {
            $this->line(sprintf(
                '  ○ %s: 스킵 (외부 composer 의존성 없음)',
                $label,
            ));
        } elseif ($result->skipped && $result->reason === 'extension-not-installed') {
            $this->line(sprintf(
                '  ○ %s: 스킵 (확장이 설치되지 않음 — 먼저 module:install / plugin:install 필요)',
                $label,
            ));
        } elseif ($result->skipped) {
            $this->line(sprintf(
                '  ○ %s: 스킵 (%s, %s, %d packages)',
                $label,
                $result->reason,
                $result->zipSizeHuman(),
                $result->packageCount,
            ));
        } else {
            $this->line(sprintf(
                '  ✓ %s: %s, %d packages',
                $label,
                $result->zipSizeHuman(),
                $result->packageCount,
            ));
        }

        return $result;
    }

    /**
     * stale 여부 확인만 수행.
     *
     * @return bool stale 이면 true
     */
    private function performCheck(VendorBundler $bundler, array $target): bool
    {
        $label = $this->formatTargetLabel($target);
        $sourcePath = $this->resolveSourcePath($target);
        $outputPath = $this->resolveOutputPath($target);

        if (! is_dir($sourcePath)) {
            $this->warn("- $label: 소스 경로 없음 ($sourcePath)");

            return false;
        }

        $composerJsonPath = $sourcePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($composerJsonPath)) {
            $this->line("- $label: SKIPPED (composer.json 없음)");

            return false;
        }

        // 외부 composer 의존성이 없는 확장은 번들링 대상이 아님
        if (! $this->composerJsonHasExternalDependencies($composerJsonPath)) {
            $this->line("- $label: SKIPPED (외부 composer 의존성 없음)");

            return false;
        }

        $stale = $bundler->isStale($sourcePath, $outputPath);
        if ($stale) {
            $this->error("✗ $label: STALE — 재빌드 필요");
        } else {
            $this->line("✓ $label: up-to-date");
        }

        return $stale;
    }

    /**
     * composer.json 에 외부 패키지 의존성이 있는지 확인합니다.
     *
     * VendorBundler::hasExternalDependencies() 와 동일 로직 — trait 에서 중복 구현을
     * 피하지 않고 독립적으로 구현하여 VendorBundler 인스턴스 없이도 사용 가능하게 함.
     */
    private function composerJsonHasExternalDependencies(string $composerJsonPath): bool
    {
        $json = @file_get_contents($composerJsonPath);
        if ($json === false) {
            return false;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }

        $require = $data['require'] ?? [];
        if (! is_array($require) || empty($require)) {
            return false;
        }

        foreach (array_keys($require) as $package) {
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array{type: string, identifier: ?string}  $target
     */
    private function formatTargetLabel(array $target): string
    {
        return $target['type'] === 'core'
            ? 'core'
            : sprintf('%ss/%s', $target['type'], $target['identifier']);
    }

    /**
     * 소스 경로 (composer.json, composer.lock, vendor/ 를 읽는 경로).
     *
     * 코어: base_path() 자체가 소스이자 출력
     * 모듈/플러그인: 활성 디렉토리 (modules/{id}, plugins/{id}) — 설치 시 composer install 이 실행된 곳
     *
     * @param  array{type: string, identifier: ?string}  $target
     */
    private function resolveSourcePath(array $target): string
    {
        return match ($target['type']) {
            'core' => base_path(),
            'module' => base_path('modules/'.$target['identifier']),
            'plugin' => base_path('plugins/'.$target['identifier']),
            default => '',
        };
    }

    /**
     * 출력 경로 (vendor-bundle.zip 과 vendor-bundle.json 을 쓰는 경로).
     *
     * 코어: base_path() (소스와 동일)
     * 모듈/플러그인: _bundled 디렉토리 — Git 추적되는 배포 아티팩트 저장 위치
     *
     * @param  array{type: string, identifier: ?string}  $target
     */
    private function resolveOutputPath(array $target): string
    {
        return match ($target['type']) {
            'core' => base_path(),
            'module' => base_path('modules/_bundled/'.$target['identifier']),
            'plugin' => base_path('plugins/_bundled/'.$target['identifier']),
            default => '',
        };
    }

    /**
     * @param  array<int, VendorBundleResult>  $results
     */
    private function printBuildSummary(array $results): void
    {
        $built = 0;
        $skipped = 0;
        $totalSize = 0;

        foreach ($results as $result) {
            if ($result->skipped) {
                $skipped++;
            } else {
                $built++;
            }
            $totalSize += $result->zipSize;
        }

        $this->line(sprintf(
            'Summary: %d built, %d skipped, total %.1f MB',
            $built,
            $skipped,
            $totalSize / 1024 / 1024,
        ));
    }
}
