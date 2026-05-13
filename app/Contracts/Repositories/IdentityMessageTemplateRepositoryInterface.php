<?php

namespace App\Contracts\Repositories;

use App\Models\IdentityMessageTemplate;
use Illuminate\Database\Eloquent\Collection;

interface IdentityMessageTemplateRepositoryInterface
{
    /**
     * ID로 메시지 템플릿 조회.
     *
     * @param  int  $id
     * @return IdentityMessageTemplate|null
     */
    public function findById(int $id): ?IdentityMessageTemplate;

    /**
     * 정의 ID + 채널로 템플릿 조회.
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return IdentityMessageTemplate|null
     */
    public function findByDefinitionAndChannel(int $definitionId, string $channel): ?IdentityMessageTemplate;

    /**
     * 활성 (정의 ID, 채널) 템플릿 조회.
     *
     * @param  int  $definitionId
     * @param  string  $channel
     * @return IdentityMessageTemplate|null
     */
    public function getActiveByDefinitionAndChannel(int $definitionId, string $channel): ?IdentityMessageTemplate;

    /**
     * 특정 정의의 전체 템플릿 조회.
     *
     * @param  int  $definitionId
     * @return Collection
     */
    public function getByDefinitionId(int $definitionId): Collection;

    /**
     * 템플릿 수정.
     *
     * @param  IdentityMessageTemplate  $template
     * @param  array  $data
     * @return IdentityMessageTemplate
     */
    public function update(IdentityMessageTemplate $template, array $data): IdentityMessageTemplate;

    /**
     * 템플릿 생성 또는 수정 (idempotent upsert).
     *
     * @param  array  $attributes
     * @param  array  $values
     * @return IdentityMessageTemplate
     */
    public function updateOrCreate(array $attributes, array $values): IdentityMessageTemplate;

    /**
     * 템플릿 신규 생성.
     *
     * @param  array  $data
     * @return IdentityMessageTemplate
     */
    public function create(array $data): IdentityMessageTemplate;
}
