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
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 알림 정의 응답 배열
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
            // 「관련 사용자」 선택지의 출처. 비면 화면이 그 타입을 감춘다.
            'relations' => $this->getValue('relations') ?? [],
            // 선택지·목록이 `post_author` 같은 키 대신 읽을 이름. 이름이 없으면 키를 그대로 둔다.
            'relation_labels' => $this->relationLabels((array) ($this->getValue('relations') ?? [])),
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

    /**
     * 관계 키마다 운영자가 읽을 이름을 찾습니다.
     *
     * 관계는 그 알림을 만든 확장이 선언하므로 이름도 그 확장의 언어 파일에서 찾는다 —
     * 확장은 `{식별자}::messages.notification_relations.{관계}`, 코어는 `notification.relations.{관계}`.
     * 이름이 없는 관계는 키를 그대로 돌려준다(선언은 있는데 이름만 빠진 관계도 고를 수는 있어야 한다).
     *
     * @param  array<int, mixed>  $relations  정의가 선언한 관계 키 목록
     * @return array<string, string> 관계 키 => 표시 이름
     */
    private function relationLabels(array $relations): array
    {
        $identifier = (string) ($this->getValue('extension_identifier') ?? '');
        $labels = [];

        foreach ($relations as $relation) {
            if (! is_string($relation) || $relation === '') {
                continue;
            }

            $key = ($identifier === '' || $identifier === 'core')
                ? 'notification.relations.'.$relation
                : $identifier.'::messages.notification_relations.'.$relation;
            $label = __($key);

            $labels[$relation] = is_string($label) && $label !== $key ? $label : $relation;
        }

        return $labels;
    }
}
