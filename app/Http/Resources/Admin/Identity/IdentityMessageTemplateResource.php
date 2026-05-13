<?php

namespace App\Http\Resources\Admin\Identity;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * IDV 메시지 템플릿 리소스.
 */
class IdentityMessageTemplateResource extends BaseApiResource
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
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'definition_id' => $this->getValue('definition_id'),
            'channel' => $this->getValue('channel'),
            'subject' => $this->getValue('subject'),
            'body' => $this->getValue('body'),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'user_overrides' => $this->getValue('user_overrides'),
            'updated_by' => $this->resource->updater?->uuid ?? null,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
