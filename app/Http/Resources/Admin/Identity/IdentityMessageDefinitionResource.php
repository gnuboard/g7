<?php

namespace App\Http\Resources\Admin\Identity;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * IDV 메시지 정의 리소스.
 */
class IdentityMessageDefinitionResource extends BaseApiResource
{
    /**
     * {@inheritDoc}
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.admin.identity.messages.update',
            'can_delete' => 'core.admin.identity.messages.update',
        ];
    }

    /**
     * abilities 동적 후처리 — 시드 정의(is_default=true)는 삭제 거부.
     *
     * @param  Request  $request
     * @return array<string, bool>
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        if (isset($abilities['can_delete']) && $this->getValue('is_default')) {
            $abilities['can_delete'] = false;
        }

        return $abilities;
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'provider_id' => $this->getValue('provider_id'),
            'scope_type' => $this->getValue('scope_type'),
            'scope_value' => $this->getValue('scope_value'),
            'name' => $this->getValue('name'),
            'description' => $this->getValue('description'),
            'channels' => $this->getValue('channels'),
            'variables' => $this->getValue('variables'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'user_overrides' => $this->getValue('user_overrides'),
            // 목록은 대표 템플릿 1건만 로드하고(firstTemplate), 단건은 전체를 로드한다(templates).
            // 화면 계약은 양쪽 모두 `templates` 배열이라 여기서 하나로 합류시킨다 —
            // 로드된 쪽을 쓰고, 어느 쪽도 로드되지 않았으면 키 자체를 내보내지 않는다.
            //
            // 대표 템플릿 분기는 `whenLoaded` 로 쓸 수 없다. `whenLoaded($relation, $value)` 는
            // 관계가 로드됐어도 값이 null 이면 $value 를 평가하지 않고 그대로 null 을 돌려준다
            // (HasOne 이 0건이면 null). 그러면 "로드하지 않음"(null)과 "로드했더니 0건"(빈 배열)이
            // 같은 값이 되어 소비자가 둘을 구분할 수 없다 — 관계 로드 여부는 직접 판정한다.
            'templates' => $this->whenLoaded(
                'templates',
                fn () => IdentityMessageTemplateResource::collection($this->templates),
                fn () => $this->resource->relationLoaded('firstTemplate')
                    ? ($this->firstTemplate
                        ? IdentityMessageTemplateResource::collection(collect([$this->firstTemplate]))
                        : [])
                    : new MissingValue,
            ),
            // 목록이 대표 1건만 싣기 때문에, "템플릿이 더 있는가" 는 배열 길이로 알 수 없다.
            'templates_count' => $this->whenHas('templates_count', fn () => (int) $this->templates_count),
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
