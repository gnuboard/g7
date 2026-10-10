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
     * @param  array  $recipients  수신자 규칙 배열
     * @return array
     */
    private function enrichRecipients(array $recipients): array
    {
        $locale = app()->getLocale();

        // 수정 요청이 줄 번호 객체(`{"1":…,"0":…}`)로 저장해 둔 행이 남아 있다 — 줄 번호 순서의
        // 목록으로 낸다. 그대로 내면 화면이 저장 순서(1, 0)로 그려 규칙 순서가 뒤집혀 보인다.
        ksort($recipients, SORT_NUMERIC);
        $recipients = array_values($recipients);

        return array_map(function (array $rule) use ($locale) {
            if ($rule['type'] === 'role' && ! empty($rule['value'])) {
                // 값은 문자열 하나일 수도, 여러 개일 수도 있다(역할 다중 선택).
                $identifiers = array_values(array_filter((array) $rule['value']));
                $names = Role::whereIn('identifier', $identifiers)
                    ->get()
                    ->keyBy('identifier');

                $labels = array_map(
                    static fn (string $identifier): string => ($names[$identifier]->name[$locale]
                        ?? $names[$identifier]->name['ko']
                        ?? $identifier),
                    $identifiers,
                );

                // 화면은 칩을 하나씩 그린다 — 단수 키는 첫 항목으로 유지해 기존 바인딩이
                // 빈칸이 되지 않게 하고, 복수 키를 새로 싣는다.
                $rule['display_name'] = $labels[0] ?? '';
                $rule['display_names'] = $labels;
                // 값도 **언제나 배열**로 낸다. 저장된 값이 문자열인 행이 남아 있는데
                // 읽는 쪽마다 모양을 가르면 그 판정이 화면·서버 두 벌이 되고, 한쪽만
                // 고쳐졌을 때 역할이 조용히 덮인다. 저장은 배열·문자열 모두 받는다.
                $rule['value'] = $identifiers;
            }

            if ($rule['type'] === 'specific_users' && ! empty($rule['value'])) {
                $uuids = array_values(array_filter((array) $rule['value']));
                $names = User::whereIn('uuid', $uuids)->pluck('name', 'uuid');

                // **저장된 순서**로 되돌린다. DB 반환 순서로 뽑으면 화면이 인덱스로 짝지을 때
                // 다른 사람 이름이 붙고, 삭제된 UUID 가 섞이면 그 뒤가 통째로 한 칸씩 밀린다.
                $rule['display_names'] = array_map(
                    static fn (string $uuid): string => (string) ($names[$uuid] ?? $uuid),
                    $uuids,
                );
            }

            return $rule;
        }, $recipients);
    }
}
