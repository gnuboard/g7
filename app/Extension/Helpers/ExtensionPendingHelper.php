<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 _pending / _bundled 디렉토리 유틸리티
 *
 * _pending 및 _bundled 디렉토리의 확장 스캔, 복사, 삭제 등의 공통 로직을 제공합니다.
 */
class ExtensionPendingHelper
{
    /**
     * 복사에서 제외할 디렉토리명 목록
     *
     * 빌드/테스트용 디렉토리는 _bundled에서만 사용되므로 활성 디렉토리에 불필요합니다.
     */
    public const EXCLUDED_DIRECTORIES = [
        'node_modules',
    ];

    /**
     * _pending 또는 _bundled 디렉토리에서 확장 메타데이터를 로드합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로 (예: base_path('modules'))
     * @param  string  $subDir  하위 디렉토리명 ('_pending' 또는 '_bundled')
     * @param  string  $manifestName  manifest 파일명 ('module.json', 'plugin.json', 'template.json')
     * @return array 확장 메타데이터 배열 (identifier 기준 키)
     */
    public static function loadExtensions(string $basePath, string $subDir, string $manifestName): array
    {
        $scanPath = $basePath.DIRECTORY_SEPARATOR.$subDir;

        if (! File::isDirectory($scanPath)) {
            return [];
        }

        $result = [];
        $dirs = File::directories($scanPath);

        foreach ($dirs as $dir) {
            $manifestPath = $dir.DIRECTORY_SEPARATOR.$manifestName;

            if (! File::exists($manifestPath)) {
                continue;
            }

            $content = File::get($manifestPath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                continue;
            }

            $dirName = basename($dir);
            $identifier = $data['identifier'] ?? $dirName;

            // 디렉토리명과 identifier 가 일치하는 경우만 등록.
            // _pending / _bundled 에는 업데이트·백업 과정의 임시 디렉토리
            // (예: sirsoft-admin_basic_20260402_081819, sirsoft-admin_basic_updating_<uniq>,
            // sirsoft-admin_basic_old_<uniq>) 가 남을 수 있고, 그 내부에 원본 manifest 가 그대로
            // 있어 identifier 가 원본과 동일해진다. 이 경우 표준 경로({basePath}/{subDir}/{identifier})
            // 와 실제 디렉토리 경로가 어긋나 install 이 실패하므로, 엄격히 일치하는 경로만
            // 정식 확장 소스로 인정한다.
            if ($dirName !== $identifier) {
                continue;
            }

            $result[$identifier] = array_merge($data, [
                'identifier' => $identifier,
                'directory' => $dirName,
                'source_path' => $dir,
            ]);
        }

        return $result;
    }

    /**
     * _pending 디렉토리의 확장 메타데이터를 로드합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로 (예: base_path('modules'))
     * @param  string  $manifestName  manifest 파일명
     * @return array 확장 메타데이터 배열
     */
    public static function loadPendingExtensions(string $basePath, string $manifestName): array
    {
        return self::loadExtensions($basePath, '_pending', $manifestName);
    }

    /**
     * _bundled 디렉토리의 확장 메타데이터를 로드합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로 (예: base_path('modules'))
     * @param  string  $manifestName  manifest 파일명
     * @return array 확장 메타데이터 배열
     */
    public static function loadBundledExtensions(string $basePath, string $manifestName): array
    {
        return self::loadExtensions($basePath, '_bundled', $manifestName);
    }

    /**
     * _pending 또는 _bundled에서 활성 디렉토리로 확장을 복사합니다.
     *
     * 원자적 교체 패턴을 사용하여 복사 실패 시에도 기존 디렉토리를 보존합니다.
     * (1) 임시 경로에 소스 복사 → (2) 기존 삭제 → (3) 임시를 이동
     * 1단계 실패 시 기존 디렉토리가 온전히 보존됩니다.
     *
     * 이 메서드는 ExtensionBackupHelper::restoreFromBackup() 및
     * ModuleManager/PluginManager/TemplateManager의 downloadXxxUpdate()에서도
     * 공통 디렉토리 교체 로직으로 사용됩니다.
     *
     * @param  string  $sourcePath  소스 경로 (예: modules/_pending/sirsoft-board)
     * @param  string  $targetPath  대상 경로 (예: modules/sirsoft-board)
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     *
     * @throws \RuntimeException 소스가 존재하지 않을 때
     *
     * @see ExtensionPendingHelperTest File Facade Spy 패턴으로 복사 실패 테스트
     * @see ProtectsExtensionDirectories 확장 활성 디렉토리 보호 trait
     */
    public static function copyToActive(string $sourcePath, string $targetPath, ?\Closure $onProgress = null): void
    {
        if (! File::isDirectory($sourcePath)) {
            throw new \RuntimeException(
                "Source directory does not exist: {$sourcePath}"
            );
        }

        // 기존 대상 디렉토리가 없으면 바로 복사
        if (! File::isDirectory($targetPath)) {
            self::copyDirectoryWithProgress($sourcePath, $targetPath, $sourcePath, $onProgress);

            return;
        }

        // 원자적 교체: 임시 경로에 복사 → 기존을 rename → 임시를 rename → 기존 삭제
        // Windows에서 deleteDirectory 직후 같은 이름으로 rename이 실패하는
        // 타이밍 이슈를 회피하기 위해 rename→rename→delete 패턴을 사용합니다.
        // 임시 디렉토리를 _pending/ 하위에 생성하여 오토로드 오염 방지
        $basePath = dirname($targetPath);
        $pendingPath = $basePath.DIRECTORY_SEPARATOR.'_pending';
        File::ensureDirectoryExists($pendingPath, 0775);

        $identifier = basename($targetPath);
        $tempPath = $pendingPath.DIRECTORY_SEPARATOR.$identifier.'_updating_'.uniqid();
        $oldPath = $pendingPath.DIRECTORY_SEPARATOR.$identifier.'_old_'.uniqid();

        try {
            self::copyDirectoryWithProgress($sourcePath, $tempPath, $sourcePath, $onProgress);
        } catch (\Exception $e) {
            // 복사 실패 시 임시 디렉토리 정리 후 예외 전파
            if (File::isDirectory($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            throw $e;
        }

        // 기존 → _old 이동 (rename, Windows NTFS 타이밍 이슈 대응 재시도)
        if (! self::retryMoveDirectory($targetPath, $oldPath)) {
            // 파일 잠금 감지 및 해제 시도
            if (self::tryReleaseLocks($targetPath, $onProgress)) {
                // 잠금 해제 후 재시도
                if (! self::retryMoveDirectory($targetPath, $oldPath)) {
                    File::deleteDirectory($tempPath);
                    throw new \RuntimeException(
                        "Failed to move existing directory: {$targetPath} → {$oldPath}"
                    );
                }
            } else {
                File::deleteDirectory($tempPath);
                throw new \RuntimeException(
                    "Failed to move existing directory: {$targetPath} → {$oldPath}"
                );
            }
        }

        // 임시 → 활성 이동 (rename, Windows NTFS 타이밍 이슈 대응 재시도)
        if (! self::retryMoveDirectory($tempPath, $targetPath)) {
            // 롤백: _old를 원래 위치로 복원
            File::moveDirectory($oldPath, $targetPath);
            throw new \RuntimeException(
                "Failed to move directory: {$tempPath} → {$targetPath}"
            );
        }

        // 교체 완료 후 _old 삭제 (실패해도 무해)
        File::deleteDirectory($oldPath);

        // 원자적 rename 은 inode 단위로 교체되므로 PHP realpath/stat 캐시가
        // 이전 디렉토리의 파일 존재 여부를 기준으로 판단할 수 있다. 직후 Composer
        // PSR-4 autoload 가 신규 파일(beta.1 에 없던 Seeder/Model)을 file_exists 로
        // 탐색할 때 false 반환 → "Class not found" fatal 로 업그레이드 스텝이 실패.
        // clearstatcache(true) 로 전체 stat 캐시를 비워 신규 파일이 즉시 보이도록 한다.
        clearstatcache(true);

        // opcache 가 활성화된 프로덕션에서는 활성 디렉토리 하위의 이전 컴파일 바이트코드가
        // 남아있을 수 있어 신규 PHP 파일을 즉시 invalidate (재컴파일 유도).
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * 활성 디렉토리의 vendor/를 스테이징 디렉토리로 복사합니다.
     *
     * composer install을 스킵할 때, 스테이징에 vendor/가 없으므로
     * copyToActive() 원자적 교체 전에 기존 vendor/를 보존합니다.
     *
     * @param  string  $activePath  활성 디렉토리 (vendor/ 소스)
     * @param  string  $stagingPath  스테이징 디렉토리 (vendor/ 복사 대상)
     * @param  \Closure|null  $onProgress  진행 콜백
     */
    public static function copyVendorFromActive(string $activePath, string $stagingPath, ?\Closure $onProgress = null): void
    {
        $sourceVendor = $activePath.DIRECTORY_SEPARATOR.'vendor';
        $destVendor = $stagingPath.DIRECTORY_SEPARATOR.'vendor';

        if (! File::isDirectory($sourceVendor)) {
            Log::info('활성 디렉토리에 vendor/ 없음 — 복사 생략', [
                'active' => $activePath,
            ]);

            return;
        }

        $onProgress?->__invoke(null, 'vendor 디렉토리 복사 중 (기존 유지)...');

        self::copyDirectoryWithProgress($sourceVendor, $destVendor, $sourceVendor, $onProgress);

        Log::info('활성 vendor/ → 스테이징 복사 완료', [
            'source' => $sourceVendor,
            'dest' => $destVendor,
        ]);
    }

    /**
     * 파일 잠금을 감지하고 해제를 시도합니다.
     *
     * Windows에서 다른 프로세스(IDE 등)가 파일 핸들을 보유하고 있을 때,
     * 해당 프로세스를 감지하고 종료하여 디렉토리 이동이 가능하도록 합니다.
     * 프로그레스바와 별개로 STDERR에 직접 출력하여 메시지가 덮어씌워지지 않습니다.
     *
     * @param  string  $directoryPath  잠금 해제할 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return bool 잠금 해제 성공 여부
     */
    private static function tryReleaseLocks(string $directoryPath, ?\Closure $onProgress = null): bool
    {
        if (! FileHandleHelper::isWindows()) {
            return false;
        }

        // 프로그레스바에 의해 메시지가 덮어씌워지지 않도록 STDERR에 직접 출력
        $stderr = fopen('php://stderr', 'w');
        $outputCallback = function (string $message) use ($stderr) {
            if ($stderr) {
                fwrite($stderr, $message.PHP_EOL);
            }
            Log::info($message, ['context' => 'file_lock_release']);
        };

        $result = FileHandleHelper::releaseLocks($directoryPath, $outputCallback);

        if ($stderr) {
            fclose($stderr);
        }

        return $result;
    }

    /**
     * 디렉토리 이동을 재시도합니다 (Windows NTFS 타이밍 이슈 대응).
     *
     * Windows에서 rename(A→B) 직후 rename(C→A) 시도 시,
     * NTFS가 경로 A를 완전히 해제하지 않아 실패할 수 있습니다.
     * 최대 3회까지 200ms 간격으로 재시도합니다.
     *
     * @param  string  $from  소스 경로
     * @param  string  $to  대상 경로
     * @return bool 이동 성공 여부
     */
    private static function retryMoveDirectory(string $from, string $to): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(200_000); // 200ms 대기
            }

            if (File::moveDirectory($from, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 디렉토리를 파일별로 복사하며 진행 콜백에 개별 파일을 보고합니다.
     *
     * @param  string  $source  소스 디렉토리
     * @param  string  $dest  대상 디렉토리
     * @param  string  $basePath  상대 경로 계산 기준
     * @param  \Closure|null  $onProgress  진행 콜백
     */
    private static function copyDirectoryWithProgress(
        string $source,
        string $dest,
        string $basePath,
        ?\Closure $onProgress = null
    ): void {
        File::ensureDirectoryExists($dest, 0775);
        $items = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            // 제외 대상 디렉토리는 건너뛰기
            if ($item->isDir() && in_array($item->getBasename(), self::EXCLUDED_DIRECTORIES, true)) {
                continue;
            }

            $target = $dest.DIRECTORY_SEPARATOR.$item->getBasename();
            $relativePath = ltrim(str_replace($basePath, '', $item->getPathname()), '/\\');

            if ($item->isDir()) {
                self::copyDirectoryWithProgress($item->getPathname(), $target, $basePath, $onProgress);
            } else {
                $onProgress?->__invoke(null, $relativePath);
                FilePermissionHelper::copyFile($item->getPathname(), $target);
            }
        }
    }

    /**
     * 확장 디렉토리를 삭제합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     */
    public static function deleteExtensionDirectory(string $basePath, string $identifier): void
    {
        $targetPath = $basePath.DIRECTORY_SEPARATOR.$identifier;

        if (File::isDirectory($targetPath)) {
            File::deleteDirectory($targetPath);
        }
    }

    /**
     * _pending 디렉토리에 해당 확장이 존재하는지 확인합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     */
    public static function isPending(string $basePath, string $identifier): bool
    {
        return File::isDirectory(self::getPendingPath($basePath, $identifier));
    }

    /**
     * _bundled 디렉토리에 해당 확장이 존재하는지 확인합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     */
    public static function isBundled(string $basePath, string $identifier): bool
    {
        return File::isDirectory(self::getBundledPath($basePath, $identifier));
    }

    /**
     * _pending 경로를 반환합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     */
    public static function getPendingPath(string $basePath, string $identifier): string
    {
        return $basePath.DIRECTORY_SEPARATOR.'_pending'.DIRECTORY_SEPARATOR.$identifier;
    }

    /**
     * _bundled 경로를 반환합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     */
    public static function getBundledPath(string $basePath, string $identifier): string
    {
        return $basePath.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier;
    }

    /**
     * 업데이트 스테이징용 타임스탬프 디렉토리를 생성합니다.
     *
     * `{basePath}/_pending/{identifier}_{Ymd_His}/` 형식의 격리된 디렉토리를 생성하여
     * 동시 실행 충돌을 방지합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로 (예: base_path('modules'))
     * @param  string  $identifier  확장 식별자
     * @return string 생성된 스테이징 경로
     */
    public static function createUpdateStagingPath(string $basePath, string $identifier): string
    {
        $timestamp = date('Ymd_His');
        $stagingPath = $basePath.DIRECTORY_SEPARATOR.'_pending'.DIRECTORY_SEPARATOR.$identifier.'_'.$timestamp;

        File::ensureDirectoryExists($stagingPath, 0775, true);

        return $stagingPath;
    }

    /**
     * 소스를 스테이징 디렉토리로 복사합니다.
     *
     * FilePermissionHelper를 사용하여 퍼미션/소유자/소유그룹을 보존합니다.
     *
     * @param  string  $sourcePath  소스 경로
     * @param  string  $stagingPath  스테이징 경로
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return void
     *
     * @throws \RuntimeException 소스가 존재하지 않을 때
     */
    public static function stageForUpdate(string $sourcePath, string $stagingPath, ?\Closure $onProgress = null): void
    {
        if (! File::isDirectory($sourcePath)) {
            throw new \RuntimeException(
                "Source directory does not exist: {$sourcePath}"
            );
        }

        self::copyDirectoryWithProgress($sourcePath, $stagingPath, $sourcePath, $onProgress);
    }

    /**
     * 스테이징 디렉토리를 정리합니다.
     *
     * Windows 환경에서 File::deleteDirectory() 호출 후에도
     * 파일 핸들 해제 지연으로 빈 디렉토리가 남을 수 있습니다.
     * 이를 방지하기 위해 삭제 후 디렉토리가 잔존하면 재시도합니다.
     *
     * @param  string  $stagingPath  스테이징 경로
     * @return void
     */
    public static function cleanupStaging(string $stagingPath): void
    {
        if (! File::isDirectory($stagingPath)) {
            return;
        }

        File::deleteDirectory($stagingPath);

        // Windows: 파일 핸들 해제 지연으로 빈 디렉토리 잔존 시 재시도
        if (File::isDirectory($stagingPath)) {
            usleep(100_000); // 100ms 대기
            File::deleteDirectory($stagingPath);
        }

        // 최종 시도: 재귀적으로 빈 디렉토리만 제거
        if (File::isDirectory($stagingPath)) {
            usleep(200_000); // 200ms 추가 대기
            self::removeEmptyDirectories($stagingPath);
            @rmdir($stagingPath);
        }
    }

    /**
     * 빈 디렉토리를 재귀적으로 제거합니다.
     *
     * @param  string  $path  대상 경로
     * @return bool 디렉토리가 비어있어 삭제되었으면 true
     */
    private static function removeEmptyDirectories(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $items = scandir($path);
        if ($items === false) {
            return false;
        }

        $isEmpty = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($fullPath)) {
                if (! self::removeEmptyDirectories($fullPath)) {
                    $isEmpty = false;
                }
            } else {
                $isEmpty = false;
            }
        }

        if ($isEmpty) {
            @rmdir($path);

            return true;
        }

        return false;
    }
}
