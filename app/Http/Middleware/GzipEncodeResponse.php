<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON 응답에 gzip 압축을 적용하는 미들웨어입니다.
 *
 * 웹서버 설정 없이 애플리케이션 레벨에서 압축을 처리하여
 * 오픈소스 환경에서의 호환성을 보장합니다.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Encoding
 */
class GzipEncodeResponse
{
    /**
     * 압축을 적용할 최소 응답 크기 (바이트).
     *
     * 작은 응답은 압축 오버헤드가 더 클 수 있으므로 생략합니다.
     */
    private const MIN_COMPRESS_SIZE = 1024;

    /**
     * gzip 압축 레벨 (1-9).
     *
     * 6은 속도와 압축률의 균형점입니다.
     */
    private const COMPRESSION_LEVEL = 6;

    /**
     * 압축 대상 Content-Type 목록.
     */
    private const COMPRESSIBLE_TYPES = [
        'application/json',
        'text/html',
        'text/plain',
        'text/css',
        'text/javascript',
        'application/javascript',
        'application/xml',
        'text/xml',
    ];

    /**
     * 들어오는 요청을 처리하고 응답을 압축합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 압축 조건 확인
        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        return $this->compressResponse($response);
    }

    /**
     * 응답을 압축해야 하는지 확인합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Response  $response  HTTP 응답
     * @return bool 압축 필요 여부
     */
    private function shouldCompress(Request $request, Response $response): bool
    {
        // PHP zlib 확장 모듈이 활성화되어 있는지 확인
        if (! $this->isGzipAvailable()) {
            return false;
        }

        // 클라이언트가 gzip을 지원하는지 확인
        if (! $this->clientAcceptsGzip($request)) {
            return false;
        }

        // 이미 압축된 응답인지 확인
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        // 304 Not Modified 등 본문이 없는 응답 제외
        if ($response->getStatusCode() === 304) {
            return false;
        }

        // 압축 가능한 Content-Type인지 확인
        if (! $this->isCompressibleContentType($response)) {
            return false;
        }

        // 최소 크기 이상인지 확인
        $content = $response->getContent();
        if ($content === false || strlen($content) < self::MIN_COMPRESS_SIZE) {
            return false;
        }

        return true;
    }

    /**
     * PHP zlib 확장 모듈이 활성화되어 있는지 확인합니다.
     *
     * gzencode 함수가 사용 가능해야 압축을 수행할 수 있습니다.
     *
     * @return bool zlib 모듈 활성화 여부
     */
    private function isGzipAvailable(): bool
    {
        return function_exists('gzencode');
    }

    /**
     * 클라이언트가 gzip 인코딩을 지원하는지 확인합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool gzip 지원 여부
     */
    private function clientAcceptsGzip(Request $request): bool
    {
        $acceptEncoding = $request->header('Accept-Encoding', '');

        return str_contains($acceptEncoding, 'gzip');
    }

    /**
     * 응답의 Content-Type이 압축 가능한지 확인합니다.
     *
     * @param  Response  $response  HTTP 응답
     * @return bool 압축 가능 여부
     */
    private function isCompressibleContentType(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        foreach (self::COMPRESSIBLE_TYPES as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 응답 본문을 gzip으로 압축합니다.
     *
     * @param  Response  $response  HTTP 응답
     * @return Response 압축된 HTTP 응답
     */
    private function compressResponse(Response $response): Response
    {
        $content = $response->getContent();

        // gzip 압축 적용
        $compressed = gzencode($content, self::COMPRESSION_LEVEL);

        if ($compressed === false) {
            // 압축 실패 시 원본 반환
            return $response;
        }

        // 압축된 응답 설정
        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        // Vary 헤더가 없으면 추가 (캐시 프록시 지원)
        if (! $response->headers->has('Vary')) {
            $response->headers->set('Vary', 'Accept-Encoding');
        } elseif (! str_contains($response->headers->get('Vary', ''), 'Accept-Encoding')) {
            $currentVary = $response->headers->get('Vary');
            $response->headers->set('Vary', $currentVary.', Accept-Encoding');
        }

        return $response;
    }
}
