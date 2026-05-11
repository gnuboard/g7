<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use Composer\Semver\Semver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * 템플릿 API 리소스
 *
 * name, description 필드는 현재 로케일에 맞는 값을 반환합니다.
 */
class TemplateResource extends BaseApiResource
{
    /**
     * 템플릿 목록용 리소스를 배열로 변환합니다 (간소화된 정보).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 템플릿 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'type' => $this->getValue('type'),
            'status' => $this->getValue('status'),
            'description' => $this->getLocalizedField('description'),
            'dependencies' => $this->getDependenciesSummary(),
            'dependencies_met' => $this->areDependenciesMet(),
            // 업데이트 관련 필드
            'update_available' => $this->getValue('update_available', false),
            'update_source' => $this->getValue('update_source'),
            'latest_version' => $this->getValue('latest_version'),
            'file_version' => $this->getValue('file_version'),
            'github_url' => $this->getValue('github_url'),
            'github_changelog_url' => $this->getValue('github_changelog_url'),
            // pending/bundled 상태
            'is_pending' => $this->getValue('is_pending', false),
            'is_bundled' => $this->getValue('is_bundled', false),
            // 비활성화 메타 (코어 버전 호환성)
            'deactivated_reason' => $this->getDeactivatedReasonValue(),
            'deactivated_at' => $this->getValue('deactivated_at'),
            'incompatible_required_version' => $this->getValue('incompatible_required_version'),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * deactivated_reason 을 항상 string|null 로 직렬화합니다.
     *
     * @return string|null
     */
    protected function getDeactivatedReasonValue(): ?string
    {
        $value = $this->getValue('deactivated_reason');

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_install' => 'core.templates.install',
            'can_activate' => 'core.templates.activate',
            'can_uninstall' => 'core.templates.uninstall',
            'can_edit_layouts' => 'core.templates.layouts.edit',
        ];
    }

    /**
     * 템플릿 상세 정보를 배열로 반환합니다.
     *
     * @return array<string, mixed> 상세 템플릿 정보
     */
    public function toDetailArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'latest_version' => $this->getValue('latest_version'),
            'update_available' => $this->getValue('update_available', false),
            'update_source' => $this->getValue('update_source'),
            'file_version' => $this->getValue('file_version'),
            'type' => $this->getValue('type'),
            'status' => $this->getValue('status'),
            'description' => $this->getLocalizedField('description'),
            'github_url' => $this->getValue('github_url'),
            'github_changelog_url' => $this->getValue('github_changelog_url'),
            'requires_core' => $this->getValue('requires_core'),
            // pending/bundled 상태
            'is_pending' => $this->getValue('is_pending', false),
            'is_bundled' => $this->getValue('is_bundled', false),
            // 비활성화 메타 (코어 버전 호환성)
            'deactivated_reason' => $this->getDeactivatedReasonValue(),
            'deactivated_at' => $this->getValue('deactivated_at'),
            'incompatible_required_version' => $this->getValue('incompatible_required_version'),
            // 상세 정보
            'locales' => $this->getValue('locales', []),
            'layouts_count' => $this->getValue('layouts_count', 0),
            'components' => $this->getValue('components', ['basic' => [], 'composite' => []]),
            'license' => $this->getValue('license'),
            'metadata' => $this->getValue('metadata', []),
            // 의존성 상세 정보
            'dependencies' => $this->getDetailedDependencies(),
            // 타임스탬프
            'created_at' => $this->getValue('created_at'),
            'updated_at' => $this->getValue('updated_at'),
        ];
    }

    /**
     * 마켓플레이스용 간단한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 마켓플레이스용 템플릿 정보
     */
    public function toMarketplaceArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'type' => $this->getValue('type'),
            'status' => $this->getValue('status'),
            'description' => $this->getLocalizedField('description'),
        ];
    }

    /**
     * 의존성 체크용 간단한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 의존성 확인용 템플릿 정보
     */
    public function toDependencyArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'type' => $this->getValue('type'),
            'status' => $this->getValue('status'),
        ];
    }

    /**
     * 다국어 필드 값을 현재 로케일에 맞게 반환합니다.
     *
     * @param  string  $field  필드명
     * @return string|array|null 다국어 값
     */
    protected function getLocalizedField(string $field)
    {
        $value = $this->getValue($field);

        if ($value === null) {
            return null;
        }

        // 이미 문자열이면 그대로 반환
        if (is_string($value)) {
            return $value;
        }

        // 배열(다국어)이면 현재 로케일에 맞는 값 반환
        if (is_array($value)) {
            $locale = App::getLocale();
            $fallbackLocale = config('app.fallback_locale', 'ko');

            return $value[$locale] ?? $value[$fallbackLocale] ?? reset($value) ?: null;
        }

        // Model의 getLocalizedName() 등의 메서드가 있으면 사용
        $methodName = 'getLocalized'.ucfirst($field);
        if (is_object($this->resource) && method_exists($this->resource, $methodName)) {
            return $this->resource->{$methodName}();
        }

        return $value;
    }

    /**
     * 의존성 요약 정보를 반환합니다 (목록용).
     *
     * @return array 의존성 목록 ['modules' => [...], 'plugins' => [...]]
     */
    protected function getDependenciesSummary(): array
    {
        $dependencies = $this->getValue('dependencies', []);

        $moduleRepository = app(ModuleRepositoryInterface::class);
        $pluginRepository = app(PluginRepositoryInterface::class);

        $modules = [];
        $plugins = [];

        // 모듈 의존성 정보 추출 (identifier + name + type)
        if (isset($dependencies['modules']) && is_array($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $moduleIdentifier => $versionConstraint) {
                $module = $moduleRepository->findByIdentifier($moduleIdentifier);
                $modules[] = [
                    'identifier' => $moduleIdentifier,
                    'name' => $module ? $this->getExtensionName($module) : $moduleIdentifier,
                    'type' => 'module',
                ];
            }
        }

        // 플러그인 의존성 정보 추출 (identifier + name + type)
        if (isset($dependencies['plugins']) && is_array($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $pluginIdentifier => $versionConstraint) {
                $plugin = $pluginRepository->findByIdentifier($pluginIdentifier);
                $plugins[] = [
                    'identifier' => $pluginIdentifier,
                    'name' => $plugin ? $this->getExtensionName($plugin) : $pluginIdentifier,
                    'type' => 'plugin',
                ];
            }
        }

        return [
            'modules' => $modules,
            'plugins' => $plugins,
        ];
    }

    /**
     * 모든 의존성이 충족되었는지 확인합니다.
     *
     * @return bool 모든 의존성 충족 여부
     */
    protected function areDependenciesMet(): bool
    {
        $dependencies = $this->getValue('dependencies', []);

        // 의존성이 없으면 충족으로 간주
        if (empty($dependencies)) {
            return true;
        }

        $moduleRepository = app(ModuleRepositoryInterface::class);
        $pluginRepository = app(PluginRepositoryInterface::class);

        // 모듈 의존성 확인
        if (isset($dependencies['modules']) && is_array($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $moduleIdentifier => $versionConstraint) {
                $module = $moduleRepository->findActiveByIdentifier($moduleIdentifier);
                if (! $module) {
                    return false;
                }

                // 버전 체크
                if (! $this->checkVersionConstraint($module->version, $versionConstraint)) {
                    return false;
                }
            }
        }

        // 플러그인 의존성 확인
        if (isset($dependencies['plugins']) && is_array($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $pluginIdentifier => $versionConstraint) {
                $plugin = $pluginRepository->findActiveByIdentifier($pluginIdentifier);
                if (! $plugin) {
                    return false;
                }

                // 버전 체크
                if (! $this->checkVersionConstraint($plugin->version, $versionConstraint)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 상세 의존성 정보를 반환합니다 (상세 모달용).
     *
     * @return array{modules: array, plugins: array} 상세 의존성 정보
     */
    protected function getDetailedDependencies(): array
    {
        $dependencies = $this->getValue('dependencies', []);

        $moduleRepository = app(ModuleRepositoryInterface::class);
        $pluginRepository = app(PluginRepositoryInterface::class);

        $moduleDeps = [];
        $pluginDeps = [];

        // 모듈 의존성 상세 정보
        if (isset($dependencies['modules']) && is_array($dependencies['modules'])) {
            foreach ($dependencies['modules'] as $moduleIdentifier => $versionConstraint) {
                $module = $moduleRepository->findByIdentifier($moduleIdentifier);
                $activeModule = $moduleRepository->findActiveByIdentifier($moduleIdentifier);

                $installedVersion = $module?->version;
                $isActive = (bool) $activeModule;
                $isMet = $isActive && $installedVersion && $this->checkVersionConstraint($installedVersion, $versionConstraint);

                $moduleDeps[] = [
                    'identifier' => $moduleIdentifier,
                    'name' => $this->getExtensionName($module),
                    'required_version' => $versionConstraint,
                    'installed_version' => $installedVersion,
                    'is_active' => $isActive,
                    'is_met' => $isMet,
                ];
            }
        }

        // 플러그인 의존성 상세 정보
        if (isset($dependencies['plugins']) && is_array($dependencies['plugins'])) {
            foreach ($dependencies['plugins'] as $pluginIdentifier => $versionConstraint) {
                $plugin = $pluginRepository->findByIdentifier($pluginIdentifier);
                $activePlugin = $pluginRepository->findActiveByIdentifier($pluginIdentifier);

                $installedVersion = $plugin?->version;
                $isActive = (bool) $activePlugin;
                $isMet = $isActive && $installedVersion && $this->checkVersionConstraint($installedVersion, $versionConstraint);

                $pluginDeps[] = [
                    'identifier' => $pluginIdentifier,
                    'name' => $this->getExtensionName($plugin),
                    'required_version' => $versionConstraint,
                    'installed_version' => $installedVersion,
                    'is_active' => $isActive,
                    'is_met' => $isMet,
                ];
            }
        }

        return [
            'modules' => $moduleDeps,
            'plugins' => $pluginDeps,
        ];
    }

    /**
     * 버전 제약조건을 검증합니다.
     *
     * @param  string  $installedVersion  설치된 버전
     * @param  string  $versionConstraint  요구 버전 제약조건 (예: '>=1.0.0', '^2.0', '~1.2.3')
     * @return bool 제약조건 충족 여부
     */
    protected function checkVersionConstraint(string $installedVersion, string $versionConstraint): bool
    {
        try {
            return Semver::satisfies($installedVersion, $versionConstraint);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 확장(모듈/플러그인)의 다국어 이름을 반환합니다.
     *
     * @param  object|null  $extension  확장 모델
     * @return string|null 다국어 이름
     */
    protected function getExtensionName(?object $extension): ?string
    {
        if (! $extension) {
            return null;
        }

        $name = $extension->name;

        // 다국어 배열인 경우
        if (is_array($name)) {
            $locale = App::getLocale();
            $fallbackLocale = config('app.fallback_locale', 'ko');

            return $name[$locale] ?? $name[$fallbackLocale] ?? reset($name) ?: null;
        }

        // JSON 문자열인 경우
        if (is_string($name) && str_starts_with($name, '{')) {
            $decoded = json_decode($name, true);
            if (is_array($decoded)) {
                $locale = App::getLocale();
                $fallbackLocale = config('app.fallback_locale', 'ko');

                return $decoded[$locale] ?? $decoded[$fallbackLocale] ?? reset($decoded) ?: null;
            }
        }

        return $name;
    }
}
