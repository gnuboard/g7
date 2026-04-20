<?php

namespace App\Services;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\ChangelogParser;
use App\Extension\Helpers\CoreBackupHelper;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Helpers\GithubHelper;
use App\Extension\UpgradeContext;
use App\Extension\Vendor\VendorInstallContext;
use App\Extension\Vendor\VendorInstallResult;
use App\Extension\Vendor\VendorMode;
use App\Extension\Vendor\VendorResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoreUpdateService
{
    /**
     * GitHub API에서 최신 코어 릴리스를 확인합니다.
     *
     * @return array{update_available: bool, current_version: string, latest_version: string, github_url: string, check_failed?: bool, error?: string}
     */
    public function checkForUpdates(): array
    {
        $currentVersion = CoreVersionChecker::getCoreVersion();
        $githubUrl = config('app.update.github_url');
        $result = $this->fetchLatestVersionFromGithub($githubUrl);

        if ($result['error'] !== null) {
            return [
                'update_available' => false,
                'current_version' => $currentVersion,
                'latest_version' => $currentVersion,
                'github_url' => $githubUrl,
                'check_failed' => true,
                'error' => $result['error'],
            ];
        }

        $latestVersion = $result['version'];
        $updateAvailable = $latestVersion && version_compare($latestVersion, $currentVersion, '>');

        // 업데이트가 있으면 원격 CHANGELOG.md를 다운로드하여 캐시
        if ($updateAvailable) {
            $this->cacheRemoteChangelog($githubUrl, $latestVersion);
        }

        return [
            'update_available' => $updateAvailable,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion ?? $currentVersion,
            'github_url' => $githubUrl,
        ];
    }

    /**
     * 코어 CHANGELOG.md를 파싱합니다.
     *
     * from/to 버전이 지정되면 캐시된 원격 CHANGELOG에서 범위를 추출합니다.
     * 버전 미지정 시 로컬 CHANGELOG 전체를 반환합니다.
     *
     * @param  string|null  $fromVersion  시작 버전
     * @param  string|null  $toVersion  종료 버전
     * @return array 파싱된 변경사항
     */
    public function getChangelog(?string $fromVersion = null, ?string $toVersion = null): array
    {
        // 범위 지정 시: 캐시된 원격 CHANGELOG에서 범위 필터링
        if ($fromVersion && $toVersion) {
            $cachedPath = $this->getRemoteChangelogCachePath();

            if (File::exists($cachedPath)) {
                return ChangelogParser::getVersionRange($cachedPath, $fromVersion, $toVersion);
            }

            // 캐시가 없으면 로컬 파일에서 시도 (폴백)
            $localPath = base_path('CHANGELOG.md');
            if (File::exists($localPath)) {
                return ChangelogParser::getVersionRange($localPath, $fromVersion, $toVersion);
            }

            return [];
        }

        // 범위 미지정 시: 로컬 CHANGELOG 전체
        $changelogPath = base_path('CHANGELOG.md');

        if (! File::exists($changelogPath)) {
            return [];
        }

        return ChangelogParser::parse($changelogPath);
    }

    /**
     * GitHub에서 원격 CHANGELOG.md를 다운로드하여 캐시합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @param  string  $version  최신 버전 (태그명)
     */
    protected function cacheRemoteChangelog(string $githubUrl, string $version): void
    {
        try {
            if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
                return;
            }

            $owner = $matches[1];
            $repo = $matches[2];
            $token = config('app.update.github_token', '');

            $content = GithubHelper::fetchRawFile($owner, $repo, $version, 'CHANGELOG.md', $token);

            if ($content !== null) {
                $cachePath = $this->getRemoteChangelogCachePath();
                File::ensureDirectoryExists(dirname($cachePath));
                File::put($cachePath, $content);
            }
        } catch (\Exception $e) {
            Log::warning('원격 CHANGELOG 캐시 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 원격 CHANGELOG 캐시 파일 경로를 반환합니다.
     *
     * @return string 캐시 파일 경로
     */
    protected function getRemoteChangelogCachePath(): string
    {
        return storage_path('app/temp/core_remote_changelog.md');
    }

    /**
     * 코어 업데이트에 필요한 시스템 요구사항을 검증합니다.
     *
     * @return array{valid: bool, errors: string[], available_methods: string[]}
     */
    public function checkSystemRequirements(): array
    {
        $errors = [];
        $strategies = $this->buildExtractionStrategies();
        $availableMethods = array_map(fn ($s) => $s['label'], $strategies);

        if (empty($strategies)) {
            $errors[] = __('settings.core_update.no_extract_method_available');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'available_methods' => $availableMethods,
        ];
    }

    /**
     * GitHub에서 최신 버전을 조회합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array{version: string|null, error: string|null}
     */
    protected function fetchLatestVersionFromGithub(string $githubUrl): array
    {
        if (! $githubUrl) {
            return ['version' => null, 'error' => __('settings.core_update.github_url_not_configured')];
        }

        try {
            if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
                return ['version' => null, 'error' => __('settings.core_update.invalid_github_url')];
            }

            $owner = $matches[1];
            $repo = $matches[2];
            $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
            $token = (string) (config('app.update.github_token') ?? '');

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'G7',
                    'Accept' => 'application/vnd.github.v3+json',
                ])
                    ->when($token !== '', fn ($r) => $r->withToken($token))
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->get($apiUrl);
            } catch (ConnectionException $e) {
                Log::warning(__('settings.core_update.log_api_call_failed'), [
                    'url' => $apiUrl,
                    'error' => $e->getMessage(),
                ]);

                return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
            }

            $statusCode = $response->status();
            $data = $response->json();
            $apiMessage = is_array($data) && isset($data['message']) ? $data['message'] : '';

            if ($statusCode === 401 || $statusCode === 403) {
                Log::warning(__('settings.core_update.log_auth_failed'), [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'has_token' => $token !== '',
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => $token === ''
                    ? __('settings.core_update.github_token_required')
                    : __('settings.core_update.github_token_invalid', ['status' => $statusCode, 'message' => $apiMessage]),
                ];
            }

            if ($statusCode === 404) {
                // releases/latest 404 → 저장소 자체 존재 여부를 추가 확인
                $repoExists = $this->checkGithubRepoExists($owner, $repo, $token);

                if ($repoExists) {
                    // 저장소는 존재하지만 릴리스가 없음
                    Log::info(__('settings.core_update.log_not_found'), [
                        'url' => $apiUrl,
                        'reason' => 'no_releases',
                    ]);

                    return ['version' => null, 'error' => __('settings.core_update.no_releases_found', ['status' => $statusCode, 'message' => $apiMessage])];
                }

                // 저장소 자체를 찾을 수 없음
                Log::warning(__('settings.core_update.log_not_found'), [
                    'url' => $apiUrl,
                    'has_token' => $token !== '',
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => $token === ''
                    ? __('settings.core_update.github_repo_not_found_no_token', ['status' => $statusCode, 'message' => $apiMessage])
                    : __('settings.core_update.github_repo_not_found', ['status' => $statusCode, 'message' => $apiMessage]),
                ];
            }

            if ($statusCode !== 200) {
                Log::warning(__('settings.core_update.log_unexpected_status'), [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => __('settings.core_update.github_api_error', ['status' => $statusCode, 'message' => $apiMessage])];
            }

            if (is_array($data) && isset($data['tag_name'])) {
                return ['version' => ltrim($data['tag_name'], 'v'), 'error' => null];
            }

            return ['version' => null, 'error' => __('settings.core_update.no_releases_found')];
        } catch (\Exception $e) {
            Log::error(__('settings.core_update.log_version_check_error'), ['error' => $e->getMessage()]);

            return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
        }
    }

    /**
     * GitHub 저장소 존재 여부를 확인합니다.
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소 이름
     * @param  string  $token  GitHub Personal Access Token
     * @return bool 저장소가 존재하면 true
     */
    protected function checkGithubRepoExists(string $owner, string $repo, string $token = ''): bool
    {
        return GithubHelper::checkRepoExists($owner, $repo, $token);
    }

    /**
     * _pending 디렉토리의 존재/퍼미션/소유그룹을 검증합니다.
     *
     * @return array{valid: bool, path: string, errors: array}
     */
    public function validatePendingPath(): array
    {
        $pendingPath = config('app.update.pending_path');
        $errors = [];

        if (! File::isDirectory($pendingPath)) {
            try {
                File::ensureDirectoryExists($pendingPath, 0770, true);
            } catch (\Exception $e) {
                $errors[] = __('settings.core_update.pending_path_create_failed', [
                    'path' => $pendingPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $owner = 'unknown';
        $group = 'unknown';
        $perms = 'unknown';

        if (File::isDirectory($pendingPath)) {
            if (! is_writable($pendingPath)) {
                $errors[] = __('settings.core_update.pending_path_not_writable', ['path' => $pendingPath]);
            }

            $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($pendingPath))['name'] ?? 'unknown' : 'unknown';
            $group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($pendingPath))['name'] ?? 'unknown' : 'unknown';
            $perms = substr(sprintf('%o', fileperms($pendingPath)), -3);
        }

        return [
            'valid' => empty($errors),
            'path' => $pendingPath,
            'owner' => $owner,
            'group' => $group,
            'permissions' => $perms,
            'errors' => $errors,
        ];
    }

    /**
     * GitHub에서 아카이브를 다운로드하여 _pending에 압축 해제합니다.
     *
     * 추출 폴백 체인:
     * 1. zipball + ZipArchive (PHP zip 확장)
     * 2. zipball + unzip 명령어 (Linux만)
     * 3. tarball + PharData (PHP 내장)
     * 4. 모두 실패 시 에러
     *
     * @param  string  $version  다운로드할 버전
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 내 압축 해제된 경로
     */
    public function downloadUpdate(string $version, ?\Closure $onProgress = null): string
    {
        $githubUrl = config('app.update.github_url');

        if (! preg_match('#github\.com[/:]([^/]+)/([^/\.]+)#', $githubUrl, $matches)) {
            throw new \RuntimeException(__('settings.core_update.invalid_github_url'));
        }

        $owner = $matches[1];
        $repo = $matches[2];
        $pendingPath = $this->createPendingDirectory();

        $onProgress?->__invoke('download', __('settings.core_update.downloading'));

        $token = (string) (config('app.update.github_token') ?? '');
        $extractDir = $pendingPath.DIRECTORY_SEPARATOR.'extracted';

        // 폴백 체인: zipball(ZipArchive → unzip) → tarball(PharData)
        $strategies = $this->buildExtractionStrategies();
        $lastError = null;

        foreach ($strategies as $strategy) {
            $archiveType = $strategy['archive_type'];
            $extractMethod = $strategy['method'];
            $label = $strategy['label'];

            // GitHub URL 해석
            $archiveUrl = GithubHelper::resolveArchiveUrl($owner, $repo, $version, $archiveType, $token);
            if (! $archiveUrl) {
                $onProgress?->__invoke('fallback', __('settings.core_update.archive_url_not_found', ['type' => $archiveType]));

                continue;
            }

            $extension = $archiveType === 'zipball' ? '.zip' : '.tar.gz';
            $archivePath = $pendingPath.DIRECTORY_SEPARATOR.'core_update'.$extension;

            try {
                // 다운로드 (Http 파사드 sink 사용 → allow_url_fopen 의존 제거)
                GithubHelper::downloadArchive($archiveUrl, $archivePath, $token);

                $onProgress?->__invoke('extract', __('settings.core_update.extracting_with', ['method' => $label]));

                // 추출 디렉토리 초기화
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }
                File::ensureDirectoryExists($extractDir);

                // 추출 시도
                $this->$extractMethod($archivePath, $extractDir);

                // GitHub 아카이브는 owner-repo-hash/ 형태로 압축해제됨
                $extractedDirs = File::directories($extractDir);
                if (empty($extractedDirs)) {
                    throw new \RuntimeException(__('settings.core_update.extract_empty'));
                }

                $sourcePath = $extractedDirs[0];

                // 아카이브 파일 삭제
                File::delete($archivePath);

                $onProgress?->__invoke('validate', __('settings.core_update.validating'));
                $this->validatePendingUpdate($sourcePath);

                return $sourcePath;
            } catch (\Exception $e) {
                $lastError = $e;

                // 아카이브 파일 정리
                if (File::exists($archivePath)) {
                    File::delete($archivePath);
                }

                // 추출 디렉토리 정리
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }

                $onProgress?->__invoke('fallback', __('settings.core_update.extract_fallback', [
                    'method' => $label,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        // 모든 전략 실패
        throw new \RuntimeException(
            __('settings.core_update.all_extract_methods_failed'),
            0,
            $lastError
        );
    }

    /**
     * 사용 가능한 추출 전략 목록을 빌드합니다.
     *
     * @return array<int, array{archive_type: string, method: string, label: string}>
     */
    protected function buildExtractionStrategies(): array
    {
        $strategies = [];

        // 1단계: ZipArchive (PHP zip 확장)
        if (class_exists(\ZipArchive::class)) {
            $strategies[] = [
                'archive_type' => 'zipball',
                'method' => 'extractWithZipArchive',
                'label' => 'ZipArchive',
            ];
        }

        // 2단계: unzip 명령어 (Linux만)
        if (PHP_OS_FAMILY !== 'Windows' && $this->isUnzipAvailable()) {
            $strategies[] = [
                'archive_type' => 'zipball',
                'method' => 'extractWithUnzip',
                'label' => 'unzip',
            ];
        }

        return $strategies;
    }

    /**
     * ZipArchive를 사용하여 ZIP 파일을 압축 해제합니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $extractDir  추출 대상 디렉토리
     */
    protected function extractWithZipArchive(string $zipPath, string $extractDir): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('settings.core_update.zip_extract_failed'));
        }

        $zip->extractTo($extractDir);
        $zip->close();
    }

    /**
     * 시스템 unzip 명령어를 사용하여 ZIP 파일을 압축 해제합니다.
     *
     * @param  string  $zipPath  ZIP 파일 경로
     * @param  string  $extractDir  추출 대상 디렉토리
     */
    protected function extractWithUnzip(string $zipPath, string $extractDir): void
    {
        $escapedZip = escapeshellarg($zipPath);
        $escapedDir = escapeshellarg($extractDir);

        exec("unzip -o {$escapedZip} -d {$escapedDir} 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(__('settings.core_update.unzip_command_failed', [
                'code' => $exitCode,
                'output' => implode("\n", array_slice($output, -5)),
            ]));
        }
    }

    /**
     * 시스템에 unzip 명령어가 사용 가능한지 확인합니다.
     */
    protected function isUnzipAvailable(): bool
    {
        exec('which unzip 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * 다운로드된 업데이트 패키지를 검증합니다.
     *
     * @param  string  $pendingPath  검증할 경로
     *
     * @throws \RuntimeException 검증 실패 시
     */
    public function validatePendingUpdate(string $pendingPath): void
    {
        if (! File::exists($pendingPath.DIRECTORY_SEPARATOR.'composer.json')) {
            throw new \RuntimeException(__('settings.core_update.invalid_package'));
        }

        if (! File::isDirectory($pendingPath.DIRECTORY_SEPARATOR.'app')) {
            throw new \RuntimeException(__('settings.core_update.invalid_package'));
        }

        // 그누보드7 프로젝트인지 확인 (config/app.php의 version 키 존재 여부)
        $configPath = $pendingPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        if (! File::exists($configPath)) {
            throw new \RuntimeException(__('settings.core_update.invalid_package_not_g7'));
        }

        $config = include $configPath;
        if (! is_array($config) || ! isset($config['version'])) {
            throw new \RuntimeException(__('settings.core_update.invalid_package_not_g7'));
        }
    }

    /**
     * 외부 소스 디렉토리를 _pending 경로로 복제합니다.
     *
     * --source 모드에서 원본 소스 디렉토리를 보호하기 위해
     * _pending으로 복사한 뒤 해당 경로에서 작업합니다.
     *
     * @param  string  $sourceDir  원본 소스 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 경로
     */
    public function copySourceToPending(string $sourceDir, ?\Closure $onProgress = null): string
    {
        $pendingPath = $this->createPendingDirectory();

        $onProgress?->__invoke('copy', '소스 디렉토리 복제 중...');

        FilePermissionHelper::copyDirectory($sourceDir, $pendingPath, $onProgress);

        return $pendingPath;
    }

    /**
     * 외부 ZIP 파일을 _pending으로 추출합니다.
     *
     * --zip 모드에서 사용합니다. ZIP 구조가 GitHub 릴리스 zipball(owner-repo-hash/ 래퍼)이든
     * 평탄한 G7 루트든 모두 지원합니다. 추출 후 validatePendingUpdate() 로 G7 패키지
     * 구조(composer.json + app/ + config/app.php)를 검증합니다.
     *
     * 추출 전략은 downloadUpdate() 와 동일한 폴백 체인(ZipArchive → unzip) 을 사용하며,
     * GitHub 호출은 수행하지 않습니다.
     *
     * @param  string  $zipPath  외부 ZIP 파일 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 내 추출된 소스 경로 (래퍼 감지 후)
     *
     * @throws \RuntimeException ZIP 미존재 / 추출 실패 / 패키지 검증 실패 시
     */
    public function extractZipToPending(string $zipPath, ?\Closure $onProgress = null): string
    {
        if (! File::exists($zipPath)) {
            throw new \RuntimeException(__('settings.core_update.zip_file_not_found', ['path' => $zipPath]));
        }

        $pendingPath = $this->createPendingDirectory();
        $extractDir = $pendingPath.DIRECTORY_SEPARATOR.'extracted';
        File::ensureDirectoryExists($extractDir);

        $strategies = $this->buildExtractionStrategies();
        if (empty($strategies)) {
            throw new \RuntimeException(__('settings.core_update.no_extract_method_available'));
        }

        $lastError = null;
        foreach ($strategies as $strategy) {
            $method = $strategy['method'];
            $label = $strategy['label'];

            $onProgress?->__invoke('extract', __('settings.core_update.extracting_with', ['method' => $label]));

            try {
                // 전 전략 잔여물 제거
                if (File::isDirectory($extractDir)) {
                    File::deleteDirectory($extractDir);
                }
                File::ensureDirectoryExists($extractDir);

                $this->$method($zipPath, $extractDir);

                $sourcePath = $this->resolveExtractedRoot($extractDir);

                $onProgress?->__invoke('validate', __('settings.core_update.validating'));
                $this->validatePendingUpdate($sourcePath);

                return $sourcePath;
            } catch (\Throwable $e) {
                $lastError = $e;
                $onProgress?->__invoke('fallback', __('settings.core_update.extract_fallback', [
                    'method' => $label,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        throw new \RuntimeException(
            __('settings.core_update.all_extract_methods_failed'),
            0,
            $lastError
        );
    }

    /**
     * 추출 디렉토리에서 G7 소스 루트를 판별합니다.
     *
     * - 하위에 디렉토리 1개, 파일 0개 → 래퍼 디렉토리(GitHub zipball 등) → 하위 반환
     * - 그 외 → extractDir 자체를 반환 (composer.json 등이 루트에 있다고 가정)
     *
     * @param  string  $extractDir  추출 대상 디렉토리
     * @return string 확장 소스 디렉토리 경로
     */
    protected function resolveExtractedRoot(string $extractDir): string
    {
        $dirs = File::directories($extractDir);
        $files = File::files($extractDir);

        if (count($dirs) === 1 && count($files) === 0) {
            return $dirs[0];
        }

        return $extractDir;
    }

    /**
     * 코어 핵심 파일을 백업합니다.
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string 백업 경로
     */
    public function createBackup(?\Closure $onProgress = null): string
    {
        $targets = array_merge(
            config('app.update.targets', []),
            config('app.update.backup_only', []),
            config('app.update.backup_extra', [])
        );

        $excludes = config('app.update.excludes', []);

        return CoreBackupHelper::createBackup($targets, $onProgress, $excludes);
    }

    /**
     * 코어 업데이트 대상 파일만 선택적으로 덮어씁니다.
     *
     * 주의: ExtensionPendingHelper::copyToActive()는 PHP copy()를 사용하여
     * 파일 퍼미션/소유자를 보존하지 않으므로, 코어 업데이트에서는 사용하지 않습니다.
     * 대신 FilePermissionHelper::copyDirectory()로 기존 퍼미션을 유지합니다.
     *
     * @param  string  $sourcePath  소스 경로 (_pending 내)
     * @param  \Closure|null  $onProgress  진행 콜백
     */
    public function applyUpdate(string $sourcePath, ?\Closure $onProgress = null): void
    {
        $targets = config('app.update.targets', []);
        $excludes = config('app.update.excludes', []);

        foreach ($targets as $target) {
            $src = $sourcePath.DIRECTORY_SEPARATOR.$target;
            $dest = base_path($target);

            if (! File::exists($src) && ! File::isDirectory($src)) {
                continue;
            }

            $onProgress?->__invoke('apply', $target);

            if (File::isDirectory($src)) {
                FilePermissionHelper::copyDirectory($src, $dest, $onProgress, $excludes, removeOrphans: true);
            } else {
                File::ensureDirectoryExists(dirname($dest));
                FilePermissionHelper::copyFile($src, $dest);
            }
        }
    }

    /**
     * _pending 디렉토리에서 composer install을 실행합니다 (사전 검증용).
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws \RuntimeException 실행 실패 시
     */
    public function runComposerInstallInPending(string $pendingPath, ?\Closure $onProgress = null): void
    {
        $this->executeComposerInstall($pendingPath, $onProgress, noScripts: true);
    }

    /**
     * _pending 디렉토리의 vendor/ 를 구성합니다 (VendorResolver 경유).
     *
     * VendorMode에 따라:
     * - Composer: 기존 흐름 재사용 (runComposerInstallInPending + copyVendorFromPending)
     * - Bundled: vendor-bundle.zip 추출 (pending 디렉토리에 배치 후 운영 vendor로 복사 필요)
     * - Auto: EnvironmentDetector 기반 자동 결정
     *
     * 본 메서드 완료 후 vendor/ 는 _pending 내부에 위치하며,
     * 이후 copyVendorFromPending() 또는 bundle 내장 vendor 직접 사용으로 운영 반영됨.
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @param  VendorMode  $mode  요청된 vendor 설치 모드
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws \RuntimeException 실행 실패 시
     */
    public function runVendorInstallInPending(
        string $pendingPath,
        VendorMode $mode = VendorMode::Auto,
        ?\Closure $onProgress = null,
    ): VendorInstallResult {
        $resolver = App::make(VendorResolver::class);

        $context = new VendorInstallContext(
            target: 'core',
            identifier: null,
            sourceDir: $pendingPath,
            targetDir: $pendingPath,
            requestedMode: $mode,
            composerBinaryHint: config('process.composer_binary'),
            operation: 'update',
        );

        // Composer 전략 시 기존 코어 로직을 콜백으로 전달 (코드 중복 방지)
        $composerExecutor = function (VendorInstallContext $ctx) use ($onProgress): VendorInstallResult {
            $this->runComposerInstallInPending($ctx->sourceDir, $onProgress);

            return new VendorInstallResult(
                mode: VendorMode::Composer,
                strategy: 'composer',
                packageCount: 0,
                details: ['pending_path' => $ctx->sourceDir],
            );
        };

        return $resolver->install($context, $composerExecutor);
    }

    /**
     * _pending과 운영 디렉토리의 composer.json/composer.lock이 동일한지 확인합니다.
     *
     * 두 파일이 모두 동일하면 composer install 및 vendor 복사를 스킵할 수 있습니다.
     *
     * @param  string  $pendingPath  _pending 내 소스 경로
     * @return bool 두 파일이 모두 동일하면 true
     */
    public function isComposerUnchangedForCore(string $pendingPath): bool
    {
        $pendingJson = $pendingPath.DIRECTORY_SEPARATOR.'composer.json';
        $pendingLock = $pendingPath.DIRECTORY_SEPARATOR.'composer.lock';
        $baseJson = base_path('composer.json');
        $baseLock = base_path('composer.lock');

        // composer.json 비교
        if (! file_exists($pendingJson) || ! file_exists($baseJson)) {
            return false;
        }

        if (md5_file($pendingJson) !== md5_file($baseJson)) {
            Log::info('코어 업데이트: composer.json 변경 감지');

            return false;
        }

        // composer.lock 비교
        $pendingLockExists = file_exists($pendingLock);
        $baseLockExists = file_exists($baseLock);

        if ($pendingLockExists !== $baseLockExists) {
            Log::info('코어 업데이트: composer.lock 존재 여부 불일치');

            return false;
        }

        if ($pendingLockExists && $baseLockExists) {
            if (md5_file($pendingLock) !== md5_file($baseLock)) {
                Log::info('코어 업데이트: composer.lock 변경 감지');

                return false;
            }
        }

        Log::info('코어 업데이트: composer 의존성 변경 없음 — 스킵 가능');

        return true;
    }

    /**
     * 운영 디렉토리에서 composer install을 실행합니다 (파일 덮어쓰기 후 autoload 갱신용).
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws \RuntimeException 실행 실패 시
     */
    public function runComposerInstall(?\Closure $onProgress = null): void
    {
        $this->executeComposerInstall(base_path(), $onProgress);
    }

    /**
     * _pending(또는 소스) 디렉토리의 vendor를 운영 디렉토리로 복사합니다.
     *
     * composer install을 2번 실행하는 대신, _pending에서 이미 설치된 vendor를
     * 운영 디렉토리로 직접 복사하여 효율성을 높입니다.
     *
     * @param  string  $pendingPath  _pending 또는 소스 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     *
     * @throws \RuntimeException vendor 디렉토리가 없을 경우
     */
    public function copyVendorFromPending(string $pendingPath, ?\Closure $onProgress = null): void
    {
        $sourceVendor = $pendingPath.DIRECTORY_SEPARATOR.'vendor';
        $destVendor = base_path('vendor');

        if (! File::isDirectory($sourceVendor)) {
            throw new \RuntimeException('소스 디렉토리에 vendor가 없습니다. composer install이 실행되지 않았을 수 있습니다.');
        }

        $onProgress?->__invoke('vendor', 'vendor 디렉토리 복사 중...');

        // 기존 vendor/ 내용만 비움 — 디렉토리 자체는 유지하여 공유 호스팅에서
        // 프로젝트 루트 쓰기 권한이 없어도 작동하도록 한다.
        // (File::deleteDirectory($destVendor) 는 vendor/ 자체를 삭제하므로
        //  base_path() 에 쓰기 권한이 있어야 하는 문제를 회피)
        if (File::isDirectory($destVendor)) {
            File::cleanDirectory($destVendor);
        } else {
            File::ensureDirectoryExists($destVendor);
        }

        FilePermissionHelper::copyDirectory($sourceVendor, $destVendor, $onProgress);
    }

    /**
     * 지정 디렉토리에서 composer install을 별도 프로세스로 실행합니다.
     *
     * @param  string  $workingDir  작업 디렉토리
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  bool  $noScripts  post-autoload-dump 등 스크립트 건너뛰기 (_pending용)
     *
     * @throws \RuntimeException 실행 실패 시
     */
    protected function executeComposerInstall(string $workingDir, ?\Closure $onProgress = null, bool $noScripts = false): void
    {
        $onProgress?->__invoke('composer', __('settings.core_update.running_composer'));

        $composerBin = config('process.composer_binary');
        $phpBinary = config('process.php_binary', 'php');

        if ($composerBin) {
            if (str_contains($composerBin, ' ')) {
                // 공백 포함 = 전체 실행 명령어 (예: "/usr/local/php84/bin/php /home/user/g7/composer.phar")
                $composerCmd = $composerBin;
            } elseif (str_ends_with($composerBin, '.phar')) {
                // .phar인 경우 PHP 바이너리로 실행
                $composerCmd = escapeshellarg($phpBinary).' '.escapeshellarg($composerBin);
            } else {
                $composerCmd = escapeshellarg($composerBin);
            }
        } else {
            $composerCmd = 'composer';
        }

        $command = $composerCmd.' install --no-dev --optimize-autoloader --no-interaction'.($noScripts ? ' --no-scripts' : '').' 2>&1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDir);

        if (! is_resource($process)) {
            throw new \RuntimeException(__('settings.core_update.composer_failed'));
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        Log::info('코어 업데이트: composer install 완료', [
            'working_dir' => $workingDir,
            'exit_code' => $exitCode,
            'output' => $output,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException(__('settings.core_update.composer_failed')."\n".$output);
        }
    }

    /**
     * 데이터베이스 마이그레이션을 실행합니다.
     */
    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * 코어 역할/권한을 동기화합니다.
     *
     * RolePermissionSeeder와 달리 기존 데이터를 삭제하지 않고,
     * ExtensionRoleSyncHelper를 사용하여 user_overrides를 보존합니다.
     *
     * - 신규 권한: 생성
     * - 기존 권한: 항상 덮어쓰기 (Permission은 유저 수정 불가)
     * - 신규 역할: 생성
     * - 기존 역할: user_overrides에 없는 필드만 갱신
     * - 역할-권한 매핑: user_overrides에 기록된 개별 권한 식별자는 보호
     */
    public function syncCoreRolesAndPermissions(): void
    {
        $roleSyncHelper = app(ExtensionRoleSyncHelper::class);
        $permConfig = $this->getCorePermissionDefinitions();
        $moduleConfig = $permConfig['module'];

        // 1레벨: 코어 모듈 권한
        $coreModule = $roleSyncHelper->syncPermission(
            identifier: $moduleConfig['identifier'],
            newName: $moduleConfig['name'],
            newDescription: $moduleConfig['description'],
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            otherAttributes: [
                'type' => isset($moduleConfig['type'])
                    ? PermissionType::from($moduleConfig['type'])
                    : PermissionType::Admin,
                'order' => $moduleConfig['order'],
                'parent_id' => null,
            ],
        );

        // 모든 리프 권한 식별자를 수집 (역할-권한 매핑용)
        $allLeafIdentifiers = [];

        // 2레벨: 카테고리 + 3레벨: 개별 권한
        $categories = $permConfig['categories'];

        foreach ($categories as $categoryData) {
            // 카테고리 type 결정 우선순위:
            // 1. 카테고리에 명시적 type 필드
            // 2. 모든 하위 권한이 동일한 type → 그 type
            // 3. 그 외 → admin (기본값)
            $childTypes = collect($categoryData['permissions'] ?? [])
                ->map(fn ($p) => $p['type'] ?? 'admin')
                ->unique();

            $categoryType = ($childTypes->count() === 1 && $childTypes->first() === 'user')
                ? PermissionType::User
                : PermissionType::Admin;

            if (isset($categoryData['type'])) {
                $categoryType = PermissionType::from($categoryData['type']);
            }

            $category = $roleSyncHelper->syncPermission(
                identifier: $categoryData['identifier'],
                newName: $categoryData['name'],
                newDescription: $categoryData['description'],
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                otherAttributes: [
                    'type' => $categoryType,
                    'order' => $categoryData['order'],
                    'parent_id' => $coreModule->id,
                ],
            );

            foreach ($categoryData['permissions'] as $permData) {
                // 개별 권한 type: 명시 우선, 없으면 카테고리 type 상속
                $permissionType = isset($permData['type'])
                    ? PermissionType::from($permData['type'])
                    : $categoryType;

                $roleSyncHelper->syncPermission(
                    identifier: $permData['identifier'],
                    newName: $permData['name'],
                    newDescription: $permData['description'],
                    extensionType: ExtensionOwnerType::Core,
                    extensionIdentifier: 'core',
                    otherAttributes: [
                        'type' => $permissionType,
                        'order' => $permData['order'],
                        'parent_id' => $category->id,
                        'resource_route_key' => $permData['resource_route_key'] ?? null,
                        'owner_key' => $permData['owner_key'] ?? null,
                    ],
                );

                $allLeafIdentifiers[] = $permData['identifier'];
            }
        }

        // 2. 코어 역할 동기화 (user_overrides 보존)
        $coreRoles = $this->getCoreRoleDefinitions();

        // 역할-권한 매핑 구축: permIdentifier → [roleIdentifier, ...]
        $permissionRoleMap = [];

        foreach ($coreRoles as $roleDef) {
            $roleSyncHelper->syncRole(
                identifier: $roleDef['identifier'],
                newDescription: $roleDef['description'],
                newName: $roleDef['name'],
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                otherAttributes: $roleDef['attributes'] ?? [],
            );

            // 역할-권한 매핑 수집
            $rolePerms = $roleDef['permissions'];
            if ($rolePerms === 'all_leaf') {
                $rolePerms = $allLeafIdentifiers;
            }

            if (is_array($rolePerms)) {
                foreach ($rolePerms as $permIdentifier) {
                    $permissionRoleMap[$permIdentifier][] = $roleDef['identifier'];
                }
            }
        }

        // 3. 역할-권한 할당 동기화 (user_overrides 보호)
        // 코어: core/core 소유 전체 권한을 diff 범위로 사용해 이관된 구 식별자도 detach 가능
        $allCorePermIdentifiers = app(\App\Contracts\Repositories\PermissionRepositoryInterface::class)
            ->getByExtension(ExtensionOwnerType::Core, 'core')
            ->pluck('identifier')
            ->all();
        $roleSyncHelper->syncAllRoleAssignments($permissionRoleMap, $allCorePermIdentifiers);

        // 완전 동기화: config 에서 제거된 stale 권한 삭제 (user_overrides 보존)
        // leaf + 카테고리 + 모듈 레벨 식별자를 모두 수집해서 diff
        $allDefinedIdentifiers = array_merge(
            [$moduleConfig['identifier']],
            array_column($categories, 'identifier'),
            $allLeafIdentifiers,
        );
        $deletedPerms = $roleSyncHelper->cleanupStalePermissions(
            ExtensionOwnerType::Core,
            'core',
            $allDefinedIdentifiers,
        );

        // 완전 동기화: config 에서 제거된 stale 역할 삭제 (user_overrides + user_roles 참조 보존)
        $definedRoleIdentifiers = array_column($coreRoles, 'identifier');
        $deletedRoles = $roleSyncHelper->cleanupStaleRoles(
            ExtensionOwnerType::Core,
            'core',
            $definedRoleIdentifiers,
        );

        Log::info('코어 역할/권한 동기화 완료', [
            'stale_permissions_deleted' => $deletedPerms,
            'stale_roles_deleted' => $deletedRoles,
        ]);
    }

    /**
     * 코어 메뉴를 동기화합니다.
     *
     * CoreAdminMenuSeeder와 달리 기존 데이터를 삭제하지 않고,
     * ExtensionMenuSyncHelper를 사용하여 user_overrides를 보존합니다.
     *
     * - 신규 메뉴: 생성
     * - 기존 메뉴: user_overrides에 없는 필드(name, icon, order, url)만 갱신
     */
    public function syncCoreMenus(): void
    {
        $menuSyncHelper = app(ExtensionMenuSyncHelper::class);
        $coreMenus = $this->getCoreMenuDefinitions();

        foreach ($coreMenus as $menuData) {
            $menuSyncHelper->syncMenuRecursive(
                menuData: $menuData,
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                parentId: null,
            );
        }

        // 완전 동기화: config 에서 제거된 stale 메뉴 삭제 (user_overrides 보존)
        $currentSlugs = $menuSyncHelper->collectSlugsRecursive($coreMenus);
        $deleted = $menuSyncHelper->cleanupStaleMenus(
            ExtensionOwnerType::Core,
            'core',
            $currentSlugs,
        );

        Log::info('코어 메뉴 동기화 완료', ['stale_deleted' => $deleted]);
    }

    /**
     * 디스크의 config/core.php 를 재로드하고 코어 권한/메뉴를 재동기화합니다.
     *
     * Laravel 은 프로세스 시작 시점에 로드한 config 를 재로드하지 않으므로,
     * 업데이트로 config/core.php 가 교체되어도 현재 프로세스의 `config('core.*')`
     * 는 이전 값을 반환한다. 본 메서드는 디스크 값을 다시 require 하여 Config
     * Repository 에 주입한 뒤 syncCoreRolesAndPermissions/syncCoreMenus 를
     * 재호출하여 신규 권한·메뉴를 DB 에 반영한다.
     *
     * 주 사용처: CoreUpdateCommand Step 10 에서 별도 프로세스 spawn 이
     * 실패했을 때의 in-process fallback. 수동 복구 도구로도 사용 가능.
     *
     * ⚠ 경로 A(beta.1 → beta.2) 에서는 직접 호출 금지. beta.1 메모리에는
     * 본 메서드가 존재하지 않으므로 Fatal 발생. 해당 경로의 upgrade step 은
     * 파일 내부 로컬 로직으로 config 재로드 + sync 재호출을 직접 구현해야 한다.
     */
    public function reloadCoreConfigAndResync(): void
    {
        $path = config_path('core.php');
        if (! File::exists($path)) {
            Log::warning('reloadCoreConfigAndResync: config/core.php 미존재 — 스킵');

            return;
        }

        $fresh = require $path;
        if (! is_array($fresh)) {
            Log::warning('reloadCoreConfigAndResync: config/core.php 반환값이 배열이 아님 — 스킵');

            return;
        }

        config(['core' => $fresh]);

        try {
            $this->syncCoreRolesAndPermissions();
        } catch (\Throwable $e) {
            Log::warning('reloadCoreConfigAndResync: 권한 재동기화 실패', ['error' => $e->getMessage()]);
        }

        try {
            $this->syncCoreMenus();
        } catch (\Throwable $e) {
            Log::warning('reloadCoreConfigAndResync: 메뉴 재동기화 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 코어 업그레이드 스텝을 실행합니다.
     * 각 스텝에서 환경설정 파일 생성, 데이터 마이그레이션 등을 수행합니다.
     *
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @param  \Closure|null  $onStep  각 스텝 실행 시 콜백 (버전 문자열 전달)
     * @param  bool  $force  true 시 fromVersion == toVersion이면 해당 버전 스텝도 포함
     */
    public function runUpgradeSteps(string $fromVersion, string $toVersion, ?\Closure $onStep = null, bool $force = false): void
    {
        $upgradesPath = base_path('upgrades');

        if (! File::isDirectory($upgradesPath)) {
            return;
        }

        // force + 동일 버전: 해당 버전의 스텝도 포함 (>= 비교)
        $sameVersion = version_compare($fromVersion, $toVersion, '==');

        $steps = [];
        $files = File::files($upgradesPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();
            if (! preg_match('/^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/', $filename, $matches)) {
                continue;
            }

            $version = "{$matches[1]}.{$matches[2]}.{$matches[3]}";

            if (! empty($matches[4])) {
                $version .= '-'.str_replace('_', '.', $matches[4]);
            }

            $included = $force && $sameVersion
                ? version_compare($version, $toVersion, '==')
                : version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=');

            if ($included) {
                require_once $file->getPathname();
                $className = "App\\Upgrades\\{$filename}";

                if (class_exists($className)) {
                    $instance = new $className;
                    if ($instance instanceof UpgradeStepInterface) {
                        $steps[$version] = $instance;
                    }
                }
            }
        }

        uksort($steps, 'version_compare');

        $context = new UpgradeContext($fromVersion, $toVersion);

        foreach ($steps as $version => $step) {
            $onStep?->__invoke($version);
            Log::info("코어 업그레이드 스텝 실행: {$version}");
            $step->run($context->withCurrentStep($version));
        }
    }

    /**
     * .env 파일의 APP_VERSION을 갱신합니다.
     *
     * @param  string  $version  새 버전
     */
    public function updateVersionInEnv(string $version): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return;
        }

        $content = File::get($envPath);

        if (preg_match('/^APP_VERSION=.*/m', $content)) {
            $content = preg_replace('/^APP_VERSION=.*/m', "APP_VERSION={$version}", $content);
        } else {
            $content .= "\nAPP_VERSION={$version}\n";
        }

        File::put($envPath, $content);
    }

    /**
     * 백업에서 코어 파일을 복원합니다.
     *
     * @param  string  $backupPath  백업 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return array 복원 실패한 target 목록 (빈 배열이면 전체 성공)
     */
    public function restoreFromBackup(string $backupPath, ?\Closure $onProgress = null): array
    {
        $targets = array_merge(
            config('app.update.targets', []),
            config('app.update.backup_only', [])
        );
        $excludes = config('app.update.excludes', []);

        return CoreBackupHelper::restoreFromBackup($backupPath, $targets, $onProgress, $excludes);
    }

    /**
     * Maintenance 모드를 활성화합니다.
     *
     * @return string bypass secret
     */
    public function enableMaintenanceMode(): string
    {
        $secret = Str::uuid()->toString();

        Artisan::call('down', [
            '--secret' => $secret,
            '--retry' => 60,
            '--refresh' => 15,
        ]);

        Log::info('코어 업데이트: 유지보수 모드 활성화', ['secret' => $secret]);

        return $secret;
    }

    /**
     * Maintenance 모드를 비활성화합니다.
     */
    public function disableMaintenanceMode(): void
    {
        Artisan::call('up');
        Log::info('코어 업데이트: 유지보수 모드 비활성화');
    }

    /**
     * 타임스탬프 기반 _pending 하위 디렉토리를 생성합니다.
     *
     * `{pending_path}/core_{Ymd_His}/` 형식의 격리된 디렉토리를 생성하여
     * .gitignore 덮어쓰기, 정리 실패, 동시 실행 충돌을 방지합니다.
     *
     * @return string 생성된 pending 디렉토리 경로
     */
    public function createPendingDirectory(): string
    {
        $basePath = config('app.update.pending_path');
        $timestamp = date('Ymd_His');
        $pendingPath = $basePath.DIRECTORY_SEPARATOR.'core_'.$timestamp;

        File::ensureDirectoryExists($pendingPath, 0770, true);

        return $pendingPath;
    }

    /**
     * _pending 하위 디렉토리를 정리합니다.
     *
     * 타임스탬프 기반 격리 디렉토리를 통째로 삭제합니다.
     *
     * @param  string  $pendingPath  삭제할 pending 디렉토리 경로
     */
    public function cleanupPending(string $pendingPath): void
    {
        ExtensionPendingHelper::cleanupStaging($pendingPath);
    }

    /**
     * 현재 코드베이스의 targets을 _pending에 복제합니다 (로컬 테스트용).
     *
     * GitHub 다운로드 대신 현재 프로젝트의 업데이트 대상 파일/디렉토리를
     * _pending/local_source/로 복사하여 업데이트 패키지를 시뮬레이션합니다.
     *
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return string _pending 내 소스 경로
     */
    public function prepareLocalSource(?\Closure $onProgress = null): string
    {
        $pendingPath = $this->createPendingDirectory();
        $sourcePath = $pendingPath.DIRECTORY_SEPARATOR.'local_source';

        File::ensureDirectoryExists($sourcePath, 0770, true);

        $targets = config('app.update.targets', []);
        $excludes = config('app.update.excludes', []);

        foreach ($targets as $target) {
            $src = base_path($target);

            if (! File::exists($src) && ! File::isDirectory($src)) {
                continue;
            }

            $dest = $sourcePath.DIRECTORY_SEPARATOR.$target;
            $onProgress?->__invoke('copy', $target);

            if (File::isDirectory($src)) {
                FilePermissionHelper::copyDirectory($src, $dest, $onProgress, $excludes);
            } else {
                FilePermissionHelper::copyFile($src, $dest);
            }
        }

        // 패키지 유효성 검증
        $this->validatePendingUpdate($sourcePath);

        return $sourcePath;
    }

    /**
     * 모든 캐시를 초기화하고 패키지 목록을 재생성합니다.
     *
     * vendor 교체 후 bootstrap/cache의 컴파일 캐시가 stale 상태일 수 있으므로
     * services.php/packages.php 삭제 후 package:discover로 재생성합니다.
     * 이는 composer install의 post-autoload-dump 후속 작업(clearCompiled + package:discover)을 재현합니다.
     */
    public function clearAllCaches(): void
    {
        // 1. 기존 캐시 초기화
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        // 2. 컴파일 캐시 삭제 (composer postAutoloadDump → clearCompiled 재현)
        //    services.php, packages.php가 교체 전 vendor를 참조할 수 있음
        $app = app();
        @unlink($app->getCachedServicesPath());
        @unlink($app->getCachedPackagesPath());

        // 3. 현재 vendor 기반으로 packages.php 재생성
        Artisan::call('package:discover');

        // 4. 확장 오토로드 재생성 (코어 업데이트로 _bundled 변경 가능)
        Artisan::call('extension:update-autoload');
    }

    /**
     * 코어 업그레이드 컨텍스트의 번들 확장 업데이트 감지.
     *
     * `_bundled/{id}/{manifest}.json` 의 version 과 DB 에 설치된 현재 version 을
     * 직접 비교하여 "번들에 최신 버전이 포함된" 확장 목록을 반환한다.
     *
     * `Manager::checkXxxUpdate()` 와의 차이:
     *   - 일반 update 커맨드용 `checkXxxUpdate()` 는 GitHub 엄격 우선 정책
     *     (GitHub URL 조회 성공 시 _bundled 폴백 없음)
     *   - 본 메서드는 **코어 업그레이드 후 _bundled 자동 반영** 용도이므로
     *     GitHub 상태와 무관하게 _bundled 버전만 기준으로 판정
     *   - beta.2 가 GitHub 미릴리스 상태에서도 _bundled 신버전을 정확히 감지
     *
     * 호출처:
     *   - `BundledExtensionUpdatePrompt::collectBundledUpdates()` (beta.2+ 프롬프트)
     *   - `Upgrade_7_0_0_beta_2::spawnResyncInlineLocal()` inline PHP (beta.1 → beta.2 경로 C)
     *
     * @return array{
     *     modules: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>,
     *     plugins: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>,
     *     templates: array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>
     * }
     */
    public function collectBundledExtensionUpdates(): array
    {
        return [
            'modules' => $this->detectBundledUpdatesFor('modules', 'module.json'),
            'plugins' => $this->detectBundledUpdatesFor('plugins', 'plugin.json'),
            'templates' => $this->detectBundledUpdatesFor('templates', 'template.json'),
        ];
    }

    /**
     * 단일 확장 타입의 _bundled 업데이트 목록을 조회합니다.
     *
     * @param  string  $tableAndDir  'modules' | 'plugins' | 'templates'  (DB 테이블명 + 디렉토리명 일치 전제)
     * @param  string  $manifestName  'module.json' | 'plugin.json' | 'template.json'
     * @return array<int, array{identifier:string, current_version:string, latest_version:string, update_source:string}>
     */
    private function detectBundledUpdatesFor(string $tableAndDir, string $manifestName): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($tableAndDir)) {
            return [];
        }

        $results = [];
        foreach (DB::table($tableAndDir)->get(['identifier', 'version']) as $record) {
            $identifier = (string) $record->identifier;
            $current = (string) $record->version;
            $bundledPath = base_path($tableAndDir.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.$manifestName);

            if (! is_file($bundledPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($bundledPath), true);
            $bundled = is_array($manifest) ? ($manifest['version'] ?? null) : null;

            if ($bundled === null || version_compare((string) $bundled, $current, '<=')) {
                continue;
            }

            $results[] = [
                'identifier' => $identifier,
                'current_version' => $current,
                'latest_version' => (string) $bundled,
                'update_source' => 'bundled',
            ];
        }

        return $results;
    }

    /**
     * 업데이트 시작 시점의 원본 소유자·그룹을 스냅샷합니다.
     *
     * 이 스냅샷은 업데이트 종료 시점 `restoreOwnership()` 에 전달되어 각 경로를
     * **원래 자신의 소유자** 로 정확히 복원한다. base_path 기준 통일 방식과 달리
     * 비대칭 환경(예: 루트=someuser, vendor=www-data) 에서도 원본을 유지한다.
     *
     * 대상 경로는 config('app.update.restore_ownership') 에 정의된 목록.
     * chown 미지원 환경(Windows 등) 은 빈 배열을 반환한다.
     *
     * @return array<string, array{owner:int|false, group:int|false}>  target => {owner, group}
     */
    public function snapshotOwnership(): array
    {
        $targets = config('app.update.restore_ownership', ['vendor']);

        return $this->snapshotOwnershipFor($targets);
    }

    /**
     * 지정한 경로 목록의 소유자·그룹을 스냅샷합니다.
     *
     * 확장 업데이트(모듈/플러그인/템플릿) 에서 업데이트 전 해당 확장 스코프의
     * 경로만 스냅샷하고 싶을 때 사용. 예: `['bootstrap/cache', "modules/{$id}"]`.
     * 본 메서드 결과는 `restoreOwnership()` 에 그대로 전달 가능.
     *
     * @param  array  $paths  base_path() 기준 상대 경로 배열
     * @return array<string, array{owner:int|false, group:int|false}>
     */
    public function snapshotOwnershipFor(array $paths): array
    {
        if (! function_exists('chown')) {
            return [];
        }

        $snapshot = [];
        foreach ($paths as $target) {
            $path = base_path($target);
            if (! File::exists($path) && ! File::isDirectory($path)) {
                continue;
            }

            $snapshot[$target] = [
                'owner' => @fileowner($path),
                'group' => @filegroup($path),
            ];
        }

        return $snapshot;
    }

    /**
     * 업데이트 경로의 소유권을 스냅샷 기준으로 복원합니다.
     *
     * sudo 로 실행된 외부 프로세스(composer install, package:discover,
     * extension:update-autoload 등)가 root 소유로 생성한 파일을 **업데이트 전의
     * 각 경로 원본 소유자** 로 되돌린다. 스냅샷은 `snapshotOwnership()` 로 업데이트
     * 초반에 수집하여 전달한다.
     *
     * 동작 원칙:
     * - target 별로 스냅샷에 기록된 원본 owner/group 을 기준으로 재귀 chown
     * - 스냅샷에 없거나 수집 실패(false)한 target 은 `FilePermissionHelper::inferWebServerOwnership()`
     *   의 웹서버 계정 추정값으로 fallback (storage 등 웹서버 쓰기 디렉토리 기준)
     * - 이미 일치하는 항목은 no-op
     * - 소유권만 복원, 퍼미션은 건드리지 않음
     * - @chown/@chgrp suppress 로 권한 부족 시 silent fail
     * - 대상 경로 목록은 config('app.update.restore_ownership') 기준
     *
     * @param  array<string, array{owner:int|false, group:int|false}>  $snapshot  snapshotOwnership() 결과
     * @param  \Closure|null  $onProgress  진행 콜백
     * @return void
     */
    public function restoreOwnership(array $snapshot, ?\Closure $onProgress = null): void
    {
        if (! function_exists('chown')) {
            return;
        }

        // 스냅샷이 제공되면 그 키를 복원 대상 범위로 사용 (스코프 복원).
        // 스냅샷이 비어있으면 전체 config 범위로 fallback (기존 동작 유지).
        $targets = ! empty($snapshot)
            ? array_keys($snapshot)
            : config('app.update.restore_ownership', ['vendor']);
        $restoredCount = 0;
        $fallbackOwner = null;
        $fallbackGroup = null;
        $fallbackSource = null;

        foreach ($targets as $target) {
            $path = base_path($target);
            if (! File::exists($path) && ! File::isDirectory($path)) {
                continue;
            }

            // 1순위: 스냅샷의 원본 소유자
            $owner = $snapshot[$target]['owner'] ?? false;
            $group = $snapshot[$target]['group'] ?? false;
            $source = 'snapshot';

            // 2순위: inferWebServerOwnership fallback
            if ($owner === false) {
                if ($fallbackOwner === null) {
                    [$fallbackOwner, $fallbackGroup, $fallbackSource] = FilePermissionHelper::inferWebServerOwnership();
                }
                $owner = $fallbackOwner;
                $group = $fallbackGroup;
                $source = 'infer:'.$fallbackSource;
            }

            if ($owner === false) {
                continue;
            }

            $onProgress?->__invoke('ownership', $target);
            $changed = FilePermissionHelper::chownRecursive($path, $owner, $group);

            if ($changed > 0) {
                Log::info('코어 업데이트: 소유권 복원', [
                    'target' => $target,
                    'owner' => $owner,
                    'group' => $group,
                    'source' => $source,
                    'changed_entries' => $changed,
                ]);
                $restoredCount += $changed;
            }
        }

        if ($restoredCount > 0) {
            Log::info('코어 업데이트: 소유권 복원 완료', [
                'restored_entries_total' => $restoredCount,
                'targets' => $targets,
            ]);
        }
    }

    /**
     * 업데이트 실패 리포트를 생성합니다.
     *
     * @param  \Throwable  $exception  발생한 예외
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @return string 리포트 파일 경로
     */
    public function generateFailureReport(\Throwable $exception, string $fromVersion, string $toVersion): string
    {
        $timestamp = date('Ymd_His');
        $reportPath = storage_path("logs/core_update_failure_{$timestamp}.log");

        $content = implode("\n", [
            '=== 그누보드7 코어 업데이트 실패 리포트 ===',
            '날짜: '.date('Y-m-d H:i:s'),
            "시작 버전: {$fromVersion}",
            "대상 버전: {$toVersion}",
            '',
            '=== 오류 정보 ===',
            "메시지: {$exception->getMessage()}",
            "파일: {$exception->getFile()}:{$exception->getLine()}",
            '',
            '=== 스택 트레이스 ===',
            $exception->getTraceAsString(),
            '',
            '=== 시스템 정보 ===',
            'PHP: '.PHP_VERSION,
            'Laravel: '.app()->version(),
            'OS: '.PHP_OS,
        ]);

        File::put($reportPath, $content);

        Log::error('코어 업데이트 실패', [
            'from' => $fromVersion,
            'to' => $toVersion,
            'error' => $exception->getMessage(),
            'report' => $reportPath,
        ]);

        return $reportPath;
    }

    /**
     * 코어 권한 정의를 반환합니다.
     *
     * @return array 권한 정의 배열
     */
    protected function getCorePermissionDefinitions(): array
    {
        return config('core.permissions', []);
    }

    /**
     * 코어 역할 정의를 반환합니다.
     *
     * @return array 역할 정의 배열
     */
    protected function getCoreRoleDefinitions(): array
    {
        return config('core.roles', []);
    }

    /**
     * 코어 메뉴 정의를 반환합니다.
     *
     * @return array 메뉴 정의 배열
     */
    protected function getCoreMenuDefinitions(): array
    {
        return config('core.menus', []);
    }

}
