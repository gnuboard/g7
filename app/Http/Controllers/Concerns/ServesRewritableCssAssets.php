<?php

namespace App\Http\Controllers\Concerns;

use App\Support\AssetCssUrlRewriter;
use App\Support\AssetUrl;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 확장 자산 서빙에서 CSS 안의 상대 참조를 절대 자산 URL 로 바꿔 내보냅니다.
 *
 * 배경:
 *   `general.asset_url_mode` 가 `extensionless` 면 자산 URL 이
 *   `/api/templates/assets/{id}?file=vendor%2Fx%2Fa.css` 형태가 된다. 브라우저는 CSS 안의
 *   상대 `url()` 을 스타일시트 URL 의 **디렉토리** 기준으로 푸는데, 이 형태에서는 디렉토리가
 *   `/api/templates/assets/` 라서 `./woff2/f.woff2` 가 엉뚱한 곳을 가리킨다. 확장자 모드나
 *   정적 게시본에서는 경로 형태라 정상 해석되므로, 어긋남은 이 조합에서만 나타난다.
 *
 *   증상은 404 하나뿐이다 — 글꼴은 기본 서체로 대체되고 아이콘은 빈칸이 되며, 서버 로그에는
 *   정상 요청으로 남는다. 그래서 운영자에게는 원인을 특정할 단서가 없다.
 *
 * 모드와 무관하게 항상 치환하는 이유:
 *   확장자 모드에서도 결과 URL 은 브라우저가 상대 해석으로 얻던 것과 같은 주소다. 모드에
 *   따라 치환 여부를 가르면 두 경로가 서로 다른 코드로 갈라져, 정작 깨지는 쪽만 검증에서
 *   빠지기 쉽다. 한 경로로 두고 두 모드를 같은 테스트로 잠근다.
 *
 * @see AssetCssUrlRewriter 치환 규칙(대상·비대상 판정)
 */
trait ServesRewritableCssAssets
{
    /**
     * 확장 자산 응답을 만듭니다 — CSS 면 상대 참조를 치환해 내보냅니다.
     *
     * CSS 가 아니면 종전 `fileResponse()` 와 동일하게 동작합니다.
     *
     * @param  string  $filePath  실제 파일 절대 경로
     * @param  string  $mimeType  MIME 타입
     * @param  string  $extensionType  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $requestedPath  확장 기준 요청 경로 (CSS 상대 참조의 해석 기준)
     * @param  int  $maxAge  캐시 유지 시간 (초)
     * @return BinaryFileResponse|Response 자산 응답 (If-None-Match 일치 시 304)
     */
    protected function rewritableAssetResponse(
        string $filePath,
        string $mimeType,
        string $extensionType,
        string $identifier,
        string $requestedPath,
        int $maxAge = 31536000
    ): BinaryFileResponse|Response {
        if (! $this->isCssAsset($mimeType, $requestedPath)) {
            return $this->fileResponse($filePath, $mimeType, $maxAge);
        }

        $css = @file_get_contents($filePath);

        // 읽기에 실패하면 치환을 포기하고 원본을 그대로 서빙한다 — 치환은 편의 장치이므로
        // 그 실패가 자산 자체를 못 내보내는 사유가 되어서는 안 된다.
        if ($css === false) {
            return $this->fileResponse($filePath, $mimeType, $maxAge);
        }

        // 서브리소스에는 CSS 자신이 받은 캐시 버전을 그대로 승계한다. 버전이 오르면 CSS URL
        // 이 바뀌어 재요청되고, 그 안의 서브리소스 URL 도 같은 버전을 달고 나가므로 두 계층의
        // 무효화 시점이 어긋나지 않는다.
        $version = request()->query('v');
        $version = is_string($version) && $version !== '' ? $version : null;

        $rewritten = AssetCssUrlRewriter::rewrite(
            $css,
            $requestedPath,
            static fn (string $path): string => AssetUrl::extensionApiAsset($extensionType, $identifier, $path, $version)
        );

        // ETag 는 **내보내는 본문** 기준이어야 한다. 파일 stat 기준으로 잡으면 URL 모드가
        // 바뀌어 본문이 달라져도 같은 ETag 가 나와 브라우저가 옛 본문을 계속 쓴다.
        $etag = md5($rewritten);

        if (request()->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        $cacheControl = app()->environment('production')
            ? "public, max-age={$maxAge}, immutable"
            : 'no-cache';

        return response($rewritten, 200, [
            'Content-Type' => $mimeType,
            'Expires' => gmdate('D, d M Y H:i:s', time() + $maxAge).' GMT',
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
        ]);
    }

    /**
     * 이 자산이 CSS 인지 판정합니다.
     *
     * MIME 과 확장자를 함께 본다 — 서빙 계층이 돌려주는 MIME 은 환경에 따라
     * `text/plain` 으로 떨어질 수 있고, 그때 확장자가 유일한 단서다.
     *
     * @param  string  $mimeType  MIME 타입
     * @param  string  $path  확장 기준 요청 경로
     * @return bool CSS 여부
     */
    private function isCssAsset(string $mimeType, string $path): bool
    {
        if (str_contains(strtolower($mimeType), 'css')) {
            return true;
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css';
    }
}
