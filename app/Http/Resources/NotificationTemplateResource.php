<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationTemplateResource extends BaseApiResource
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
            'definition_id' => $this->getValue('definition_id'),
            'channel' => $this->getValue('channel'),
            'subject' => $this->getValue('subject'),
            'body' => $this->getValue('body'),
            'click_url' => $this->getValue('click_url'),
            'recipients' => $this->enrichRecipients($this->getValue('recipients') ?? []),
            'is_active' => (bool) $this->getValue('is_active'),
            'is_default' => (bool) $this->getValue('is_default'),
            'user_overrides' => $this->getValue('user_overrides'),
            'updated_by' => $this->resource->updater?->uuid ?? null,
            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 수신자 규칙에 표시용 이름을 추가합니다.
     *
     * @param array $recipients 수신자 규칙 배열
     * @return array
     */
    private function enrichRecipients(array $recipients): array
    {
        $locale = app()->getLocale();

        return array_map(function (array $rule) use ($locale) {
            if ($rule['type'] === 'role' && ! empty($rule['value'])) {
                $role = Role::where('identifier', $rule['value'])->first();
                $rule['display_name'] = $role
                    ? ($role->name[$locale] ?? $role->name['ko'] ?? $rule['value'])
                    : $rule['value'];
            }

            if ($rule['type'] === 'specific_users' && ! empty($rule['value'])) {
                $users = User::whereIn('uuid', $rule['value'])->pluck('name', 'uuid');
                $rule['display_names'] = $users->values()->all();
            }

            return $rule;
        }, $recipients);
    }
}
