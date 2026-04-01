<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\EcommerceMailTemplate;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceMailTemplateRepositoryInterface;

/**
 * 이커머스 메일 템플릿 리포지토리 구현체
 */
class EcommerceMailTemplateRepository implements EcommerceMailTemplateRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?EcommerceMailTemplate
    {
        return EcommerceMailTemplate::find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findByType(string $type): ?EcommerceMailTemplate
    {
        return EcommerceMailTemplate::where('type', $type)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveByType(string $type): ?EcommerceMailTemplate
    {
        return EcommerceMailTemplate::active()->byType($type)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTemplates(): Collection
    {
        return EcommerceMailTemplate::orderBy('id')->get();
    }

    /**
     * {@inheritdoc}
     */
    public function update(EcommerceMailTemplate $template, array $data): bool
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
        $query = EcommerceMailTemplate::query()->orderBy($sortBy, $sortOrder);

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
