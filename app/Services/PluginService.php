<?php

namespace App\Services;

use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\ChangelogParser;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class PluginService
{
    /**
     * 검색 가능한 필드 목록
     */
    private const SEARCHABLE_FIELDS = ['name', 'identifier', 'description', 'vendor'];

    public function __construct(
        private PluginRepositoryInterface $pluginRepository,
        private PluginManagerInterface $pluginManager
    ) {}

    /**
     * 활성화된 플러그인들의 identifier 목록을 반환합니다.
     *
     * @return array 활성화된 플러그인 identifier 배열
     */
    public function getActivePluginIdentifiers(): array
    {
        return $this->pluginRepository->getActivePluginIdentifiers();
    }

    /**
     * 모든 플러그인 목록을 조회합니다 (설치된 플러그인과 미설치 플러그인 포함).
     *
     * @return array 모든 플러그인 목록
     */
    public function getAllPlugins(): array
    {
        // PluginManager 초기화
        $this->pluginManager->loadPlugins();

        // 설치된 플러그인과 미설치 플러그인을 분리하여 반환
        $installedPlugins = $this->pluginManager->getInstalledPluginsWithDetails();
        $uninstalledPlugins = $this->pluginManager->getUninstalledPlugins();

        return [
            'installed' => array_values($installedPlugins),
            'uninstalled' => array_values($uninstalledPlugins),
            'total' => count($installedPlugins) + count($uninstalledPlugins),
        ];
    }

    /**
     * 설치된 플러그인만 조회합니다.
     *
     * @return array 설치된 플러그인 목록
     */
    public function getInstalledPluginsOnly(): array
    {
        $this->pluginManager->loadPlugins();

        return array_values($this->pluginManager->getInstalledPluginsWithDetails());
    }

    /**
     * 미설치 플러그인만 조회합니다.
     *
     * @return array 미설치 플러그인 목록
     */
    public function getUninstalledPluginsOnly(): array
    {
        $this->pluginManager->loadPlugins();

        return array_values($this->pluginManager->getUninstalledPlugins());
    }

    /**
     * 특정 플러그인의 상세 정보를 조회합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return array|null 플러그인 정보 또는 null
     */
    public function getPluginInfo(string $pluginName): ?array
    {
        $this->pluginManager->loadPlugins();

        return $this->pluginManager->getPluginInfo($pluginName);
    }

    /**
     * 플러그인 삭제 시 삭제될 데이터 정보를 조회합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return array|null 삭제 정보 (테이블 목록, 스토리지 디렉토리 목록, 용량) 또는 null
     */
    public function getPluginUninstallInfo(string $pluginName): ?array
    {
        $this->pluginManager->loadPlugins();

        return $this->pluginManager->getPluginUninstallInfo($pluginName);
    }

    public function installPlugin(
        string $pluginName,
        \App\Extension\Vendor\VendorMode $vendorMode = \App\Extension\Vendor\VendorMode::Auto,
        bool $force = false,
    ): ?array {
        HookManager::doAction('core.plugins.before_install', $pluginName);

        try {
            $this->pluginManager->loadPlugins();
            $result = $this->pluginManager->installPlugin($pluginName, null, $vendorMode, $force);

            if ($result) {
                // 설치 후 플러그인 정보 반환
                $pluginInfo = $this->pluginManager->getPluginInfo($pluginName);

                HookManager::doAction('core.plugins.after_install', $pluginName, $pluginInfo);

                return $pluginInfo;
            }

            return null;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.installation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    public function activatePlugin(string $pluginName, bool $force = false): array
    {
        HookManager::doAction('core.plugins.before_activate', $pluginName);

        try {
            $this->pluginManager->loadPlugins();
            $result = $this->pluginManager->activatePlugin($pluginName, $force);

            // 경고 응답인 경우 그대로 반환
            if (isset($result['warning']) && $result['warning'] === true) {
                return $result;
            }

            if ($result['success']) {
                $pluginInfo = $this->pluginManager->getPluginInfo($pluginName);

                HookManager::doAction('core.plugins.after_activate', $pluginName, $pluginInfo);

                return [
                    'success' => true,
                    'plugin_info' => $pluginInfo,
                ];
            }

            return ['success' => false];
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.activation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    public function deactivatePlugin(string $pluginName, bool $force = false): array
    {
        HookManager::doAction('core.plugins.before_deactivate', $pluginName);

        try {
            $this->pluginManager->loadPlugins();
            $result = $this->pluginManager->deactivatePlugin($pluginName, $force);

            // 경고 응답인 경우 그대로 반환
            if (isset($result['warning']) && $result['warning'] === true) {
                return $result;
            }

            if ($result['success']) {
                $pluginInfo = $this->pluginManager->getPluginInfo($pluginName);

                HookManager::doAction('core.plugins.after_deactivate', $pluginName, $pluginInfo);

                return array_merge($result, ['plugin_info' => $pluginInfo]);
            }

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.deactivation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    public function uninstallPlugin(string $pluginName, bool $deleteData = false): bool
    {
        HookManager::doAction('core.plugins.before_uninstall', $pluginName, $deleteData);

        try {
            $this->pluginManager->loadPlugins();

            $result = $this->pluginManager->uninstallPlugin($pluginName, $deleteData);

            HookManager::doAction('core.plugins.after_uninstall', $pluginName, $deleteData, $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.uninstallation_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 페이지네이션 및 검색 필터가 적용된 플러그인 목록을 조회합니다.
     *
     * @param  array  $filters  검색 필터 (search, filters, status)
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  현재 페이지
     * @return array 페이지네이션된 플러그인 목록
     */
    public function getPaginatedPlugins(array $filters, int $perPage = 12, int $page = 1): array
    {
        $this->pluginManager->loadPlugins();

        // 모든 플러그인 가져오기
        $installedPlugins = $this->pluginManager->getInstalledPluginsWithDetails();
        $uninstalledPlugins = $this->pluginManager->getUninstalledPlugins();

        // 모든 플러그인 합치기
        $allPlugins = array_merge(
            array_values($installedPlugins),
            array_values($uninstalledPlugins)
        );

        // 상태 필터 적용
        if (! empty($filters['status'])) {
            $allPlugins = $this->applyStatusFilter($allPlugins, $filters['status']);
        }

        // 다중 검색 필터 적용 (우선)
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $allPlugins = $this->applyMultipleSearchFilters($allPlugins, $filters['filters']);
        }
        // 단일 검색어 필터 (하위 호환성)
        elseif (! empty($filters['search'])) {
            $allPlugins = $this->applyOrSearchAcrossFields($allPlugins, $filters['search']);
        }

        // 총 개수
        $total = count($allPlugins);

        // 페이지네이션 적용
        $offset = ($page - 1) * $perPage;
        $paginatedPlugins = array_slice($allPlugins, $offset, $perPage);

        return [
            'data' => array_values($paginatedPlugins),
            'total' => $total,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'per_page' => $perPage,
        ];
    }

    /**
     * 상태 필터를 적용합니다.
     *
     * @param  array  $plugins  플러그인 목록
     * @param  string  $status  상태 (installed, not_installed, active, inactive)
     * @return array 필터링된 플러그인 목록
     */
    private function applyStatusFilter(array $plugins, string $status): array
    {
        return array_filter($plugins, function ($plugin) use ($status) {
            return match ($status) {
                'installed' => ($plugin['status'] ?? '') !== 'uninstalled',
                'uninstalled' => ($plugin['status'] ?? '') === 'uninstalled',
                'active' => ($plugin['status'] ?? '') === 'active',
                'inactive' => ($plugin['status'] ?? '') === 'inactive',
                default => true,
            };
        });
    }

    /**
     * 다중 검색 조건을 적용합니다 (AND 조건).
     *
     * @param  array  $plugins  플러그인 목록
     * @param  array  $searchFilters  검색 필터 배열
     * @return array 필터링된 플러그인 목록
     */
    private function applyMultipleSearchFilters(array $plugins, array $searchFilters): array
    {
        if (empty($searchFilters)) {
            return $plugins;
        }

        return array_filter($plugins, function ($plugin) use ($searchFilters) {
            foreach ($searchFilters as $filter) {
                if (! $this->matchesFilter($plugin, $filter)) {
                    return false; // AND 조건: 하나라도 실패하면 제외
                }
            }

            return true;
        });
    }

    /**
     * 단일 필터 조건 매칭 여부를 확인합니다.
     *
     * @param  array  $plugin  플러그인 정보
     * @param  array  $filter  필터 조건
     * @return bool 매칭 여부
     */
    private function matchesFilter(array $plugin, array $filter): bool
    {
        $field = $filter['field'] ?? null;
        $value = $filter['value'] ?? null;
        $operator = $filter['operator'] ?? 'like';

        if (! $field || ! $value || ! in_array($field, self::SEARCHABLE_FIELDS)) {
            return true; // 유효하지 않은 필터는 통과
        }

        // 필드 값 가져오기 (다국어 필드 처리)
        $fieldValue = $this->getFieldValue($plugin, $field);

        if ($fieldValue === null) {
            return false;
        }

        return match ($operator) {
            'eq' => mb_strtolower($fieldValue) === mb_strtolower($value),
            'starts_with' => str_starts_with(mb_strtolower($fieldValue), mb_strtolower($value)),
            'ends_with' => str_ends_with(mb_strtolower($fieldValue), mb_strtolower($value)),
            default => str_contains(mb_strtolower($fieldValue), mb_strtolower($value)), // like
        };
    }

    /**
     * 단일 검색어로 여러 필드를 OR 조건으로 검색합니다.
     *
     * @param  array  $plugins  플러그인 목록
     * @param  string  $searchTerm  검색어
     * @return array 필터링된 플러그인 목록
     */
    private function applyOrSearchAcrossFields(array $plugins, string $searchTerm): array
    {
        $searchTerm = mb_strtolower($searchTerm);

        return array_filter($plugins, function ($plugin) use ($searchTerm) {
            foreach (self::SEARCHABLE_FIELDS as $field) {
                $fieldValue = $this->getFieldValue($plugin, $field);
                if ($fieldValue !== null && str_contains(mb_strtolower($fieldValue), $searchTerm)) {
                    return true; // OR 조건: 하나라도 매칭되면 포함
                }
            }

            return false;
        });
    }

    /**
     * 플러그인에서 필드 값을 가져옵니다 (다국어 필드 처리 포함).
     *
     * @param  array  $plugin  플러그인 정보
     * @param  string  $field  필드명
     * @return string|null 필드 값
     */
    private function getFieldValue(array $plugin, string $field): ?string
    {
        $value = $plugin[$field] ?? null;

        if ($value === null) {
            return null;
        }

        // 다국어 필드인 경우 (name, description)
        if (is_array($value)) {
            // 현재 로케일 우선, 없으면 ko, 그 다음 en
            $locale = app()->getLocale();

            return $value[$locale] ?? $value['ko'] ?? $value['en'] ?? reset($value) ?: null;
        }

        return (string) $value;
    }

    /**
     * 설치된 플러그인들의 업데이트 가능 여부를 확인합니다.
     *
     * @return array 업데이트 확인 결과 (updated_count, details)
     *
     * @throws ValidationException 업데이트 확인 실패 시
     */
    public function checkForUpdates(): array
    {
        HookManager::doAction('core.plugins.before_check_updates');

        try {
            $this->pluginManager->loadPlugins();
            $result = $this->pluginManager->checkAllPluginsForUpdates();

            HookManager::doAction('core.plugins.after_check_updates', $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugins' => [__('plugins.check_updates_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 지정된 플러그인을 업데이트합니다.
     *
     * @param  string  $pluginName  업데이트할 플러그인 identifier
     * @param  \App\Extension\Vendor\VendorMode  $vendorMode  Vendor 설치 모드
     * @param  string  $layoutStrategy  레이아웃 전략 (overwrite|keep)
     * @return array 업데이트 결과 (identifier, from_version, to_version 등)
     *
     * @throws ValidationException 업데이트 실패 시
     */
    public function updatePlugin(
        string $pluginName,
        \App\Extension\Vendor\VendorMode $vendorMode = \App\Extension\Vendor\VendorMode::Auto,
        string $layoutStrategy = 'overwrite',
    ): array {
        HookManager::doAction('core.plugins.before_update', $pluginName);

        try {
            $this->pluginManager->loadPlugins();
            $result = $this->pluginManager->updatePlugin(
                $pluginName,
                false,
                null,
                $vendorMode,
                $layoutStrategy,
            );

            $pluginInfo = $this->pluginManager->getPluginInfo($pluginName);

            HookManager::doAction('core.plugins.after_update', $pluginName, $result, $pluginInfo);

            return array_merge($result, [
                'plugin_info' => $pluginInfo,
            ]);
        } catch (\Exception $e) {
            // Manager의 RuntimeException은 이미 번역된 메시지를 포함하므로
            // getPrevious()로 원본 에러를 추출하여 이중 래핑 방지
            $rawError = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();

            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.errors.update_failed', ['plugin' => $pluginName, 'error' => $rawError])],
            ]);
        }
    }

    /**
     * 지정된 플러그인의 수정된 레이아웃을 확인합니다.
     *
     * @param  string  $pluginName  확인할 플러그인 identifier
     * @return array{has_modified_layouts: bool, modified_count: int, modified_layouts: array}
     *
     * @throws ValidationException 확인 실패 시
     */
    public function checkModifiedLayouts(string $pluginName): array
    {
        try {
            $this->pluginManager->loadPlugins();

            return $this->pluginManager->hasModifiedLayouts($pluginName);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.check_modified_layouts_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 플러그인의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws ValidationException 플러그인을 찾을 수 없거나 갱신 실패 시
     */
    public function refreshPluginLayouts(string $pluginName): array
    {
        try {
            $this->pluginManager->loadPlugins();

            return $this->pluginManager->refreshPluginLayouts($pluginName);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'plugin_name' => [__('plugins.refresh_layouts_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 플러그인 에셋 파일 경로를 반환합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $path  파일 경로
     * @return array{success: bool, filePath: string|null, mimeType: string|null, error: string|null}
     */
    public function getAssetFilePath(string $identifier, string $path): array
    {
        // 1. 활성화된 플러그인 확인
        $plugin = $this->pluginRepository->findByIdentifier($identifier);

        if (! $plugin || $plugin->status !== ExtensionStatus::Active->value) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'plugin_not_found',
            ];
        }

        // 2. 파일 경로 구성 (플러그인 루트 기준)
        $filePath = base_path("plugins/{$identifier}/{$path}");

        // 3. 파일 존재 확인
        if (! file_exists($filePath) || ! is_file($filePath)) {
            return [
                'success' => false,
                'filePath' => null,
                'mimeType' => null,
                'error' => 'file_not_found',
            ];
        }

        // 4. MIME 타입 감지
        $mimeType = $this->getMimeType($filePath);

        return [
            'success' => true,
            'filePath' => $filePath,
            'mimeType' => $mimeType,
            'error' => null,
        ];
    }

    /**
     * MIME 타입 감지
     *
     * @param  string  $filePath  파일 경로
     * @return string MIME 타입
     */
    private function getMimeType(string $filePath): string
    {
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'map' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * 플러그인의 변경 내역(changelog)을 조회합니다.
     *
     * source가 'github'이면 GitHub에서 원격 CHANGELOG.md를 가져와 파싱합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string|null  $source  소스 ('active', 'bundled', 'github')
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    public function getPluginChangelog(string $identifier, ?string $source = null, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        // GitHub 소스: 원격에서 CHANGELOG.md를 가져옴
        if ($source === 'github') {
            return $this->fetchRemoteChangelog($identifier, $fromVersion, $toVersion);
        }

        $basePath = base_path('plugins');
        $filePath = ChangelogParser::resolveChangelogPath($basePath, $identifier, $source);

        if ($filePath === null) {
            return [];
        }

        if ($fromVersion !== null && $toVersion !== null) {
            return ChangelogParser::getVersionRange($filePath, $fromVersion, $toVersion);
        }

        return ChangelogParser::parse($filePath);
    }

    /**
     * GitHub에서 원격 CHANGELOG.md를 가져와 파싱합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string|null  $fromVersion  시작 버전 (초과)
     * @param  string|null  $toVersion  끝 버전 (이하)
     * @return array 변경 내역 배열
     */
    private function fetchRemoteChangelog(string $identifier, ?string $fromVersion = null, ?string $toVersion = null): array
    {
        $plugin = $this->pluginManager->getPlugin($identifier);

        if (! $plugin) {
            return [];
        }

        $githubUrl = $plugin->getGithubUrl();

        if (empty($githubUrl)) {
            return $this->getPluginChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        try {
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
        } catch (\RuntimeException $e) {
            return $this->getPluginChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        $ref = $toVersion ?? 'main';
        $content = GithubHelper::fetchRawFile($owner, $repo, $ref, 'CHANGELOG.md');

        if ($content === null) {
            return $this->getPluginChangelog($identifier, 'bundled', $fromVersion, $toVersion);
        }

        if ($fromVersion !== null && $toVersion !== null) {
            return ChangelogParser::getVersionRangeFromString($content, $fromVersion, $toVersion);
        }

        return ChangelogParser::parseFromString($content);
    }

    /**
     * ZIP 파일에서 플러그인을 설치합니다.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @return array 설치된 플러그인 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    public function installFromZipFile(UploadedFile $file): array
    {
        $tempPath = storage_path('app/temp/plugins');
        $extractPath = $tempPath.'/'.uniqid('plugin_');

        try {
            File::ensureDirectoryExists($tempPath);

            $result = ZipInstallHelper::extractAndValidate(
                $file->getRealPath(), $extractPath, 'plugin.json', 'plugins'
            );

            $this->ensurePluginNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('plugins/_pending'), $result['identifier']
            );

            try {
                return $this->executePluginInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('plugins/_pending/'.$result['identifier']);
                if (File::exists($pendingPath)) {
                    File::deleteDirectory($pendingPath);
                }
                throw $e;
            }
        } finally {
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
        }
    }

    /**
     * GitHub 저장소에서 플러그인을 설치합니다.
     *
     * @param  string  $githubUrl  GitHub 저장소 URL
     * @return array 설치된 플러그인 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    public function installFromGithub(string $githubUrl): array
    {
        $tempPath = storage_path('app/temp/plugins');
        $extractPath = $tempPath.'/'.uniqid('plugin_');
        $zipPath = null;

        try {
            File::ensureDirectoryExists($tempPath);

            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);

            $token = config('app.update.github_token') ?? '';

            if (! GithubHelper::checkRepoExists($owner, $repo, $token)) {
                throw new \RuntimeException(__('plugins.errors.github_repo_not_found'));
            }

            $zipPath = GithubHelper::downloadZip($owner, $repo, $tempPath, $token);

            $result = ZipInstallHelper::extractAndValidate(
                $zipPath, $extractPath, 'plugin.json', 'plugins'
            );

            $this->ensurePluginNotInstalled($result['identifier']);

            ZipInstallHelper::moveToPending(
                $result['sourcePath'], base_path('plugins/_pending'), $result['identifier']
            );

            try {
                return $this->executePluginInstall($result['identifier']);
            } catch (\Throwable $e) {
                $pendingPath = base_path('plugins/_pending/'.$result['identifier']);
                if (File::exists($pendingPath)) {
                    File::deleteDirectory($pendingPath);
                }
                throw $e;
            }
        } finally {
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }
            if ($zipPath && File::exists($zipPath)) {
                File::delete($zipPath);
            }
        }
    }

    /**
     * 플러그인이 이미 설치되어 있는지 확인합니다.
     *
     * _bundled/_pending에만 존재하는 경우(is_installed=false)는 설치 허용합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     *
     * @throws \RuntimeException 이미 설치된 경우
     */
    private function ensurePluginNotInstalled(string $identifier): void
    {
        $this->pluginManager->loadPlugins();
        $existingPlugin = $this->pluginManager->getPluginInfo($identifier);

        if ($existingPlugin && $existingPlugin['is_installed']) {
            throw new \RuntimeException(__('plugins.errors.already_installed', ['plugin' => $identifier]));
        }
    }

    /**
     * _pending에서 플러그인을 설치합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return array 설치된 플러그인 정보
     *
     * @throws \RuntimeException 설치 실패 시
     */
    private function executePluginInstall(string $identifier): array
    {
        $this->pluginManager->loadPlugins();
        $result = $this->pluginManager->installPlugin($identifier);

        if (! $result) {
            throw new \RuntimeException(__('plugins.errors.install_failed'));
        }

        return $this->pluginManager->getPluginInfo($identifier);
    }
}
