<?php

namespace Modules\Sirsoft\Page\Services;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageVersionRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 페이지 서비스
 *
 * 페이지 생성/수정/삭제/발행 비즈니스 로직을 담당합니다.
 */
class PageService
{
    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private PageVersionRepositoryInterface $pageVersionRepository,
        private PageAttachmentService $pageAttachmentService,
    ) {}

    /**
     * 페이지 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지 목록
     */
    public function getPages(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $filters = HookManager::applyFilters('sirsoft-page.page.filter_list_query', $filters);

        return $this->pageRepository->paginate($filters, $perPage);
    }

    /**
     * 페이지를 생성합니다.
     *
     * 생성 후 버전 1 스냅샷을 저장합니다.
     *
     * @param  array  $data  페이지 생성 데이터
     * @return Page 생성된 페이지 모델
     */
    public function createPage(array $data): Page
    {
        HookManager::doAction('sirsoft-page.page.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-page.page.filter_create_data', $data);

        return DB::transaction(function () use ($data) {
            $userId = Auth::id();

            $pageData = array_merge($data, [
                'created_by' => $userId,
                'updated_by' => $userId,
                'current_version' => 1,
            ]);

            // 발행 상태로 생성 시 published_at 설정
            if (! empty($pageData['published'])) {
                $pageData['published_at'] = now();
            }

            $page = $this->pageRepository->create($pageData);

            // 버전 1 스냅샷 저장
            $this->saveVersionSnapshot($page, $userId);

            // temp_key 첨부파일 연결 및 파일 이동
            if (! empty($data['temp_key'])) {
                $this->pageAttachmentService->linkTempAttachmentsWithMove($data['temp_key'], $page->id);
            }

            HookManager::doAction('sirsoft-page.page.after_create', $page, $data);

            return $page;
        });
    }

    /**
     * 페이지를 수정합니다.
     *
     * 수정 후 버전 스냅샷을 저장하고 current_version을 증가시킵니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  array  $data  수정할 데이터
     * @return Page 수정된 페이지 모델
     */
    public function updatePage(Page $page, array $data): Page
    {
        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        HookManager::doAction('sirsoft-page.page.before_update', $page, $data);

        $snapshot = $page->toArray();

        $data = HookManager::applyFilters('sirsoft-page.page.filter_update_data', $data, $page);

        return DB::transaction(function () use ($page, $data, $snapshot) {
            $userId = Auth::id();

            $updateData = array_merge($data, [
                'updated_by' => $userId,
                'current_version' => $page->current_version + 1,
            ]);

            // 발행 상태이거나 발행 전환 시 published_at 갱신
            if (! empty($updateData['published'])) {
                $updateData['published_at'] = now();
            }

            $page = $this->pageRepository->update($page, $updateData);

            // 버전 스냅샷 저장
            $this->saveVersionSnapshot($page, $userId);

            // temp_key 첨부파일 연결 및 파일 이동
            if (! empty($data['temp_key'])) {
                $this->pageAttachmentService->linkTempAttachmentsWithMove($data['temp_key'], $page->id);
            }

            HookManager::doAction('sirsoft-page.page.after_update', $page, $data, $snapshot);

            return $page;
        });
    }

    /**
     * 페이지를 삭제합니다 (소프트 삭제).
     *
     * 첨부파일도 함께 소프트 삭제합니다.
     *
     * @param  Page  $page  페이지 모델
     * @return bool 삭제 성공 여부
     */
    public function deletePage(Page $page): bool
    {
        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.delete')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        HookManager::doAction('sirsoft-page.page.before_delete', $page);

        return DB::transaction(function () use ($page) {
            // 첨부파일 삭제: 물리 파일 + DB 소프트 삭제 (DB CASCADE 의존 금지)
            $attachments = $this->pageAttachmentService->getByPageId($page->id);
            foreach ($attachments as $attachment) {
                $this->pageAttachmentService->deleteAttachment($attachment);
            }

            $result = $this->pageRepository->delete($page);

            HookManager::doAction('sirsoft-page.page.after_delete', $page);

            return $result;
        });
    }

    /**
     * 페이지 발행 상태를 변경합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  bool  $published  발행 여부
     * @return Page 수정된 페이지 모델
     */
    public function changePublishStatus(Page $page, bool $published): Page
    {
        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        HookManager::doAction('sirsoft-page.page.before_publish', $page, $published);

        $updateData = [
            'published' => $published,
            'updated_by' => Auth::id(),
        ];

        // 발행 시 published_at 갱신
        if ($published) {
            $updateData['published_at'] = now();
        }

        $page = $this->pageRepository->update($page, $updateData);

        HookManager::doAction('sirsoft-page.page.after_publish', $page, $published);

        return $page;
    }

    /**
     * 여러 페이지의 발행 상태를 일괄 변경합니다.
     *
     * @param  array  $ids  페이지 ID 목록
     * @param  bool  $published  발행 여부
     * @return int 변경된 페이지 수
     */
    public function bulkChangePublishStatus(array $ids, bool $published): int
    {
        $updateData = [
            'published' => $published,
            'updated_by' => Auth::id(),
        ];

        if ($published) {
            $updateData['published_at'] = now();
        }

        return $this->pageRepository->bulkUpdatePublished($ids, $updateData);
    }

    /**
     * 페이지 버전을 복원합니다.
     *
     * 지정된 버전의 스냅샷으로 현재 페이지 내용을 덮어쓰고
     * 새로운 버전 스냅샷을 생성합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  int  $versionId  복원할 버전 ID
     * @return Page 복원된 페이지 모델
     */
    public function restoreVersion(Page $page, int $versionId): Page
    {
        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        $version = $this->pageVersionRepository->findOrFail($versionId);

        if ($version->page_id !== $page->id) {
            throw new \InvalidArgumentException(
                __('sirsoft-page::messages.errors.version_belongs_to_different_page')
            );
        }

        return DB::transaction(function () use ($page, $version) {
            $userId = Auth::id();

            $updateData = [
                'title' => $version->title,
                'content' => $version->content,
                'content_mode' => $version->content_mode,
                'seo_meta' => $version->seo_meta,
                'updated_by' => $userId,
                'current_version' => $page->current_version + 1,
            ];

            $page = $this->pageRepository->update($page, $updateData);

            // 복원 버전 스냅샷 저장 (복원 원본 버전 번호 전달)
            $this->saveVersionSnapshot($page, $userId, $version->version);

            HookManager::doAction('sirsoft-page.page.after_restore', $page, $version);

            return $page;
        });
    }

    /**
     * ID로 페이지를 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @return Page 페이지 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getPage(int $id): Page
    {
        $page = $this->pageRepository->findOrFail($id);

        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.read')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        return $page;
    }

    /**
     * 슬러그로 발행된 페이지를 조회합니다.
     *
     * @param  string  $slug  페이지 슬러그
     * @return Page|null 발행된 페이지 모델 또는 null
     */
    public function getPublishedPageBySlug(string $slug): ?Page
    {
        $page = $this->pageRepository->findBySlug($slug);

        return ($page && $page->published) ? $page : null;
    }

    /**
     * 페이지 버전 이력을 조회합니다.
     *
     * @param  Page  $page  페이지 모델
     * @return \Illuminate\Database\Eloquent\Collection 버전 목록 (최신순)
     */
    public function getVersions(Page $page): \Illuminate\Database\Eloquent\Collection
    {
        return $this->pageVersionRepository->getVersionsByPage($page);
    }

    /**
     * 버전 ID로 페이지 버전을 조회합니다.
     *
     * @param  int  $versionId  버전 ID
     * @return \Modules\Sirsoft\Page\Models\PageVersion 버전 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getVersion(int $versionId): \Modules\Sirsoft\Page\Models\PageVersion
    {
        return $this->pageVersionRepository->findOrFail($versionId);
    }

    /**
     * 슬러그 중복 여부를 확인합니다.
     *
     * @param  string  $slug  확인할 슬러그
     * @param  int|null  $excludeId  제외할 페이지 ID (수정 시)
     * @return bool 중복 여부 (true: 중복)
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        return $this->pageRepository->slugExists($slug, $excludeId);
    }

    /**
     * 키워드로 발행된 페이지를 검색합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  string  $orderBy  정렬 컬럼
     * @param  string  $direction  정렬 방향 (asc, desc)
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchByKeyword(string $keyword, string $orderBy = 'created_at', string $direction = 'desc', int $limit = 10): array
    {
        return $this->pageRepository->searchByKeyword($keyword, $orderBy, $direction, $limit);
    }

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return int 일치하는 페이지 수
     */
    public function countByKeyword(string $keyword): int
    {
        return $this->pageRepository->countByKeyword($keyword);
    }

    /**
     * 현재 페이지 상태를 버전 스냅샷으로 저장합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  int  $userId  작성자 ID
     * @param  int|null  $restoredFrom  복원 원본 버전 번호 (복원 시에만)
     */
    private function saveVersionSnapshot(Page $page, int $userId, ?int $restoredFrom = null): void
    {
        $this->pageVersionRepository->create([
            'page_id' => $page->id,
            'version' => $page->current_version,
            'title' => $page->title,
            'content' => $page->content,
            'content_mode' => $page->content_mode,
            'seo_meta' => $page->seo_meta,
            'changes_summary' => $this->calculateChangesSummary($page, $restoredFrom),
            'created_by' => $userId,
        ]);
    }

    /**
     * 이전 버전과 비교하여 변경 요약을 계산합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  int|null  $restoredFrom  복원 원본 버전 번호
     * @return array|null 변경 요약 (최초 생성 시 null)
     */
    private function calculateChangesSummary(Page $page, ?int $restoredFrom): ?array
    {
        // 최초 생성 (version 1)은 변경 요약 없음
        if ($page->current_version <= 1) {
            return null;
        }

        $previousVersion = $this->pageVersionRepository->findByPageAndVersion(
            $page,
            $page->current_version - 1
        );

        // 이전 버전이 없으면 변경 요약 계산 불가
        if (! $previousVersion) {
            return null;
        }

        $changedFields = [];
        $compareFields = ['title', 'content', 'seo_meta'];

        foreach ($compareFields as $field) {
            if (json_encode($page->{$field}) !== json_encode($previousVersion->{$field})) {
                $changedFields[] = $field;
            }
        }

        // content_mode는 문자열 비교
        if ($page->content_mode !== $previousVersion->content_mode) {
            $changedFields[] = 'content_mode';
        }

        return [
            'changed_fields' => $changedFields,
            'restored_from' => $restoredFrom,
        ];
    }
}
