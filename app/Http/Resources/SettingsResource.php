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
        ];
    }
}
