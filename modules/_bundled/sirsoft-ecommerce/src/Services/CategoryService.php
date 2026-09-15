<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\CategoryOperationException;
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
        protected CategoryImageRepositoryInterface $imageRepository,
        protected CategoryImageService $categoryImageService
    ) {}

    /**
     * 계층형 카테고리 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @return Collection 계층 구조가 적용된 카테고리 컬렉션
     */
    public function getHierarchicalCategories(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.category.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.category.filter_list_query', $filters);

        // hierarchical 플래그가 true이면 전체 트리 구조 반환
        if (! empty($filters['hierarchical'])) {
            $categories = Category::getTree(null, $filters['is_active'] ?? false);
        } elseif (! empty($filters['flat'])) {
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
     * @return Collection 활성 카테고리로만 구성된 공개 트리
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
     * @param  string  $slug  카테고리 slug
     * @return Category|null 활성 카테고리 또는 null (비활성·미존재)
     */
    public function getPublicCategoryBySlug(string $slug): ?Category
    {
        HookManager::doAction('sirsoft-ecommerce.category.before_public_show', $slug);

        $category = $this->repository->findBySlug($slug, [
            'activeChildren' => function ($query) {
                $query->withCount('products')->orderBy('sort_order');
            },
            'images',
        ], withCounts: true);

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
     * @param  int  $id  카테고리 ID
     * @return Category|null 카테고리 모델 또는 null (미존재)
     */
    public function getCategory(int $id): ?Category
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_show', $id);

        $category = $this->repository->findById($id, [
            'images',
            'children',
            'parent:id,name,slug', // parent에서 필요한 필드만 선택
        ], withCounts: true);

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
     * @param  array  $data  카테고리 데이터
     * @return Category 생성된 카테고리 모델
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
            if (! isset($data['sort_order'])) {
                $data['sort_order'] = $this->repository->getNextSortOrder($data['parent_id'] ?? null);
            }

            // 카테고리 생성
            $categoryData = collect($data)->except(['temp_key'])->toArray();
            $category = $this->repository->create($categoryData);

            // path 업데이트 (ID 포함)
            $this->updatePath($category);

            // 이미지 처리 (temp_key 방식)
            if (! empty($data['temp_key'])) {
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
     * @param  int  $id  카테고리 ID
     * @param  array  $data  수정할 데이터
     * @return Category 수정된 카테고리 모델
     */
    public function updateCategory(int $id, array $data): Category
    {
        // 쓰기 경로는 상품 수·자식 수 집계를 읽지 않는다 — 계산하면 조회마다 서브쿼리 2개가 붙는다.
        $category = $this->repository->findById($id, withCounts: false);

        if (! $category) {
            throw new CategoryOperationException('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $category->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.category.filter_update_data', $data, $id);

        $category = DB::transaction(function () use ($category, $data) {
            // parent_id가 변경되었으면 depth/path 재계산
            // 루트로 이동(parent_id = null)도 변경이므로 array_key_exists 사용 (isset 은 null 을 미설정으로 취급)
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $category->parent_id) {
                $depthAndPath = $this->calculateDepthAndPath($data['parent_id']);
                $data['depth'] = $depthAndPath['depth'];
                $data['path'] = $depthAndPath['path'];
            }

            // 카테고리 수정
            $categoryData = collect($data)->except(['temp_key'])->toArray();
            $category = $this->repository->update($category->id, $categoryData);

            // path 업데이트 (parent_id 변경 시 — 루트 이동 포함)
            if (array_key_exists('parent_id', $data)) {
                $this->updatePath($category);
                // 하위 카테고리들의 path도 재계산
                $this->updateDescendantsPaths($category);
            }

            // 이미지 처리
            if (! empty($data['temp_key'])) {
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
     * @param  int  $id  카테고리 ID
     * @return array 삭제 결과 정보
     *
     * @throws CategoryOperationException 카테고리 미존재 또는 삭제 불가 조건
     */
    public function deleteCategory(int $id): array
    {
        $category = $this->repository->findById($id, withCounts: false);

        if (! $category) {
            throw new CategoryOperationException('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]);
        }

        // 하위 카테고리 확인
        if ($this->repository->hasChildren($id)) {
            $childrenCount = $category->children()->count();
            throw new CategoryOperationException('sirsoft-ecommerce::exceptions.category_has_children', [
                'category_id' => $id,
                'count' => $childrenCount,
            ]);
        }

        // 연결된 상품 수 확인 - 상품이 있으면 삭제 차단
        $productsCount = $this->repository->getProductCount($id);
        if ($productsCount > 0) {
            throw new CategoryOperationException('sirsoft-ecommerce::exceptions.category_has_products', [
                'count' => $productsCount,
            ]);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_delete', $category);

        DB::transaction(function () use ($category) {
            // 카테고리 이미지 삭제 (명시적 삭제 - CASCADE 의존 금지)
            // 물리 파일 → 행 순으로 정리한다. 행만 지우면 파일이 디스크에 영구 잔존한다
            // (상품 삭제와 동일한 순서 — ProductService::delete 선례).
            $this->categoryImageService->deleteByCategoryId($category->id);

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
     * @param  int|null  $parentId  부모 카테고리 ID
     * @return array ['depth' => int, 'path' => string]
     */
    private function calculateDepthAndPath(?int $parentId): array
    {
        if ($parentId === null) {
            return ['depth' => 0, 'path' => ''];
        }

        $parent = $this->repository->findById($parentId, withCounts: false);

        if (! $parent) {
            return ['depth' => 0, 'path' => ''];
        }

        return [
            'depth' => $parent->depth + 1,
            'path' => $parent->path ? $parent->path.'/'.$parent->id : (string) $parent->id,
        ];
    }

    /**
     * 카테고리의 path를 업데이트합니다 (ID 포함).
     */
    private function updatePath(Category $category): void
    {
        if ($category->parent_id) {
            $parent = $this->repository->findById($category->parent_id, withCounts: false);
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
     * 검증(NotCircularCategoryParent)을 우회한 경로(시더/훅/기존 오염 데이터)에서
     * 순환 참조가 남아 있어도 무한 재귀 대신 유한 실패로 끝나도록 방문 ID 를 추적한다.
     *
     * @param  Category  $category  기준 카테고리
     * @param  array<int, bool>  $visited  방문한 카테고리 ID 집합 (재귀 내부 전달용)
     */
    private function updateDescendantsPaths(Category $category, array $visited = []): void
    {
        $visited[$category->id] = true;

        foreach ($category->children as $child) {
            if (isset($visited[$child->id])) {
                Log::warning('[sirsoft-ecommerce] 카테고리 순환 참조 감지 — path 재계산 중단', [
                    'category_id' => $category->id,
                    'child_id' => $child->id,
                ]);

                continue;
            }

            $this->updatePath($child);
            $this->updateDescendantsPaths($child, $visited);
        }
    }

    /**
     * 카테고리 상태 토글
     *
     * @param  int  $id  카테고리 ID
     * @return Category 상태가 토글된 카테고리 모델
     *
     * @throws CategoryOperationException 카테고리 미존재 또는 삭제 불가 조건
     */
    public function toggleStatus(int $id): Category
    {
        $category = $this->repository->findById($id, withCounts: false);

        if (! $category) {
            throw new CategoryOperationException('sirsoft-ecommerce::exceptions.category_not_found', ['category_id' => $id]);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_toggle_status', $category);

        $category = $this->repository->update($id, [
            'is_active' => ! $category->is_active,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category.after_toggle_status', $category);

        return $category->fresh(['images']);
    }

    /**
     * 카테고리 순서 변경
     *
     * @param  array  $orders  순서 변경 데이터 [['id' => 1, 'parent_id' => null, 'sort_order' => 0], ...]
     */
    public function reorder(array $orders): void
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category.before_reorder', $orders);

        // 항목마다 조회하면 재정렬 한 번에 항목 수만큼 쿼리가 난다. 한 번에 읽어 맵으로 들고 간다.
        // 집계(상품 수·자식 수)는 재정렬이 읽지 않으므로 계산하지 않는다.
        $categoriesById = $this->repository->findByIdsKeyed(
            array_map(fn ($order) => (int) $order['id'], $orders)
        );

        DB::transaction(function () use ($orders, $categoriesById) {
            foreach ($orders as $order) {
                $category = $categoriesById->get((int) $order['id']);
                if (! $category) {
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
