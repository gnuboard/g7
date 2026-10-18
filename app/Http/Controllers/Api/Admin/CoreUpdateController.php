<?php

namespace App\Http\Controllers\Api\Admin;

use App\Extension\Custom\CustomUpdatePreflight;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Extension\ChangelogRequest;
use App\Services\CoreUpdateService;
use Illuminate\Http\JsonResponse;

class CoreUpdateController extends AdminBaseController
{
    /**
     * CoreUpdateController 생성자.
     *
     * @param  CoreUpdateService  $coreUpdateService  코어 업데이트 서비스
     */
    public function __construct(private CoreUpdateService $coreUpdateService)
    {
        parent::__construct();
    }

    /**
     * 코어 업데이트 가능 여부를 확인합니다.
     *
     * @return JsonResponse 확인 결과 (직전 custom 자리 사전 검사 중단 기록 포함), 확인 실패 시 422
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

        // 직전 업데이트 시도가 개발자 custom 자리 사전 검사로 멈췄으면(#792) 같은 새 버전에 한해 그 결과를 싣는다 —
        // 확인 결과 창이 「업데이트를 시작하지 않았습니다」 표를 보여 준다. 업데이트는 CLI 가 하므로 기록으로 전달한다.
        $record = CustomUpdatePreflight::lastRecord();
        $result['custom_preflight'] = ($record !== null && ($record['to_version'] ?? null) === ($result['latest_version'] ?? null))
            ? $record
            : null;

        return $this->success(
            'settings.core_update.check_success',
            $result
        );
    }

    /**
     * 코어 변경사항을 조회합니다.
     *
     * @param  ChangelogRequest  $request  검증된 요청
     * @return JsonResponse 변경사항 응답
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
