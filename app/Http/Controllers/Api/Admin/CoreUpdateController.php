<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Services\CoreUpdateService;
use Illuminate\Http\JsonResponse;

class CoreUpdateController extends AdminBaseController
{
    /**
     * CoreUpdateController 생성자.
     *
     * @param CoreUpdateService $coreUpdateService 코어 업데이트 서비스
     */
    public function __construct(private CoreUpdateService $coreUpdateService)
    {
        parent::__construct();
    }

    /**
     * 코어 업데이트 가능 여부를 확인합니다.
     *
     * @return JsonResponse
     */
    public function checkForUpdates(): JsonResponse
    {
        $result = $this->coreUpdateService->checkForUpdates();

        if (! empty($result['check_failed'])) {
            return $this->error(
                'settings.core_update.check_failed',
                422,
                [
                    'reason' => $result['error'] ?? '',
                    'current_version' => $result['current_version'],
                    'github_url' => $result['github_url'],
                ]
            );
        }

        return $this->success(
            'settings.core_update.check_success',
            $result
        );
    }

    /**
     * 코어 변경사항을 조회합니다.
     *
     * @param ChangelogRequest $request 검증된 요청
     * @return JsonResponse
     */
    public function changelog(ChangelogRequest $request): JsonResponse
    {
        $changelog = $this->coreUpdateService->getChangelog(
            $request->validated('from_version'),
            $request->validated('to_version')
        );

        return $this->success(
            'settings.fetch_success',
            ['changelog' => $changelog]
        );
    }
}
