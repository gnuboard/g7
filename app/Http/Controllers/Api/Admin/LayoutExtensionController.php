<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ConcurrentModificationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\LayoutVersionListRequest;
use App\Http\Requests\Layout\CompileLayoutMarkupRequest;
use App\Http\Requests\Layout\StoreLayoutExtensionPreviewRequest;
use App\Http\Requests\Layout\UpdateLayoutExtensionContentRequest;
use App\Http\Resources\LayoutExtensionResource;
use App\Http\Resources\LayoutExtensionVersionResource;
use App\Models\LayoutExtension;
use App\Services\LayoutExtensionService;
use App\Services\LayoutMarkupService;
use App\Services\LayoutPreviewService;
use App\Services\TemplateService;
use App\Support\LayoutMarkup\CompileError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 레이아웃 확장 관리 컨트롤러 (admin)
 *
 * 모듈/플러그인이 주입한 레이아웃 확장을 관리자가 편집·버전관리·미리보기할 수 있도록 합니다.
 * 확장은 동일 target_name 에 여러 source 가 존재할 수 있어 정수 PK(extensionId)로 식별합니다.
 */
class LayoutExtensionController extends AdminBaseController
{
    /**
     * @param  LayoutExtensionService  $layoutExtensionService  확장 조각 조회·저장·주입 해석
     * @param  TemplateService  $templateService  템플릿 식별자 → 템플릿 해석
     * @param  LayoutPreviewService  $layoutPreviewService  저장하지 않은 조각의 일회용 미리보기 발급
     */
    public function __construct(
        private LayoutExtensionService $layoutExtensionService,
        private TemplateService $templateService,
        private LayoutPreviewService $layoutPreviewService
    ) {
        parent::__construct();
    }

    /**
     * 특정 템플릿의 레이아웃 확장 목록 조회 (출처별 그룹핑)
     *
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 출처별 그룹핑된 확장 목록
     */
    public function index(string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $groups = $this->layoutExtensionService->getExtensionsByTemplateId($template->id);

        // 그룹별 extensions 를 LayoutExtensionResource 컬렉션으로 직렬화.
        // 각 확장에 호스트 레이아웃 목록(host_layouts)을 부착해, 라우트 트리가 클릭(캔버스 로드)
        // 없이도 layoutName 매칭으로 화면별 연결 확장 목록을 정적 구성하게 한다.
        $data = array_map(function (array $group): array {
            foreach ($group['extensions'] as $extension) {
                $extension->setAttribute(
                    'host_layouts',
                    $this->layoutExtensionService->getExtensionHostLayouts($extension)
                );
            }
            // 목록은 경량 표현만 내려준다 — 편집 본문(content)은 단건 조회가 공급한다.
            $group['extensions'] = collect($group['extensions'])
                ->map(fn ($extension) => (new LayoutExtensionResource($extension))->toListArray(request()))
                ->values()
                ->all();

            return $group;
        }, $groups);

        return $this->success('common.success', $data);
    }

    /**
     * 특정 레이아웃 확장 상세 조회
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return JsonResponse 확장 상세 응답
     */
    public function show(string $templateName, int $extensionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        // 호스트 레이아웃 후보 — 확장 편집 모드 캔버스가 호스트 병합
        // 렌더할 대상. overlay = [target_layout], extension_point = 그 확장점을 포함하는
        // 레이아웃 전체(복수면 클라이언트가 대표 호스트 선택 picker 를 띄운다).
        // ResponseHelper::success 는 Resource additional() 을 보존하지 않으므로 data 안에 병합.
        $resource = new LayoutExtensionResource($extension);

        // `?with_markup=1` — 코드 편집기 HTML 탭용 역변환(JSON→.g7.html). 기본 응답은 무변경.
        if (request()->boolean('with_markup')) {
            $resource->withMarkup($this->buildMarkupPayload($extension, $templateName));
        }

        $payload = $resource->resolve();
        $payload['host_layouts'] = $this->layoutExtensionService->getExtensionHostLayouts($extension);

        return $this->success('common.success', $payload);
    }

    /**
     * 확장 조각 마크업(`.g7.html`)을 저장 없이 프로덕션 컴파일해 JSON·경고를 돌려줍니다 (코드 편집기 JSON 뷰 갱신용).
     *
     * @param  CompileLayoutMarkupRequest  $request  변환 요청 (`markup`)
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return JsonResponse 200 `{content, warnings}` | 422 `compile_error`
     */
    public function compileMarkup(CompileLayoutMarkupRequest $request, string $templateName, int $extensionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        $service = app(LayoutMarkupService::class);

        try {
            $result = $service->toJson(
                $request->validated('markup'),
                $templateName,
                $extension->source_type?->value,
                $extension->source_identifier,
                "{$extension->target_name}.g7.html",
            );
        } catch (CompileError $error) {
            $described = $service->describeError($error);

            // 구조화 오류는 `errors` 안에 — 레이아웃이 `{{error.errors?.compile_error}}` 로 읽는다.
            $response = $this->error(
                'validation.layout_extension.content.markup_compile_failed',
                422,
                ['markup' => [$error->getDetail()], 'compile_error' => $described],
                ['detail' => $error->getDetail()],
            );
            $payload = $response->getData(true);
            $payload['compile_error'] = $described;

            return $response->setData($payload);
        }

        return $this->success('common.success', [
            'content' => $service->encodeJson($result->json),
            'warnings' => $service->describeWarnings($result->warnings),
        ]);
    }

    /**
     * `?with_markup=1` 페이로드 — 역변환 결과 + 되컴파일 lint 경고(실패면 `markup_compile_error`).
     *
     * @param  LayoutExtension  $extension  확장 행
     * @param  string  $templateName  템플릿 identifier
     * @return array{content_markup: string, markup_escapes: array<int, string>, markup_warnings: array<int, array<string, mixed>>, markup_compile_error: array<string, mixed>|null}
     */
    private function buildMarkupPayload(LayoutExtension $extension, string $templateName): array
    {
        $service = app(LayoutMarkupService::class);
        // 레이아웃 갈래와 같은 이유로 원본 JSON 문자열에서 디코드한다 — `array` 캐스트는 `{}` 와 `[]` 를
        // 구분하지 못해, 그대로 역변환하면 `{}` 가 편집기에 `[]` 로 보이고 저장 시 실제로 바뀐다.
        $content = $service->decodeStoredContent((string) $extension->getRawOriginal('content'));
        ['markup' => $markup, 'escapes' => $escapes] = $service->toMarkup($content, $templateName);
        $warnings = [];
        $compileError = null;

        try {
            $result = $service->toJson($markup, $templateName, $extension->source_type?->value, $extension->source_identifier, "{$extension->target_name}.g7.html");
            $warnings = $service->describeWarnings($result->warnings);
        } catch (CompileError $error) {
            $compileError = $service->describeError($error);
        }

        return [
            'content_markup' => $markup,
            'markup_escapes' => $escapes,
            'markup_warnings' => $warnings,
            'markup_compile_error' => $compileError,
        ];
    }

    /**
     * 레이아웃 확장 content 수정
     *
     * @param  UpdateLayoutExtensionContentRequest  $request  검증된 요청
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return JsonResponse 업데이트된 확장 응답
     */
    public function update(UpdateLayoutExtensionContentRequest $request, string $templateName, int $extensionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            // content 는 input() 으로 전체 배열을 전달한다.
            // validated() 는 content.* 하위 규칙이 있을 경우 하위 키만 재구성하여
            // extension_point/components 등 최상위 키가 누락되기 때문이다.
            $data = [
                'content' => $request->input('content'),
            ];
            if ($request->has('priority')) {
                $data['priority'] = $request->input('priority');
            }
            if ($request->has('expected_lock_version')) {
                $data['expected_lock_version'] = $request->input('expected_lock_version');
            }

            $updated = $this->layoutExtensionService->updateExtension($extensionId, $data);

            DB::commit();

            $payload = (new LayoutExtensionResource($updated))->resolve();
            // `format=markup` 저장이 낸 lint 경고 — 편집기가 노란 마커로 보여준다 (JSON 저장이면 빈 배열).
            $payload['markup_warnings'] = $request->markupWarnings();

            return $this->success('common.success', $payload);
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return $this->notFound('common.not_found');
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

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 레이아웃 확장의 모든 버전 목록 조회
     *
     * @param  LayoutVersionListRequest  $request  버전 목록 조회 요청 (limit)
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return JsonResponse 버전 목록 응답
     */
    public function versions(LayoutVersionListRequest $request, string $templateName, int $extensionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        $versions = $this->layoutExtensionService->getExtensionVersions(
            $extensionId,
            (int) ($request->validated()['limit'] ?? LayoutVersionListRequest::DEFAULT_LIMIT)
        );

        // 목록은 경량 표현만 내려준다 — 확장 본문은 버전 비교 diff 전용이라 단건 조회가 공급한다.
        $items = $versions
            ->map(fn ($version) => (new LayoutExtensionVersionResource($version))->toListArray($request))
            ->values()
            ->all();

        return $this->success('common.success', $items);
    }

    /**
     * 특정 버전의 레이아웃 확장 content 조회
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @param  int  $version  버전 번호
     * @return JsonResponse 특정 버전 응답
     */
    public function showVersion(string $templateName, int $extensionId, int $version): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        try {
            $extensionVersion = $this->layoutExtensionService->getExtensionVersion($extensionId, $version);
        } catch (ModelNotFoundException) {
            return $this->notFound('common.not_found');
        }

        return $this->success('common.success', new LayoutExtensionVersionResource($extensionVersion));
    }

    /**
     * 레이아웃 확장 버전 복원
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return JsonResponse 복원 후 새 버전 응답
     */
    public function restoreVersion(string $templateName, int $extensionId, int $versionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $newVersion = $this->layoutExtensionService->restoreExtensionVersion($extensionId, $versionId);

            DB::commit();

            return $this->success('common.success', new LayoutExtensionVersionResource($newVersion));
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return $this->notFound('common.not_found');
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 레이아웃 확장 미리보기 생성
     *
     * 편집 중인 확장 content를 임시 저장하고, 대표 레이아웃에 적용한 미리보기 URL을 반환합니다.
     *
     * @param  StoreLayoutExtensionPreviewRequest  $request  검증된 요청
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return JsonResponse 미리보기 토큰 응답
     */
    public function storePreview(StoreLayoutExtensionPreviewRequest $request, string $templateName, int $extensionId): JsonResponse
    {
        $extension = $this->resolveExtension($templateName, $extensionId);

        if (! $extension) {
            return $this->notFound('common.not_found');
        }

        // 대표 레이아웃 결정:
        // - overlay 타입은 target_name 자체가 대표 레이아웃
        // - extension_point 타입은 프론트가 preview_layout 으로 전달
        $previewLayout = $request->validated('preview_layout');
        if (! $previewLayout) {
            $previewLayout = $extension->extension_type->value === 'overlay'
                ? $extension->target_name
                : null;
        }

        if (! $previewLayout) {
            return $this->error('validation.layout_extension.preview_layout.required', 422);
        }

        try {
            $preview = $this->layoutPreviewService->createExtensionPreview(
                $extension->template_id,
                $extensionId,
                $previewLayout,
                $request->validated('content'),
                $request->user()->id
            );

            return $this->success('common.success', [
                'token' => $preview->token,
                'preview_url' => '/preview/'.$preview->token,
                'expires_at' => $preview->expires_at->toIso8601String(),
                'markup_warnings' => $request->markupWarnings(),
            ]);
        } catch (\Exception $e) {
            return $this->error('common.failed', 500, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 템플릿 식별자와 확장 ID 로 확장을 조회하고, 템플릿 소속을 교차검증합니다.
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  int  $extensionId  확장 ID
     * @return LayoutExtension|null 확장 모델 또는 null (템플릿 미존재 / 확장 미존재 / 소속 불일치)
     */
    private function resolveExtension(string $templateName, int $extensionId): ?LayoutExtension
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return null;
        }

        try {
            $extension = $this->layoutExtensionService->getExtensionById($extensionId);
        } catch (ModelNotFoundException) {
            return null;
        }

        // 확장이 요청 템플릿에 속하는지 교차검증
        if ($extension->template_id !== $template->id) {
            return null;
        }

        return $extension;
    }
}
