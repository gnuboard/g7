<?php

namespace Modules\Sirsoft\Page\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Page\Http\Requests\BulkChangePageStatusRequest;
use Modules\Sirsoft\Page\Http\Requests\ChangePageStatusRequest;
use Modules\Sirsoft\Page\Http\Requests\CheckSlugRequest;
use Modules\Sirsoft\Page\Http\Requests\PageListRequest;
use Modules\Sirsoft\Page\Http\Requests\StorePageRequest;
use Modules\Sirsoft\Page\Http\Requests\UpdatePageRequest;
use Modules\Sirsoft\Page\Http\Resources\PageCollection;
use Modules\Sirsoft\Page\Http\Resources\PageResource;
use Modules\Sirsoft\Page\Http\Resources\PageVersionResource;
use Modules\Sirsoft\Page\Services\PageService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 관리자용 페이지 관리 컨트롤러
 *
 * 페이지의 생성, 수정, 삭제, 조회, 발행 관리 등 관리자 전용 기능을 제공합니다.
 */
class PageController extends AdminBaseController
{
    /**
     * PageController 생성자
     *
     * @param  PageService  $pageService  페이지 서비스
     */
    public function __construct(
        private PageService $pageService,
    ) {
        parent::__construct();
    }

    /**
     * 페이지 목록을 조회합니다.
     *
     * @param  PageListRequest  $request  목록 조회 요청
     * @return JsonResponse 페이지 목록 응답
     */
    public function index(PageListRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $perPage = $validated['per_page'] ?? 15;
            $pages = $this->pageService->getPages(
                array_filter($validated, fn ($v) => $v !== null),
                $perPage
            );

            return $this->success(
                'sirsoft-page::messages.page.fetch_success',
                new PageCollection($pages)
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 페이지를 생성합니다.
     *
     * @param  StorePageRequest  $request  페이지 생성 요청
     * @return JsonResponse 생성된 페이지 응답
     */
    public function store(StorePageRequest $request): JsonResponse
    {
        try {
            $page = $this->pageService->createPage($request->validated());
            $page->load(['creator', 'updater', 'attachments']);

            return $this->successWithResource(
                'sirsoft-page::messages.page.create_success',
                new PageResource($page),
                201
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 페이지 상세 정보를 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @return JsonResponse 페이지 상세 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $page->load(['creator', 'updater', 'attachments']);

            return $this->successWithResource(
                'sirsoft-page::messages.page.fetch_success',
                new PageResource($page)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 페이지를 수정합니다.
     *
     * @param  UpdatePageRequest  $request  수정 요청
     * @param  int  $id  페이지 ID
     * @return JsonResponse 수정된 페이지 응답
     */
    public function update(UpdatePageRequest $request, int $id): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $page = $this->pageService->updatePage($page, $request->validated());
            $page->load(['creator', 'updater', 'attachments']);

            return $this->successWithResource(
                'sirsoft-page::messages.page.update_success',
                new PageResource($page)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 페이지를 삭제합니다 (소프트 삭제).
     *
     * @param  int  $id  페이지 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $this->pageService->deletePage($page);

            return $this->success('sirsoft-page::messages.page.delete_success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 슬러그 중복 여부를 확인합니다.
     *
     * @param  CheckSlugRequest  $request  슬러그 확인 요청
     * @return JsonResponse 중복 여부 응답
     */
    public function checkSlug(CheckSlugRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $exists = $this->pageService->slugExists(
            $validated['slug'],
            $validated['exclude_id'] ?? null
        );

        return $this->success('sirsoft-page::messages.page.slug_check_success', [
            'exists' => $exists,
        ]);
    }

    /**
     * 페이지 발행 상태를 변경합니다.
     *
     * @param  ChangePageStatusRequest  $request  상태 변경 요청
     * @param  int  $id  페이지 ID
     * @return JsonResponse 변경된 페이지 응답
     */
    public function publish(ChangePageStatusRequest $request, int $id): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $page = $this->pageService->changePublishStatus($page, $request->validated()['published']);

            return $this->successWithResource(
                'sirsoft-page::messages.page.publish_success',
                new PageResource($page)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.publish_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 페이지의 발행 상태를 일괄 변경합니다.
     *
     * @param  BulkChangePageStatusRequest  $request  일괄 상태 변경 요청
     * @return JsonResponse 변경 결과 응답
     */
    public function bulkPublish(BulkChangePageStatusRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $count = $this->pageService->bulkChangePublishStatus($validated['ids'], $validated['published']);

            return $this->success('sirsoft-page::messages.page.bulk_publish_success', [
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.bulk_publish_failed', 500, $e->getMessage());
        }
    }

    /**
     * 페이지 버전 이력을 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @return JsonResponse 버전 이력 응답
     */
    public function versions(int $id): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $versions = $this->pageService->getVersions($page);

            return $this->success(
                'sirsoft-page::messages.page.fetch_success',
                PageVersionResource::collection($versions)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 버전 상세 정보를 조회합니다.
     *
     * @param  int  $id  페이지 ID
     * @param  int  $versionId  버전 ID
     * @return JsonResponse 버전 상세 응답
     */
    public function showVersion(int $id, int $versionId): JsonResponse
    {
        try {
            $this->pageService->getPage($id);
            $version = $this->pageService->getVersion($versionId);
            $version->load('creator');

            return $this->successWithResource(
                'sirsoft-page::messages.page.fetch_success',
                new PageVersionResource($version)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 버전으로 페이지를 복원합니다.
     *
     * @param  int  $id  페이지 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return JsonResponse 복원된 페이지 응답
     */
    public function restoreVersion(int $id, int $versionId): JsonResponse
    {
        try {
            $page = $this->pageService->getPage($id);
            $page = $this->pageService->restoreVersion($page, $versionId);
            $page->load(['creator', 'updater', 'attachments']);

            return $this->successWithResource(
                'sirsoft-page::messages.page.restore_success',
                new PageResource($page)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('sirsoft-page::messages.page.not_found');
        } catch (AccessDeniedHttpException) {
            return $this->error('auth.scope_denied', 403);
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.restore_failed', 500, $e->getMessage());
        }
    }
}
