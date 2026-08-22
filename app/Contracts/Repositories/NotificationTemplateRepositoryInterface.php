<?php

namespace App\Contracts\Repositories;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Collection;

interface NotificationTemplateRepositoryInterface
{
    /**
     * ID로 알림 템플릿 조회.
     *
     * @param int $id
     * @return NotificationTemplate|null
     */
    public function findById(int $id): ?NotificationTemplate;

    /**
     * 알림 정의 ID + 채널로 템플릿 조회.
     *
     * @param int $definitionId
     * @param string $channel
     * @return NotificationTemplate|null
     */
    public function findByDefinitionAndChannel(int $definitionId, string $channel): ?NotificationTemplate;

    /**
     * 알림 타입 + 채널로 활성 템플릿 조회.
     *
     * @param string $type
     * @param string $channel
     * @return NotificationTemplate|null
     */
    public function getActiveByTypeAndChannel(string $type, string $channel): ?NotificationTemplate;

    /**
     * 특정 알림 정의의 모든 템플릿 조회.
     *
     * @param int $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection;

    /**
     * 템플릿 수정.
     *
     * @param NotificationTemplate $template
     * @param array $data
     * @return NotificationTemplate
     */
    public function update(NotificationTemplate $template, array $data): NotificationTemplate;

    /**
     * 템플릿 생성 또는 수정.
     *
     * @param array $attributes
     * @param array $values
     * @return NotificationTemplate
     */
    public function updateOrCreate(array $attributes, array $values): NotificationTemplate;
}
