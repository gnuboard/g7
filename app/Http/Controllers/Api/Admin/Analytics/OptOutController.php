<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\OptOutRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 수집 제외(opt-out) API
 *
 * 운영자가 자기 브라우저를 통계에서 빼는 길입니다. 사이트를 손보는 동안 자기 방문이
 * 인기 페이지 1위가 되는 것을 막습니다.
 *
 * 쿠키는 서버가 심습니다 — 동의 플러그인이 브라우저 쪽 쿠키 쓰기를 가로채므로,
 * 화면에서 직접 심으면 조용히 사라집니다. 다만 사용자 화면의 트래커가 이 값을 읽어야
 * 하므로 스크립트가 못 읽는 쿠키로 만들지는 않습니다.
 */
class OptOutController extends AdminBaseController
{
    /**
     * 수집 제외 쿠키 이름
     */
    private const COOKIE_NAME = 'g7_analytics_optout';

    /**
     * 수집 제외 쿠키 수명 (분)
     */
    private const COOKIE_MINUTES = 525600;

    /**
     * 지금 이 브라우저가 제외 상태인지 조회합니다.
     *
     * @param  OptOutRequest  $request  현재 요청
     * @return JsonResponse 제외 상태 응답
     */
    public function show(OptOutRequest $request): JsonResponse
    {
        return $this->success('analytics.opt_out.fetch_success', [
            'opted_out' => $request->cookie(self::COOKIE_NAME) === '1',
        ]);
    }

    /**
     * 이 브라우저를 수집에서 뺍니다.
     *
     * @param  OptOutRequest  $request  현재 요청
     * @return JsonResponse 제외 응답
     */
    public function store(OptOutRequest $request): JsonResponse
    {
        HookManager::doAction('core.analytics.opt_out_changed', ['opted_out' => true]);

        return $this->success('analytics.opt_out.enabled', ['opted_out' => true])
            ->withCookie($this->cookie($request, '1'));
    }

    /**
     * 이 브라우저의 수집 제외를 해제합니다.
     *
     * @param  OptOutRequest  $request  현재 요청
     * @return JsonResponse 해제 응답
     */
    public function destroy(OptOutRequest $request): JsonResponse
    {
        HookManager::doAction('core.analytics.opt_out_changed', ['opted_out' => false]);

        // 수명을 0 으로 주면 「브라우저를 닫을 때까지」인 세션 쿠키가 된다 — 지우라는
        // 뜻이 되려면 만료 시각이 과거여야 한다.
        return $this->success('analytics.opt_out.disabled', ['opted_out' => false])
            ->withCookie($this->cookie($request, '', -self::COOKIE_MINUTES));
    }

    /**
     * 수집 제외 쿠키를 만듭니다.
     *
     * @param  OptOutRequest  $request  현재 요청 (보안 연결 여부 판정)
     * @param  string  $value  쿠키 값
     * @param  int|null  $minutes  수명 (분, null 이면 기본 수명)
     * @return Cookie 쿠키
     */
    private function cookie(OptOutRequest $request, string $value, ?int $minutes = null): Cookie
    {
        // httpOnly 를 끈다 — 사용자 화면의 트래커가 이 값을 읽어 보내기 전에 스스로
        // 멈춰야 하기 때문이다. 담긴 값은 「제외됨」이라는 사실 하나뿐이다.
        return cookie(
            self::COOKIE_NAME,
            $value,
            $minutes ?? self::COOKIE_MINUTES,
            '/',
            null,
            $request->isSecure(),
            false,
            false,
            'Lax',
        );
    }
}
