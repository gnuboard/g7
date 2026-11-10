<?php

namespace Modules\Sirsoft\Page\Services;

use App\Exceptions\ContentMediaLimitExceededException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Search\SearchPagePolicy;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;
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
    /**
     * 검색 정렬 이름 → [실제 컬럼, 방향] 선언
     *
     * 코어({@see SearchPagePolicy})가 이 선언을 읽어 커서 적용 여부를 판정한다.
     * 여기에 없는 정렬 이름(관련도순 등)은 커서로 처리하지 않고 offset 을 유지한다.
     */
    public const SEARCH_SORT_MAP = [
        'latest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
    ];

    /**
     * 커서(키셋) 경계로 쓸 수 있는 실제 컬럼 선언
     */
    public const SEARCH_CURSOR_COLUMNS = ['created_at'];

    /**
     * @param  PageRepositoryInterface  $pageRepository  페이지 리포지토리
     * @param  PageVersionRepositoryInterface  $pageVersionRepository  페이지 버전 리포지토리
     * @param  PageAttachmentService  $pageAttachmentService  첨부 서비스
     * @param  PageContentMediaService  $contentMediaService  본문 미디어 서비스 (#883)
     */
    public function __construct(
        private PageRepositoryInterface $pageRepository,
        private PageVersionRepositoryInterface $pageVersionRepository,
        private PageAttachmentService $pageAttachmentService,
        private PageContentMediaService $contentMediaService,
    ) {}

    /**
     * 페이지 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지 목록
     */
    public function getPages(array $filters = [], int $perPage = 20): LengthAwarePaginator
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
     *
     * @throws ContentMediaLimitExceededException 본문 미디어가 문서당 최대 개수를 넘을 때 (저장 전체를 되돌림)
     */
    public function createPage(array $data): Page
    {
        HookManager::doAction('sirsoft-page.page.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-page.page.filter_create_data', $data);

        return DB::transaction(function () use ($data) {
            $userId = Auth::id();

            // 본문 미디어 서명 주소는 정식 주소로 저장한다 (#883)
            if (array_key_exists('content', $data)) {
                $data['content'] = $this->contentMediaService->normalizeContent($data['content']);
            }

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

            // temp_key 첨부파일 연결 및 파일 이동 (업로드 순서대로 order 부여)
            if (! empty($data['temp_key'])) {
                $this->pageAttachmentService->linkTempAttachmentsWithMove(
                    $data['temp_key'],
                    $page->id
                );
            }

            // 본문이 참조한 본문 미디어만 잇는다 — 문서당 최대 개수를 넘으면 422 로 저장 전체를 되돌린다 (#883)
            // 다른 페이지 소유 본문 미디어는 이 페이지 소유로 복제하고 본문 주소를 바꾼다 (#887)
            $page = $this->applyClonedContentMedia($page, $this->contentMediaService->syncWithContent($page, $data['temp_key'] ?? null));

            // 버전 1 스냅샷 저장 — 본문 미디어 복제로 바뀐 본문을 담도록 동기화 뒤에 (#887)
            $this->saveVersionSnapshot($page, $userId);

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
     *
     * @throws AccessDeniedHttpException 수정 권한 범위 밖의 페이지일 때
     * @throws ContentMediaLimitExceededException 본문 미디어가 문서당 최대 개수를 넘을 때 (저장 전체를 되돌림)
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

            // 본문 미디어 서명 주소는 정식 주소로 저장한다 (#883)
            if (array_key_exists('content', $data)) {
                $data['content'] = $this->contentMediaService->normalizeContent($data['content']);
            }

            $updateData = array_merge($data, [
                'updated_by' => $userId,
                'current_version' => $page->current_version + 1,
            ]);

            // 발행 상태이거나 발행 전환 시 published_at 갱신
            if (! empty($updateData['published'])) {
                $updateData['published_at'] = now();
            }

            $page = $this->pageRepository->update($page, $updateData);

            // temp_key 첨부파일 연결 및 파일 이동 (업로드 순서대로 order 부여)
            if (! empty($data['temp_key'])) {
                $this->pageAttachmentService->linkTempAttachmentsWithMove(
                    $data['temp_key'],
                    $page->id
                );
            }

            // 본문 미디어 동기화 — 본문에서 빠진 것은 지우고 새로 참조한 임시 본문 미디어는 잇는다 (#883)
            // 다른 페이지 소유 본문 미디어는 이 페이지 소유로 복제하고 본문 주소를 바꾼다 (#887)
            $page = $this->applyClonedContentMedia($page, $this->contentMediaService->syncWithContent($page, $data['temp_key'] ?? null));

            // 버전 스냅샷 저장 — 본문 미디어 복제로 바뀐 본문을 담도록 동기화 뒤에 (#887)
            $this->saveVersionSnapshot($page, $userId);

            HookManager::doAction('sirsoft-page.page.after_update', $page, $data, $snapshot);

            return $page;
        });
    }

    /**
     * 페이지를 삭제합니다.
     *
     * 첨부파일과 본문 미디어도 파일과 행째 함께 지웁니다 — 파일은 커밋된 뒤에 지워 롤백과 어긋나지 않게 합니다.
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
            // 첨부파일 삭제: 물리 파일 + DB 행 (DB CASCADE 의존 금지)
            $attachments = $this->pageAttachmentService->getByPageId($page->id);
            foreach ($attachments as $attachment) {
                $this->pageAttachmentService->deleteAttachment($attachment);
            }

            // 본문 미디어 삭제 — 첨부 목록 조회가 본문 미디어를 빼므로 따로 지운다. 빠뜨리면 파일이 영구히 남는다.
            // 버전이 참조해 남겨 둔 것까지 모두 지운다(버전도 함께 지워진다). 행마다 core.content_media.before_delete (#887)
            $this->contentMediaService->deleteContentMediaForPage($page->id);

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
        if (empty($ids)) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $published) {
            $userId = Auth::id();
            $count = 0;

            foreach ($ids as $id) {
                // 존재하지 않는 페이지가 섞이면 예외 → 트랜잭션 전체 롤백 (all-or-nothing)
                $page = $this->pageRepository->findOrFail((int) $id);

                // 일괄 라우트(`PATCH admin/pages/bulk-publish`)에는 `{page}` 파라미터가 없어
                // PermissionMiddleware 의 스코프 검사가 통째로 스킵된다. 단건 경로
                // (updatePage/deletePage 등)는 전부 이 검사를 하는데 일괄만 비어 있으면
                // 그 경로가 우회로다 — 같은 판정을 여기서 재적용한다.
                // 예외는 위 findOrFail 과 같은 all-or-nothing 롤백을 탄다.
                if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
                    throw new AccessDeniedHttpException(__('auth.scope_denied'));
                }

                HookManager::doAction('sirsoft-page.page.before_publish', $page, $published);

                $updateData = [
                    'published' => $published,
                    'updated_by' => $userId,
                ];

                // 발행 시 published_at 갱신
                if ($published) {
                    $updateData['published_at'] = now();
                }

                $page = $this->pageRepository->update($page, $updateData);

                // 페이지별 after_publish 발화 → 활동로그 per-item 기록
                HookManager::doAction('sirsoft-page.page.after_publish', $page, $published);

                $count++;
            }

            return $count;
        });
    }

    /**
     * 페이지 버전을 복원합니다.
     *
     * 지정된 버전의 스냅샷으로 현재 페이지 내용을 덮어쓰고
     * 새로운 버전 스냅샷을 생성합니다. 복원 본문은 저장 경로와 같이 본문 미디어 정규화·동기화를 거친 뒤
     * 스냅샷을 남깁니다 (#887).
     *
     * @param  Page  $page  페이지 모델
     * @param  int  $versionId  복원할 버전 ID
     * @return Page 복원된 페이지 모델
     *
     * @throws ModelNotFoundException 버전이 해당 페이지에 속하지 않을 때
     * @throws AccessDeniedHttpException 수정 권한 범위 밖의 페이지일 때
     * @throws ContentMediaLimitExceededException 복원 본문의 본문 미디어가 문서당 최대 개수를 넘을 때 (복원 전체를 되돌림)
     */
    public function restoreVersion(Page $page, int $versionId): Page
    {
        if (! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // 상위 페이지 스코프를 Repository where 절에 반영한다 (SSoT).
        // 사후 비교로 처리하면 예외 타입이 컨트롤러 catch 사슬과 어긋나 500 이 되고,
        // 같은 위반에 404 를 내는 조회(getVersion)와 계약이 갈린다.
        $version = $this->pageVersionRepository->findForPage($page->id, $versionId);

        return DB::transaction(function () use ($page, $version) {
            $userId = Auth::id();

            $updateData = [
                'title' => $version->title,
                // 본문 미디어 서명 주소가 섞여 있어도 정식 주소로 저장한다 (#887)
                'content' => $this->contentMediaService->normalizeContent($version->content),
                'content_mode' => $version->content_mode,
                'seo_meta' => $version->seo_meta,
                'updated_by' => $userId,
                'current_version' => $page->current_version + 1,
            ];

            $page = $this->pageRepository->update($page, $updateData);

            // 복원 본문과 본문 미디어를 맞춘다 — 그 버전이 참조하던 미디어는 버전 참조 덕에 남아 있으므로 그대로 이어지고,
            // 다른 페이지 소유 미디어가 섞여 있으면 볼 수 있을 때 이 페이지 소유로 복제한다. 복원은 임시 업로드가 없다 (#887)
            $page = $this->applyClonedContentMedia($page, $this->contentMediaService->syncWithContent($page, null));

            // 복원 버전 스냅샷 저장 (복원 원본 버전 번호 전달) — 본문 미디어 복제로 바뀐 본문을 담도록 동기화 뒤에 (#887)
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
     * @throws ModelNotFoundException
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
     * $allowUnpublished 가 true 이면 미발행 페이지도 반환합니다. 관리자 미리보기
     * (사용자 화면에서 발행 전 페이지 확인)를 위한 것으로, 권한 판정은 호출부(컨트롤러)가
     * 담당하고 이 메서드는 허용 여부만 플래그로 전달받습니다.
     *
     * @param  string  $slug  페이지 슬러그
     * @param  bool  $allowUnpublished  미발행 페이지 반환 허용 여부 (관리자 미리보기)
     * @return Page|null 페이지 모델 또는 null
     */
    public function getPublishedPageBySlug(string $slug, bool $allowUnpublished = false): ?Page
    {
        $page = $this->pageRepository->findBySlug($slug);

        if (! $page) {
            return null;
        }

        return ($page->published || $allowUnpublished) ? $page : null;
    }

    /**
     * 페이지 버전 이력을 조회합니다.
     *
     * @param  Page  $page  페이지 모델
     * @return Collection 버전 목록 (최신순)
     */
    public function getVersions(Page $page): Collection
    {
        return $this->pageVersionRepository->getVersionsByPage($page);
    }

    /**
     * 페이지에 속한 버전을 조회합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @param  int  $versionId  버전 ID
     * @return PageVersion 버전 모델
     *
     * @throws ModelNotFoundException
     */
    public function getVersion(int $pageId, int $versionId): PageVersion
    {
        return $this->pageVersionRepository->findForPage($pageId, $versionId);
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
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $page  페이지 번호
     * @return BoundedPage 페이지 결과 (총 건수 정확도 포함)
     */
    public function searchByKeyword(
        string $keyword,
        string $orderBy = 'created_at',
        string $direction = 'desc',
        int $perPage = 10,
        int $page = 1
    ): BoundedPage {
        return $this->pageRepository->searchByKeyword($keyword, $orderBy, $direction, $perPage, $page);
    }

    /**
     * 키워드로 페이지를 커서(키셋)로 검색합니다.
     *
     * 커서 적용 가능 여부는 코어({@see SearchPagePolicy})가 판정한다. 이 서비스는
     * 정렬 선언({@see self::SEARCH_SORT_MAP})만 제공하고 규칙을 다시 쓰지 않는다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  string  $sort  정렬 옵션
     * @param  int  $perPage  페이지당 항목 수
     * @param  string|null  $cursor  인코딩된 커서 (첫 페이지면 null)
     * @param  int  $page  요청 페이지 번호 (커서 없이 깊은 페이지를 지목했는지 판정용)
     * @return CursorPaginator|null 커서 페이지 결과 (커서 적용 불가 시 null)
     */
    public function searchByKeywordWithCursor(
        string $keyword,
        string $sort = 'latest',
        int $perPage = 10,
        ?string $cursor = null,
        int $page = 1
    ): ?CursorPaginator {
        $sortKeys = SearchPagePolicy::sortKeys($sort, self::SEARCH_SORT_MAP);

        if (! SearchPagePolicy::usesCursor($cursor, $sortKeys, self::SEARCH_CURSOR_COLUMNS, $page)) {
            return null;
        }

        return $this->pageRepository->searchByKeywordWithCursor($keyword, $sortKeys, $perPage, $cursor);
    }

    /**
     * 키워드와 일치하는 발행된 페이지 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return BoundedCount 일치하는 페이지 수 (정확도 포함)
     */
    public function countByKeyword(string $keyword): BoundedCount
    {
        return $this->pageRepository->countByKeyword($keyword);
    }

    /**
     * 본문 미디어 동기화가 복제한 해시로 바뀐 본문을 페이지에 다시 저장합니다 — 저장 트랜잭션 안 (#887).
     *
     * @param  Page  $page  저장된 페이지
     * @param  array{cloned?: int, content?: mixed}  $result  `PageContentMediaService::syncWithContent` 결과
     * @return Page 본문을 다시 저장한 페이지 (복제가 없으면 그대로)
     */
    private function applyClonedContentMedia(Page $page, array $result): Page
    {
        if (($result['cloned'] ?? 0) < 1 || ! array_key_exists('content', $result) || $result['content'] === $page->content) {
            return $page;
        }

        return $this->pageRepository->update($page, ['content' => $result['content']]);
    }

    /**
     * 현재 페이지 상태를 버전 스냅샷으로 저장합니다.
     *
     * @param  Page  $page  페이지 모델
     * @param  int  $userId  작성자 ID
     * @param  int|null  $restoredFrom  복원 원본 버전 번호 (복원 시에만)
     * @return void 반환값 없음
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
