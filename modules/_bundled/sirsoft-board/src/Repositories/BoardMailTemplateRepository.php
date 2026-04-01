<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardMailTemplateRepositoryInterface;

/**
 * 게시판 메일 템플릿 리포지토리 구현체
 */
class BoardMailTemplateRepository implements BoardMailTemplateRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?BoardMailTemplate
    {
        return BoardMailTemplate::find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findByType(string $type): ?BoardMailTemplate
    {
        return BoardMailTemplate::where('type', $type)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveByType(string $type): ?BoardMailTemplate
    {
        return BoardMailTemplate::active()->byType($type)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTemplates(): Collection
    {
        return BoardMailTemplate::orderBy('id')->get();
    }

    /**
     * {@inheritdoc}
     */
    public function update(BoardMailTemplate $template, array $data): bool
    {
        return $template->update($data);
    }

    /**
     * {@inheritdoc}
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query = BoardMailTemplate::query()->orderBy($sortBy, $sortOrder);

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
