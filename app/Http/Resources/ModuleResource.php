<?php

namespace App\Http\Resources;

use App\Extension\CoreVersionChecker;
use App\Helpers\TimezoneHelper;
use App\Http\Resources\Traits\ExposesUpdateCoreCompatibility;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ModuleResource extends BaseApiResource
{
    use ExposesUpdateCoreCompatibility;

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
            'integrations' => $this->getValue('integrations', []),
            'status' => $this->getValue('status'),
            'assets' => $this->getValue('assets'),
            'icon_url' => $this->getValue('icon_url'),
            'icon_source' => $this->getValue('icon_source', 'none'),
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
            // 현재 매니페스트의 코어 요구 버전(g7_version) 충족 여부 — 설치·활성 가드
            // (CoreVersionChecker::validateExtension)와 같은 판정이다. 화면은 이 값으로 설치 버튼과
            // 켜기 스위치를 잠근다(안 잠그면 누른 뒤 422 core_version_mismatch 로 끝난다).
            'core_compatible' => CoreVersionChecker::isCompatible(
                is_string($this->getValue('requires_core')) ? $this->getValue('requires_core') : null
            ),
            // 업데이트 대상 버전의 코어 호환(업데이트가 있을 때만) — 업데이트 창의 경고·강제 업데이트 체크가 읽는다.
            ...$this->updateCoreCompatibilityFields(),
            // 레이아웃 갱신 응답에만 — 7.0 JSON 레이아웃의 깨진 조각 참조를 빈 자리로 등록한 경고 (#712)
            ...(is_array($this->getValue('layout_warnings')) ? ['layout_warnings' => $this->getValue('layout_warnings')] : []),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * deactivated_reason 을 항상 string|null 로 직렬화합니다.
     *
     * @return string|null 비활성화 사유 문자열 (없으면 null)
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
     * @return array<string, string> 능력 키 → 권한 식별자 맵
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
            'integrations' => $this->getValue('integrations', []),
            'status' => $this->getValue('status'),
            'is_installed' => $this->getValue('is_installed', false),
            // 상세 정보
            'icon_url' => $this->getValue('icon_url'),
            'icon_source' => $this->getValue('icon_source', 'none'),
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
            // 상세 창이 목록 행(abilities 포함)을 담던 전역을 이 응답으로 덮어쓰므로 권한 메타도 함께 싣는다.
            ...$this->resourceMeta(request()),
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
