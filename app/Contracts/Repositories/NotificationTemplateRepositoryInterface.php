<?php

namespace App\Contracts\Repositories;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Collection;

interface NotificationTemplateRepositoryInterface
{
    /**
     * ID로 알림 템플릿 조회.
     *
     * @param  int  $id  템플릿 ID
     * @return NotificationTemplate|null 템플릿 (없으면 null)
     */
    public function findById(int $id): ?NotificationTemplate;

    /**
     * 알림 정의 ID + 채널로 템플릿 조회.
     *
     * @param  int  $definitionId  알림 정의 ID
     * @param  string  $channel  채널명
     * @return NotificationTemplate|null 템플릿 (없으면 null)
     */
    public function findByDefinitionAndChannel(int $definitionId, string $channel): ?NotificationTemplate;

    /**
     * 알림 타입 + 채널로 활성 템플릿 조회.
     *
     * @param  string  $type  알림 정의 타입
     * @param  string  $channel  채널명
     * @return NotificationTemplate|null 활성 템플릿 (없으면 null)
     */
    public function getActiveByTypeAndChannel(string $type, string $channel): ?NotificationTemplate;

    /**
     * 특정 알림 정의의 모든 템플릿 조회.
     *
     * @param  int  $definitionId  알림 정의 ID
     * @return Collection<int, NotificationTemplate> 템플릿 목록
     */
    public function getByDefinitionId(int $definitionId): Collection;

    /**
     * 알림 정의에 「기본」 이 아닌(운영자가 고친) 템플릿이 하나라도 있는지 확인합니다.
     *
     * @param  int  $definitionId  알림 정의 ID
     * @return bool 기본이 아닌 템플릿이 있으면 true
     */
    public function hasNonDefaultByDefinitionId(int $definitionId): bool;

    /**
     * 템플릿 수정.
     *
     * @param  NotificationTemplate  $template  수정할 템플릿
     * @param  array<string, mixed>  $data  수정할 값
     * @return NotificationTemplate 수정된 템플릿
     */
    public function update(NotificationTemplate $template, array $data): NotificationTemplate;

    /**
     * 템플릿 생성 또는 수정.
     *
     * @param  array<string, mixed>  $attributes  찾을 조건
     * @param  array<string, mixed>  $values  저장할 값
     * @return NotificationTemplate 생성 또는 수정된 템플릿
     */
    public function updateOrCreate(array $attributes, array $values): NotificationTemplate;
}
