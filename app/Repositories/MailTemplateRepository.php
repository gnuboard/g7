<?php

namespace App\Repositories;

use App\Contracts\Repositories\MailTemplateRepositoryInterface;
use App\Models\MailTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 메일 템플릿 리포지토리 구현체
 *
 * 메일 템플릿의 조회, 수정 등 데이터 접근 로직을 처리합니다.
 */
class MailTemplateRepository implements MailTemplateRepositoryInterface
{
    /**
     * ID로 메일 템플릿을 찾습니다.
     *
     * @param int $id 메일 템플릿 ID
     * @return MailTemplate|null 찾은 모델 또는 null
     */
    public function findById(int $id): ?MailTemplate
    {
        return MailTemplate::find($id);
    }

    /**
     * 유형으로 메일 템플릿을 찾습니다.
     *
     * @param string $type 템플릿 유형
     * @return MailTemplate|null 찾은 모델 또는 null
     */
    public function findByType(string $type): ?MailTemplate
    {
        return MailTemplate::where('type', $type)->first();
    }

    /**
     * 활성 상태인 특정 유형 템플릿을 찾습니다.
     *
     * @param string $type 템플릿 유형
     * @return MailTemplate|null 활성 템플릿 또는 null
     */
    public function getActiveByType(string $type): ?MailTemplate
    {
        return MailTemplate::active()->byType($type)->first();
    }

    /**
     * 모든 메일 템플릿 목록을 반환합니다.
     *
     * @return Collection 메일 템플릿 컬렉션
     */
    public function getAllTemplates(): Collection
    {
        return MailTemplate::orderBy('id')->get();
    }

    /**
     * 메일 템플릿을 수정합니다.
     *
     * @param MailTemplate $template 수정 대상
     * @param array $data 수정 데이터
     * @return bool 수정 성공 여부
     */
    public function update(MailTemplate $template, array $data): bool
    {
        return $template->update($data);
    }

    /**
     * 메일 템플릿 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query = MailTemplate::query()->orderBy($sortBy, $sortOrder);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $searchType = $filters['search_type'] ?? 'all';
            $query->where(function ($q) use ($search, $searchType) {
                $locales = config('app.supported_locales', ['ko', 'en']);

                if ($searchType === 'subject' || $searchType === 'all') {
                    foreach ($locales as $locale) {
                        $q->orWhere("subject->{$locale}", 'like', "%{$search}%");
                    }
                }

                if ($searchType === 'body' || $searchType === 'all') {
                    foreach ($locales as $locale) {
                        $q->orWhere("body->{$locale}", 'like', "%{$search}%");
                    }
                }
            });
        }

        return $query->paginate($perPage);
    }
}
