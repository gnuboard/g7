<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductCommonInfoOperationException;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductCommonInfoRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\PurifiesHtml;

/**
 * 공통정보 서비스
 *
 * HTML 모드 본문은 상품 설명과 같은 저장 정화(`PurifiesHtml` 의 `content` 프로필, 로케일별)를 거쳐 저장한다(#887) — 위험 요소는
 * 지우고, 코어 「본문 콘텐츠」 후보(그림 캡션·업로드 동영상·오디오·외부 영상)와 운영자 추가 태그는 스위치와 무관하게 원문대로 둔다.
 */
class ProductCommonInfoService
{
    use PurifiesHtml;

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
     * @return Collection 공통정보 목록
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
     * @return LengthAwarePaginator 페이지네이션된 공통정보 목록
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
     * @return ProductCommonInfo|null 공통정보 (없으면 null)
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
     * @return ProductCommonInfo 생성된 공통정보
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

        // 본문 미디어 서명 주소는 정식 주소로 되돌린 뒤 HTML 모드면 정화한다 (#883, #887)
        $data = $this->sanitizeContent($data, 'text');

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
     * @return ProductCommonInfo 수정된 공통정보
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

        // 본문 미디어 서명 주소는 정식 주소로 되돌린 뒤 HTML 모드면 정화한다 — 모드를 보내지 않은 수정은 저장된 모드를 따른다 (#883, #887)
        $data = $this->sanitizeContent($data, (string) ($commonInfo->content_mode ?? 'text'));

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
     * 공통 정보 본문을 저장 전에 정리합니다 — 본문 미디어 서명 주소를 정식 주소로 되돌리고, HTML 모드면 로케일마다 상품 설명과 같은
     * 저장 정화(`content` 프로필)를 한다 (#887).
     *
     * 정화는 서명 주소를 되돌린 뒤에 한다 — 정화기가 쿼리의 `&` 를 바꾸기 전에(상품 설명 `sanitizeDescription` 과 같은 순서).
     * 후보 스위치·외부 영상 서비스 켜고 끄기는 표시에서만 따르므로(결정 5) 꺼 둔 채 저장해도 그 요소가 남고, 문단 안 미디어·인라인 추가
     * 태그는 넣은 자리에 남는다(결정 6).
     *
     * @param  array  $data  요청 데이터
     * @param  string  $currentMode  저장된 본문 모드 (요청에 `content_mode` 가 없을 때 — 생성은 기본값 text)
     * @return array 정리한 데이터
     */
    private function sanitizeContent(array $data, string $currentMode): array
    {
        if (! array_key_exists('content', $data)) {
            return $data;
        }

        $data['content'] = app(ContentMediaService::class)->normalizeContent($data['content']);

        $mode = isset($data['content_mode']) && is_string($data['content_mode']) ? $data['content_mode'] : $currentMode;
        if ($mode !== 'html' || ! is_array($data['content'])) {
            return $data;
        }

        foreach ($data['content'] as $locale => $content) {
            if (is_string($content) && $content !== '') {
                $data['content'][$locale] = $this->purifyHtml($content, 'content');
            }
        }

        return $data;
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
        $result = app(ContentMediaService::class)->syncWithContent(
            ContentMediaOwnerType::CommonInfo,
            (int) $commonInfo->id,
            $commonInfo->content,
            ($commonInfo->content_mode ?? 'text') === 'html',
            isset($data['content_media_temp_key']) && is_string($data['content_media_temp_key']) ? $data['content_media_temp_key'] : null
        );

        // 상품·다른 공통 정보의 본문 미디어를 이 공통 정보 소유로 복제했으면 바뀐 본문을 다시 저장한다 (#887)
        if (($result['cloned'] ?? 0) > 0 && array_key_exists('content', $result) && $result['content'] !== $commonInfo->content) {
            $this->repository->update($commonInfo->id, ['content' => $result['content']]);
        }
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

            // 공통정보 본문 미디어 영구 삭제 — 본문에서 빠져 소프트 삭제된 것 포함, 파일은 커밋 뒤 (#887)
            app(ContentMediaPurger::class)->purgeForOwner(ContentMediaOwnerType::CommonInfo, $commonInfo->id);

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
     * @return ProductCommonInfo 토글된 공통정보
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
     * @return ProductCommonInfo|null 기본 공통정보 (없으면 null)
     */
    public function getDefaultCommonInfo(): ?ProductCommonInfo
    {
        return $this->repository->findDefault();
    }
}
