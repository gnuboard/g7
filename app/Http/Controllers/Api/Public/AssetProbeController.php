<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\Response;

/**
 * 자산 URL 모드 감지 프로브.
 *
 * 서버(nginx/Apache)의 정적 최적화 블록이 확장자 붙은 동적 응답을 가로채는지
 * 판정하기 위한 대조 엔드포인트다. 브라우저가 아래 두 URL 을 쌍으로 요청한다.
 *
 * ```text
 * GET /api/system/asset-probe.js   → 확장자 형태 (정적 블록의 표적)
 * GET /api/system/asset-probe      → 대조군
 * ```
 *
 * | probe.js | probe | 판정 |
 * |---|---|---|
 * | 성공 | 성공 | `extension` — 확장자 유지 가능 |
 * | 실패 | 성공 | `extensionless` — 정적 블록 가로채기 확정 |
 * | 실패 | 실패 | 모드 문제 아님 (PHP/라우팅 장애) — 별도 안내 |
 *
 * ## 판정은 상태코드가 아니라 본문이다
 *
 * 클라이언트는 `res.ok && body.includes(PROBE_TOKEN)` 로 판정해야 한다.
 * 상태코드만 보면 "404 대신 200 + 에러 HTML" 이나 catch-all 200 페이지를
 * 반환하는 설정에서 영원히 `extension` 으로 오판해, 재감지를 몇 번 눌러도
 * 같은 오답이 나온다.
 *
 * ## 감지는 반드시 브라우저에서 수행한다
 *
 * 서버측에서 자기 `APP_URL` 로 curl 하면 loopback 이 nginx vhost·SSL·프록시
 * 체인을 우회하거나 다른 vhost 를 타서 오판한다.
 *
 * ## 실물 파일을 두지 않는다
 *
 * `public/` 하위에 실제 `asset-probe.js` 파일이 존재하면 nginx 가 그것을
 * 성공적으로 서빙해 거짓 양성이 된다. 본 라우트는 반드시 실물 파일이 없는
 * 경로여야 한다.
 */
class AssetProbeController extends PublicBaseController
{
    /**
     * 프로브 성공 판정용 매직 토큰.
     *
     * 클라이언트·테스트가 응답 본문에서 이 문자열을 찾아 성공을 판정한다.
     */
    public const PROBE_TOKEN = 'G7_ASSET_PROBE_OK';

    /**
     * 프로브 응답을 반환합니다.
     *
     * DB 에 접근하지 않으며(설치 전에도 응답 가능) 캐시되지 않습니다.
     * ResponseHelper 의 JSON 봉투를 쓰지 않는 이유: 이 엔드포인트의 목적은
     * "정적 자산으로 오인될 응답"을 실제로 흉내내는 것이므로, 자바스크립트
     * Content-Type 과 본문 형태를 그대로 유지해야 대표성이 있다.
     *
     * @return Response 매직 토큰을 담은 자바스크립트 응답
     */
    public function probe(): Response
    {
        $body = "/* G7 asset URL mode probe */\n"
            ."window.__g7AssetProbe = '".self::PROBE_TOKEN."';\n";

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
