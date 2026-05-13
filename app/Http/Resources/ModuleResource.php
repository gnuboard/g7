<?php

namespace App\Http\Resources;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ModuleResource extends BaseApiResource
{
    /**
     * 모듈 목록용 리소스를 배열로 변환합니다 (간소화된 정보).
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 모듈 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'description' => $this->getLocalizedField('description'),
            'dependencies' => $this->getValue('dependencies', []),
            'status' => $this->getValue('status'),
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
            'can_install' => 'core.modules.install',
            'can_activate' => 'core.modules.activate',
            'can_uninstall' => 'core.modules.uninstall',
        ];
    }

    /**
     * 모듈 상세 정보를 배열로 반환합니다.
     *
     * @return array<string, mixed> 상세 모듈 정보
     */
    public function toDetailArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'description' => $this->getLocalizedField('description'),
            'github_url' => $this->getValue('github_url'),
            'requires_core' => $this->getValue('requires_core'),
            'dependencies' => $this->getValue('dependencies', []),
            'status' => $this->getValue('status'),
            'is_installed' => $this->getValue('is_installed', false),
            // 상세 정보
            'permissions' => $this->getValue('permissions', []),
            'roles' => $this->getValue('roles', []),
            'admin_menus' => $this->getValue('admin_menus', []),
            'license' => $this->getValue('license'),
            'layouts_count' => $this->getValue('layouts_count', 0),
            'config' => $this->getValue('config', []),
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
            'created_at' => $this->getValue('created_at')
                ? TimezoneHelper::toUserDateTimeString(Carbon::parse($this->getValue('created_at')))
                : null,
            'updated_at' => $this->getValue('updated_at')
                ? TimezoneHelper::toUserDateTimeString(Carbon::parse($this->getValue('updated_at')))
                : null,
        ];
    }

    /**
     * 마켓플레이스용 간단한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 마켓플레이스용 모듈 정보
     */
    public function toMarketplaceArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'status' => $this->getValue('status'),
        ];
    }

    /**
     * 의존성 체크용 간단한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 의존성 확인용 모듈 정보
     */
    public function toDependencyArray(): array
    {
        return [
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedField('name'),
            'version' => $this->getValue('version'),
            'status' => $this->getValue('status'),
        ];
    }
}
