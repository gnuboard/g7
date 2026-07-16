<?php

namespace App\Http\Resources\Admin\Identity;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

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
            'templates' => $this->relationLoaded('templates')
                ? IdentityMessageTemplateResource::collection($this->templates)
                : null,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
