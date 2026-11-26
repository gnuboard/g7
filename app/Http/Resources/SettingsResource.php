<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 시스템 설정 리소스
 *
 * 배열 기반 설정 데이터에 abilities 메타를 추가합니다.
 */
class SettingsResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * Settings는 Eloquent 모델이 아닌 배열이므로
     * 원본 배열에 abilities 메타를 병합합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 설정 데이터 + abilities
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : parent::toArray($request);

        return array_merge($data, $this->resourceMeta($request));
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.settings.update',
            // audit:allow abilitymap-permission-matches-route-ssot reason: 코어 교체 권한은
            // 설정 리소스의 것이 아니다 — 라우트 SSoT 도 `core.system.update` 이고,
            // 이 값은 설정 화면이 **업데이트 탭을 보여 줄지**를 정하는 표시 게이트다. 설정
            // 수정 권한만 위임받은 관리자에게 이 영역이 감춰지는 것이 의도된 동작이므로,
            // 룰이 막으려는 「원 리소스 권한만 가진 액터에게 정당한 조작이 감춰지는」 형태가
            // 아니다. 이 값이 거짓이면 화면은 업데이트 영역을 **비활성이 아니라 미표시**로
            // 다룬다 — 누를 수 없는 버튼을 남겨 두면 그 권한이 있는 줄 알고 기다리게 된다.
            'can_core_update' => 'core.system.update',
        ];
    }
}
