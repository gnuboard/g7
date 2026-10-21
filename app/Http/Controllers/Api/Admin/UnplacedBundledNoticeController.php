<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Extension\DismissUnplacedBundledRequest;
use App\Http\Requests\Extension\UnplacedBundledListRequest;
use App\Http\Resources\UnplacedBundledNoticeResource;
use App\Services\UnplacedBundledNoticeService;
use Illuminate\Http\JsonResponse;

/**
 * 미배치 공식 번들 안내 조회·닫기 엔드포인트 (공개 #146).
 *
 * 코어 업데이트가 배치하지 않은 공식 번들을 관리자 목록 화면(모듈·플러그인·템플릿·언어팩)에 안내하고,
 * 관리자별로 닫을 수 있게 한다. 조회는 네 종류 중 하나의 조회 권한이면 열리고, 권한 없는 종류는 비운다.
 */
class UnplacedBundledNoticeController extends AdminBaseController
{
    /**
     * @param  UnplacedBundledNoticeService  $noticeService  안내 데이터·닫기 서비스
     */
    public function __construct(
        private readonly UnplacedBundledNoticeService $noticeService,
    ) {
        parent::__construct();
    }

    /**
     * 미배치 공식 번들 안내를 유형별로 조회합니다.
     *
     * @param  UnplacedBundledListRequest  $request  요청
     * @return JsonResponse 안내 데이터 (items·hash·core_version·command·commands)
     */
    public function index(UnplacedBundledListRequest $request): JsonResponse
    {
        $user = $request->user();
        $allowed = [];
        foreach (UnplacedBundledNoticeService::TYPE_KEYS as $type => $plural) {
            if (PermissionHelper::check("core.{$plural}.read", $user)) {
                $allowed[] = $type;
            }
        }

        $notice = $this->noticeService->noticeFor($user?->id, $allowed);

        return ResponseHelper::success(
            'extensions.alerts.unplaced_bundled_listed',
            (new UnplacedBundledNoticeResource($notice))->toArray($request),
        );
    }

    /**
     * 한 유형의 미배치 공식 번들 안내를 현재 사용자에 한해 닫습니다.
     *
     * @param  DismissUnplacedBundledRequest  $request  요청
     * @param  string  $type  유형 (module|plugin|template|language_pack)
     * @return JsonResponse 닫기 결과 (type·hash)
     */
    public function dismiss(DismissUnplacedBundledRequest $request, string $type): JsonResponse
    {
        $hash = $this->noticeService->dismiss($request->user()?->id, $type);

        return ResponseHelper::success('extensions.alerts.unplaced_bundled_dismissed', [
            'type' => $type,
            'hash' => $hash,
        ]);
    }
}
