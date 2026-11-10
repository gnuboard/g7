<?php

namespace App\Http\Resources\Traits;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 확장 목록 행의 업데이트 대상 코어 호환 필드를 직렬화하는 Trait
 *
 * 매니저 목록 빌더는 업데이트가 있을 때만 `is_compatible`·`required_core_version`·
 * `current_core_version` 을 행에 싣는다. 업데이트 창의 코어 호환 경고·「강제 업데이트」 체크가
 * 이 셋을 읽는다. 행에 없으면 응답에도 싣지 않는다 — 설치본 호환은 `core_compatible` 이 맡는다.
 * `when()` 대신 펼침으로 둔다 — 리소스를 `toArray()` 로 직접 부르는 경로에서 MissingValue 가 새지 않게.
 * 업데이트 대상 판이 요구하는 의존·연동 대조 결과도 같은 조건(업데이트가 있는 행)으로 싣는다.
 */
trait ExposesUpdateCoreCompatibility
{
    /**
     * 업데이트 대상 코어 호환 필드를 반환합니다 (행에 없으면 빈 배열).
     *
     * @return array<string, mixed> is_compatible·required_core_version·current_core_version 또는 빈 배열
     */
    protected function updateCoreCompatibilityFields(): array
    {
        if (! is_bool($this->getValue('is_compatible'))) {
            return [];
        }

        return [
            'is_compatible' => $this->getValue('is_compatible'),
            'required_core_version' => $this->getValue('required_core_version'),
            'current_core_version' => $this->getValue('current_core_version'),
        ];
    }

    /**
     * 업데이트 대상 판이 요구하는 의존·연동 중 지금 못 미치는 것을 반환합니다 (행에 없으면 빈 배열).
     *
     * 매니저 목록 빌더가 업데이트가 있는 행에만 싣는다. 업데이트 창이 실행 전에 이 목록으로 경고한다. null 은 업데이트 대상
     * 판 manifest 를 알 수 없다는 뜻이다(GitHub 출처).
     *
     * @return array<string, mixed> update_dependency_warnings·update_integration_notices 또는 빈 배열
     */
    protected function updateTargetRequirementFields(): array
    {
        // 목록 컬렉션은 생성자에서 항목을 이미 리소스로 감싸고 toArray() 에서 한 번 더 감싼다 — 그때 resource 는
        // 배열이 아니라 안쪽 리소스다. 키가 「없음」과 「null(대상 판 manifest 모름)」을 가려야 하므로 안쪽 행 배열을 직접 본다.
        $row = $this->resource instanceof JsonResource ? $this->resource->resource : $this->resource;

        if (! is_array($row) || ! array_key_exists('update_integration_notices', $row)) {
            return [];
        }

        $dependencyWarnings = $row['update_dependency_warnings'] ?? null;
        $integrationNotices = $row['update_integration_notices'];

        return [
            'update_dependency_warnings' => is_array($dependencyWarnings) ? array_values($dependencyWarnings) : null,
            'update_integration_notices' => is_array($integrationNotices) ? array_values($integrationNotices) : null,
        ];
    }
}
