<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Extension\ServeExtensionIconRequest;
use App\Services\Extension\ExtensionIconResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 공개 확장 아이콘 서빙 컨트롤러
 *
 * 관리자 화면이 `<img src>` 로 부르는데 관리자 API 는 Bearer 토큰 인증이라
 * 이미지 태그가 쓸 수 없다. 그래서 확장 자산 lane 과 같은 성격의 **공개** 라우트다.
 * 미설치 번들 확장도 대상이라 DB 상태·인증을 보지 않는다.
 *
 * 규격 위반 아이콘은 해석기가 null 로 돌려주므로 404 다 — 화면은 유형 글리프로
 * 떨어진다. 규정 원문은 docs/extension/extension-icons.md.
 */
class PublicExtensionIconController extends PublicBaseController
{
    /**
     * 컨트롤러를 생성합니다.
     *
     * @param  ExtensionIconResolver  $iconResolver  확장 아이콘 해석기
     */
    public function __construct(
        private readonly ExtensionIconResolver $iconResolver
    ) {
        parent::__construct();
    }

    /**
     * 확장 아이콘 파일을 서빙합니다.
     *
     * 응답은 `<img>` 전용이다 — 브라우저가 SVG 를 직접 열어도 스크립트가 돌지
     * 않도록 CSP 와 nosniff 를 붙인다(검증기와 이중 방어).
     *
     * @param  ServeExtensionIconRequest  $request  검증된 요청 (유형·식별자 검증 완료)
     * @param  string  $type  확장 유형 (module|plugin|template|language-pack)
     * @param  string  $identifier  확장 식별자
     * @return BinaryFileResponse|JsonResponse|Response 아이콘 파일 응답 또는 404
     */
    public function show(
        ServeExtensionIconRequest $request,
        string $type,
        string $identifier
    ): BinaryFileResponse|JsonResponse|Response {
        $this->logApiUsage('extensions.icon', ['type' => $type, 'identifier' => $identifier]);

        $resolved = $this->iconResolver->resolve($type, $identifier);

        if ($resolved === null) {
            return $this->notFound('extensions.icon.not_found', ['identifier' => $identifier]);
        }

        $response = $this->fileResponse($resolved['path'], $resolved['mime']);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($resolved['mime'] === 'image/svg+xml') {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'"
            );
        }

        return $response;
    }
}
