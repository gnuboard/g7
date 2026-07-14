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
     *
     * @param  \Closure|null  $runner  대체 러너 (null 이면 기본 composer install 사용)
     */
    public function setComposerInstallRunner(?\Closure $runner): void
    {
        $this->composerInstallRunner = $runner;
    }

    /**
     * 코어(루트) vendor/를 번들링합니다.
     *
     * 코어는 소스 = 출력 경로가 base_path() 로 동일합니다.
     *
     * @param  bool  $force  manifest 해시 일치 여부와 무관하게 강제 재빌드
     * @return VendorBundleResult 번들 생성 결과 (zip 경로, manifest 경로, 패키지 수 등)
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
     * @param  string  $type  확장 타입 ('module' 또는 'plugin')
     * @param  string  $identifier  확장 식별자 (vendor-name 형식, 예: 'sirsoft-ecommerce')
     * @param  bool  $force  manifest 해시 일치 여부와 무관하게 강제 재빌드
     * @return VendorBundleResult 번들 생성 결과 (활성 디렉토리 부재 시 skipped=true)
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
     * @return bool 번들이 stale 한지 여부 (해시 불일치 또는 manifest 부재). 외부 의존성이 없으면 항상 false
     */
    public function isStale(string $sourcePath, ?string $outputPath = null): bool
    {
        $outputPath ??= $sourcePath;

        // 의존성 판정도 build() 와 같은 파일을 본다 (출력 우선, 없으면 소스 폴백).
        // 소스만 보면 _bundled 에 외부 패키지를 처음 추가한 상태를 "의존성 없음" 으로
        // 오판해 재빌드가 필요 없다고 답한다.
        $composerJsonPath = $this->resolveHashTarget($sourcePath, $outputPath, 'composer.json');
        if ($composerJsonPath !== null && ! $this->hasExternalDependencies($composerJsonPath)) {
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

        // 해시 비교 대상은 manifest 가 놓인 출력 디렉토리 기준 ({@see build()} 참조).
        // 소스만 검사하면 _bundled 의 composer.json 변경을 up-to-date 로 오보한다.
        $hashJsonPath = $this->resolveHashTarget($sourcePath, $outputPath, 'composer.json');
        if ($hashJsonPath !== null) {
            $currentHash = $this->integrityChecker->computeFileHash($hashJsonPath);
            if (($manifest['composer_json_sha256'] ?? null) !== $currentHash) {
                return true;
            }
        }

        $hashLockPath = $this->resolveHashTarget($sourcePath, $outputPath, 'composer.lock');
        if ($hashLockPath !== null) {
            $currentHash = $this->integrityChecker->computeFileHash($hashLockPath);
            if (($manifest['composer_lock_sha256'] ?? null) !== $currentHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * manifest 해시 비교 기준이 될 파일 경로를 결정합니다.
     *
     * manifest 와 zip 은 출력 디렉토리(_bundled)에 함께 저장되어 그대로 배포되며,
     * 런타임 검증({@see VendorIntegrityChecker::verify()})은 zip 이 놓인 디렉토리
     * (_bundled 또는 설치/업데이트 시 그 복사본인 _pending/staging)의 composer.json 을
     * 대조합니다. 따라서 해시도 그 디렉토리를 기준으로 계산해야 생성자와 검증자의
     * 기준이 일치합니다.
     *
     * 출력 디렉토리에 파일이 없으면 소스(활성 디렉토리)로 폴백합니다.
     * 코어는 소스와 출력이 동일하므로 어느 쪽을 골라도 같은 경로가 됩니다.
     *
     * @param  string  $sourcePath  composer.json/lock 과 vendor/ 를 읽는 경로 (활성 디렉토리)
     * @param  string  $outputPath  manifest 와 zip 을 쓰는 경로 (_bundled, 코어는 소스와 동일)
     * @param  string  $filename  'composer.json' 또는 'composer.lock'
     * @return string|null 해시 대상 파일의 절대 경로. 양쪽 모두 없으면 null
     */
    private function resolveHashTarget(string $sourcePath, string $outputPath, string $filename): ?string
    {
        $outputFile = $outputPath.DIRECTORY_SEPARATOR.$filename;
        if (file_exists($outputFile)) {
            return $outputFile;
        }

        $sourceFile = $sourcePath.DIRECTORY_SEPARATOR.$filename;

        return file_exists($sourceFile) ? $sourceFile : null;
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
        // 빌드가 사용하는 composer.json/lock 은 **출력(_bundled) 우선, 없으면 소스 폴백** 이다
        // ({@see resolveHashTarget()}). 설치 입력·해시 기준·런타임 검증이 모두 같은 파일을
        // 보아야 한다.
        //
        // 개발자는 규정상 `_bundled` 에서만 작업하므로 새 패키지를 추가하면 `_bundled` 의
        // composer.json/lock 에만 반영되고 활성 디렉토리는 구버전으로 남는다. 설치 입력만
        // 활성에서 가져오면 구버전 lock 으로 install 한 zip 에 신버전 해시가 붙어, 무결성은
        // 통과하는데 새 패키지가 번들에서 누락된다 (런타임에 클래스 not found).
        // 코어는 source == output 이므로 항상 같은 파일이다.
        $composerJsonPath = $this->resolveHashTarget($sourcePath, $outputPath, 'composer.json');
        if ($composerJsonPath === null) {
            throw new VendorInstallException(
                errorKey: 'composer_json_not_found',
                context: ['path' => $sourcePath.DIRECTORY_SEPARATOR.'composer.json'],
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

        // === 스테이징 빌드 ===
        // 개발 머신의 vendor/ 를 직접 사용하지 않고, composer install --no-dev 를
        // 스테이징 디렉토리에서 새로 실행하여 dev 의존성과 완전히 분리된 번들을 생성한다.
        //
        // 새 zip/manifest 는 임시 경로(.tmp)에 먼저 쓰고, 빌드가 성공한 뒤에만 원본을
        // 교체한다. 기존 파일을 빌드 전에 삭제하면 composer install 실패 시 새 파일이
        // 생성되지 못한 채 원본만 유실되어 이후 module:update 가 번들 부재로 실패한다.
        $stagingDir = storage_path('app/vendor-bundle-staging/'.uniqid('build-', true));
        File::ensureDirectoryExists($stagingDir, 0755);

        $tmpZipPath = $zipPath.'.tmp';
        $tmpManifestPath = $manifestPath.'.tmp';
        if (file_exists($tmpZipPath)) {
            @unlink($tmpZipPath);
        }
        if (file_exists($tmpManifestPath)) {
            @unlink($tmpManifestPath);
        }

        // 설치 입력 = 해시 기준과 동일한 파일 (출력 우선, 없으면 소스 폴백).
        $composerLockPath = $this->resolveHashTarget($sourcePath, $outputPath, 'composer.lock');

        try {
            // 1. composer.json + composer.lock 을 스테이징으로 복사
            File::copy($composerJsonPath, $stagingDir.DIRECTORY_SEPARATOR.'composer.json');
            if ($composerLockPath !== null) {
                File::copy($composerLockPath, $stagingDir.DIRECTORY_SEPARATOR.'composer.lock');
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

            // 4. zip 생성 — 임시 경로에 먼저 쓴다 (EXCLUDE_PATTERNS 만 적용)
            [$zipSize, $fileCount] = $this->writeZip($stagedVendor, $tmpZipPath);

            // 5. manifest 작성 — 스테이징의 composer.lock 을 기준으로 패키지 목록 추출
            $stagedLockPath = $stagingDir.DIRECTORY_SEPARATOR.'composer.lock';
            $packages = file_exists($stagedLockPath)
                ? $this->collectPackages($stagedLockPath)
                : [];

            // manifest 의 해시는 설치 입력과 **동일한** composer.json/lock 기준 (출력 우선).
            // manifest·zip·composer.json 이 함께 배포되는 곳이 출력 디렉토리이며,
            // 런타임 검증도 그 디렉토리를 대조한다 ({@see resolveHashTarget()}).
            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at' => date('c'),
                'generator' => 'g7 vendor-bundle:build',
                'target' => $target,
                'composer_json_sha256' => $this->integrityChecker->computeFileHash($composerJsonPath),
                'composer_lock_sha256' => $composerLockPath !== null
                    ? $this->integrityChecker->computeFileHash($composerLockPath)
                    : null,
                'zip_sha256' => $this->integrityChecker->computeFileHash($tmpZipPath),
                'zip_size' => $zipSize,
                'package_count' => count($packages),
                'php_requirement' => $this->extractPhpRequirement($composerJsonPath),
                'g7_version' => config('app.version'),
                'packages' => $packages,
            ];

            File::put($tmpManifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // 6. 원자적 교체 — 여기까지 성공했으므로 임시 파일을 최종 경로로 옮긴다.
            //    이 지점 이전에 실패하면 기존 번들이 그대로 보존된다.
            $this->promoteAtomic($tmpZipPath, $zipPath);
            $this->promoteAtomic($tmpManifestPath, $manifestPath);

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
            // 스테이징 및 미승격 임시 파일 정리 (실패/성공 무관)
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
            if (file_exists($tmpZipPath)) {
                @unlink($tmpZipPath);
            }
            if (file_exists($tmpManifestPath)) {
                @unlink($tmpManifestPath);
            }
        }
    }

    /**
     * 임시 파일을 최종 경로로 원자적으로 교체합니다.
     *
     * Windows 에서 `rename()` 은 대상이 이미 존재하면 실패하므로, 기존 파일을 먼저
     * 제거한 뒤 이동한다. 이 메서드는 빌드가 성공적으로 완료된 뒤에만 호출되므로,
     * 여기서 기존 파일을 제거해도 (제거 후 이동 실패 확률이 극히 낮아) 안전하다.
     *
     * @param  string  $tmpPath  임시 파일 경로 (성공적으로 생성 완료된 상태)
     * @param  string  $finalPath  최종 경로
     *
     * @throws VendorInstallException 이동 실패 시
     */
    private function promoteAtomic(string $tmpPath, string $finalPath): void
    {
        if (file_exists($finalPath)) {
            @unlink($finalPath);
        }

        if (! @rename($tmpPath, $finalPath)) {
            throw new VendorInstallException(
                errorKey: 'bundle_build_promote_failed',
                context: ['from' => $tmpPath, 'to' => $finalPath],
            );
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

        // 명령 조립과 실행 옵션은 canExecuteComposer 와 동일한 방식으로 통일한다.
        // buildComposerCommand + bypass_shell:true 를 함께 써야 Windows 에서 .bat
        // 경로가 cmd.exe 의 따옴표 처리로 깨지지 않는다.
        $command = $detector->buildComposerCommand($binary, [
            'install', '--no-dev', '--no-scripts', '--prefer-dist', '--no-interaction', '--no-progress',
        ]);

        Log::info('vendor-bundle 빌드: composer install 시작', [
            'target' => $target,
            'staging' => $stagingDir,
        ]);

        // stdout/stderr 는 파이프가 아닌 파일 descriptor 로 받는다.
        // (Windows 회귀) composer 는 autoload dump 단계에서 임시 추출물을
        // `rmdir /S /Q` async `cmd.exe` 손자 프로세스로 정리하는데, stdout/stderr 를
        // 파이프로 열면 이 손자들이 부모 파이프 핸들을 상속받아 붙잡은 채 종료하지 않아
        // 부모(php)의 read/proc_close 가 자식 완료 신호를 영영 받지 못하고 무한 대기한다.
        // 파일 descriptor 는 손자에게 상속될 열린 파이프 핸들이 없어 이 교착을 원천 차단한다
        // (실측: 파이프=행, 파일=140s 완주 EXIT 0 + autoload 정상 생성).
        $outPath = $stagingDir.DIRECTORY_SEPARATOR.'_composer_stdout.log';
        $errPath = $stagingDir.DIRECTORY_SEPARATOR.'_composer_stderr.log';
        $descriptors = self::composerOutputDescriptors($outPath, $errPath);

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $stagingDir,
            EnvironmentDetector::buildComposerEnv(),
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new VendorInstallException(
                errorKey: 'bundle_build_composer_failed',
                context: ['exit' => -1, 'message' => "proc_open 실패: {$stagingDir}"],
            );
        }

        // stdin(파이프)만 즉시 닫고, stdout/stderr 는 파일이므로 배수 불필요 —
        // proc_close 가 자식 종료까지 블록 대기한다.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);

        $stdout = is_file($outPath) ? (string) @file_get_contents($outPath) : '';
        $stderr = is_file($errPath) ? (string) @file_get_contents($errPath) : '';
        $output = trim($stdout.\PHP_EOL.$stderr);

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
     * composer install 의 stdout/stderr 를 받을 proc_open descriptor 배열을 구성합니다.
     *
     * stdout/stderr 를 파이프가 아닌 파일로 여는 이유는 runComposerInstall 주석 참조 —
     * Windows 에서 composer autoload dump 의 async 손자 프로세스가 상속받는 파이프 핸들로
     * 인한 무한 대기(행)를 원천 차단한다. 이 메서드는 순수 함수로, descriptor 구조를
     * 회귀 테스트로 고정하기 위해 분리했다.
     *
     * @param  string  $outPath  stdout 로그 파일 경로
     * @param  string  $errPath  stderr 로그 파일 경로
     * @return array<int, array<int, string>> proc_open 2번째 인자용 descriptor 배열
     */
    public static function composerOutputDescriptors(string $outPath, string $errPath): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['file', $outPath, 'w'],
            2 => ['file', $errPath, 'w'],
        ];
    }

    /**
     * vendor/ 디렉토리를 재귀적으로 zip에 쓰기.
     *
     * 스테이징에서 composer install --no-dev 로 새로 생성된 vendor/ 를 대상으로 하므로
     * 화이트리스트 필터링은 불필요하며, EXCLUDE_PATTERNS (tests/, docs/ 등) 만 적용한다.
     *
     * @return array{0: int, 1: int} [zipSize, fileCount]
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
