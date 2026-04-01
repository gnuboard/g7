<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;

/**
 * 게시판 메일 템플릿 리포지토리 인터페이스
 */
interface BoardMailTemplateRepositoryInterface
{
    /**
     * ID로 메일 템플릿을 조회합니다.
     *
     * @param int $id 템플릿 ID
     * @return BoardMailTemplate|null
     */
    public function findById(int $id): ?BoardMailTemplate;

    /**
     * 유형으로 메일 템플릿을 조회합니다.
     *
     * @param string $type 템플릿 유형
     * @return BoardMailTemplate|null
     */
    public function findByType(string $type): ?BoardMailTemplate;

    /**
     * 활성 상태인 특정 유형의 템플릿을 조회합니다.
     *
     * @param string $type 템플릿 유형
     * @return BoardMailTemplate|null
     */
    public function getActiveByType(string $type): ?BoardMailTemplate;

    /**
     * 전체 템플릿 목록을 조회합니다.
     *
     * @return Collection
     */
    public function getAllTemplates(): Collection;

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param BoardMailTemplate $template 수정 대상
     * @param array $data 수정 데이터
     * @return bool
     */
    public function update(BoardMailTemplate $template, array $data): bool;

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
