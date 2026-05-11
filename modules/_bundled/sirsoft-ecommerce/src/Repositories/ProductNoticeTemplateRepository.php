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
     * 필터/eager loading 을 적용해 모든 상품정보 템플릿을 조회합니다 (sort_order 정렬).
     *
     * @param  array{is_active?: bool, search?: string}  $filters  필터 조건
     * @param  array<int, string>  $with  eager loading 관계명 배열
     * @return Collection<int, ProductNoticeTemplate> 템플릿 컬렉션
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
     * 필터/페이지네이션을 적용해 상품정보 템플릿을 조회합니다.
     *
     * @param  array{is_active?: bool, search?: string}  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @param  array<int, string>  $with  eager loading 관계명 배열
     * @return LengthAwarePaginator 페이지네이션된 템플릿 컬렉션
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
     * ID 로 상품정보 템플릿을 조회합니다.
     *
     * @param  int  $id  템플릿 ID
     * @param  array<int, string>  $with  eager loading 관계명 배열
     * @return ProductNoticeTemplate|null 템플릿 또는 부재 시 null
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
     * 새 상품정보 템플릿을 생성합니다.
     *
     * @param  array<string, mixed>  $data  fillable 필드 데이터 (name/category/fields/is_active/sort_order)
     * @return ProductNoticeTemplate 생성된 템플릿
     */
    public function create(array $data): ProductNoticeTemplate
    {
        return $this->model->create($data);
    }

    /**
     * ID 로 상품정보 템플릿을 갱신합니다.
     *
     * @param  int  $id  템플릿 ID
     * @param  array<string, mixed>  $data  갱신할 fillable 필드 데이터
     * @return ProductNoticeTemplate 갱신 직후 fresh 인스턴스
     */
    public function update(int $id, array $data): ProductNoticeTemplate
    {
        $template = $this->findById($id);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * ID 로 상품정보 템플릿을 삭제합니다 (SoftDeletes 적용 모델인 경우 soft delete).
     *
     * @param  int  $id  템플릿 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        $template = $this->findById($id);

        return $template->delete();
    }

    /**
     * 기존 템플릿을 복제합니다 (이름에 locale 별 `(Copy)` 접미사 추가, sort_order 는 max+1).
     *
     * 다국어 name 의 경우 활성 언어팩의 `sirsoft-ecommerce::messages.copy_suffix` 번역을 사용하며
     * 미정의 시 영어 ` (Copy)` 로 폴백합니다.
     *
     * @param  int  $id  복제 대상 템플릿 ID
     * @return ProductNoticeTemplate 복제된 새 템플릿
     */
    public function copy(int $id): ProductNoticeTemplate
    {
        $original = $this->findById($id);

        // 이름에 locale 별 복사 접미사 추가 — 언어팩이 제공하는 번역 사용
        $name = $original->name;
        if (is_array($name)) {
            $previousLocale = app()->getLocale();
            try {
                foreach ($name as $locale => $value) {
                    app()->setLocale($locale);
                    // sirsoft-ecommerce::messages.copy_suffix 가 정의된 locale 만 번역 적용,
                    // 미정의 시 영어 폴백 (모듈 자체 ko/en 또는 활성 언어팩 ja 등에서 제공)
                    $copySuffix = trans('sirsoft-ecommerce::messages.copy_suffix');
                    if ($copySuffix === 'sirsoft-ecommerce::messages.copy_suffix') {
                        $copySuffix = ' (Copy)';
                    }
                    $name[$locale] = $value.$copySuffix;
                }
            } finally {
                app()->setLocale($previousLocale);
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
     * 현재 등록된 템플릿 중 가장 큰 sort_order 값을 반환합니다 (신규 등록 시 +1 위치 산정용).
     *
     * @return int 최대 sort_order. 레코드가 없으면 0
     */
    public function getMaxSortOrder(): int
    {
        return (int) $this->model->max('sort_order');
    }
}
