<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SettingResource extends BaseApiResource
{
    /**
     * 설정 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 설정 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->getValue('key'),
            'value' => $this->getValue('value'),
            'type' => $this->getValue('type', 'string'),
            'description' => $this->getValue('description'),
            'group' => $this->getValue('group', 'general'),
            'is_public' => $this->getValue('is_public', false),
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
            'can_update' => 'core.settings.update',
        ];
    }

    /**
     * 키-값 쌍만 반환하는 단순한 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 키-값 쌍 배열
     */
    public function toSimpleArray(): array
    {
        return [
            $this->getValue('key') => $this->getValue('value'),
        ];
    }
}
