<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductNoticeTemplateRepositoryInterface;

/**
 * 상품정보제공고시 템플릿 Repository 구현체
 */
class ProductNoticeTemplateRepository implements ProductNoticeTemplateRepositoryInterface
{
    public function __construct(
        protected ProductNoticeTemplate $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAll(array $filters = [], array $with = []): Collection
    {
        $query = $this->model->newQuery();

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 검색 키워드 (다국어 name 필드 검색)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                }
            });
        }

        // 정렬: 기본은 sort_order → id
        $query->orderBy('sort_order')->orderBy('id');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getPaginated(array $filters = [], int $perPage = 20, array $with = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // 활성 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 검색 키워드 (다국어 name 필드 검색)
        if (! empty($filters['search'])) {
            $keyword = $filters['search'];
            $locales = config('app.translatable_locales', ['ko', 'en']);
            $query->where(function ($q) use ($keyword, $locales) {
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$keyword}%");
                }
            });
        }

        // 정렬: 기본은 sort_order → id
        $query->orderBy('sort_order')->orderBy('id');

        // Eager loading
        if (! empty($with)) {
            $query->with($with);
        }

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id, array $with = []): ?ProductNoticeTemplate
    {
        $query = $this->model->newQuery();

        if (! empty($with)) {
            $query->with($with);
        }

        return $query->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ProductNoticeTemplate
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ProductNoticeTemplate
    {
        $template = $this->findById($id);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $template = $this->findById($id);

        return $template->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function copy(int $id): ProductNoticeTemplate
    {
        $original = $this->findById($id);

        // 이름에 복사 접미사 추가
        $name = $original->name;
        if (is_array($name)) {
            foreach ($name as $locale => $value) {
                $copySuffix = $locale === 'ko' ? ' (복사)' : ' (Copy)';
                $name[$locale] = $value . $copySuffix;
            }
        }

        return $this->model->create([
            'name' => $name,
            'category' => $original->category,
            'fields' => $original->fields,
            'is_active' => $original->is_active,
            'sort_order' => $this->getMaxSortOrder() + 1,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxSortOrder(): int
    {
        return (int) $this->model->max('sort_order');
    }
}
