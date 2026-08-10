<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class NotificationDefinitionResource extends BaseApiResource
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.settings.update',
            'can_delete' => 'core.settings.update',
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        // 컬렉션(NotificationDefinitionCollection)이 이 배열을 그대로 응답에 실어 Laravel 의
        // MissingValue 제거 단계를 거치지 않는다 — 미충족 조건부 필드를 직접 걸러낸다.
        // (걸러내지 않으면 `"templates": {}` 같은 빈 객체가 응답에 남는다)
        return $this->withoutMissing([
            'id' => $this->getValue('id'),
            'type' => $this->getValue('type'),
            'hook_prefix' => $this->getValue('hook_prefix'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'name' => $this->getValue('name'),
            'description' => $this->getValue('description'),
            'variables' => $this->getValue('variables'),
            'channels' => $this->getValue('channels'),
            'hooks' => $this->getValue('hooks'),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'templates' => $this->whenLoaded(
                'templates',
                fn () => NotificationTemplateResource::collection($this->templates)
            ),
            // 목록이 템플릿을 한 채널로 좁혀 싣기 때문에, "커스터마이즈된 템플릿이 있는가" 는
            // 배열을 훑어서는 알 수 없다 (다른 채널만 수정한 경우를 놓친다). 되돌리기 버튼
            // 노출 조건이 이 값에 의존하므로 집계로 내려보낸다.
            'has_customized_templates' => $this->whenHas(
                'customized_templates_count',
                fn () => (int) $this->customized_templates_count > 0
            ),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ]);
    }
}
