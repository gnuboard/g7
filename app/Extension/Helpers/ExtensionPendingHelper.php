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
     * 확장 교체 시 보존할 최상위 디렉토리명 목록
     *
     * `custom/` 은 운영자가 자기 CSS·JS·정적 파일을 두는 자리다. 확장이 소유한 것이
     * 아니므로 새 배포본으로 덮어써서는 안 된다 — 덮어쓰면 업데이트할 때마다 운영자가
     * 넣은 파일이 사라지고, 그 사실이 어디에도 남지 않는다(파일이 조용히 없어질 뿐이다).
     *
     * 보존은 **교체 경로 둘 다**에서 성립해야 한다: 디렉토리 rename 경로와, 하위 트리에
     * 열린 핸들이 있을 때의 제자리 동기화 폴백. 한쪽만 고치면 Windows 잠금 상황에서만
     * 조용히 사라진다.
     */
    public const PRESERVED_DIRECTORIES = [
        'custom',
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
        //
        // Windows 잠금 대응: 디렉토리 rename 은 하위 트리에 열린 핸들(파일 워처의
        // 디렉토리 핸들, IDE/Node 프로세스가 열어 둔 파일 등)이 하나라도 있으면
        // 실패한다. 잠금 프로세스의 식별·종료는 신뢰할 수 없으므로(디렉토리 핸들은
        // Restart Manager 로 감지 불가), rename 이 차단되면 파일 단위 연산으로
        // 폴백한다 — 파일 생성/덮어쓰기/읽기는 디렉토리 핸들 잠금의 영향을 받지
        // 않아 어떤 프로세스도 종료하지 않고 교체를 완료할 수 있다.
        $basePath = dirname($targetPath);
        $pendingPath = $basePath.DIRECTORY_SEPARATOR.'_pending';
        File::ensureDirectoryExists($pendingPath, 0775);

        $identifier = basename($targetPath);

        // 이전 실패 실행이 남긴 교체용 임시 디렉토리 정리 (best-effort)
        self::cleanupSwapLeftovers($pendingPath, $identifier);

        $tempPath = $pendingPath.DIRECTORY_SEPARATOR.$identifier.'_updating_'.uniqid();
        $oldPath = $pendingPath.DIRECTORY_SEPARATOR.$identifier.'_old_'.uniqid();

        try {
            self::copyDirectoryWithProgress($sourcePath, $tempPath, $sourcePath, $onProgress);

            // 운영자 소유 디렉토리를 새 트리로 옮겨 심는다 (교체 전에 해 둬야 원본이 살아 있다)
            self::carryOverPreservedDirectories($targetPath, $tempPath, $onProgress);
        } catch (\Exception $e) {
            // 복사 실패 시 임시 디렉토리 정리 후 예외 전파
            if (File::isDirectory($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            throw $e;
        }

        // 기존 → _old 이동 (rename, Windows NTFS 타이밍 이슈 대응 재시도)
        if (! self::retryMoveDirectory($targetPath, $oldPath)) {
            // 활성 디렉토리 rename 차단 (하위 트리에 열린 핸들 존재)
            // → 파일 단위 제자리 동기화로 폴백. 덮어쓰기는 잠금의 영향을 받지 않는다.
            $onProgress?->__invoke(null, '디렉토리 이동이 차단되어 파일 단위 제자리 교체로 전환합니다...');
            Log::info('확장 교체: 활성 디렉토리 rename 차단 — 제자리 동기화 폴백', [
                'target' => $targetPath,
            ]);

            try {
                self::syncDirectoryContents($tempPath, $targetPath, $onProgress);
            } finally {
                self::bestEffortDeleteDirectory($tempPath);
            }

            self::refreshRuntimeCaches();

            return;
        }

        // 임시 → 활성 이동 (rename, Windows NTFS 타이밍 이슈 대응 재시도)
        if (! self::retryMoveDirectory($tempPath, $targetPath)) {
            // 방금 복사한 스테이징 트리를 파일 워처가 이미 열어 rename 이 차단된 경우
            // → 파일 단위 복사로 폴백. 소스에는 읽기 접근만 필요해 잠금과 무관하게 성공한다.
            $onProgress?->__invoke(null, '디렉토리 이동이 차단되어 파일 단위 복사로 전환합니다...');
            Log::info('확장 교체: 스테이징 rename 차단 — 파일 단위 복사 폴백', [
                'staging' => $tempPath,
                'target' => $targetPath,
            ]);

            try {
                self::copyDirectoryWithProgress($tempPath, $targetPath, $tempPath, $onProgress);
            } catch (\Exception $e) {
                // 복사 실패: 부분 복사본 제거 후 _old 를 원래 위치로 복원
                self::bestEffortDeleteDirectory($targetPath);
                if (! self::retryMoveDirectory($oldPath, $targetPath)) {
                    try {
                        self::syncDirectoryContents($oldPath, $targetPath, $onProgress);
                    } catch (\Throwable $restoreError) {
                        Log::error('확장 교체 롤백 실패 — 백업 복원이 필요합니다', [
                            'target' => $targetPath,
                            'old' => $oldPath,
                            'error' => $restoreError->getMessage(),
                        ]);
                    }
                }
                throw new \RuntimeException(
                    "Failed to move directory: {$tempPath} → {$targetPath}",
                    0,
                    $e
                );
            }

            self::bestEffortDeleteDirectory($tempPath);
        }

        // 교체 완료 후 _old 삭제 (실패해도 무해 — 다음 교체 시작 시 잔존물 정리가 재시도)
        self::bestEffortDeleteDirectory($oldPath);

        self::refreshRuntimeCaches();
    }

    /**
     * 교체 완료 후 PHP 런타임 캐시를 갱신합니다.
     *
     * 원자적 rename 은 inode 단위로 교체되므로 PHP realpath/stat 캐시가
     * 이전 디렉토리의 파일 존재 여부를 기준으로 판단할 수 있다. 직후 Composer
     * PSR-4 autoload 가 신규 파일(beta.1 에 없던 Seeder/Model)을 file_exists 로
     * 탐색할 때 false 반환 → "Class not found" fatal 로 업그레이드 스텝이 실패.
     * clearstatcache(true) 로 전체 stat 캐시를 비워 신규 파일이 즉시 보이도록 한다.
     */
    private static function refreshRuntimeCaches(): void
    {
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
        // 디렉토리도 부모 소유권을 상속시킨다 — 파일만 `copyFile` 로 상속시키면 sudo 로
        // 실행된 설치/업데이트가 만든 **디렉토리**가 root 소유로 남아, 이후 웹 프로세스의
        // 쓰기가 그 디렉토리에서 막힌다. 형제 구현 `ExtensionBackupHelper::
        // copyDirectoryWithProgress` 는 이미 같은 방어를 갖고 있다 (계층 불균형 해소).
        FilePermissionHelper::inheritOwnershipFromParent($dest);
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
     * 새 버전 콘텐츠를 활성 디렉토리에 파일 단위로 제자리 동기화합니다.
     *
     * 디렉토리 rename 이 외부 프로세스의 핸들 잠금으로 차단될 때 사용하는 폴백입니다.
     * 디렉토리 inode 를 건드리지 않고 파일 생성/덮어쓰기/삭제만 수행하므로
     * 디렉토리 핸들 잠금(파일 워처 등)의 영향을 받지 않습니다.
     *
     * (1) 새 버전 파일 전체를 덮어쓰기 → (2) 새 버전에 없는 잔존 파일 제거.
     * (1) 실패는 예외로 전파해 호출자(매니저)가 백업 복원으로 수습하게 하고,
     * (2) 실패는 로그만 남기고 진행합니다 (업데이트 전체 실패보다 잔존이 낫다).
     *
     * @param  string  $source  새 버전 콘텐츠 디렉토리
     * @param  string  $dest  활성 디렉토리
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws \RuntimeException 새 버전 파일을 심지 못했을 때
     */
    private static function syncDirectoryContents(string $source, string $dest, ?\Closure $onProgress = null): void
    {
        File::ensureDirectoryExists($dest, 0775);
        FilePermissionHelper::inheritOwnershipFromParent($dest);

        $failed = [];
        self::overlayDirectory($source, $dest, $source, $onProgress, $failed);

        if (! empty($failed)) {
            throw new \RuntimeException(
                'Failed to replace files locked by another process: '
                .implode(', ', array_slice($failed, 0, 5))
                .(count($failed) > 5 ? ' (+'.(count($failed) - 5).' more)' : '')
            );
        }

        $staleFailures = [];
        self::removeStaleEntries($source, $dest, $staleFailures, true);

        if (! empty($staleFailures)) {
            Log::warning('확장 제자리 교체: 일부 잔존 파일을 삭제하지 못했습니다 (다음 교체 시 재시도)', [
                'dest' => $dest,
                'failed' => $staleFailures,
            ]);
        }
    }

    /**
     * 운영자 소유 디렉토리를 기존 활성 디렉토리에서 새 트리로 옮겨 심습니다.
     *
     * 새 배포본에 같은 이름의 디렉토리가 있으면 **그것을 치우고** 기존 것을 심는다 —
     * `custom/` 은 확장이 소유하지 않는 자리이므로, 확장이 그 자리에 무언가를 담아
     * 배포했더라도 운영자 파일이 우선한다(그런 배포 자체를 정적 검사가 막는다).
     *
     * @param  string  $existingPath  기존 활성 디렉토리
     * @param  string  $stagingPath  새 배포본이 복사된 임시 디렉토리
     * @param  \Closure|null  $onProgress  진행 콜백
     */
    private static function carryOverPreservedDirectories(
        string $existingPath,
        string $stagingPath,
        ?\Closure $onProgress = null
    ): void {
        foreach (self::PRESERVED_DIRECTORIES as $name) {
            $from = $existingPath.DIRECTORY_SEPARATOR.$name;

            if (! File::isDirectory($from)) {
                continue;
            }

            $to = $stagingPath.DIRECTORY_SEPARATOR.$name;

            if (File::isDirectory($to)) {
                File::deleteDirectory($to);
            }

            $onProgress?->__invoke(null, "운영자 파일 보존: {$name}/");
            self::copyDirectoryWithProgress($from, $to, $from, $onProgress);
        }
    }

    /**
     * 소스 디렉토리의 파일을 대상 디렉토리에 재귀적으로 덮어씁니다.
     *
     * @param  string  $source  소스 디렉토리
     * @param  string  $dest  대상 디렉토리
     * @param  string  $basePath  상대 경로 계산 기준
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array  $failed  교체 실패한 상대 경로 수집 (참조)
     */
    private static function overlayDirectory(
        string $source,
        string $dest,
        string $basePath,
        ?\Closure $onProgress,
        array &$failed
    ): void {
        File::ensureDirectoryExists($dest, 0775);
        // 제자리 동기화 폴백 경로도 동일 방어 — rename 경로만 고치면 파일 잠금으로
        // 이 경로로 떨어진 교체에서만 소유권이 조용히 어긋난다.
        FilePermissionHelper::inheritOwnershipFromParent($dest);
        $items = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir() && in_array($item->getBasename(), self::EXCLUDED_DIRECTORIES, true)) {
                continue;
            }

            $target = $dest.DIRECTORY_SEPARATOR.$item->getBasename();
            $relativePath = ltrim(str_replace($basePath, '', $item->getPathname()), '/\\');

            if ($item->isDir()) {
                if (is_file($target) && ! self::deleteFileWithFallback($target)) {
                    // 파일 → 디렉토리로 바뀐 경로인데 기존 파일을 치우지 못함
                    $failed[] = $relativePath;

                    continue;
                }
                self::overlayDirectory($item->getPathname(), $target, $basePath, $onProgress, $failed);
            } else {
                if (is_dir($target)) {
                    // 디렉토리 → 파일로 바뀐 경로
                    File::deleteDirectory($target);
                }
                $onProgress?->__invoke(null, $relativePath);
                if (! self::replaceFile($item->getPathname(), $target)) {
                    $failed[] = $relativePath;
                }
            }
        }
    }

    /**
     * 파일 하나를 덮어씁니다 (잠금 대응 단계적 폴백).
     *
     * Windows 의 일반적인 파일 열기 모드는 읽기/쓰기 공유를 허용하므로,
     * 다른 프로세스가 열어 둔 파일이라도 내용 덮어쓰기는 대부분 성공합니다.
     * 덮어쓰기가 막힌 경우에만 삭제 후 재생성 → 옆으로 치우기(rename) 순으로
     * 시도합니다. FilePermissionHelper::copyFile() 은 복사 실패를 보고하지 않으므로
     * (File::copy 반환값 무시) 이 경로에서는 네이티브 copy 반환값으로 판정합니다.
     *
     * @param  string  $source  소스 파일
     * @param  string  $destination  대상 파일
     * @return bool 교체 성공 여부
     */
    private static function replaceFile(string $source, string $destination): bool
    {
        File::ensureDirectoryExists(dirname($destination));

        $isExisting = is_file($destination);
        $existingPerms = $isExisting ? @fileperms($destination) : null;
        $existingOwner = $isExisting ? @fileowner($destination) : null;
        $existingGroup = $isExisting ? @filegroup($destination) : null;

        $restoreMeta = function () use ($destination, $isExisting, $existingPerms, $existingOwner, $existingGroup) {
            if (! $isExisting) {
                // 신규 파일: 부모 디렉토리의 소유자/그룹 상속 (sudo 실행 시 root 소유 방지)
                FilePermissionHelper::inheritOwnershipFromParent($destination);

                return;
            }
            if ($existingPerms !== false && $existingPerms !== null) {
                @chmod($destination, $existingPerms);
            }
            if ($existingOwner !== false && $existingOwner !== null && function_exists('chown')) {
                @chown($destination, $existingOwner);
            }
            if ($existingGroup !== false && $existingGroup !== null && function_exists('chgrp')) {
                @chgrp($destination, $existingGroup);
            }
        };

        // 1차: 그대로 덮어쓰기
        if (@copy($source, $destination)) {
            $restoreMeta();

            return true;
        }

        // 2차: 읽기 전용 속성 해제 후 재시도
        @chmod($destination, 0666);
        if (@copy($source, $destination)) {
            $restoreMeta();

            return true;
        }

        // 3차: 삭제 후 재생성
        if (@unlink($destination) && @copy($source, $destination)) {
            $restoreMeta();

            return true;
        }

        // 4차: 잠긴 파일을 옆으로 치우고(rename 은 덮어쓰기와 별개 권한) 새 파일 생성.
        // 옆으로 치운 파일은 즉시 삭제를 시도하고, 실패해도 새 버전에 없는 파일이므로
        // 다음 제자리 동기화의 잔존 파일 제거가 다시 삭제를 시도한다.
        $aside = $destination.'.g7stale_'.uniqid();
        if (@rename($destination, $aside)) {
            @unlink($aside);
            if (@copy($source, $destination)) {
                $restoreMeta();

                return true;
            }
        }

        return false;
    }

    /**
     * 파일 하나를 삭제합니다 (잠금 대응 단계적 폴백).
     *
     * @param  string  $path  삭제할 파일 경로
     * @return bool 삭제(또는 옆으로 치우기) 성공 여부
     */
    private static function deleteFileWithFallback(string $path): bool
    {
        if (@unlink($path)) {
            return true;
        }

        @chmod($path, 0666);
        if (@unlink($path)) {
            return true;
        }

        // 삭제가 막힌 경우 옆으로 치워 원래 이름을 비운다
        $aside = $path.'.g7stale_'.uniqid();
        if (@rename($path, $aside)) {
            @unlink($aside);

            return true;
        }

        return false;
    }

    /**
     * 대상 디렉토리에서 소스에 존재하지 않는 파일/디렉토리를 제거합니다.
     *
     * @param  string  $source  새 버전 콘텐츠 디렉토리
     * @param  string  $dest  활성 디렉토리
     * @param  array  $failures  삭제 실패 경로 수집 (참조)
     */
    private static function removeStaleEntries(string $source, string $dest, array &$failures, bool $isRoot = false): void
    {
        if (! is_dir($dest)) {
            return;
        }

        $items = new \FilesystemIterator($dest, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            // 운영자 소유 디렉토리는 소스에 없어도 정리 대상이 아니다 (확장 루트에서만 판정)
            if ($isRoot && $item->isDir() && in_array($item->getBasename(), self::PRESERVED_DIRECTORIES, true)) {
                continue;
            }

            $counterpart = $source.DIRECTORY_SEPARATOR.$item->getBasename();

            if ($item->isDir()) {
                if (is_dir($counterpart)) {
                    self::removeStaleEntries($counterpart, $item->getPathname(), $failures);
                } else {
                    File::deleteDirectory($item->getPathname());
                    if (is_dir($item->getPathname())) {
                        $failures[] = $item->getPathname();
                    }
                }
            } elseif (! is_file($counterpart)) {
                if (! self::deleteFileWithFallback($item->getPathname())) {
                    $failures[] = $item->getPathname();
                }
            }
        }
    }

    /**
     * 이전 실패 실행이 남긴 교체용 임시 디렉토리를 정리합니다 (best-effort).
     *
     * 잠금으로 인해 삭제하지 못한 `_updating_`/`_old_` 디렉토리는 다음 교체 시작
     * 시점(잠금이 풀린 뒤일 가능성이 높음)에 다시 삭제를 시도합니다.
     * 호출자가 소유한 스테이징 디렉토리(`{identifier}_{Ymd_His}`)는 건드리지 않습니다.
     *
     * @param  string  $pendingPath  _pending 디렉토리 경로
     * @param  string  $identifier  확장 식별자
     */
    private static function cleanupSwapLeftovers(string $pendingPath, string $identifier): void
    {
        foreach (['_updating_', '_old_'] as $marker) {
            $pattern = $pendingPath.DIRECTORY_SEPARATOR.$identifier.$marker.'*';
            foreach (glob($pattern) ?: [] as $leftover) {
                if (is_dir($leftover)) {
                    self::bestEffortDeleteDirectory($leftover);
                }
            }
        }
    }

    /**
     * 디렉토리를 삭제하되, 실패해도 예외를 던지지 않습니다.
     *
     * 잠금으로 삭제하지 못한 디렉토리는 다음 교체 시작 시 잔존물 정리가 재시도합니다.
     *
     * @param  string  $path  삭제할 디렉토리 경로
     */
    private static function bestEffortDeleteDirectory(string $path): void
    {
        if (! File::isDirectory($path)) {
            return;
        }

        File::deleteDirectory($path);

        if (File::isDirectory($path)) {
            Log::info('디렉토리를 완전히 삭제하지 못했습니다 (다음 교체 시 잔존물 정리에서 재시도)', [
                'path' => $path,
            ]);
        }
    }

    /**
     * 확장 디렉토리를 삭제합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     * @return array<int, array{directory: string, archive: string}> 보관된 운영자 디렉토리 목록
     */
    public static function deleteExtensionDirectory(string $basePath, string $identifier): array
    {
        $targetPath = $basePath.DIRECTORY_SEPARATOR.$identifier;

        if (! File::isDirectory($targetPath)) {
            return [];
        }

        $archived = self::archivePreservedDirectories($targetPath, $identifier);

        File::deleteDirectory($targetPath);

        return $archived;
    }

    /**
     * 삭제 전에 운영자 소유 디렉토리를 보관합니다.
     *
     * 확장을 삭제하면 그 안의 `custom/` 도 함께 사라진다 — 운영자가 넣은 파일이므로
     * 되돌릴 방법 없이 없어지면 안 된다. 교체(업데이트)는 보존이 답이지만 삭제는
     * "확장을 없앤다" 는 명시적 의사이므로 막지 않고, 대신 사본을 남기고 그 사실을
     * 기록한다.
     *
     * 보관 실패가 삭제를 막지는 않는다 — 삭제는 운영자가 요청한 동작이다.
     *
     * @param  string  $targetPath  삭제 대상 확장 디렉토리
     * @param  string  $identifier  확장 식별자
     * @return array<int, array{directory: string, archive: string}> 보관에 성공한 디렉토리 목록
     */
    private static function archivePreservedDirectories(string $targetPath, string $identifier): array
    {
        $archived = [];

        foreach (self::PRESERVED_DIRECTORIES as $name) {
            $source = $targetPath.DIRECTORY_SEPARATOR.$name;

            if (! File::isDirectory($source) || self::isEmptyDirectory($source)) {
                continue;
            }

            $archivePath = storage_path(
                'app'.DIRECTORY_SEPARATOR.'extension-custom-backups'
                .DIRECTORY_SEPARATOR.$identifier.'-'.date('Ymd_His')
                .DIRECTORY_SEPARATOR.$name
            );

            try {
                self::copyDirectoryWithProgress($source, $archivePath, $source, null);

                Log::info('확장 삭제: 운영자 파일을 보관했습니다', [
                    'identifier' => $identifier,
                    'directory' => $name,
                    'archive' => $archivePath,
                ]);

                $archived[] = ['directory' => $name, 'archive' => $archivePath];
            } catch (\Throwable $e) {
                Log::warning('확장 삭제: 운영자 파일 보관에 실패했습니다 (삭제는 계속합니다)', [
                    'identifier' => $identifier,
                    'directory' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $archived;
    }

    /**
     * 디렉토리가 비어 있는지 판정합니다.
     *
     * @param  string  $path  대상 디렉토리
     * @return bool 비어 있으면 true
     */
    private static function isEmptyDirectory(string $path): bool
    {
        return ! (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS))->valid();
    }

    /**
     * _pending 디렉토리에 해당 확장이 존재하는지 확인합니다.
     *
     * @param  string  $basePath  확장 타입의 기본 경로
     * @param  string  $identifier  확장 식별자
     * @return bool _pending 존재 여부
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
     * @return bool _bundled 존재 여부
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
     * @return string _pending 절대 경로
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
     * @return string _bundled 절대 경로
     */
    public static function getBundledPath(string $basePath, string $identifier): string
    {
        return $basePath.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier;
    }

    /**
     * 확장 업데이트용 임시 디렉토리(다운로드·추출)를 부모 소유권까지 정합화해 확보합니다.
     *
     * 세 매니저(모듈·플러그인·템플릿)가 같은 경로 규약(`storage/app/temp/{type}_update_{uid}`)을 쓰므로
     * 확보 절차도 한 곳에 둔다 — 복사본은 서로 다른 하드닝을 갖고 갈라진다.
     *
     * @param  string  $tempDir  임시 디렉토리 절대 경로
     */
    public static function ensureUpdateTempDirectory(string $tempDir): void
    {
        // 부모(`storage/app/temp`)는 최초 1회만 만들어지고 자식만 삭제된다 — sudo 코어 업데이트의
        // 번들 확장 업데이트가 그 최초 생성자면 부모가 root 소유로 굳어, 이후 관리자 화면(웹 계정)의
        // 확장 업데이트가 임시 폴더를 만들지 못한다 (#651 F14). 부모는 소유권 상속·그룹 쓰기까지
        // 정합화하는 프리미티브로 확보하고, 자식은 만든 뒤 부모 소유권을 상속시킨다.
        // 확보 실패는 종전 동작(`ensureDirectoryExists` 의 예외 흐름)으로 폴백해 계약을 바꾸지 않는다.
        if (! FilePermissionHelper::ensureWritableDirectory(dirname($tempDir))) {
            File::ensureDirectoryExists($tempDir);

            return;
        }

        File::ensureDirectoryExists($tempDir);
        FilePermissionHelper::inheritOwnershipFromParent($tempDir);
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
