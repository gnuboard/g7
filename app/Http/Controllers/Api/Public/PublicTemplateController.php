<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\ExtensionStatus;
use App\Extension\Helpers\EditorSpecAssembler;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Template\ServeTemplateAssetRequest;
use App\Models\TemplateLayoutAttachment;
use App\Services\TemplateLayoutAttachmentService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 공개 템플릿 API 컨트롤러
 */
class PublicTemplateController extends PublicBaseController
{
    public function __construct(
        private TemplateService $templateService,
        private TemplateLayoutAttachmentService $layoutAttachmentService,
    ) {
        parent::__construct();
    }

    /**
     * 템플릿 라우트 정보 조회 (활성화된 모듈의 routes 포함)
     *
     * @param  string  $identifier  템플릿 식별자 (vendor-name 형식)
     * @return JsonResponse 라우트 정보 응답
     */
    public function getRoutes(string $identifier): JsonResponse
    {
        // API 사용량 기록
        $this->logApiUsage('templates.routes', ['identifier' => $identifier]);

        // 캐시 버전을 키에 포함하여 모듈/플러그인 변경 시 캐시 무효화
        $cacheVersion = request()->query('v', 0);

        // 캐싱된 응답 반환 (1시간 유효)
        $routesData = $this->cached(
            "template.routes.{$identifier}.v{$cacheVersion}",
            function () use ($identifier) {
                // Service에서 템플릿 + 모듈 routes 데이터 병합 조회
                $result = $this->templateService->getRoutesDataWithModules($identifier);

                // 에러 처리
                if (! $result['success']) {
                    return ['error' => $result['error']];
                }

                return ['success' => true, 'data' => $result['data']];
            },
            3600
        );

        // 에러 응답 처리
        if (isset($routesData['error'])) {
            return match ($routesData['error']) {
                'template_not_found' => $this->notFound(
                    __('templates.errors.not_found', ['template' => $identifier])
                ),
                'routes_not_found' => $this->notFound(
                    __('templates.errors.routes_not_found')
                ),
                'invalid_json' => $this->error(
                    __('templates.errors.invalid_json'),
                    500
                ),
                default => $this->error(__('templates.errors.unknown_error'), 500),
            };
        }

        // data 키 누락 시 기본 구조 반환 (캐시 오류 방어)
        if (! isset($routesData['data'])) {
            return $this->error(__('templates.errors.invalid_cache_data'), 500);
        }

        return $this->success(
            __('templates.messages.routes_retrieved'),
            $routesData['data']
        );
    }

    /**
     * 템플릿 정적 파일 서빙
     *
     * @param  ServeTemplateAssetRequest  $request  요청 (FormRequest 검증)
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $path  요청 경로
     * @return BinaryFileResponse|JsonResponse|Response 파일 응답 또는 에러
     */
    public function serveAsset(ServeTemplateAssetRequest $request, string $identifier, string $path): BinaryFileResponse|JsonResponse|Response
    {
        // FormRequest에서 이미 보안 검증 완료
        // API 사용량 기록
        $this->logApiUsage('templates.assets', ['identifier' => $identifier, 'path' => $path]);

        // Service에서 파일 경로 조회 (검증은 FormRequest에서 완료됨)
        $result = $this->templateService->getAssetFilePath($identifier, $path);

        // 에러 처리
        if (! $result['success']) {
            return match ($result['error']) {
                'template_not_found' => $this->notFound(__('templates.errors.not_found', ['template' => $identifier])),
                'file_not_found' => $this->notFound(__('templates.errors.file_not_found')),
                'file_type_not_allowed' => $this->forbidden(__('templates.errors.file_type_not_allowed')),
                default => $this->error(__('templates.errors.unknown_error'), 500),
            };
        }

        // 파일 반환 (ETag 및 환경별 캐싱 헤더 포함)
        return $this->fileResponse($result['filePath'], $result['mimeType'], 31536000);
    }

    /**
     * 컴포넌트 정의 파일 서빙
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 컴포넌트 정의 응답
     */
    public function serveComponents(string $identifier): JsonResponse
    {
        // API 사용량 기록
        $this->logApiUsage('templates.components', ['identifier' => $identifier]);

        // Service에서 파일 경로 조회 및 검증
        $result = $this->templateService->getComponentsFilePath($identifier);

        // 에러 처리
        if (! $result['success']) {
            return match ($result['error']) {
                'template_not_found' => $this->notFound(__('templates.errors.not_found', ['template' => $identifier])),
                'components_not_found' => $this->notFound(__('templates.errors.components_not_found')),
                default => $this->error(__('templates.errors.unknown_error'), 500),
            };
        }

        // JSON 파싱 및 반환
        $components = json_decode(file_get_contents($result['componentsPath']), true);

        return $this->cachedJsonResponse($components, 3600);
    }

    /**
     * 템플릿 설정 파일 서빙 (template.json)
     *
     * error_config 등 템플릿 메타데이터를 프론트엔드에 제공합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 템플릿 설정 응답
     */
    public function serveConfig(string $identifier): JsonResponse
    {
        // API 사용량 기록
        $this->logApiUsage('templates.config', ['identifier' => $identifier]);

        // 캐싱된 응답 반환 (1시간 유효)
        $configData = $this->cached(
            "template.config.{$identifier}",
            function () use ($identifier) {
                // 템플릿 존재 확인
                $template = $this->templateService->findByIdentifier($identifier);
                if (! $template || $template->status !== ExtensionStatus::Active->value) {
                    return ['error' => 'template_not_found'];
                }

                // template.json 파일 경로
                $configPath = base_path("templates/{$identifier}/template.json");

                if (! file_exists($configPath)) {
                    return ['error' => 'config_not_found'];
                }

                $content = file_get_contents($configPath);
                $data = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    return ['error' => 'invalid_json'];
                }

                return ['success' => true, 'data' => $data];
            },
            3600
        );

        // 에러 응답 처리
        if (isset($configData['error'])) {
            return match ($configData['error']) {
                'template_not_found' => $this->notFound(
                    __('templates.errors.not_found', ['template' => $identifier])
                ),
                'config_not_found' => $this->notFound(
                    __('templates.errors.template_json_not_found')
                ),
                'invalid_json' => $this->error(
                    __('templates.errors.invalid_json'),
                    500
                ),
                default => $this->error(__('templates.errors.unknown_error'), 500),
            };
        }

        // 캐시 버전을 응답에 포함하여 프론트엔드가 API 호출 시 사용하도록 함
        $responseData = $configData['data'];
        $responseData['cache_version'] = ClearsTemplateCaches::getExtensionCacheVersion();

        return $this->success(
            __('templates.messages.config_retrieved'),
            $responseData
        );
    }

    /**
     * 템플릿 다국어 파일 서빙 (활성화된 모듈의 다국어 데이터 포함)
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $locale  로케일 (ko, en 등)
     * @return JsonResponse 다국어 데이터 응답
     */
    public function serveLanguage(string $identifier, string $locale): JsonResponse
    {
        // API 사용량 기록
        $this->logApiUsage('templates.language', [
            'identifier' => $identifier,
            'locale' => $locale,
        ]);

        // 캐시 버전을 키에 포함하여 모듈/플러그인 변경 시 캐시 무효화
        $cacheVersion = request()->query('v', 0);

        // 캐싱된 응답 반환 (1시간 유효)
        $languageData = $this->cached(
            "template.language.{$identifier}.{$locale}.v{$cacheVersion}",
            function () use ($identifier, $locale) {
                // Service에서 템플릿 + 모듈 다국어 데이터 병합 조회
                $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

                // 에러 처리
                if (! $result['success']) {
                    return ['error' => $result['error']];
                }

                return ['success' => true, 'data' => $result['data']];
            },
            3600
        );

        // 에러 응답 처리
        if (isset($languageData['error'])) {
            return match ($languageData['error']) {
                'template_not_found' => $this->notFound(
                    __('templates.errors.not_found', ['template' => $identifier])
                ),
                'locale_not_supported' => $this->notFound(
                    __('templates.errors.locale_not_supported', [
                        'template' => $identifier,
                        'locale' => $locale,
                    ])
                ),
                'file_not_found' => $this->notFound(
                    __('templates.errors.language_file_not_found', ['locale' => $locale])
                ),
                'invalid_json' => $this->error(
                    __('templates.errors.invalid_json'),
                    500
                ),
                default => $this->error(__('templates.errors.unknown_error'), 500),
            };
        }

        // 성공 응답 (JSON 데이터 직접 반환, 래핑 없음)
        return $this->cachedJsonResponse($languageData['data'], 3600);
    }

    /**
     * 편집기 스펙 조회 — 템플릿 editor-spec.json 파일 반환
     *
     * Phase 3 S5a-1 에서 `nesting` 블록이 추가되었다. 본 엔드포인트는
     * 활성 디렉토리 → _bundled 폴백 순으로 editor-spec.json 을 읽어 반환한다.
     * 파일 미존재 시 spec=null 로 폴백 (편집기는 spec 미제공 안내).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return JsonResponse 편집기 스펙 응답
     */
    public function serveEditorSpec(string $identifier): JsonResponse
    {
        $this->logApiUsage('templates.editor_spec', ['identifier' => $identifier]);

        // 분할 editor-spec.json 은 manifest + `$include` 블록으로 구성된다.
        // 활성 디렉토리만 기준으로 합본한 단일 spec 을 반환한다(_bundled 폴백 없음).
        // 합본 결과는 분할 전 단일 파일과 동일 형태(프론트엔드 로더 무영향).
        // 미분할 파일은 원본 반환(하위 호환), 미존재 시 null.
        $spec = EditorSpecAssembler::assemble(
            base_path("templates/{$identifier}/editor-spec.json")
        );

        $message = $spec === null
            ? __('templates.messages.editor_spec_empty')
            : __('templates.messages.editor_spec_retrieved');

        return $this->success(
            $message,
            [
                'identifier' => $identifier,
                'spec' => $spec,
            ]
        );
    }

    /**
     * 레이아웃 첨부 이미지 파일 서빙.
     *
     * 발행된 배경 이미지는 일반 사이트 방문자에게도 로드되어야 하므로 인증 불필요한
     * 공개 엔드포인트로 둔다. 첨부는 비공개 `attachments` 디스크에 저장되므로 직접
     * 공개 URL 이 없어, 본 라우트가 파일을 캐싱 헤더와 함께 인라인 스트림한다.
     * 첨부가 경로의 템플릿 소속이 아니거나 파일이 없으면 404.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  TemplateLayoutAttachment  $attachment  라우트 모델 바인딩된 첨부
     * @return BinaryFileResponse|Response|JsonResponse 파일 응답 또는 404
     */
    public function serveFile(string $identifier, TemplateLayoutAttachment $attachment): BinaryFileResponse|Response|JsonResponse
    {
        $filePath = $this->layoutAttachmentService->getServableFilePath($identifier, $attachment);

        if ($filePath === null) {
            return $this->notFound('templates.layout_attachments.errors.not_found');
        }

        // 이미지/일반 파일 모두 캐싱 헤더와 함께 인라인 응답 (레이아웃 캐시 TTL, 기본 24시간).
        // PublicAttachmentController 의 이미지 서빙과 동일한 fileResponse(ETag/Cache-Control) 사용.
        return $this->fileResponse(
            $filePath,
            $attachment->mime_type,
            (int) g7_core_settings('cache.layout_ttl', 86400)
        );
    }
}
