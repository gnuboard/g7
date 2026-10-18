<?php

namespace App\Extension\Concerns;

use App\Extension\CoreVersionChecker;

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
}
