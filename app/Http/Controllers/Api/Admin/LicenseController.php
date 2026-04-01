<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;

/**
 * 라이선스 컨트롤러
 *
 * 코어 및 확장의 라이선스 정보를 제공합니다.
 */
class LicenseController extends AdminBaseController
{
    public function __construct(
        private readonly LicenseService $licenseService
    ) {
        parent::__construct();
    }

    /**
     * 코어 라이선스 파일 내용을 반환합니다.
     *
     * @return JsonResponse
     */
    public function core(): JsonResponse
    {
        $content = $this->licenseService->getCoreLicense();

        if ($content === null) {
            return $this->error('common.not_found', 404);
        }

        return $this->success('common.success', [
            'content' => $content,
        ]);
    }

    /**
     * 코어 CHANGELOG.md 파일 내용을 반환합니다.
     *
     * @return JsonResponse
     */
    public function changelog(): JsonResponse
    {
        $content = $this->licenseService->getCoreChangelog();

        if ($content === null) {
            return $this->error('common.not_found', 404);
        }

        return $this->success('common.success', [
            'content' => $content,
        ]);
    }
}
