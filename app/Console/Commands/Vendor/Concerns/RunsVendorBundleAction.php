<?php

namespace App\Console\Commands\Vendor\Concerns;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorBundleResult;
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
     * @param  VendorBundler  $bundler  vendor-bundle.zip 빌더
     * @param  array<int, array{type: string, identifier: ?string}>  $targets
     * @param  bool  $force  해시 체크 무시하고 강제 재빌드
     * @param  bool  $check  실제 빌드 없이 stale 여부만 확인 (true 면 stale 시 종료코드 1)
     * @return int 종료 코드
     */
    protected function runBuildAction(VendorBundler $bundler, array $targets, bool $force, bool $check): int
    {
        if (empty($targets)) {
            $this->error(__('extensions.commands.vendor_bundle_action.no_build_targets'));

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
     * @param  VendorIntegrityChecker  $checker  vendor 번들 무결성 검사기
     * @param  array<int, array{type: string, identifier: ?string}>  $targets
     * @return int 종료 코드 (검증 실패가 하나라도 있으면 실패)
     */
    protected function runVerifyAction(VendorIntegrityChecker $checker, array $targets): int
    {
        if (empty($targets)) {
            $this->error(__('extensions.commands.vendor_bundle_action.no_verify_targets'));

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
                $this->warn('- '.__('extensions.commands.vendor_bundle_action.output_path_missing', ['label' => $label]));

                continue;
            }

            $zipPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
            if (! file_exists($zipPath)) {
                $this->line('- '.__('extensions.commands.vendor_bundle_action.bundle_missing', ['label' => $label]));

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
            $this->error(__('extensions.commands.vendor_bundle_action.verify_failed', ['count' => $failures]));

            return self::FAILURE;
        }

        $this->info(__('extensions.commands.vendor_bundle_action.verify_passed'));

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

    /**
     * 대상 하나의 vendor 번들을 빌드하고 결과(빌드·건너뜀 사유)를 출력합니다.
     *
     * @param  VendorBundler  $bundler  vendor-bundle.zip 빌더
     * @param  array{type: string, identifier: ?string}  $target  빌드 대상 (core|module|plugin)
     * @param  bool  $force  해시 체크 무시하고 강제 재빌드
     * @return VendorBundleResult 빌드 결과
     */
    private function performBuild(VendorBundler $bundler, array $target, bool $force): VendorBundleResult
    {
        $label = $this->formatTargetLabel($target);
        $this->line('▶ '.__('extensions.commands.vendor_bundle_action.building', ['label' => $label]));

        $result = $target['type'] === 'core'
            ? $bundler->buildForCore($force)
            : $bundler->buildForExtension($target['type'], $target['identifier'], $force);

        if ($result->skipped && $result->reason === 'no-external-dependencies') {
            $this->line('  ○ '.__('extensions.commands.vendor_bundle_action.skipped_no_dependencies', ['label' => $label]));
        } elseif ($result->skipped && $result->reason === 'extension-not-installed') {
            $this->line('  ○ '.__('extensions.commands.vendor_bundle_action.skipped_not_installed', ['label' => $label]));
        } elseif ($result->skipped) {
            $this->line('  ○ '.__('extensions.commands.vendor_bundle_action.skipped_reason', [
                'label' => $label,
                'reason' => $result->reason,
                'size' => $result->zipSizeHuman(),
                'count' => (int) $result->packageCount,
            ]));
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
     * @param  VendorBundler  $bundler  stale 판정에 쓰는 vendor-bundle.zip 빌더
     * @param  array{type: string, identifier: ?string}  $target  확인 대상 (core|module|plugin)
     * @return bool stale 이면 true
     */
    private function performCheck(VendorBundler $bundler, array $target): bool
    {
        $label = $this->formatTargetLabel($target);
        $sourcePath = $this->resolveSourcePath($target);
        $outputPath = $this->resolveOutputPath($target);

        // 판정 기준 파일은 빌드(VendorBundler::build / isStale)와 같은 규칙 — 출력(_bundled) 우선,
        // 없으면 활성 디렉토리 폴백. 활성 디렉토리만 보면 미설치 확장을 "소스 경로 없음" 으로
        // 보고해 _bundled 의 stale 번들이 --check 를 통과한다.
        $composerJsonPath = $this->resolveCheckFile($sourcePath, $outputPath, 'composer.json');
        if ($composerJsonPath === null) {
            if (! is_dir($sourcePath) && ! is_dir($outputPath)) {
                $this->warn('- '.__('extensions.commands.vendor_bundle_action.path_missing', ['label' => $label, 'path' => $outputPath]));

                return false;
            }

            $this->line('- '.__('extensions.commands.vendor_bundle_action.check_skipped_no_composer', ['label' => $label]));

            return false;
        }

        // 외부 composer 의존성이 없는 확장은 번들링 대상이 아님
        if (! $this->composerJsonHasExternalDependencies($composerJsonPath)) {
            $this->line('- '.__('extensions.commands.vendor_bundle_action.check_skipped_no_dependencies', ['label' => $label]));

            return false;
        }

        $stale = $bundler->isStale($sourcePath, $outputPath);
        if ($stale) {
            $this->error('✗ '.__('extensions.commands.vendor_bundle_action.stale', ['label' => $label]));
        } else {
            $this->line("✓ $label: up-to-date");
        }

        return $stale;
    }

    /**
     * --check 가 읽을 composer 파일 경로 — 출력(_bundled) 우선, 없으면 활성 디렉토리 폴백.
     *
     * VendorBundler::resolveHashTarget() 과 같은 규칙. 판정 파일이 빌드·해시 기준과
     * 다르면 --check 결과가 실제 stale 여부와 어긋난다.
     *
     * @param  string  $sourcePath  활성 디렉토리 소스 경로
     * @param  string  $outputPath  _bundled 출력 경로
     * @param  string  $filename  찾을 파일 이름
     * @return string|null 찾은 파일 경로 (양쪽 모두 없으면 null)
     */
    private function resolveCheckFile(string $sourcePath, string $outputPath, string $filename): ?string
    {
        $outputFile = $outputPath.DIRECTORY_SEPARATOR.$filename;
        if (is_file($outputFile)) {
            return $outputFile;
        }

        $sourceFile = $sourcePath.DIRECTORY_SEPARATOR.$filename;

        return is_file($sourceFile) ? $sourceFile : null;
    }

    /**
     * composer.json 에 외부 패키지 의존성이 있는지 확인합니다.
     *
     * VendorBundler::hasExternalDependencies() 와 동일 로직 — trait 에서 중복 구현을
     * 피하지 않고 독립적으로 구현하여 VendorBundler 인스턴스 없이도 사용 가능하게 함.
     *
     * @param  string  $composerJsonPath  composer.json 경로
     * @return bool php·ext-* 외 패키지 의존성이 있으면 true (읽기·파싱 실패 시 false)
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
     * 출력용 대상 표기를 만듭니다.
     *
     * @param  array{type: string, identifier: ?string}  $target
     * @return string 'core' 또는 '{type}s/{identifier}' 형식의 표기
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
     * @return string 소스 경로 (알 수 없는 유형이면 빈 문자열)
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
     * @return string 출력 경로 (알 수 없는 유형이면 빈 문자열)
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
     * 빌드·건너뜀 건수와 전체 번들 크기 요약을 출력합니다.
     *
     * @param  array<int, VendorBundleResult>  $results
     * @return void
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
