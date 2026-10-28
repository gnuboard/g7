<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Seo\BotDetector;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Popup\Http\Requests\Public\PublicPopupRequest;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;

/**
 * 공개 팝업 컨트롤러 — 사용자 레이어(`user_global_overlay` 주입 조각)가 부른다.
 *
 * 인증은 선택(`optional.sanctum`) — 토큰이 있으면 회원, 없거나 정지 회원이면 손님으로 노출 대상을 고른다.
 * 검색엔진 봇이면 빈 목록이다(코어 BotDetector 판정을 읽기만 한다).
 */
class PopupController extends PublicBaseController
{
    /**
     * PopupController 생성자
     *
     * @param  PublicPopupService  $popups  공개 노출 서비스
     * @param  BotDetector  $bots  코어 봇 판정기
     */
    public function __construct(
        private PublicPopupService $popups,
        private BotDetector $bots,
    ) {}

    /**
     * 요청자에게 보일 수 있는 팝업 후보와 표시 방식을 반환합니다(현재 요청 언어).
     *
     * @param  PublicPopupRequest  $request  요청
     * @return JsonResponse 공개 응답
     */
    public function index(PublicPopupRequest $request): JsonResponse
    {
        $isBot = (bool) $request->attributes->get('seo.is_bot', false) || $this->bots->isBot($request);

        return $this->success(
            'sirsoft-popup::messages.popup.fetched',
            $this->popups->forRequest($request->user() !== null, app()->getLocale(), $isBot),
        );
    }
}
