<?php

namespace App\Http\Resources;

use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\DependencyEnricher;
use App\Http\Resources\Traits\ExposesIntegratedBy;
use App\Http\Resources\Traits\ExposesUpdateCoreCompatibility;
use App\Services\ExtensionStaticCacheService;
use App\Support\LocalizedValue;
use App\Support\TemplateExternals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * 템플릿 API 리소스
 *
 * name, description 필드는 현재 로케일에 맞는 값을 반환합니다.
 */
class TemplateResource extends BaseApiResource
{
    use ExposesIntegratedBy;
    use ExposesUpdateCoreCompatibility;

    /** @var array<int, array<string, mixed>>|null 보강된 의존성 (목록 응답에서 요약·충족 판정이 같이 쓴다) */
    private ?array $enrichedDependenciesCache = null;

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
            // 멈춘 업데이트 — 관리자 카드가 「업데이트 멈춤」 표시와 정리(다시 업데이트)·활성화 단추를 보인다
            'stuck_update' => (bool) $this->getValue('stuck_update', false),
            'description' => $this->getLocalizedField('description'),
            'dependencies' => $this->getDependenciesSummary(),
            // 버전 제약(7.1.4)이 실린 `integration_constraints` 를 우선한다 — 없으면(옛 정보 배열) 식별자 목록으로 보강
            'integrations' => DependencyEnricher::enrichIntegrations($this->getValue('integration_constraints') ?? $this->getValue('integrations', [])),
            // 이 템플릿을 연동으로 선언한 활성 확장 — 비활성화·제거 확인 창의 「연동 중인 확장」(7.1.4)
            'integrated_by' => $this->integratedBy('template'),
            'dependencies_met' => $this->areDependenciesMet(),
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
            // 설치·레이아웃 갱신 응답에만 — 7.0 JSON 레이아웃의 깨진 조각 참조를 빈 자리로 등록한 경고 (#712).
            // `when()` 대신 펼침으로 둔다 — 리소스를 `toArray()` 로 직접 부르는 경로에서 MissingValue 가 새지 않게.
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
            // 상세 응답은 목록이 골라 둔 전역 자리를 통째로 덮는다(상세 창의 initGlobal).
            // 여기서 빠뜨리면 창이 열린 뒤 아이콘만 사라지므로 목록과 같은 두 필드를 싣는다.
            'icon_url' => $this->getValue('icon_url'),
            'icon_source' => $this->getValue('icon_source', 'none'),
            'locales' => $this->getValue('locales', []),
            'layouts_count' => $this->getValue('layouts_count', 0),
            'components' => $this->getValue('components', ['basic' => [], 'composite' => []]),
            'license' => $this->getValue('license'),
            'metadata' => $this->getValue('metadata', []),
            // 외부 리소스 (template.json externals) — 정규화 책임은 TemplateExternals 에 위임.
            // `asset` 항목의 URL 해석에는 식별자와 캐시 버전이 필요하므로 뷰 컴포저
            // (CollectsTemplateExternals) 와 같은 인자를 넘긴다 — 빠뜨리면 `asset` 항목이
            // "식별자 미상" 으로 조용히 버려져 응답에서 사라진다.
            'externals' => TemplateExternals::normalize(
                $this->getValue('externals', []),
                $this->getValue('identifier'),
                // 트레이트를 사용하는 클래스 경유로 호출한다 — 트레이트 정적 메서드
                // 직접 호출(`ClearsTemplateCaches::`)은 PHP 8.1+ E_DEPRECATED 라
                // 템플릿 상세 응답마다 로그를 남긴다 (AssetUrl::staticExtBase 와 동형).
                ExtensionStaticCacheService::getExtensionCacheVersion(),
            ),
            // 의존성 상세 정보
            'dependencies' => $this->getDetailedDependencies(),
            // 버전 제약(7.1.4)이 실린 `integration_constraints` 를 우선한다 — 없으면(옛 정보 배열) 식별자 목록으로 보강
            'integrations' => DependencyEnricher::enrichIntegrations($this->getValue('integration_constraints') ?? $this->getValue('integrations', [])),
            // 이 템플릿을 연동으로 선언한 활성 확장 — 비활성화·제거 확인 창의 「연동 중인 확장」(7.1.4)
            'integrated_by' => $this->integratedBy('template'),
            // 타임스탬프
            'created_at' => $this->getValue('created_at'),
            'updated_at' => $this->getValue('updated_at'),
            // 상세 창이 목록 행(abilities 포함)을 담던 전역을 이 응답으로 덮어쓰므로 권한 메타도 함께 싣는다.
            ...$this->resourceMeta(request()),
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
            return LocalizedValue::pick($value, App::getLocale());
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
     * 카드가 미충족 의존을 빨간 글자·경고 아이콘·툴팁으로 그리도록 항목마다 충족 여부와 사유·버전을 싣습니다(#152).
     * 판정은 활성화·런타임 503 과 같은 원천(DependencyEnricher)입니다.
     *
     * @return array{modules: array<int, array<string, mixed>>, plugins: array<int, array<string, mixed>>} 의존성 목록
     */
    protected function getDependenciesSummary(): array
    {
        $summary = ['modules' => [], 'plugins' => []];

        foreach ($this->enrichedDependencies() as $item) {
            $summary[$item['type'] === 'module' ? 'modules' : 'plugins'][] = [
                'identifier' => $item['identifier'],
                // 이름이 비어 있으면(null·빈 문자열) 식별자로 대신한다 — 목록 카드는 이 값을 글자로 그린다(#727 React #31).
                'name' => $item['name'] ?: $item['identifier'],
                'type' => $item['type'],
                'is_met' => $item['is_met'],
                'status' => $item['status'],
                'required_version' => $item['required_version'],
                'installed_version' => $item['installed_version'],
            ];
        }

        return $summary;
    }

    /**
     * 모든 의존성이 충족되었는지 확인합니다 — 템플릿 활성화가 막는 조건과 같습니다.
     *
     * @return bool 모든 의존성 충족 여부
     */
    protected function areDependenciesMet(): bool
    {
        foreach ($this->enrichedDependencies() as $item) {
            if (! $item['is_met']) {
                return false;
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
        $detailed = ['modules' => [], 'plugins' => []];

        foreach ($this->enrichedDependencies() as $item) {
            $detailed[$item['type'] === 'module' ? 'modules' : 'plugins'][] = [
                'identifier' => $item['identifier'],
                'name' => $item['name'],
                'required_version' => $item['required_version'],
                'installed_version' => $item['installed_version'],
                'is_active' => $item['is_active'],
                'is_met' => $item['is_met'],
                'status' => $item['status'],
            ];
        }

        return $detailed;
    }

    /**
     * manifest 의 dependencies 를 판정식 원천으로 보강합니다 (응답 한 번에 한 번만 조회).
     *
     * @return array<int, array<string, mixed>> 보강된 의존성 목록 (DependencyEnricher::enrich 결과)
     */
    protected function enrichedDependencies(): array
    {
        $dependencies = $this->getValue('dependencies', []);

        return $this->enrichedDependenciesCache ??= DependencyEnricher::enrich(is_array($dependencies) ? $dependencies : []);
    }
}
