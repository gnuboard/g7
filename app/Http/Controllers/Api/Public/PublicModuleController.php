<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Module\ServeModuleAssetRequest;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 공개 모듈 API 컨트롤러
 *
 * 모듈 에셋 서빙을 담당합니다.
 */
class PublicModuleController extends PublicBaseController
{
    public function __construct(
        private readonly ModuleService $moduleService
    ) {
        parent::__construct();
    }

    /**
     * 모듈 에셋 서빙
     *
     * @param  ServeModuleAssetRequest  $request  검증된 요청 (경로, 확장자 검증 완료)
     * @param  string  $identifier  모듈 식별자 (vendor-module 형식)
     * @param  string  $path  에셋 경로 (dist/js/module.iife.js 등)
     * @return BinaryFileResponse|JsonResponse|Response 파일 응답 또는 에러 응답
     */
    public function serveAsset(
        ServeModuleAssetRequest $request,
        string $identifier,
        string $path
    ): BinaryFileResponse|JsonResponse|Response {
        // FormRequest에서 이미 보안 검증 완료
        // API 사용량 기록
        $this->logApiUsage('modules.assets', ['identifier' => $identifier, 'path' => $path]);

        // Service에서 파일 경로 조회 (검증은 FormRequest에서 완료됨)
        $result = $this->moduleService->getAssetFilePath($identifier, $path);

        // 에러 처리
        if (! $result['success']) {
            return match ($result['error']) {
                'module_not_found' => $this->notFound(__('modules.errors.not_found', ['module' => $identifier])),
                'file_not_found' => $this->notFound(__('modules.errors.file_not_found')),
                'file_type_not_allowed' => $this->forbidden(__('modules.errors.file_type_not_allowed')),
                default => $this->error(__('modules.errors.unknown_error'), 500),
            };
        }

        // 파일 반환 (ETag 및 환경별 캐싱 헤더 포함, 1년 캐시)
        return $this->fileResponse($result['filePath'], $result['mimeType'], 31536000);
    }

    /**
     * 모듈 편집기 스펙 조회 — editor-spec.json 반환
     *
     * 활성 모듈만 대상으로 하며, 활성 디렉토리 → _bundled 폴백 순으로 읽어
     * 템플릿 serveEditorSpec 과 동일한 응답 형태(`data.spec`)로 반환한다.
     * 비활성/미존재 모듈은 404. 파일 미작성은 spec=null 정상 응답.
     *
     * @param  string  $identifier  모듈 식별자 (vendor-module 형식)
     * @return JsonResponse 편집기 스펙 응답
     */
    public function serveEditorSpec(string $identifier): JsonResponse
    {
        $this->logApiUsage('modules.editor_spec', ['identifier' => $identifier]);

        $result = $this->moduleService->getEditorSpec($identifier);

        if (! $result['success']) {
            return $this->notFound(__('modules.errors.not_found', ['module' => $identifier]));
        }

        $message = $result['spec'] === null
            ? __('templates.messages.editor_spec_empty')
            : __('templates.messages.editor_spec_retrieved');

        return $this->success($message, [
            'identifier' => $identifier,
            'spec' => $result['spec'],
        ]);
    }

    /**
     * 모듈 컴포넌트 정의 파일 서빙 — components.json 반환
     *
     * 편집 모드 부팅 시 ComponentRegistry 가 활성 확장 매니페스트를 네임스페이스
     * 병합하기 위해 fetch 한다. 미생성(구버전 모듈) 시 빈 components 로
     * 폴백한다(무손실 보존 디그레이드).
     *
     * @param  string  $identifier  모듈 식별자
     * @return JsonResponse 컴포넌트 정의 응답
     */
    public function serveComponents(string $identifier): JsonResponse
    {
        $this->logApiUsage('modules.components', ['identifier' => $identifier]);

        $result = $this->moduleService->getComponents($identifier);

        if (! $result['success']) {
            return $this->notFound(__('modules.errors.not_found', ['module' => $identifier]));
        }

        return $this->cachedJsonResponse($result['components'] ?? new \stdClass, 3600);
    }
}
