<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PluginResource extends BaseApiResource
{
    /**
     * 플러그인 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 플러그인 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version', '1.0.0'),
            'description' => $this->getLocalizedField('description'),
            'dependencies' => $this->getValue('dependencies', []),
            'permissions' => $this->getValue('permissions', []),
            'roles' => $this->getValue('roles', []),
            'config' => $this->getValue('config', []),
            'hooks' => $this->getValue('hooks', []),
            'status' => $this->getValue('status', 'uninstalled'),
            'is_installed' => $this->getValue('is_installed', false),
            'has_settings' => $this->getValue('has_settings', false),
            'settings_route' => $this->getValue('settings_route'),
            'assets' => $this->getValue('assets'),
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

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_install' => 'core.plugins.install',
            'can_activate' => 'core.plugins.activate',
            'can_uninstall' => 'core.plugins.uninstall',
        ];
    }

    /**
     * 플러그인 상태를 텍스트로 반환합니다.
     *
     * @return string 플러그인 상태 (not_installed, active, inactive)
     */
    protected function getStatusText(): string
    {
        if (! $this->getValue('is_installed', false)) {
            return 'not_installed';
        }

        if ($this->getValue('is_active', false)) {
            return 'active';
        }

        return 'inactive';
    }

    /**
     * 플러그인 상세 정보를 배열로 반환합니다.
     *
     * @return array<string, mixed> 상세 플러그인 정보
     */
    public function toDetailArray(): array
    {
        return [
            'id' => $this->getValue('id'),
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version', '1.0.0'),
            'description' => $this->getLocalizedField('description'),
            'github_url' => $this->getValue('github_url'),
            'requires_core' => $this->getValue('requires_core'),
            'dependencies' => $this->getValue('dependencies', []),
            'status' => $this->getValue('status', 'uninstalled'),
            'is_installed' => $this->getValue('is_installed', false),
            'has_settings' => $this->getValue('has_settings', false),
            'settings_route' => $this->getValue('settings_route'),
            // 상세 정보
            'permissions' => $this->getValue('permissions', []),
            'roles' => $this->getValue('roles', []),
            'hooks' => $this->getValue('hooks', []),
            'config' => $this->getValue('config', []),
            'license' => $this->getValue('license'),
            'metadata' => $this->getValue('metadata', []),
            // 업데이트 관련 필드
            'update_available' => $this->getValue('update_available', false),
            'update_source' => $this->getValue('update_source'),
            'latest_version' => $this->getValue('latest_version'),
            'file_version' => $this->getValue('file_version'),
            'github_changelog_url' => $this->getValue('github_changelog_url'),
            // pending/bundled 상태
            'is_pending' => $this->getValue('is_pending', false),
            'is_bundled' => $this->getValue('is_bundled', false),
            // 비활성화 메타 (코어 버전 호환성)
            'deactivated_reason' => $this->getDeactivatedReasonValue(),
            'deactivated_at' => $this->getValue('deactivated_at'),
            'incompatible_required_version' => $this->getValue('incompatible_required_version'),
            // 타임스탬프
            'created_at' => $this->getValue('created_at'),
            'updated_at' => $this->getValue('updated_at'),
        ];
    }

    /**
     * 플러그인 기본 정보만 반환합니다 (목록용).
     *
     * @return array<string, mixed> 기본 플러그인 정보
     */
    public function toBasicArray(): array
    {
        return [
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version', '1.0.0'),
            'is_installed' => $this->getValue('is_installed', false),
            'is_active' => $this->getValue('is_active', false),
            'status' => $this->getStatusText(),
        ];
    }

    /**
     * 설치/제거 작업 시 의존성 정보를 포함합니다.
     *
     * @return $this
     */
    public function withDependencies(): self
    {
        return $this->additional([
            'dependency_check' => $this->getDependencyStatus(),
            'required_by' => $this->getValue('required_by', []),
        ]);
    }

    /**
     * deactivated_reason 을 항상 string|null 로 직렬화합니다.
     *
     * Eloquent enum cast 가 적용되어 있어도 배열 합성 모델은 raw value 를 줄 수 있어
     * 두 경우 모두 처리합니다.
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
     * 의존성 상태를 확인하여 반환합니다.
     *
     * @return array<int, array<string, mixed>> 의존성 상태 배열
     */
    protected function getDependencyStatus(): array
    {
        $dependencies = $this->getValue('dependencies', []);
        $status = [];

        foreach ($dependencies as $dependency) {
            $status[] = [
                'name' => $dependency['name'] ?? $dependency,
                'version' => $dependency['version'] ?? '*',
                'satisfied' => $dependency['satisfied'] ?? false,
            ];
        }

        return $status;
    }
}
