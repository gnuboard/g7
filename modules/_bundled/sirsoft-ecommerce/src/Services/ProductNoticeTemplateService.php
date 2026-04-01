<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductNoticeTemplateRepositoryInterface;

/**
 * 상품정보제공고시 템플릿 서비스
 */
class ProductNoticeTemplateService
{
    public function __construct(
        protected ProductNoticeTemplateRepositoryInterface $repository
    ) {}

    /**
     * 템플릿 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getAllTemplates(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_list_query', $filters);

        $templates = $this->repository->getAll($filters);

        // 필터 훅 - 결과 데이터 변형
        $templates = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_list_result', $templates, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_list', $templates, $filters);

        return $templates;
    }

    /**
     * 템플릿 목록 페이지네이션 조회
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 항목 수
     * @return LengthAwarePaginator
     */
    public function getPaginatedTemplates(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_list_query', $filters);

        $templates = $this->repository->getPaginated($filters, $perPage);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_list_paginated', $templates, $filters);

        return $templates;
    }

    /**
     * 템플릿 상세 조회
     *
     * @param int $id 템플릿 ID
     * @return ProductNoticeTemplate|null
     */
    public function getTemplate(int $id): ?ProductNoticeTemplate
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_show', $id);

        $template = $this->repository->findById($id);

        if ($template) {
            // 필터 훅 - 조회 결과 변형
            $template = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_show_result', $template);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_show', $template);
        }

        return $template;
    }

    /**
     * 템플릿 생성
     *
     * @param array $data 템플릿 데이터
     * @return ProductNoticeTemplate
     */
    public function createTemplate(array $data): ProductNoticeTemplate
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_create_data', $data);

        // sort_order가 없으면 자동 설정
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = $this->repository->getMaxSortOrder() + 1;
        }

        $template = DB::transaction(function () use ($data) {
            $template = $this->repository->create($data);

            return $template->fresh();
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_create', $template, $data);

        return $template;
    }

    /**
     * 템플릿 수정
     *
     * @param int $id 템플릿 ID
     * @param array $data 수정할 데이터
     * @return ProductNoticeTemplate
     *
     * @throws \Exception
     */
    public function updateTemplate(int $id, array $data): ProductNoticeTemplate
    {
        $template = $this->repository->findById($id);

        if (! $template) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.product_notice_template_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $template->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product-notice-template.filter_update_data', $data, $id);

        $template = DB::transaction(function () use ($template, $data) {
            $template = $this->repository->update($template->id, $data);

            return $template->fresh();
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_update', $template, $data, $snapshot);

        return $template;
    }

    /**
     * 템플릿 삭제
     *
     * 템플릿은 UI용 도구일 뿐이므로 상품과 연관이 없어 자유롭게 삭제 가능
     *
     * @param int $id 템플릿 ID
     * @return array 삭제 결과 정보
     *
     * @throws \Exception
     */
    public function deleteTemplate(int $id): array
    {
        $template = $this->repository->findById($id);

        if (! $template) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.product_notice_template_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_delete', $template);

        DB::transaction(function () use ($template) {
            $this->repository->delete($template->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_delete', $template->id);

        return [
            'template_id' => $template->id,
        ];
    }

    /**
     * 템플릿 복사
     *
     * @param int $id 원본 템플릿 ID
     * @return ProductNoticeTemplate
     *
     * @throws \Exception
     */
    public function copyTemplate(int $id): ProductNoticeTemplate
    {
        $original = $this->repository->findById($id);

        if (! $original) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.product_notice_template_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.before_copy', $original);

        $copied = DB::transaction(function () use ($id) {
            return $this->repository->copy($id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-notice-template.after_copy', $copied, $original);

        return $copied;
    }
}
