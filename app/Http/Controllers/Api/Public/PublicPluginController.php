<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Plugin\ServePluginAssetRequest;
use App\Services\PluginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 공개 플러그인 API 컨트롤러
 *
 * 플러그인 에셋 서빙을 담당합니다.
 */
class PublicPluginController extends PublicBaseController
{
    public function __construct(
        private readonly PluginService $pluginService
    ) {
        parent::__construct();
    }

    /**
     * 플러그인 에셋 서빙
     *
     * @param  ServePluginAssetRequest  $request  검증된 요청 (경로, 확장자 검증 완료)
     * @param  string  $identifier  플러그인 식별자 (vendor-plugin 형식)
     * @param  string  $path  에셋 경로 (dist/js/plugin.iife.js 등)
     * @return BinaryFileResponse|JsonResponse|Response
     */
    public function serveAsset(
        ServePluginAssetRequest $request,
        string $identifier,
        string $path
    ): BinaryFileResponse|JsonResponse|Response {
        // FormRequest에서 이미 보안 검증 완료
        // API 사용량 기록
        $this->logApiUsage('plugins.assets', ['identifier' => $identifier, 'path' => $path]);

        // Service에서 파일 경로 조회 (검증은 FormRequest에서 완료됨)
        $result = $this->pluginService->getAssetFilePath($identifier, $path);

        // 에러 처리
        if (! $result['success']) {
            return match ($result['error']) {
                'plugin_not_found' => $this->notFound(__('plugins.errors.not_found', ['plugin' => $identifier])),
                'file_not_found' => $this->notFound(__('plugins.errors.file_not_found')),
                'file_type_not_allowed' => $this->forbidden(__('plugins.errors.file_type_not_allowed')),
                default => $this->error(__('plugins.errors.unknown_error'), 500),
            };
        }

        // 파일 반환 (ETag 및 환경별 캐싱 헤더 포함, 1년 캐시)
        return $this->fileResponse($result['filePath'], $result['mimeType'], 31536000);
    }
}
