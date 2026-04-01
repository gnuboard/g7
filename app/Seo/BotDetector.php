<?php

namespace App\Seo;

use Illuminate\Http\Request;

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
        // 봇 감지 비활성화 시 항상 false
        if (! g7_core_settings('seo.bot_detection_enabled', true)) {
            return false;
        }

        // _escaped_fragment_ 파라미터 지원 (구형 크롤러 호환)
        if ($request->has('_escaped_fragment_')) {
            return true;
        }

        $userAgent = $request->userAgent() ?? '';
        if ($userAgent === '') {
            return false;
        }

        $botPatterns = g7_core_settings('seo.bot_user_agents', []);
        if (empty($botPatterns)) {
            return false;
        }

        foreach ($botPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
