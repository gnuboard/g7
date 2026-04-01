<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Layout\StoreLayoutPreviewRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Resources\LayoutResource;
use App\Http\Resources\LayoutVersionResource;
use App\Services\LayoutPreviewService;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LayoutController extends AdminBaseController
{
    public function __construct(
        private LayoutService $layoutService,
        private TemplateService $templateService,
        private LayoutPreviewService $layoutPreviewService
    ) {
        parent::__construct();
    }

    /**
     * 특정 템플릿의 모든 레이아웃 목록 조회
     *
     * @param string $templateName 템플릿 identifier
     * @return JsonResponse
     */
    public function index(string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layouts = $this->layoutService->getLayoutsByTemplateId($template->id);

        return $this->success(
            'common.success',
            LayoutResource::collection($layouts)
        );
    }

    /**
     * 특정 레이아웃 상세 조회
     *
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @return JsonResponse
     */
    public function show(string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layout = $this->layoutService->getLayoutByName($template->id, $name);

        if (! $layout) {
            return $this->notFound('common.not_found');
        }

        return $this->success(
            'common.success',
            new LayoutResource($layout)
        );
    }

    /**
     * 레이아웃 수정
     *
     * @param UpdateLayoutContentRequest $request
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @return JsonResponse
     */
    public function update(UpdateLayoutContentRequest $request, string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $layout = $this->layoutService->updateLayout($template->id, $name, $request->validated());

            DB::commit();

            return $this->success(
                'common.success',
                new LayoutResource($layout)
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error(
                'common.failed',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * 레이아웃의 모든 버전 목록 조회
     *
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @return JsonResponse
     */
    public function versions(string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layout = $this->layoutService->getLayoutByName($template->id, $name);

        if (! $layout) {
            return $this->notFound('common.not_found');
        }

        $versions = $this->layoutService->getLayoutVersions($template->id, $name);

        return $this->success(
            'common.success',
            LayoutVersionResource::collection($versions)
        );
    }

    /**
     * 특정 버전의 레이아웃 content 조회
     *
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @param int $version 버전 번호
     * @return JsonResponse
     */
    public function showVersion(string $templateName, string $name, int $version): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layoutVersion = $this->layoutService->getLayoutVersion($template->id, $name, $version);

        if (! $layoutVersion) {
            return $this->notFound('common.not_found');
        }

        return $this->success(
            'common.success',
            new LayoutVersionResource($layoutVersion)
        );
    }

    /**
     * 버전 복원
     *
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @param int $versionId 버전 ID
     * @return JsonResponse
     */
    public function restoreVersion(string $templateName, string $name, int $versionId): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $newVersion = $this->layoutService->restoreVersion($template->id, $name, $versionId);

        if (! $newVersion) {
            return $this->notFound('common.not_found');
        }

        return $this->success(
            'common.success',
            new LayoutVersionResource($newVersion)
        );
    }

    /**
     * 레이아웃 미리보기 생성
     *
     * 편집 중인 레이아웃 content를 임시 저장하고 미리보기 URL을 반환합니다.
     *
     * @param StoreLayoutPreviewRequest $request
     * @param string $templateName 템플릿 identifier
     * @param string $name 레이아웃 이름
     * @return JsonResponse
     */
    public function storePreview(StoreLayoutPreviewRequest $request, string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        try {
            $preview = $this->layoutPreviewService->createPreview(
                $template->id,
                $name,
                $request->validated('content'),
                $request->user()->id
            );

            return $this->success(
                'common.success',
                [
                    'token' => $preview->token,
                    'preview_url' => '/preview/' . $preview->token,
                    'expires_at' => $preview->expires_at->toIso8601String(),
                ]
            );
        } catch (\Exception $e) {
            return $this->error(
                'common.failed',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }
}
