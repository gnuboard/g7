<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductCommonInfoOperationException;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductCommonInfoRepositoryInterface;

/**
 * 공통정보 서비스
 */
class ProductCommonInfoService
{
    /**
     * @param  ProductCommonInfoRepositoryInterface  $repository  공통 정보 리포지토리
     */
    public function __construct(
        protected ProductCommonInfoRepositoryInterface $repository
    ) {}

    /**
     * 공통정보 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @return Collection
     */
    public function getAllCommonInfos(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_list_query', $filters);

        $commonInfos = $this->repository->getAll($filters);

        // 필터 훅 - 결과 데이터 변형
        $commonInfos = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_list_result', $commonInfos, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.product-common-info.after_list', $commonInfos, $filters);

        return $commonInfos;
    }

    /**
     * 공통정보 목록 페이지네이션 조회
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getPaginatedCommonInfos(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_list_query', $filters);

        $commonInfos = $this->repository->getPaginated($filters, $perPage);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.product-common-info.after_list_paginated', $commonInfos, $filters);

        return $commonInfos;
    }

    /**
     * 공통정보 상세 조회
     *
     * @param  int  $id  공통정보 ID
     * @return ProductCommonInfo|null
     */
    public function getCommonInfo(int $id): ?ProductCommonInfo
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_show', $id);

        $commonInfo = $this->repository->findById($id);

        if ($commonInfo) {
            // 필터 훅 - 조회 결과 변형
            $commonInfo = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_show_result', $commonInfo);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.product-common-info.after_show', $commonInfo);
        }

        return $commonInfo;
    }

    /**
     * 공통정보 생성
     *
     * @param  array  $data  공통정보 데이터
     * @return ProductCommonInfo
     */
    public function createCommonInfo(array $data): ProductCommonInfo
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_create_data', $data);

        // sort_order가 없으면 자동 설정
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->repository->getMaxSortOrder() + 1;
        }

        // 본문 미디어 서명 주소는 정식 주소로 저장한다 (#883)
        if (array_key_exists('content', $data)) {
            $data['content'] = app(ContentMediaService::class)->normalizeContent($data['content']);
        }

        $commonInfo = DB::transaction(function () use ($data) {
            // is_default가 true이면 기존 기본값 해제
            if (! empty($data['is_default'])) {
                $this->repository->clearDefault();
            }

            $commonInfo = $this->repository->create($data);

            // 본문이 참조한 본문 미디어만 잇는다 (#883)
            $this->syncContentMedia($commonInfo, $data);

            return $commonInfo->fresh();
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.product-common-info.after_create', $commonInfo, $data);

        return $commonInfo;
    }

    /**
     * 공통정보 수정
     *
     * @param  int  $id  공통정보 ID
     * @param  array  $data  수정할 데이터
     * @return ProductCommonInfo
     *
     * @throws \Exception
     */
    public function updateCommonInfo(int $id, array $data): ProductCommonInfo
    {
        $commonInfo = $this->repository->findById($id);

        if (! $commonInfo) {
            throw new ProductCommonInfoOperationException('sirsoft-ecommerce::exceptions.product_common_info_not_found');
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $commonInfo->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product-common-info.filter_update_data', $data, $id);

        // 본문 미디어 서명 주소는 정식 주소로 저장한다 (#883)
        if (array_key_exists('content', $data)) {
            $data['content'] = app(ContentMediaService::class)->normalizeContent($data['content']);
        }

        $commonInfo = DB::transaction(function () use ($commonInfo, $data) {
            // is_default가 true로 변경되면 기존 기본값 해제
            if (! empty($data['is_default']) && ! $commonInfo->is_default) {
                $this->repository->clearDefault($commonInfo->id);
            }

            $commonInfo = $this->repository->update($commonInfo->id, $data);

            // 본문 미디어 동기화 — 본문을 보낸 수정만 (#883)
            if (array_key_exists('content', $data)) {
                $this->syncContentMedia($commonInfo, $data);
            }

            return $commonInfo->fresh();
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-common-info.after_update', $commonInfo, $data, $snapshot);

        return $commonInfo;
    }

    /**
     * 공통 정보 본문이 참조한 본문 미디어를 맞춥니다 — 저장 트랜잭션 안 (#883).
     *
     * @param  ProductCommonInfo  $commonInfo  저장된 공통 정보
     * @param  array  $data  요청 데이터 (`content_media_temp_key`)
     * @return void 반환값 없음
     */
    private function syncContentMedia(ProductCommonInfo $commonInfo, array $data): void
    {
        app(ContentMediaService::class)->syncWithContent(
            ContentMediaOwnerType::CommonInfo,
            (int) $commonInfo->id,
            $commonInfo->content,
            ($commonInfo->content_mode ?? 'text') === 'html',
            isset($data['content_media_temp_key']) && is_string($data['content_media_temp_key']) ? $data['content_media_temp_key'] : null
        );
    }

    /**
     * 공통정보 삭제
     *
     * @param  int  $id  공통정보 ID
     * @return array 삭제 결과 정보
     *
     * @throws \Exception
     */
    public function deleteCommonInfo(int $id): array
    {
        $commonInfo = $this->repository->findById($id);

        if (! $commonInfo) {
            throw new ProductCommonInfoOperationException('sirsoft-ecommerce::exceptions.product_common_info_not_found');
        }

        // 연결된 상품 수 확인
        $productsCount = $this->repository->getProductCount($id);

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-common-info.before_delete', $commonInfo);

        DB::transaction(function () use ($commonInfo) {
            // 연결된 상품의 common_info_id를 null로 설정
            $commonInfo->products()->update(['common_info_id' => null]);

            // 공통정보 삭제
            $this->repository->delete($commonInfo->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-common-info.after_delete', $commonInfo->id);

        return [
            'common_info_id' => $commonInfo->id,
            'products_count' => $productsCount,
        ];
    }

    /**
     * 사용 여부 토글
     *
     * @param  int  $id  공통정보 ID
     * @return ProductCommonInfo
     *
     * @throws \Exception
     */
    public function toggleActive(int $id): ProductCommonInfo
    {
        $commonInfo = $this->repository->findById($id);

        if (! $commonInfo) {
            throw new ProductCommonInfoOperationException('sirsoft-ecommerce::exceptions.product_common_info_not_found');
        }

        return $this->updateCommonInfo($id, [
            'is_active' => ! $commonInfo->is_active,
        ]);
    }

    /**
     * 기본 공통정보 조회
     *
     * @return ProductCommonInfo|null
     */
    public function getDefaultCommonInfo(): ?ProductCommonInfo
    {
        return $this->repository->findDefault();
    }
}
