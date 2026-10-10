<?php

namespace App\Http\Controllers\Api\Public;

use App\Extension\Consent\ConsentManager;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Consent\ShowConsentRequest;
use App\Http\Resources\ConsentSnapshotResource;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 동의 스냅샷 공개 조회 API
 *
 * 브라우저의 코어 동의 런타임이 이 응답으로 `_global.consent` 를 채웁니다. 다른 확장은 그 상태를
 * 읽거나 `G7Core.consent` 로 구독하므로, 동의를 관리하는 확장의 경로를 몰라도 됩니다.
 *
 * 운영자가 고른 동의 제공자 하나에게 묻고, 판단할 수 없으면 `unknown` 으로 답합니다(fail-closed).
 * 서버의 다른 판정(방문자 통계 수집 등)도 같은 매니저를 쓰므로 이 응답은 보안 경계가 아닙니다.
 *
 * @since 7.1.0
 */
class ConsentController extends PublicBaseController
{
    /**
     * 동의 스냅샷 컨트롤러를 생성합니다.
     *
     * @param  ConsentManager  $consent  동의 제공자 매니저
     */
    public function __construct(private readonly ConsentManager $consent)
    {
        parent::__construct();
    }

    /**
     * 이 방문자의 동의 스냅샷을 돌려줍니다.
     *
     * 회원이면(Bearer 토큰) 회원 기준, 아니면 선택된 제공자가 요청에서 구한 게스트 식별자 기준입니다.
     *
     * @param  ShowConsentRequest  $request  동의 스냅샷 조회 요청
     * @return JsonResponse 동의 스냅샷 응답
     */
    public function show(ShowConsentRequest $request): JsonResponse
    {
        $snapshot = $this->consent->snapshotFor($this->consent->subjectFor($request));

        return $this->successWithResource('privacy.consent.fetch_success', new ConsentSnapshotResource($snapshot));
    }
}
