<?php

namespace App\Contracts\Repositories;

use App\Models\MailTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface MailTemplateRepositoryInterface
{
    /**
     * ID로 메일 템플릿을 찾습니다.
     *
     * @param int $id 메일 템플릿 ID
     * @return MailTemplate|null 찾은 모델 또는 null
     */
    public function findById(int $id): ?MailTemplate;

    /**
     * 유형으로 메일 템플릿을 찾습니다.
     *
     * @param string $type 템플릿 유형
     * @return MailTemplate|null 찾은 모델 또는 null
     */
    public function findByType(string $type): ?MailTemplate;

    /**
     * 활성 상태인 특정 유형 템플릿을 찾습니다.
     *
     * @param string $type 템플릿 유형
     * @return MailTemplate|null 활성 템플릿 또는 null
     */
    public function getActiveByType(string $type): ?MailTemplate;

    /**
     * 모든 메일 템플릿 목록을 반환합니다.
     *
     * @return Collection 메일 템플릿 컬렉션
     */
    public function getAllTemplates(): Collection;

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param MailTemplate $template 수정 대상
     * @param array $data 수정 데이터
     * @return bool 수정 성공 여부
     */
    public function update(MailTemplate $template, array $data): bool;

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
