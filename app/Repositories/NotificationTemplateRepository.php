<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Collection;

class NotificationTemplateRepository implements NotificationTemplateRepositoryInterface
{
    /**
     * ID로 알림 템플릿 조회.
     *
     * @param int $id
     * @return NotificationTemplate|null
     */
    public function findById(int $id): ?NotificationTemplate
    {
        return NotificationTemplate::find($id);
    }

    /**
     * 알림 정의 ID + 채널로 템플릿 조회.
     *
     * @param int $definitionId
     * @param string $channel
     * @return NotificationTemplate|null
     */
    public function findByDefinitionAndChannel(int $definitionId, string $channel): ?NotificationTemplate
    {
        return NotificationTemplate::where('definition_id', $definitionId)
            ->byChannel($channel)
            ->first();
    }

    /**
     * 알림 타입 + 채널로 활성 템플릿 조회.
     *
     * @param string $type
     * @param string $channel
     * @return NotificationTemplate|null
     */
    public function getActiveByTypeAndChannel(string $type, string $channel): ?NotificationTemplate
    {
        return NotificationTemplate::active()
            ->byChannel($channel)
            ->whereHas('definition', function ($query) use ($type) {
                $query->byType($type)->active();
            })
            ->first();
    }

    /**
     * 특정 알림 정의의 모든 템플릿 조회.
     *
     * @param int $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection
    {
        return NotificationTemplate::where('definition_id', $definitionId)->get();
    }

    /**
     * 템플릿 수정.
     *
     * @param NotificationTemplate $template
     * @param array $data
     * @return NotificationTemplate
     */
    public function update(NotificationTemplate $template, array $data): NotificationTemplate
    {
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 템플릿 생성 또는 수정.
     *
     * @param array $attributes
     * @param array $values
     * @return NotificationTemplate
     */
    public function updateOrCreate(array $attributes, array $values): NotificationTemplate
    {
        return NotificationTemplate::updateOrCreate($attributes, $values);
    }
}
