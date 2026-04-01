<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExtraFeeTemplateRepositoryInterface;

/**
 * 추가배송비 템플릿 서비스
 */
class ExtraFeeTemplateService
{
    public function __construct(
        protected ExtraFeeTemplateRepositoryInterface $repository
    ) {}

    /**
     * 템플릿 목록 조회
     *
     * @param array $filters 필터 조건
     * @return LengthAwarePaginator
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.extra_fee_template.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 지역별 통계 조회
     *
     * @return array 지역별 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatisticsByRegion();
    }

    /**
     * 템플릿 상세 조회
     *
     * @param int $id 템플릿 ID
     * @return ExtraFeeTemplate|null
     */
    public function getDetail(int $id): ?ExtraFeeTemplate
    {
        $template = $this->repository->find($id);

        if ($template) {
            HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_read', $template);
        }

        return $template;
    }

    /**
     * 우편번호로 템플릿 조회
     *
     * @param string $zipcode 우편번호
     * @return ExtraFeeTemplate|null
     */
    public function findByZipcode(string $zipcode): ?ExtraFeeTemplate
    {
        return $this->repository->findByZipcode($zipcode);
    }

    /**
     * 템플릿 생성
     *
     * @param array $data 템플릿 데이터
     * @return ExtraFeeTemplate
     */
    public function create(array $data): ExtraFeeTemplate
    {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_create', $data);

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.extra_fee_template.filter_create_data', $data);

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $template = $this->repository->create($data);

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_create', $template);

        return $template;
    }

    /**
     * 템플릿 수정
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @param array $data 수정 데이터
     * @return ExtraFeeTemplate
     */
    public function update(ExtraFeeTemplate $template, array $data): ExtraFeeTemplate
    {
        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_update', $template, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $template->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.extra_fee_template.filter_update_data', $data, $template);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        $template = $this->repository->update($template, $data);

        // 수정 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_update', $template, $snapshot);

        return $template;
    }

    /**
     * 템플릿 삭제
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @return bool
     */
    public function delete(ExtraFeeTemplate $template): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_delete', $template);

        $result = $this->repository->delete($template);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_delete', $template->id);

        return $result;
    }

    /**
     * 템플릿 사용여부 토글
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @return ExtraFeeTemplate
     */
    public function toggleActive(ExtraFeeTemplate $template): ExtraFeeTemplate
    {
        // 토글 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_toggle_active', $template);

        $template = $this->repository->toggleActive($template);

        // 토글 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_toggle_active', $template);

        return $template;
    }

    /**
     * 템플릿 일괄 삭제
     *
     * @param array $ids 템플릿 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int
    {
        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = ExtraFeeTemplate::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // 일괄 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_bulk_delete', $ids);

        $count = $this->repository->bulkDelete($ids);

        // 일괄 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 템플릿 일괄 사용여부 변경
     *
     * @param array $ids 템플릿 ID 배열
     * @param bool $isActive 사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        // 변경 전 스냅샷 캡처 (after_bulk_toggle_active 훅에 전달)
        $snapshots = ExtraFeeTemplate::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // 일괄 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_bulk_toggle_active', $ids, $isActive);

        $count = $this->repository->bulkToggleActive($ids, $isActive);

        // 일괄 변경 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_bulk_toggle_active', $ids, $isActive, $count, $snapshots);

        return $count;
    }

    /**
     * 활성화된 템플릿 목록 조회
     *
     * @return Collection
     */
    public function getActiveList(): Collection
    {
        return $this->repository->getActiveList();
    }

    /**
     * 활성화된 템플릿을 배송정책용 JSON 배열로 반환
     *
     * @return array
     */
    public function getAllAsExtraFeeSettings(): array
    {
        return $this->repository->getAllAsExtraFeeSettings();
    }

    /**
     * 일괄 등록 (CSV 또는 엑셀 업로드용)
     *
     * @param array $items 템플릿 데이터 배열 [{zipcode, fee, region?, description?}]
     * @return int 등록된 개수
     */
    public function bulkCreate(array $items): int
    {
        // 일괄 등록 전 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.before_bulk_create', $items);

        // 데이터 가공 훅
        $items = HookManager::applyFilters('sirsoft-ecommerce.extra_fee_template.filter_bulk_create_data', $items);

        $count = $this->repository->bulkCreate($items);

        // 생성/업데이트된 레코드 조회 (per-item 로깅용)
        $zipcodes = array_column($items, 'zipcode');
        $createdTemplates = ExtraFeeTemplate::whereIn('zipcode', $zipcodes)->get();

        // 일괄 등록 후 훅
        HookManager::doAction('sirsoft-ecommerce.extra_fee_template.after_bulk_create', $items, $count, $createdTemplates);

        return $count;
    }
}
