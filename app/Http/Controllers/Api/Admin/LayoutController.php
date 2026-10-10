<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ConcurrentModificationException;
use App\Exceptions\LayoutFileOperationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\LayoutVersionListRequest;
use App\Http\Requests\Layout\CompileLayoutMarkupRequest;
use App\Http\Requests\Layout\CreateLayoutFileRequest;
use App\Http\Requests\Layout\RenameLayoutRequest;
use App\Http\Requests\Layout\SearchLayoutRequest;
use App\Http\Requests\Layout\StoreLayoutPreviewRequest;
use App\Http\Requests\Layout\UpdateLayoutContentRequest;
use App\Http\Resources\LayoutListResource;
use App\Http\Resources\LayoutResource;
use App\Http\Resources\LayoutSearchResource;
use App\Http\Resources\LayoutVersionResource;
use App\Rules\LayoutContentGate;
use App\Services\LayoutFileService;
use App\Services\LayoutMarkupService;
use App\Services\LayoutPreviewService;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Support\LayoutMarkup\CompileError;
use App\Support\SafeExceptionMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LayoutController extends AdminBaseController
{
    /**
     * @param  LayoutService  $layoutService  레이아웃 조회·저장 (낙관적 잠금 포함)
     * @param  TemplateService  $templateService  템플릿 식별자 → 템플릿 해석
     * @param  LayoutPreviewService  $layoutPreviewService  저장하지 않은 본문의 일회용 미리보기 발급
     * @param  LayoutFileService  $layoutFileService  파일 단위 조작 (생성·삭제·이름변경·검색)
     * @param  LayoutMarkupService  $layoutMarkupService  마크업 역변환·컴파일·저장본 해석
     */
    public function __construct(
        private LayoutService $layoutService,
        private TemplateService $templateService,
        private LayoutPreviewService $layoutPreviewService,
        private LayoutFileService $layoutFileService,
        private LayoutMarkupService $layoutMarkupService
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

        $resource = (new LayoutResource($layout))
            ->withTranslations($this->resolveTemplateTranslations($templateName));

        // `?with_markup=1` — 코드 편집기 HTML 탭용 역변환(JSON→.g7.html). 기본 응답(위지윅 등)은 무변경.
        if (request()->boolean('with_markup')) {
            $sourceKind = $layout->source_type?->value ?? null;
            // 원본 JSON 문자열에서 디코드한다 — Eloquent 의 `array` 캐스트는 `{}` 와 `[]` 를 똑같이
            // PHP `[]` 로 만들어, 그대로 역변환하면 작성자가 적은 `{}` 가 편집기에 `[]` 로 틀리게 보이고
            // 그 상태로 저장하면 DB 의 `{}` 가 실제로 `[]` 가 된다.
            $resource->withMarkup($this->buildMarkupPayload(
                $this->layoutMarkupService->decodeStoredContent((string) $layout->getRawOriginal('content')),
                $templateName,
                $sourceKind,
                $layout->source_identifier ?? $templateName,
                "{$name}.g7.html",
            ));
        }

        return $this->success('common.success', $resource);
    }

    /**
     * 레이아웃 파일 생성
     *
     * 본문은 `.g7.html` 마크업으로 받는다. 비워 보내면 곧바로 컴파일되는 최소 골격이 대신 들어간다
     * ({@see LayoutFileService::defaultSkeleton()}).
     *
     * @param  CreateLayoutFileRequest  $request  생성 요청 (`name`, 선택 `content`)
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 201 생성된 레이아웃 | 422 이름 규칙·중복·컴파일 오류
     */
    public function store(CreateLayoutFileRequest $request, string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        try {
            DB::beginTransaction();

            $layout = $this->layoutFileService->create(
                $template->id,
                $templateName,
                (string) $request->validated('name'),
                (array) $request->validated('content'),
                $request->user()?->id,
            );

            DB::commit();
        } catch (LayoutFileOperationException $e) {
            DB::rollBack();

            return $this->layoutFileError($e);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }

        $resource = (new LayoutResource($layout))
            ->withTranslations($this->resolveTemplateTranslations($templateName));

        return $this->success('layouts.messages.created', $resource, 201);
    }

    /**
     * 레이아웃 파일 이름변경 (= 이동)
     *
     * @param  RenameLayoutRequest  $request  이름변경 요청 (`new_name`, `expected_lock_version`)
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  현재 레이아웃 이름
     * @return JsonResponse 200 바뀐 레이아웃 | 409 중복·잠금 충돌 | 422 참조 차단
     */
    public function rename(RenameLayoutRequest $request, string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layout = $this->layoutService->getLayoutByName($template->id, $name);

        if (! $layout) {
            return $this->notFound('common.not_found');
        }

        // 이름변경은 본문의 `layout_name` 까지 옮기므로 내용을 건드리는 연산이다 — 저장과 같은
        // 낙관적 잠금을 지나지 않으면 다른 창의 저장분을 못 본 채 덮어쓴다.
        $expected = (int) $request->validated('expected_lock_version');
        $current = (int) ($layout->lock_version ?? 0);

        if ($expected !== $current) {
            return $this->concurrentModificationResponse(new ConcurrentModificationException(
                currentVersion: $current,
                expectedVersion: $expected,
                resource: "template_layouts:{$layout->id}",
            ));
        }

        try {
            DB::beginTransaction();

            $renamed = $this->layoutFileService->rename(
                $template->id,
                $name,
                (string) $request->validated('new_name'),
                $request->user()?->id,
            );

            DB::commit();
        } catch (LayoutFileOperationException $e) {
            DB::rollBack();

            return $this->layoutFileError($e);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }

        $resource = (new LayoutResource($renamed))
            ->withTranslations($this->resolveTemplateTranslations($templateName));

        return $this->success('layouts.messages.renamed', $resource);
    }

    /**
     * 레이아웃 파일 삭제 (soft delete — 되돌릴 수 있다)
     *
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 200 삭제됨 | 422 참조 차단·확인 필요
     */
    public function destroy(string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        // 이 레이아웃에 라우트가 걸려 있으면 지운 직후 그 화면이 404 가 된다 — 서비스가 확인
        // 단계를 한 번 더 요구하도록 경로를 함께 넘긴다(차단은 아니다).
        $routePath = $this->templateService->getLayoutRoutePathMap($templateName)[$name] ?? null;

        try {
            DB::beginTransaction();

            $this->layoutFileService->delete(
                $template->id,
                $name,
                request()->boolean('confirm'),
                $routePath,
            );

            DB::commit();
        } catch (LayoutFileOperationException $e) {
            DB::rollBack();

            return $this->layoutFileError($e);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error('common.failed', 500, ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]);
        }

        return $this->success('layouts.messages.deleted', ['name' => $name, 'deleted' => true]);
    }

    /**
     * 레이아웃 파일명·본문 검색
     *
     * 사용자가 보는 것은 `.g7.html` 마크업이므로 검색 대상도 역변환한 마크업이다 — 줄 번호가
     * 편집기와 같아야 결과를 눌러 그 줄로 이동할 수 있다.
     *
     * @param  SearchLayoutRequest  $request  검색 요청 (`q`, `scope`, `limit`)
     * @param  string  $templateName  템플릿 identifier
     * @return JsonResponse 200 검색 결과 | 422 `q` 규칙 위반
     */
    public function search(SearchLayoutRequest $request, string $templateName): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        ['q' => $keyword, 'scope' => $scope, 'limit' => $limit] = $request->criteria();

        $rows = $this->layoutFileService->search($template->id, $templateName, $keyword, $scope, $limit);

        return $this->success('common.success', LayoutSearchResource::collection($rows));
    }

    /**
     * 파일 조작 예외를 응답으로 옮깁니다.
     *
     * 예외가 들고 온 **메시지 키**를 그대로 넘긴다 — 이미 번역된 문장을 키 자리에 넘기면 키
     * 해석에 실패해 원문이 화면에 나간다. 차단 근거가 된 참조 목록은 `errors.references` 에
     * 실어 화면이 "무엇이 이 파일을 붙들고 있는지" 를 보여줄 수 있게 한다.
     *
     * @param  LayoutFileOperationException  $e  파일 조작 예외
     * @return JsonResponse 예외가 정한 상태 코드의 오류 응답
     */
    private function layoutFileError(LayoutFileOperationException $e): JsonResponse
    {
        $errors = ['reason' => $e->errorKey];

        if ($e->references !== []) {
            // 화면은 `kind` 토큰(extends·partial·route)을 그대로 보이면 안 된다 — 사람 말 라벨을
            // 서버가 붙여 준다(`layouts.reference_kinds.*`, 요청 로케일).
            $errors['references'] = array_map(static function (array $reference): array {
                $kind = (string) ($reference['kind'] ?? '');
                $reference['kind_label'] = $kind !== '' ? __('layouts.reference_kinds.'.$kind) : '';

                return $reference;
            }, $e->references);
        }

        return $this->error($e->errorKey, $e->status, $errors, $e->params);
    }

    /**
     * 낙관적 잠금 충돌 응답을 만듭니다 ({@see update()} 와 같은 형식).
     *
     * @param  ConcurrentModificationException  $e  충돌 예외
     * @return JsonResponse 409 응답
     */
    private function concurrentModificationResponse(ConcurrentModificationException $e): JsonResponse
    {
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
    }

    /**
     * 마크업(`.g7.html`)을 저장 없이 프로덕션 컴파일해 JSON·경고를 돌려줍니다 (코드 편집기 JSON 뷰 갱신용).
     *
     * @param  CompileLayoutMarkupRequest  $request  변환 요청 (`markup`)
     * @param  string  $templateName  템플릿 identifier
     * @param  string  $name  레이아웃 이름
     * @return JsonResponse 200 `{content, warnings}` | 422 `compile_error`
     */
    public function compileMarkup(CompileLayoutMarkupRequest $request, string $templateName, string $name): JsonResponse
    {
        $template = $this->templateService->findByIdentifier($templateName);

        if (! $template) {
            return $this->notFound('common.not_found');
        }

        $layout = $this->layoutService->getLayoutByName($template->id, $name);

        if (! $layout) {
            return $this->notFound('common.not_found');
        }

        return $this->compileMarkupResponse(
            $request->validated('markup'),
            $templateName,
            $layout->source_type?->value ?? null,
            $layout->source_identifier ?? $templateName,
            "{$name}.g7.html",
            // 저장본 — 그 안에 이미 있던 미등록 템플릿 컴포넌트는 그 개수까지 허용한다 (#712)
            $this->layoutMarkupService->decodeStoredContent((string) $layout->getRawOriginal('content')),
        );
    }

    /**
     * 마크업 변환 응답을 만듭니다 — 성공 200 `{content(pretty JSON), warnings}`, 컴파일 오류 422 `compile_error`.
     *
     * @param  string  $markup  `.g7.html` 소스
     * @param  string  $templateName  템플릿 identifier
     * @param  string|null  $sourceKind  행 source_type
     * @param  string|null  $identifier  행 source_identifier
     * @param  string  $label  표시용 파일명
     * @param  array<string, mixed>|null  $baseline  편집 중인 행의 저장본 (미등록 템플릿 컴포넌트 허용 기준, #712)
     * @return JsonResponse 변환 응답
     */
    private function compileMarkupResponse(string $markup, string $templateName, ?string $sourceKind, ?string $identifier, string $label, ?array $baseline = null): JsonResponse
    {
        $service = $this->layoutMarkupService;

        try {
            $result = $service->toJson($markup, $templateName, $sourceKind, $identifier, $label, $baseline);
        } catch (CompileError $error) {
            $described = $service->describeError($error);

            // 구조화 오류는 `errors` 안에 — 레이아웃이 `{{error.errors?.compile_error}}` 로 읽는다.
            $response = $this->error(
                'validation.layout.content.markup_compile_failed',
                422,
                ['markup' => [$error->getDetail()], 'compile_error' => $described],
                ['detail' => $error->getDetail()],
            );
            $payload = $response->getData(true);
            $payload['compile_error'] = $described;

            return $response->setData($payload);
        }

        // 컴파일 산출 JSON 도 저장 경로와 같은 보안 게이트를 거친다 (KVE-2026-2350) — 편집기는 이 결과를
        // 그대로 미리보기·저장에 쓰므로, 저장에서 막힐 값을 여기서 먼저 알린다.
        $violations = LayoutContentGate::violations($result->json);
        if ($violations !== []) {
            // 편집기 오류 패널·마커가 줄을 가리키도록 위반 값이 적힌 소스 자리를 `compile_error` 로 싣는다(7.1.0 P16).
            $described = $service->describeContentViolation($markup, $result->json);
            $response = $this->error(
                'validation.layout.content.markup_compile_failed',
                422,
                ['content' => $violations, 'compile_error' => $described],
                ['detail' => $violations[0]],
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
     * `?with_markup=1` 페이로드 — 역변환 결과 + 그 마크업을 되컴파일한 lint 경고(실패면 `markup_compile_error`).
     *
     * 되컴파일까지 해 두는 이유: 편집기가 열자마자 "이 행은 저장이 막힌다" 를 알려야 하고(설치본에 남은 옛 결함),
     * 경고 마커도 첫 표시부터 있어야 한다.
     *
     * @param  array<string, mixed>  $json  행 content
     * @param  string  $templateName  템플릿 identifier
     * @param  string|null  $sourceKind  행 source_type
     * @param  string|null  $identifier  행 source_identifier
     * @param  string  $label  표시용 파일명
     * @return array{content_markup: string, markup_escapes: array<int, string>, markup_warnings: array<int, array<string, mixed>>, markup_compile_error: array<string, mixed>|null} 역변환 마크업·이스케이프 목록·lint 경고·되컴파일 오류
     */
    private function buildMarkupPayload(array $json, string $templateName, ?string $sourceKind, ?string $identifier, string $label): array
    {
        $service = $this->layoutMarkupService;

        // 템플릿 `components.json` 이 스키마를 어기면 검사 목록을 만들 수 없다 — 500 이 아니라 빈 마크업 + 오류로 연다(#712).
        try {
            ['markup' => $markup, 'escapes' => $escapes] = $service->toMarkup($json, $templateName);
        } catch (CompileError $error) {
            return [
                'content_markup' => '',
                'markup_escapes' => [],
                'markup_warnings' => [],
                'markup_compile_error' => $service->describeError($error),
            ];
        }

        $warnings = [];
        $compileError = null;

        try {
            // 저장본(이 행)에 이미 있던 미등록 템플릿 컴포넌트는 그 개수까지 허용한다 — 7.0 이 무차별 등록한 행 (#712)
            $warnings = $service->describeWarnings($service->toJson($markup, $templateName, $sourceKind, $identifier, $label, $json)->warnings);
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

            $payload = (new LayoutResource($layout))
                ->withTranslations($this->resolveTemplateTranslations($templateName))
                ->resolve();
            // `format=markup` 저장이 낸 lint 경고 — 편집기가 노란 마커로 보여준다 (JSON 저장이면 빈 배열).
            $payload['markup_warnings'] = $request->markupWarnings();

            return $this->success('common.success', $payload);
        } catch (ConcurrentModificationException $e) {
            DB::rollBack();

            return $this->concurrentModificationResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error(
                'common.failed',
                500,
                ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]
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
                    'preview_url' => $this->layoutPreviewService->previewUrl($preview),
                    'expires_at' => $preview->expires_at->toIso8601String(),
                    'markup_warnings' => $request->markupWarnings(),
                ]
            );
        } catch (\Exception $e) {
            return $this->error(
                'common.failed',
                500,
                ['error' => SafeExceptionMessage::for($e, ['operation' => __METHOD__])]
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
