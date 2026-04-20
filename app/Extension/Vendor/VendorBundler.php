<?php

namespace App\Extension\Vendor;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 개발 환경에서 vendor/ 디렉토리를 zip으로 압축하여 번들 파일을 생성합니다.
 *
 * 빌드 타임에만 실행되며, 생성된 번들은 런타임에 VendorBundleInstaller가 소비합니다.
 *
 * 빌드 절차 (staging 기반):
 * 1. storage/app/vendor-bundle-staging/{uniqid}/ 임시 디렉토리 생성
 * 2. 소스의 composer.json + composer.lock 을 임시 디렉토리로 복사
 * 3. 임시 디렉토리에서 `composer install --no-dev --no-scripts --prefer-dist` 실행
 * 4. 생성된 vendor/ 를 (EXCLUDE_PATTERNS 적용 후) zip 으로 기록
 * 5. manifest.json 기록
 * 6. 임시 디렉토리 정리
 *
 * 이 방식은 개발 vendor/ 가 dev 의존성을 포함하고 있어도 번들에 dev 의존성이
 * 섞여 들어가지 않도록 보장하며, composer 가 생성한 autoload 파일과 vendor/
 * 내용이 완벽히 일치함을 보장합니다 (autoload_files.php 가 존재하지 않는
 * dev 패키지를 require 하는 버그 방지).
 */
class VendorBundler
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * 압축 제외 경로 패턴 (패키지 디렉토리 내부 파일).
     *
     * @var array<int, string>
     */
    private const EXCLUDE_PATTERNS = [
        '.git',
        '.github',
        'tests',
        'Tests',
        'docs',
        '.gitignore',
        '.gitattributes',
        '.gitkeep',
        'phpunit.xml',
        'phpunit.xml.dist',
        'psalm.xml',
        'psalm.xml.dist',
        '.phpcs.xml',
        '.phpcs.xml.dist',
    ];

    /**
     * 테스트에서 composer 실행을 대체하기 위한 주입 가능한 러너.
     *
     * null 이면 실제 composer 바이너리를 proc_open 으로 실행합니다.
     * 테스트에서는 setComposerInstallRunner() 로 주입하여 가짜 vendor/ 구조를 생성합니다.
     */
    private ?\Closure $composerInstallRunner = null;

    public function __construct(
        private readonly VendorIntegrityChecker $integrityChecker,
        private readonly ?EnvironmentDetector $environmentDetector = null,
    ) {}

    /**
     * 테스트용 — composer 실행을 대체할 러너를 주입합니다.
     *
     * 러너는 `fn (string $stagingDir): void` 형식이며, 주어진 스테이징 디렉토리에
     * vendor/ 구조를 직접 생성해야 합니다.
     */
    public function setComposerInstallRunner(?\Closure $runner): void
    {
        $this->composerInstallRunner = $runner;
    }

    /**
     * 코어(루트) vendor/를 번들링합니다.
     *
     * 코어는 소스 = 출력 경로가 base_path() 로 동일합니다.
     */
    public function buildForCore(bool $force = false): VendorBundleResult
    {
        return $this->build(
            sourcePath: base_path(),
            outputPath: base_path(),
            target: 'core',
            force: $force,
        );
    }

    /**
     * 모듈/플러그인의 vendor/를 번들링합니다.
     *
     * 소스는 **활성 디렉토리** (예: modules/sirsoft-ecommerce/vendor/) 에서 읽고,
     * 출력은 **_bundled 디렉토리** (예: modules/_bundled/sirsoft-ecommerce/vendor-bundle.zip) 에 저장합니다.
     *
     * 이유: _bundled 는 Git 추적 소스 디렉토리로 vendor/ 를 두지 않는 것이 원칙.
     * 활성 디렉토리는 설치 시 composer install 이 실행되어 실제 패키지가 설치된 상태.
     *
     * @param  string  $type  'module' | 'plugin'
     */
    public function buildForExtension(string $type, string $identifier, bool $force = false): VendorBundleResult
    {
        $activePath = match ($type) {
            'module' => base_path('modules/'.$identifier),
            'plugin' => base_path('plugins/'.$identifier),
            default => throw new \InvalidArgumentException("Unsupported extension type: $type"),
        };

        $outputPath = match ($type) {
            'module' => base_path('modules/_bundled/'.$identifier),
            'plugin' => base_path('plugins/_bundled/'.$identifier),
        };

        if (! is_dir($outputPath)) {
            throw new VendorInstallException(
                errorKey: 'source_dir_not_found',
                context: ['path' => $outputPath],
            );
        }

        // 활성 디렉토리가 없으면 미설치 확장 — skip 처리 (예외 대신)
        //
        // 미설치 확장의 경우 composer.json 은 _bundled 에만 존재하므로
        // 외부 의존성 여부를 _bundled 의 composer.json 에서 확인한다.
        if (! is_dir($activePath)) {
            $bundledComposerJson = $outputPath.DIRECTORY_SEPARATOR.'composer.json';
            $reason = file_exists($bundledComposerJson) && $this->hasExternalDependencies($bundledComposerJson)
                ? 'extension-not-installed'
                : 'no-external-dependencies';

            return new VendorBundleResult(
                target: $type.':'.$identifier,
                zipPath: $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME,
                manifestPath: $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::MANIFEST_FILENAME,
                zipSize: 0,
                packageCount: 0,
                skipped: true,
                reason: $reason,
            );
        }

        return $this->build(
            sourcePath: $activePath,
            outputPath: $outputPath,
            target: $type.':'.$identifier,
            force: $force,
        );
    }

    /**
     * stale 여부 확인 — composer.json/lock 해시가 기존 manifest와 일치하는지 검사.
     *
     * 외부 패키지 의존성이 없는 확장은 번들링 대상이 아니므로 항상 false 를 반환합니다.
     *
     * @param  string  $sourcePath  composer.json/lock 을 읽을 경로 (활성 디렉토리)
     * @param  string|null  $outputPath  번들 출력 경로 (null 이면 sourcePath 와 동일 — 코어 케이스)
     */
    public function isStale(string $sourcePath, ?string $outputPath = null): bool
    {
        $outputPath ??= $sourcePath;

        $composerJsonPath = $sourcePath.DIRECTORY_SEPARATOR.'composer.json';
        if (file_exists($composerJsonPath) && ! $this->hasExternalDependencies($composerJsonPath)) {
            return false;
        }

        $manifestPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::MANIFEST_FILENAME;
        $zipPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;

        if (! file_exists($manifestPath) || ! file_exists($zipPath)) {
            return true;
        }

        $manifest = $this->integrityChecker->readManifest($outputPath);
        if ($manifest === null) {
            return true;
        }

        if (file_exists($composerJsonPath)) {
            $currentHash = $this->integrityChecker->computeFileHash($composerJsonPath);
            if (($manifest['composer_json_sha256'] ?? null) !== $currentHash) {
                return true;
            }
        }

        $composerLockPath = $sourcePath.DIRECTORY_SEPARATOR.'composer.lock';
        if (file_exists($composerLockPath)) {
            $currentHash = $this->integrityChecker->computeFileHash($composerLockPath);
            if (($manifest['composer_lock_sha256'] ?? null) !== $currentHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * 빌드 실행.
     *
     * @param  string  $sourcePath  composer.json, composer.lock, vendor/ 를 읽을 경로 (활성 디렉토리)
     * @param  string  $outputPath  vendor-bundle.zip 과 vendor-bundle.json 을 쓸 경로 (_bundled 또는 코어는 sourcePath 와 동일)
     * @param  string  $target  라벨용 대상 식별자 (예: 'core', 'module:sirsoft-ecommerce')
     * @param  bool  $force  해시 체크 무시 강제 재빌드
     */
    private function build(string $sourcePath, string $outputPath, string $target, bool $force): VendorBundleResult
    {
        // 전제 조건 검증
        $composerJsonPath = $sourcePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! file_exists($composerJsonPath)) {
            throw new VendorInstallException(
                errorKey: 'composer_json_not_found',
                context: ['path' => $composerJsonPath],
            );
        }

        $zipPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
        $manifestPath = $outputPath.DIRECTORY_SEPARATOR.VendorIntegrityChecker::MANIFEST_FILENAME;

        // 실제 외부 패키지 의존성이 없는 확장은 번들링 대상이 아님 — skip 처리
        // (composer.json 에 `php: ^8.2` 런타임 제약만 있고 패키지 require 가 없는 경우)
        if (! $this->hasExternalDependencies($composerJsonPath)) {
            return new VendorBundleResult(
                target: $target,
                zipPath: $zipPath,
                manifestPath: $manifestPath,
                zipSize: 0,
                packageCount: 0,
                skipped: true,
                reason: 'no-external-dependencies',
            );
        }

        // stale 체크
        if (! $force && ! $this->isStale($sourcePath, $outputPath)) {
            $existingSize = file_exists($zipPath) ? (int) filesize($zipPath) : 0;
            $existingManifest = $this->integrityChecker->readManifest($outputPath) ?? [];
            $existingCount = (int) ($existingManifest['package_count'] ?? 0);

            return new VendorBundleResult(
                target: $target,
                zipPath: $zipPath,
                manifestPath: $manifestPath,
                zipSize: $existingSize,
                packageCount: $existingCount,
                skipped: true,
                reason: 'up-to-date',
            );
        }

        // 기존 파일 제거
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
        if (file_exists($manifestPath)) {
            @unlink($manifestPath);
        }

        // === 스테이징 빌드 ===
        // 개발 머신의 vendor/ 를 직접 사용하지 않고, composer install --no-dev 를
        // 스테이징 디렉토리에서 새로 실행하여 dev 의존성과 완전히 분리된 번들을 생성한다.
        $stagingDir = storage_path('app/vendor-bundle-staging/'.uniqid('build-', true));
        File::ensureDirectoryExists($stagingDir, 0755);

        try {
            // 1. composer.json + composer.lock 을 스테이징으로 복사
            File::copy($composerJsonPath, $stagingDir.DIRECTORY_SEPARATOR.'composer.json');
            $sourceLockPath = $sourcePath.DIRECTORY_SEPARATOR.'composer.lock';
            if (file_exists($sourceLockPath)) {
                File::copy($sourceLockPath, $stagingDir.DIRECTORY_SEPARATOR.'composer.lock');
            }

            // 2. 스테이징에서 composer install --no-dev 실행
            $this->runComposerInstall($stagingDir, $target);

            // 3. 생성된 vendor/ 검증
            $stagedVendor = $stagingDir.DIRECTORY_SEPARATOR.'vendor';
            if (! is_dir($stagedVendor)) {
                throw new VendorInstallException(
                    errorKey: 'vendor_dir_not_found',
                    context: ['path' => $stagedVendor.' (composer install 후에도 vendor/ 가 생성되지 않음)'],
                );
            }

            // 4. zip 생성 (EXCLUDE_PATTERNS 만 적용 — 화이트리스트 불필요)
            [$zipSize, $fileCount] = $this->writeZip($stagedVendor, $zipPath);

            // 5. manifest 작성 — 스테이징의 composer.lock 을 기준으로 패키지 목록 추출
            $stagedLockPath = $stagingDir.DIRECTORY_SEPARATOR.'composer.lock';
            $packages = file_exists($stagedLockPath)
                ? $this->collectPackages($stagedLockPath)
                : [];

            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at' => date('c'),
                'generator' => 'g7 vendor-bundle:build',
                'target' => $target,
                // manifest 의 해시는 **소스** 의 composer.json/lock 기준 — 런타임에서
                // 소스 디렉토리(또는 번들과 함께 배포된) composer.json 과 비교하여
                // stale 여부를 감지한다.
                'composer_json_sha256' => $this->integrityChecker->computeFileHash($composerJsonPath),
                'composer_lock_sha256' => file_exists($sourceLockPath)
                    ? $this->integrityChecker->computeFileHash($sourceLockPath)
                    : null,
                'zip_sha256' => $this->integrityChecker->computeFileHash($zipPath),
                'zip_size' => $zipSize,
                'package_count' => count($packages),
                'php_requirement' => $this->extractPhpRequirement($composerJsonPath),
                'g7_version' => config('app.version'),
                'packages' => $packages,
            ];

            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Log::info('Vendor 번들 빌드 완료', [
                'target' => $target,
                'zip_size' => $zipSize,
                'package_count' => count($packages),
                'file_count' => $fileCount,
            ]);

            return new VendorBundleResult(
                target: $target,
                zipPath: $zipPath,
                manifestPath: $manifestPath,
                zipSize: $zipSize,
                packageCount: count($packages),
                skipped: false,
                reason: 'built',
            );
        } finally {
            // 스테이징 정리 (실패/성공 무관)
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
        }
    }

    /**
     * 스테이징 디렉토리에서 `composer install --no-dev` 를 실행합니다.
     *
     * 테스트에서는 setComposerInstallRunner() 로 주입된 closure 를 대신 호출합니다.
     *
     * @throws VendorInstallException composer 미설치, 실행 실패 시
     */
    private function runComposerInstall(string $stagingDir, string $target): void
    {
        // 테스트용 러너 우선
        if ($this->composerInstallRunner !== null) {
            ($this->composerInstallRunner)($stagingDir);

            return;
        }

        $detector = $this->environmentDetector ?? app(EnvironmentDetector::class);
        if (! $detector->canExecuteComposer()) {
            throw new VendorInstallException(
                errorKey: 'composer_not_available_for_build',
            );
        }

        $binary = $detector->findComposerBinary();
        if ($binary === null) {
            throw new VendorInstallException(
                errorKey: 'composer_not_available_for_build',
            );
        }

        $phpBinary = config('process.php_binary', 'php');

        if (str_contains($binary, ' ')) {
            $composerCmd = $binary;
        } elseif (str_ends_with(strtolower($binary), '.phar')) {
            $composerCmd = escapeshellarg($phpBinary).' '.escapeshellarg($binary);
        } else {
            $composerCmd = escapeshellarg($binary);
        }

        $command = $composerCmd.' install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress 2>&1';

        Log::info('vendor-bundle 빌드: composer install 시작', [
            'target' => $target,
            'staging' => $stagingDir,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $stagingDir);

        if (! is_resource($process)) {
            throw new VendorInstallException(
                errorKey: 'bundle_build_composer_failed',
                context: ['exit' => -1, 'message' => "proc_open 실패: {$stagingDir}"],
            );
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new VendorInstallException(
                errorKey: 'bundle_build_composer_failed',
                context: ['exit' => $exitCode, 'message' => (string) $output],
            );
        }

        Log::info('vendor-bundle 빌드: composer install 완료', [
            'target' => $target,
            'staging' => $stagingDir,
        ]);
    }

    /**
     * vendor/ 디렉토리를 재귀적으로 zip에 쓰기.
     *
     * 스테이징에서 composer install --no-dev 로 새로 생성된 vendor/ 를 대상으로 하므로
     * 화이트리스트 필터링은 불필요하며, EXCLUDE_PATTERNS (tests/, docs/ 등) 만 적용한다.
     *
     * @return array{0: int, 1: int}  [zipSize, fileCount]
     */
    private function writeZip(string $vendorPath, string $zipPath): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new VendorInstallException('zip_archive_not_available');
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new VendorInstallException(
                errorKey: 'extraction_failed',
                context: ['message' => 'cannot create zip for writing: '.$zipPath],
            );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // 경로 정규화 — Windows 의 경로 구분자 차이를 제거하여 Linux 와 동일하게 처리
        $normalizedVendorPath = rtrim(str_replace('\\', '/', $vendorPath), '/');
        $vendorPathLen = strlen($normalizedVendorPath);
        $fileCount = 0;

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            // vendor 루트로부터의 상대 경로 계산 (OS 일관)
            $normalizedReal = str_replace('\\', '/', $realPath);
            if (! str_starts_with($normalizedReal, $normalizedVendorPath.'/')) {
                continue;
            }
            $subPath = substr($normalizedReal, $vendorPathLen + 1);
            if ($subPath === '' || $subPath === false) {
                continue;
            }
            $relativePath = 'vendor/'.$subPath;

            if ($this->shouldExclude($relativePath)) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($realPath, $relativePath);
                $fileCount++;
            }
        }

        $zip->close();

        $size = @filesize($zipPath) ?: 0;

        return [$size, $fileCount];
    }

    /**
     * 제외 패턴 검사.
     */
    private function shouldExclude(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDE_PATTERNS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * composer.lock 에서 설치된 패키지 목록 추출.
     *
     * @return array<int, array{name: string, version: string, type: string}>
     */
    private function collectPackages(string $composerLockPath): array
    {
        $json = @file_get_contents($composerLockPath);
        if ($json === false) {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        $packages = [];
        foreach (($data['packages'] ?? []) as $package) {
            if (! is_array($package)) {
                continue;
            }
            $packages[] = [
                'name' => (string) ($package['name'] ?? ''),
                'version' => (string) ($package['version'] ?? ''),
                'type' => (string) ($package['type'] ?? 'library'),
            ];
        }

        return $packages;
    }

    /**
     * composer.json 에서 php 요구 버전 추출.
     */
    private function extractPhpRequirement(string $composerJsonPath): ?string
    {
        $json = @file_get_contents($composerJsonPath);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return $data['require']['php'] ?? null;
    }

    /**
     * composer.json 에 외부 패키지 의존성이 선언되어 있는지 확인합니다.
     *
     * 런타임 제약(php, ext-*)만 있는 경우 false 를 반환합니다.
     * 이런 확장은 vendor 번들링 대상이 아니므로 skip 처리됩니다.
     */
    private function hasExternalDependencies(string $composerJsonPath): bool
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
            // php 버전 제약과 ext-* PHP 확장 제약은 외부 의존성이 아님
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }

            return true;
        }

        return false;
    }
}
