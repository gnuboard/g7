<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;

/**
 * 카테고리 서비스
 */
class CategoryService
{
    public function __construct(
        protected CategoryRepositoryInterface $repository,
        protected CategoryImageRepositoryInterface $imageRepository
    ) {}

    /**
     * 계층형 카테고리 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getHierarchicalCategories(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.category.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.category.filter_list_query', $filters);

        // hierarchical 플래그가 true이면 전체 트리 구조 반환
        if (!empty($filters['hierarchical'])) {
            $categories = Category::getTree(null, $filters['is_active'] ?? false);
        } elseif (!empty($filters['flat'])) {
            // flat 플래그가 true이면 평면 리스트 반환 (TagInput 등에 사용)
            $categories = $this->repository->getFlatList($filters, ['images']);
        } else {
            $categories = $this->repository->getHierarchical($filters, ['images']);
        }

        // 필터 훅 - 결과 데이터 변형
        $categories = HookManager::applyFilters('sirsoft-ecommerce.category.filter_list_result', $categories, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.category.after_list', $categories, $filters);

        return $categories;
    }

    /**
     * 공개 카테고리 트리 조회 (활성 카테고리만)
     *
     * 프론트엔드 사용자 페이지에서 카테고리 필터에 사용합니다.
     *
     * @return Collection
     */
    public function getPublicCategoryTree(): Collection
    {
        HookManager::doAction('sirsoft-ecommerce.category.before_public_list');

        $categories = Category::getTree(null, true);

        $categories = HookManager::applyFilters('sirsoft-ecommerce.category.filter_public_list_result', $categories);

        HookManager::doAction('sirsoft-ecommerce.category.after_public_list', $categories);

        return $categories;
    }

    /**
     * slug로 공개 카테고리 조회 (활성 카테고리만)
     *
     * @param string $slug 카테고리 slug
     * @return Category|null
     */
    public function getPublicCategoryBySlug(string $slug): ?Category
    {
        HookManager::doAction('sirsoft-ecommerce.category.before_public_show', $slug);

        $category = $this->repository->findBySlug($slug, [
            'activeChildren' => function ($query) {
                $query->withCount('products')->orderBy('sort_order');
            },
            'images',
        ]);

        if ($category && ! $category->is_active) {
            return null;
        }

        if ($category) {
            $category = HookManager::applyFilters('sirsoft-ecommerce.category.filter_public_show_result', $category);
            HookManager::doAction('sirsoft-ecommerce.category.after_public_show', $category);
        }

        return $category;
    }

    /**
     * 카테고리 상세 조회
     *
     * @param int $id 카테고리 ID
     * @return Category|null
     */
    public function getCategory(int $id): ?Category
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_show', $id);

        $category = $this->repository->findById($id, [
            'images',
            'children',
            'parent:id,name,slug', // parent에서 필요한 필드만 선택
        ]);

        if ($category) {
            // 필터 훅 - 조회 결과 변형
            $category = HookManager::applyFilters('sirsoft-ecommerce.category.filter_show_result', $category);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.category.after_show', $category);
        }

        return $category;
    }

    /**
     * 카테고리 생성
     *
     * @param array $data 카테고리 데이터
     * @return Category
     */
    public function createCategory(array $data): Category
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.category.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.category.filter_create_data', $data);

        $category = DB::transaction(function () use ($data) {
            // depth와 path 계산
            $depthAndPath = $this->calculateDepthAndPath($data['parent_id'] ?? null);
            $data['depth'] = $depthAndPath['depth'];
            $data['path'] = $depthAndPath['path'];

            // sort_order가 없으면 자동 계산
            if (!isset($data['sort_order'])) {
                $data['sort_order'] = $this->repository->getNextSortOrder($data['parent_id'] ?? null);
            }

            // 카테고리 생성
            $categoryData = collect($data)->except(['temp_key'])->toArray();
            $category = $this->repository->create($categoryData);

            // path 업데이트 (ID 포함)
            $this->updatePath($category);

            // 이미지 처리 (temp_key 방식)
            if (!empty($data['temp_key'])) {
                $this->imageRepository->linkTempImages($data['temp_key'], $category->id);
            }

            return $category->fresh(['images']);
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.category.after_create', $category, $data);

        return $category;
    }

    /**
     * 카테고리 수정
     *
     * @param int $id 카테고리 ID
     * @param array $data 수정할 데이터
     * @return Category
     */
    public function updateCategory(int $id, array $data): Category
    {
        $category = $this->repository->findById($id);

        if (!$category) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $category->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.category.filter_update_data', $data, $id);

        $category = DB::transaction(function () use ($category, $data) {
            // parent_id가 변경되었으면 depth/path 재계산
            if (isset($data['parent_id']) && $data['parent_id'] !== $category->parent_id) {
                $depthAndPath = $this->calculateDepthAndPath($data['parent_id']);
                $data['depth'] = $depthAndPath['depth'];
                $data['path'] = $depthAndPath['path'];
            }

            // 카테고리 수정
            $categoryData = collect($data)->except(['temp_key'])->toArray();
            $category = $this->repository->update($category->id, $categoryData);

            // path 업데이트 (parent_id 변경 시)
            if (isset($data['parent_id'])) {
                $this->updatePath($category);
                // 하위 카테고리들의 path도 재계산
                $this->updateDescendantsPaths($category);
            }

            // 이미지 처리
            if (!empty($data['temp_key'])) {
                $this->imageRepository->linkTempImages($data['temp_key'], $category->id);
            }

            return $category->fresh(['images']);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category.after_update', $category, $data, $snapshot);

        return $category;
    }

    /**
     * 카테고리 삭제
     *
     * @param int $id 카테고리 ID
     * @return array 삭제 결과 정보
     * @throws \Exception
     */
    public function deleteCategory(int $id): array
    {
        $category = $this->repository->findById($id);

        if (!$category) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]));
        }

        // 하위 카테고리 확인
        if ($this->repository->hasChildren($id)) {
            $childrenCount = $category->children()->count();
            throw new \Exception(__('sirsoft-ecommerce::exceptions.category_has_children', [
                'category_id' => $id,
                'count' => $childrenCount,
            ]));
        }

        // 연결된 상품 수 확인 - 상품이 있으면 삭제 차단
        $productsCount = $this->repository->getProductCount($id);
        if ($productsCount > 0) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.category_has_products', [
                'count' => $productsCount,
            ]));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_delete', $category);

        DB::transaction(function () use ($category) {
            // 카테고리 이미지 삭제 (명시적 삭제 - CASCADE 의존 금지)
            $category->images()->delete();

            // 카테고리 삭제
            $this->repository->delete($category->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category.after_delete', $category->id);

        return [
            'category_id' => $category->id,
        ];
    }

    /**
     * depth와 Materialized Path를 계산합니다.
     *
     * @param int|null $parentId 부모 카테고리 ID
     * @return array ['depth' => int, 'path' => string]
     */
    private function calculateDepthAndPath(?int $parentId): array
    {
        if ($parentId === null) {
            return ['depth' => 0, 'path' => ''];
        }

        $parent = $this->repository->findById($parentId);

        if (!$parent) {
            return ['depth' => 0, 'path' => ''];
        }

        return [
            'depth' => $parent->depth + 1,
            'path' => $parent->path ? $parent->path.'/'.$parent->id : (string) $parent->id,
        ];
    }

    /**
     * 카테고리의 path를 업데이트합니다 (ID 포함).
     *
     * @param Category $category
     * @return void
     */
    private function updatePath(Category $category): void
    {
        if ($category->parent_id) {
            $parent = $this->repository->findById($category->parent_id);
            if ($parent) {
                $newPath = $parent->path ? $parent->path.'/'.$category->id : (string) $category->id;
                $category->update(['path' => $newPath]);
            }
        } else {
            $category->update(['path' => (string) $category->id]);
        }
    }

    /**
     * 하위 카테고리들의 path를 재계산합니다 (재귀).
     *
     * @param Category $category
     * @return void
     */
    private function updateDescendantsPaths(Category $category): void
    {
        $children = $category->children;

        foreach ($children as $child) {
            $this->updatePath($child);
            $this->updateDescendantsPaths($child); // 재귀 호출
        }
    }

    /**
     * 카테고리 상태 토글
     *
     * @param int $id 카테고리 ID
     * @return Category
     * @throws \Exception
     */
    public function toggleStatus(int $id): Category
    {
        $category = $this->repository->findById($id);

        if (!$category) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_toggle_status', $category);

        $category = $this->repository->update($id, [
            'is_active' => !$category->is_active,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category.after_toggle_status', $category);

        return $category->fresh(['images']);
    }

    /**
     * 카테고리 순서 변경
     *
     * @param array $orders 순서 변경 데이터 [['id' => 1, 'parent_id' => null, 'sort_order' => 0], ...]
     * @return void
     */
    public function reorder(array $orders): void
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_reorder', $orders);

        DB::transaction(function () use ($orders) {
            foreach ($orders as $order) {
                $category = $this->repository->findById($order['id']);
                if (!$category) {
                    continue;
                }

                $updateData = [
                    'sort_order' => $order['sort_order'] ?? 0,
                ];

                // parent_id가 변경되었으면 depth/path도 재계산
                // isset()은 null에 대해 false를 반환하므로 array_key_exists() 사용
                if (array_key_exists('parent_id', $order) && $order['parent_id'] !== $category->parent_id) {
                    $updateData['parent_id'] = $order['parent_id'];
                    $depthAndPath = $this->calculateDepthAndPath($order['parent_id']);
                    $updateData['depth'] = $depthAndPath['depth'];
                    $updateData['path'] = $depthAndPath['path'];
                }

                $updatedCategory = $this->repository->update($order['id'], $updateData);

                // path 업데이트 (parent_id 변경 시)
                if (array_key_exists('parent_id', $order) && $order['parent_id'] !== $category->parent_id) {
                    $this->updatePath($updatedCategory);
                    $this->updateDescendantsPaths($updatedCategory);
                }
            }
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category.after_reorder', $orders);
    }
}
