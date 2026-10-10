<?php

namespace App\Http\Resources\Traits;

/**
 * 확장 목록 행의 업데이트 대상 코어 호환 필드를 직렬화하는 Trait
 *
 * 매니저 목록 빌더는 업데이트가 있을 때만 `is_compatible`·`required_core_version`·
 * `current_core_version` 을 행에 싣는다. 업데이트 창의 코어 호환 경고·「강제 업데이트」 체크가
 * 이 셋을 읽는다. 행에 없으면 응답에도 싣지 않는다 — 설치본 호환은 `core_compatible` 이 맡는다.
 * `when()` 대신 펼침으로 둔다 — 리소스를 `toArray()` 로 직접 부르는 경로에서 MissingValue 가 새지 않게.
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
}
