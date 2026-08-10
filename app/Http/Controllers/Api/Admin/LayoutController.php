<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ConcurrentModificationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\LayoutVersionListRequest;
use App\Http\Requests\Layout\StoreLayoutPreviewRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Resources\LayoutListResource;
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
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 레이아웃 목록 응답
     */
    public function index(string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        // 목록은 본문(`content`)을 담지 않는 경량 조회를 쓴다 — 파일 목록 화면은 이름·설명·
        // 크기·수정일만 표시하고, 편집 대상 본문은 상세 엔드포인트가 따로 제공한다.
        // 종전에는 목록·상세가 같은 Resource 를 공용해 102개 레이아웃의 본문이 전부 실렸고
        // (응답 18.30MB 중 content 17.41MB), 디버그 모드에서 렌더러가 메모리 한계를 넘었다.
        $layouts = $this->layoutService->getLayoutListByTemplateId($template->id);

        // 레이아웃 이름 → 라우트 path 매핑 — 코드 편집기가 파일 선택 시 ?route= URL
        // 동기화 / 위지윅에서 넘어온 ?route= 로 해당 파일 복원에 사용한다.
        $routePathMap = $this->templateService->getLayoutRoutePathMap($templateName);

        // 레이아웃 설명(`meta.description`)은 그 레이아웃을 소유한 템플릿의 사전 키를 쓴다.
        // 코드 편집 화면은 관리자 템플릿 사전으로 렌더하므로 유저 템플릿 키를 알지 못해,
        // 해석하지 않고 내보내면 설명 칸에 `$t:user.…` 가 원문으로 노출된다.
        $translations = $this->resolveTemplateTranslations($templateName);

        $collection = LayoutListResource::collection($layouts);
        $collection->collection->transform(
            fn (LayoutListResource $resource) => $resource
                ->withRoutePathMap($routePathMap)
                ->withTranslations($translations)
        );

        return $this->success('common.success', $collection);
    }

    /**
     * 특정 레이아웃 상세 조회
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 레이아웃 상세 응답
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
            (new LayoutResource($layout))
                ->withTranslations($this->resolveTemplateTranslations($templateName))
        );
    }

    /**
     * 레이아웃 수정
     *
     * @param  UpdateLayoutContentRequest  $request  레이아웃 수정 요청
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 수정된 레이아웃 응답
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
                (new LayoutResource($layout))
                    ->withTranslations($this->resolveTemplateTranslations($templateName))
            );
        } catch (ConcurrentModificationException $e) {
            DB::rollBack();

            return $this->error(
                'exceptions.concurrent_modification',
                409,
                [
                    'error' => 'concurrent_modification',
                    'current_version' => $e->currentVersion,
                    'your_version' => $e->expectedVersion,
                    'resource' => $e->resource,
                ],
                ['resource' => $e->resource, 'current' => $e->currentVersion, 'expected' => $e->expectedVersion],
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
     * @param  LayoutVersionListRequest  $request  버전 목록 조회 요청 (limit)
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 버전 목록 응답
     */
    public function versions(LayoutVersionListRequest $request, string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layout = $this->layoutService->getLayoutByName($template->id, $name);

        if (! $layout) {
            return $this->notFound('common.not_found');
        }

        $versions = $this->layoutService->getLayoutVersions(
            $template->id,
            $name,
            (int) ($request->validated()['limit'] ?? LayoutVersionListRequest::DEFAULT_LIMIT)
        );

        // 목록은 경량 표현만 내려준다 — 분해된 본문은 버전 비교 diff 전용이라 단건 조회가 공급한다.
        $items = $versions
            ->map(fn ($version) => (new LayoutVersionResource($version))->toListArray($request))
            ->values()
            ->all();

        return $this->success('common.success', $items);
    }

    /**
     * 특정 버전의 레이아웃 content 조회
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @param  int  $version  버전 번호
     * @return JsonResponse 버전 content 응답
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
            // 버전 비교 diff 용 — content 원본 전체(slots/extends 등 포함) 노출.
            (new LayoutVersionResource($layoutVersion))->withFullContent()
        );
    }

    /**
     * 버전 복원
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @param  int  $versionId  버전 ID
     * @return JsonResponse 복원된 버전 응답
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
     * @param  StoreLayoutPreviewRequest  $request  미리보기 생성 요청
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 미리보기 토큰/URL 응답
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
                    'preview_url' => '/preview/'.$preview->token,
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

    /**
     * 목록 설명 해석에 쓸 소유 템플릿 사전을 로드합니다.
     *
     * 활성 로케일 기준이며, 로드에 실패하면 빈 배열을 돌려준다 — 그 경우
     * {@see LayoutListResource} 가 레이아웃 이름으로 폴백하므로 목록은 계속 그려진다.
     *
     * @param  string  $templateName  템플릿 식별자
     * @return array<string, mixed> 템플릿 프론트엔드 다국어 데이터
     */
    private function resolveTemplateTranslations(string $templateName): array
    {
        $result = $this->templateService->getLanguageDataWithModules(
            $templateName,
            app()->getLocale()
        );

        return ($result['success'] ?? false) && is_array($result['data'] ?? null)
            ? $result['data']
            : [];
    }
}
