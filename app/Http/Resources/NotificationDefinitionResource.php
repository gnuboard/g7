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
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
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
            'templates' => $this->relationLoaded('templates')
                ? NotificationTemplateResource::collection($this->templates)
                : null,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
