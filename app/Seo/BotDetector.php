<?php

namespace App\Seo;

use App\Extension\HookManager;
use Illuminate\Http\Request;

/**
 * 검색/링크 프리뷰/AI 봇 감지기.
 *
 * 평가 체인:
 *   1. seo.bot_detection_enabled = false → false
 *   2. _escaped_fragment_ 쿼리 → true (구형 크롤러 호환)
 *   3. UA 빈 문자열 → false
 *   4. core.seo.resolve_is_bot 훅 결과(non-null) → 즉시 결정 (확장 슬롯)
 *   5. seo.bot_detection_library_enabled = true → jaybizzle/crawler-detect
 *      + G7 보강 패턴(미커버 3종) + 운영자 커스텀 패턴
 *   6. 라이브러리 비활성 → 운영자 커스텀 패턴 stripos 매칭만 (레거시 모드)
 *   7. fallthrough → false
 */
class BotDetector
{
    /**
     * 요청이 검색 봇인지 판별합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool 봇 여부
     */
    public function isBot(Request $request): bool
    {
        if (! g7_core_settings('seo.bot_detection_enabled', true)) {
            return false;
        }

        if ($request->has('_escaped_fragment_')) {
            return true;
        }

        $userAgent = $request->userAgent() ?? '';
        if ($userAgent === '') {
            return false;
        }

        $hookResult = HookManager::applyFilters('core.seo.resolve_is_bot', null, [
            'request' => $request,
            'userAgent' => $userAgent,
        ]);
        if ($hookResult !== null) {
            return (bool) $hookResult;
        }

        $libraryEnabled = (bool) g7_core_settings('seo.bot_detection_library_enabled', true);
        $userPatterns = (array) g7_core_settings('seo.bot_user_agents', []);

        if ($libraryEnabled) {
            // 라이브러리 1차: jaybizzle 약 1,000종 + G7 보강 패턴.
            // 라이브러리는 isCrawler() 내부에서 Exclusions 패턴(Firefox/Mozilla/Chrome/Safari 등 일반 브라우저 식별자)
            // 을 UA 에서 strip 한 후 매칭하므로, 운영자가 "Firefox" 등을 봇으로 지정해도 라이브러리 경로로는 잡히지 않음.
            if ((new BotDetectorCustomProvider($userPatterns))->isCrawler($userAgent)) {
                return true;
            }

            // 라이브러리 2차: 운영자 커스텀 패턴은 raw UA 에 stripos 직접 매칭 — Exclusions 우회.
            // 라이브러리와 함께 작동.
        }

        foreach ($userPatterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
