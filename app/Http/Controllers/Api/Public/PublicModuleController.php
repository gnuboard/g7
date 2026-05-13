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
     * @return BinaryFileResponse|JsonResponse|Response
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
}
