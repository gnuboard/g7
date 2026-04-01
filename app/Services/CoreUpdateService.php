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
use App\Models\MailTemplate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

            $token = config('app.update.github_token');

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => $this->buildGithubHeaders($token),
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($apiUrl, false, $context);

            if ($response === false) {
                Log::warning(__('settings.core_update.log_api_call_failed'), ['url' => $apiUrl]);

                return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
            }

            // HTTP 상태 코드 확인
            $statusCode = $this->extractHttpStatusCode($http_response_header ?? []);
            $data = json_decode($response, true);
            $apiMessage = $data['message'] ?? '';

            if ($statusCode === 401 || $statusCode === 403) {
                Log::warning(__('settings.core_update.log_auth_failed'), [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'has_token' => ! empty($token),
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => empty($token)
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
                    'has_token' => ! empty($token),
                    'api_message' => $apiMessage,
                ]);

                return ['version' => null, 'error' => empty($token)
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

            if (isset($data['tag_name'])) {
                return ['version' => ltrim($data['tag_name'], 'v'), 'error' => null];
            }

            return ['version' => null, 'error' => __('settings.core_update.no_releases_found')];
        } catch (\Exception $e) {
            Log::error(__('settings.core_update.log_version_check_error'), ['error' => $e->getMessage()]);

            return ['version' => null, 'error' => __('settings.core_update.github_api_failed')];
        }
    }

    /**
     * HTTP 응답 헤더에서 상태 코드를 추출합니다.
     *
     * @param  array  $responseHeaders  $http_response_header 배열
     * @return int HTTP 상태 코드
     */
    protected function extractHttpStatusCode(array $responseHeaders): int
    {
        return GithubHelper::extractStatusCode($responseHeaders);
    }

    /**
     * GitHub API 요청용 HTTP 헤더를 생성합니다.
     *
     * @param  string  $token  GitHub Personal Access Token (빈 문자열이면 인증 없음)
     * @return array HTTP 헤더 배열
     */
    protected function buildGithubHeaders(string $token = ''): array
    {
        return GithubHelper::buildHeaders($token);
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

        $token = config('app.update.github_token');
        $authHeaders = $this->buildGithubHeaders($token);
        $extractDir = $pendingPath.DIRECTORY_SEPARATOR.'extracted';

        // 폴백 체인: zipball(ZipArchive → unzip) → tarball(PharData)
        $strategies = $this->buildExtractionStrategies();
        $lastError = null;

        foreach ($strategies as $strategy) {
            $archiveType = $strategy['archive_type'];
            $extractMethod = $strategy['method'];
            $label = $strategy['label'];

            // GitHub URL 해석
            $archiveUrl = $this->resolveGithubArchiveUrl($owner, $repo, $version, $archiveType, $authHeaders);
            if (! $archiveUrl) {
                $onProgress?->__invoke('fallback', __('settings.core_update.archive_url_not_found', ['type' => $archiveType]));

                continue;
            }

            $extension = $archiveType === 'zipball' ? '.zip' : '.tar.gz';
            $archivePath = $pendingPath.DIRECTORY_SEPARATOR.'core_update'.$extension;

            try {
                // 다운로드
                $content = $this->downloadArchive($archiveUrl, $authHeaders);
                File::put($archivePath, $content);

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
     * GitHub 아카이브 URL을 해석합니다 (v 접두사 유/무 모두 시도).
     *
     * @param  string  $owner  저장소 소유자
     * @param  string  $repo  저장소명
     * @param  string  $version  버전
     * @param  string  $archiveType  zipball 또는 tarball
     * @param  array  $authHeaders  인증 헤더
     * @return string|null 유효한 아카이브 URL 또는 null
     */
    protected function resolveGithubArchiveUrl(string $owner, string $repo, string $version, string $archiveType, array $authHeaders): ?string
    {
        $tagVariants = ["v{$version}", $version];

        foreach ($tagVariants as $tag) {
            $testUrl = "https://api.github.com/repos/{$owner}/{$repo}/{$archiveType}/{$tag}";
            $testContext = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'header' => $authHeaders,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            @file_get_contents($testUrl, false, $testContext);
            $statusCode = $this->extractHttpStatusCode($http_response_header ?? []);
            if ($statusCode === 200 || $statusCode === 302) {
                return $testUrl;
            }
        }

        return null;
    }

    /**
     * GitHub에서 아카이브 파일을 다운로드합니다.
     *
     * @param  string  $url  다운로드 URL
     * @param  array  $authHeaders  인증 헤더
     * @return string 다운로드된 파일 내용
     */
    protected function downloadArchive(string $url, array $authHeaders): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $authHeaders,
                'follow_location' => true,
                'timeout' => 120,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new \RuntimeException(__('settings.core_update.download_failed', ['version' => basename($url)]));
        }

        return $content;
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

        // 기존 vendor 삭제
        if (File::isDirectory($destVendor)) {
            File::deleteDirectory($destVendor);
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
                'type' => PermissionType::Admin,
                'order' => $moduleConfig['order'],
                'parent_id' => null,
            ],
        );

        // 모든 리프 권한 식별자를 수집 (역할-권한 매핑용)
        $allLeafIdentifiers = [];

        // 2레벨: 카테고리 + 3레벨: 개별 권한
        $categories = $permConfig['categories'];

        foreach ($categories as $categoryData) {
            $category = $roleSyncHelper->syncPermission(
                identifier: $categoryData['identifier'],
                newName: $categoryData['name'],
                newDescription: $categoryData['description'],
                extensionType: ExtensionOwnerType::Core,
                extensionIdentifier: 'core',
                otherAttributes: [
                    'type' => PermissionType::Admin,
                    'order' => $categoryData['order'],
                    'parent_id' => $coreModule->id,
                ],
            );

            foreach ($categoryData['permissions'] as $permData) {
                $roleSyncHelper->syncPermission(
                    identifier: $permData['identifier'],
                    newName: $permData['name'],
                    newDescription: $permData['description'],
                    extensionType: ExtensionOwnerType::Core,
                    extensionIdentifier: 'core',
                    otherAttributes: [
                        'type' => PermissionType::Admin,
                        'order' => $permData['order'],
                        'parent_id' => $category->id,
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
        $roleSyncHelper->syncAllRoleAssignments($permissionRoleMap, $allLeafIdentifiers);

        Log::info('코어 역할/권한 동기화 완료');
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

        Log::info('코어 메뉴 동기화 완료');
    }

    /**
     * 코어 메일 템플릿을 동기화합니다.
     *
     * MailTemplateSeeder와 달리 기존 데이터를 삭제하지 않고,
     * user_overrides를 확인하여 사용자 커스터마이징을 보존합니다.
     *
     * - 신규 템플릿 (type 기준): 생성
     * - 기존 템플릿: user_overrides에 없는 필드(subject, body, is_active)만 갱신
     * - variables: 항상 최신 정의로 덮어쓰기 (사용자 수정 대상 아님)
     */
    public function syncCoreMailTemplates(): void
    {
        $coreTemplates = $this->getCoreMailTemplateDefinitions();

        foreach ($coreTemplates as $templateDef) {
            $existing = MailTemplate::where('type', $templateDef['type'])->first();

            if (! $existing) {
                // 신규 생성
                MailTemplate::create(array_merge($templateDef, [
                    'is_default' => true,
                    'is_active' => true,
                ]));

                continue;
            }

            // 기존 템플릿 업데이트: user_overrides에 없는 필드만 갱신
            $userOverrides = $existing->user_overrides ?? [];

            $updateData = [
                'variables' => $templateDef['variables'] ?? $existing->variables,
            ];

            if (! in_array('subject', $userOverrides, true)) {
                $updateData['subject'] = $templateDef['subject'];
            }

            if (! in_array('body', $userOverrides, true)) {
                $updateData['body'] = $templateDef['body'];
            }

            if (! in_array('is_active', $userOverrides, true) && isset($templateDef['is_active'])) {
                $updateData['is_active'] = $templateDef['is_active'];
            }

            $existing->update($updateData);
        }

        Log::info('코어 메일 템플릿 동기화 완료');
    }

    /**
     * 코어 업그레이드 스텝을 실행합니다.
     * 각 스텝에서 환경설정 파일 생성, 데이터 마이그레이션 등을 수행합니다.
     *
     * @param  string  $fromVersion  시작 버전
     * @param  string  $toVersion  종료 버전
     * @param  \Closure|null  $onStep  각 스텝 실행 시 콜백 (버전 문자열 전달)
     */
    public function runUpgradeSteps(string $fromVersion, string $toVersion, ?\Closure $onStep = null): void
    {
        $upgradesPath = base_path('upgrades');

        if (! File::isDirectory($upgradesPath)) {
            return;
        }

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

            if (version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=')) {
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

    /**
     * 코어 메일 템플릿 정의를 반환합니다.
     *
     * @return array 메일 템플릿 정의 배열
     */
    protected function getCoreMailTemplateDefinitions(): array
    {
        return config('core.mail_templates', []);
    }
}
