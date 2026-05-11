<?php

namespace App\Repositories;

use App\Contracts\Repositories\IdentityMessageTemplateRepositoryInterface;
use App\Models\IdentityMessageTemplate;
use Illuminate\Database\Eloquent\Collection;

class IdentityMessageTemplateRepository implements IdentityMessageTemplateRepositoryInterface
{
    /**
     * ID로 메시지 템플릿 조회.
     *
     * @param  int  $id
     * @return IdentityMessageTemplate|null
     */
    public function findById(int $id): ?IdentityMessageTemplate
    {
        return IdentityMessageTemplate::find($id);
    }

    /**
     * 정의 ID + 채널로 템플릿 조회.
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return IdentityMessageTemplate|null
     */
    public function findByDefinitionAndChannel(int $definitionId, string $channel): ?IdentityMessageTemplate
    {
        return IdentityMessageTemplate::where('definition_id', $definitionId)
            ->byChannel($channel)
            ->first();
    }

    /**
     * 활성 (정의 ID, 채널) 템플릿 조회.
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return IdentityMessageTemplate|null
     */
    public function getActiveByDefinitionAndChannel(int $definitionId, string $channel): ?IdentityMessageTemplate
    {
        return IdentityMessageTemplate::active()
            ->where('definition_id', $definitionId)
            ->byChannel($channel)
            ->first();
    }

    /**
     * 특정 정의의 전체 템플릿 조회.
     *
     * @param  int  $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection
    {
        return IdentityMessageTemplate::where('definition_id', $definitionId)->get();
    }

    /**
     * 템플릿 수정.
     *
     * @param  IdentityMessageTemplate  $template
     * @param  array  $data
     * @return IdentityMessageTemplate
     */
    public function update(IdentityMessageTemplate $template, array $data): IdentityMessageTemplate
    {
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 템플릿 생성 또는 수정 (idempotent upsert).
     *
     * @param  array  $attributes
     * @param  array  $values
     * @return IdentityMessageTemplate
     */
    public function updateOrCreate(array $attributes, array $values): IdentityMessageTemplate
    {
        return IdentityMessageTemplate::updateOrCreate($attributes, $values);
    }

    /**
     * 템플릿 신규 생성.
     *
     * @param  array  $data
     * @return IdentityMessageTemplate
     */
    public function create(array $data): IdentityMessageTemplate
    {
        return IdentityMessageTemplate::create($data);
    }
}
