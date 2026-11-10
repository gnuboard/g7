<?php

namespace App\Extension\Concerns;

use App\Extension\CoreVersionChecker;
use App\Extension\Helpers\DependencyVersionWarnings;
use App\Extension\Helpers\IntegrationNotices;

/**
 * 업데이트 대상 버전의 코어 호환 정보를 만드는 공통 조각
 *
 * 매니저 셋(Module·Plugin·Template)의 단건 업데이트 확인(check*Update)과 목록 빌더
 * (getInstalled*WithDetails)가 같은 규칙으로 `required_core_version`·`is_compatible`·
 * `current_core_version` 을 만든다. 목록이 이 셋을 싣지 않으면 업데이트 창의 코어 호환 경고와
 * 강제 업데이트 체크가 뜨지 않는다.
 *
 * 요구 버전 규칙: 번들 출처면 _bundled 매니페스트의 g7_version(없으면 활성 매니페스트 값),
 * GitHub 출처면 활성 매니페스트의 g7_version(원격 매니페스트를 받지 않는 베스트 이펙트 —
 * 정확한 판정은 업데이트 스테이징 단계에서 한다).
 *
 * 같은 자리에서 업데이트 대상 판이 요구하는 의존(`dependencies`)·연동(`integrations`) 중 지금 설치 상태로 충족되지
 * 않는 것도 만든다 — 업데이트 창이 실행 **전에** 알리게. 판정은 업데이트가 끝난 뒤 완료 문구에 붙는 경고·안내와 같다
 * ({@see DependencyVersionWarnings::forManifest()}·{@see IntegrationNotices::forManifest()}). 업데이트를 막지 않는다.
 */
trait ResolvesUpdateCoreCompatibility
{
    /**
     * 업데이트 대상 버전의 코어 요구 버전을 정합니다.
     *
     * @param  string|null  $updateSource  업데이트 출처 (github|bundled|null)
     * @param  array<string, mixed>|null  $bundledManifest  같은 식별자의 _bundled 매니페스트 (없으면 null)
     * @param  string|null  $activeRequiredCoreVersion  활성 매니페스트의 코어 요구 버전
     * @return string|null 코어 요구 버전 제약 (null 이면 제약 없음)
     */
    protected function resolveUpdateTargetRequiredCoreVersion(
        ?string $updateSource,
        ?array $bundledManifest,
        ?string $activeRequiredCoreVersion,
    ): ?string {
        if ($updateSource !== 'github' && $bundledManifest !== null) {
            $bundledRequired = $bundledManifest['g7_version'] ?? null;

            return is_string($bundledRequired) ? $bundledRequired : $activeRequiredCoreVersion;
        }

        return $activeRequiredCoreVersion;
    }

    /**
     * 코어 요구 버전으로 업데이트 호환 필드 셋을 만듭니다.
     *
     * @param  string|null  $requiredCoreVersion  코어 요구 버전 제약 (null 이면 호환으로 간주)
     * @return array{required_core_version: ?string, is_compatible: bool, current_core_version: string} 호환 필드
     */
    protected function buildUpdateCompatibilityFields(?string $requiredCoreVersion): array
    {
        // 업데이트 확인·목록 행의 정보 필드다(update_available 과 함께 실린다) — 422 오류 응답이 아니므로
        // error_code 를 싣지 않는다. 실제 거절은 스테이징 단계의 CoreVersionMismatchException 이 맡는다.
        // audit:allow error-code-core-version-mismatch reason: 정보 응답(check*Update·목록 행) 필드 빌더
        return [
            'required_core_version' => $requiredCoreVersion,
            'is_compatible' => CoreVersionChecker::isCompatible($requiredCoreVersion),
            'current_core_version' => CoreVersionChecker::getCoreVersion(),
        ];
    }

    /**
     * 업데이트 대상 판의 manifest 를 정합니다 — 업데이트 전 의존·연동 대조에 쓴다.
     *
     * 번들 출처면 _bundled manifest 다. GitHub 출처는 원격 manifest 를 받지 않으므로, _bundled 판이 업데이트 대상 버전과
     * 같을 때만 그 manifest 를 쓰고(같은 판이다) 아니면 null(알 수 없음)이다.
     *
     * @param  string|null  $updateSource  업데이트 출처 (github|bundled|null)
     * @param  array<string, mixed>|null  $bundledManifest  같은 식별자의 _bundled manifest (없으면 null)
     * @param  string|null  $latestVersion  업데이트 대상 버전
     * @return array<string, mixed>|null 업데이트 대상 판 manifest (알 수 없으면 null)
     */
    protected function resolveUpdateTargetManifest(?string $updateSource, ?array $bundledManifest, ?string $latestVersion): ?array
    {
        if ($bundledManifest === null) {
            return null;
        }

        if ($updateSource !== 'github') {
            return $bundledManifest;
        }

        $bundledVersion = $bundledManifest['version'] ?? null;

        return is_string($bundledVersion) && is_string($latestVersion) && version_compare($bundledVersion, $latestVersion, '==')
            ? $bundledManifest
            : null;
    }

    /**
     * 업데이트 대상 판이 요구하는 의존·연동 중 지금 설치 상태로 충족되지 않는 것을 필드 셋으로 만듭니다.
     *
     * 값이 null 이면 업데이트 대상 manifest 를 알 수 없다는 뜻이다(GitHub 출처) — 빈 배열(모두 충족)과 구분한다.
     *
     * @param  array<string, mixed>|null  $targetManifest  업데이트 대상 판 manifest ({@see resolveUpdateTargetManifest()})
     * @return array{update_dependency_warnings: list<array<string, mixed>>|null, update_integration_notices: list<array<string, mixed>>|null} 업데이트 전 대조 필드
     */
    protected function buildUpdateTargetRequirementFields(?array $targetManifest): array
    {
        if ($targetManifest === null) {
            return ['update_dependency_warnings' => null, 'update_integration_notices' => null];
        }

        return [
            'update_dependency_warnings' => DependencyVersionWarnings::forManifest($targetManifest),
            'update_integration_notices' => IntegrationNotices::forManifest($targetManifest),
        ];
    }
}
