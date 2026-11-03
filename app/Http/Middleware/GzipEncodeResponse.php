<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
     * 파일 응답을 압축할 최대 크기 (바이트).
     *
     * BinaryFileResponse 압축은 파일 **전체를 메모리에 적재**한 뒤 압축본을 한 벌 더 만든다.
     * 서빙 대상이 코어·확장 번들(≤1MB)일 때는 무해했지만, 공개 스토리지 서빙 라우트가
     * 생기면서 대상이 운영자·사용자 업로드물로 넓어졌다 — 압축 대상 MIME(json·txt·css·js·
     * html·xml)의 대용량 파일 하나로 memory_limit 를 넘긴다. 상한을 넘으면 압축을 포기하고
     * 스트리밍 그대로 흘려보낸다 (계획서 §7.6 R35 「신규 서빙 라우트도 스트리밍」).
     */
    private const MAX_FILE_COMPRESS_SIZE = 8 * 1024 * 1024;

    /**
     * gzip 압축 레벨 (1-9).
     *
     * 6은 속도와 압축률의 균형점입니다.
     */
    private const COMPRESSION_LEVEL = 6;

    /**
     * 라우트가 압축 제외를 선언하는 요청 속성 키.
     *
     * 컨트롤러가 `$request->attributes->set(GzipEncodeResponse::SKIP_ATTRIBUTE, true)` 로
     * 선언한다. 응답 헤더가 아니라 요청 속성인 이유는 ① 클라이언트에 새지 않고
     * ② 미들웨어가 특정 라우트 이름을 알 필요가 없기 때문이다.
     */
    public const SKIP_ATTRIBUTE = 'g7.skip_gzip';

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

        // 압축 여부와 무관하게, **압축 대상 유형이면** `Vary: Accept-Encoding` 을 붙인다.
        // 종전에는 실제로 압축한 응답에만 붙어, 같은 URL 이 상한 초과·소용량·미지원
        // 클라이언트에서는 Vary 없이 나갔다 — 공유 캐시가 그 응답을 인코딩 구분 없이
        // 재사용하면 gzip 을 못 읽는 클라이언트에게 압축본이 갈 수 있다 (3차 L3).
        if ($this->isCompressibleContentType($response)) {
            $this->ensureVaryAcceptEncoding($response);
        }

        // 압축 조건 확인
        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        return $this->compressResponse($response);
    }

    /**
     * 해당 MIME 유형이 압축 대상인지 판정합니다 (압축 대상 목록의 단일 출처).
     *
     * 304 처럼 `Content-Type` 이 제거된 응답에서는 미들웨어가 유형을 볼 수 없다. 그 자리의
     * `Vary: Accept-Encoding` 판정이 목록을 사본으로 들면 두 벌이 되므로, 그 판정도 이
     * 함수를 부른다 (`BaseApiController::notModified`).
     *
     * @param  string  $mimeType  Content-Type 값 (파라미터 포함 가능)
     * @return bool 압축 대상이면 true
     */
    public static function isCompressibleMimeType(string $mimeType): bool
    {
        foreach (self::COMPRESSIBLE_TYPES as $type) {
            if (str_contains($mimeType, $type)) {
                return true;
            }
        }

        return false;
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
        // 라우트가 압축을 명시적으로 거부했는가 (H5 · 제품 결정 2026-08-25).
        //
        // 압축 경로는 `BinaryFileResponse` 를 **일반 Response 로 치환하며 파일을 통째로
        // 읽는다.** 인증도 요청 상한도 없는 공개 파일 서빙에서는 그 전체 적재가 동시
        // 요청 수만큼 곱해져 메모리·CPU 증폭 표면이 된다 — 그리고 링크가 되는 서버의
        // 정적 서빙(웹서버·CDN)은 같은 파일을 버퍼 스트리밍으로 압축하거나 아예 하지
        // 않는다. 두 통로가 같아야 한다는 것이 이 저장소의 파일 서빙 규율이므로, 그런
        // 라우트는 이 표식으로 압축에서 빠진다. 표식은 요청 속성이라 응답 헤더로 새지
        // 않고, 라우트 이름을 미들웨어에 적어 넣는 결합도 만들지 않는다.
        if ($request->attributes->get(self::SKIP_ATTRIBUTE) === true) {
            return false;
        }

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

        // HEAD 는 본문이 없어야 한다. BinaryFileResponse 는 `prepare()` 에서 본문 전송을
        // 끄지만(maxlen=0), 아래 압축 경로는 그 응답을 **일반 Response 로 치환**하면서
        // 파일을 통째로 읽어 본문에 싣는다 — 치환된 응답은 prepare() 를 다시 거치지 않고
        // `Response::send()` 에는 HEAD 가드가 없어 그대로 나간다 (3차 G3 라운드 M4).
        if ($request->isMethod('HEAD')) {
            return false;
        }

        // 부분 응답(206)은 압축하지 않는다. 헤더를 승계하면 `Content-Range` 는 부분 범위를
        // 가리키는데 본문은 **파일 전체**의 gzip 이 되어 둘이 정면으로 어긋난다. 미디어
        // 시킹(mp4·webm)과 재개 다운로드가 이 경로를 쓴다.
        if ($response->getStatusCode() === 206 || $response->headers->has('Content-Range')) {
            return false;
        }

        // 압축 가능한 Content-Type인지 확인
        if (! $this->isCompressibleContentType($response)) {
            return false;
        }

        // 최소 크기 이상인지 확인
        // BinaryFileResponse 는 getContent() 가 false 를 반환하므로 파일 크기로 판정한다.
        // (번들 JS/CSS 서빙은 response()->file() 로 BinaryFileResponse 를 반환하며,
        //  이 분기가 없으면 미들웨어가 압축 없이 통과시켜 비압축 전송된다.)
        if ($response instanceof BinaryFileResponse) {
            $file = $response->getFile();
            if ($file === null) {
                return false;
            }

            $size = $file->getSize();

            // 상한 초과는 압축하지 않는다 — 압축 경로가 전체 적재라 대용량 업로드물에서
            // memory_limit 를 넘긴다 (MAX_FILE_COMPRESS_SIZE 주석 · R35).
            //
            // 그 절벽은 **조용하다.** 확장이 늘어 번들이 상한을 넘기는 순간 응답 크기가
            // 몇 배로 뛰는데 헤더에도 로그에도 아무 신호가 없어, 성능 회귀의 원인을
            // 찾을 단서가 남지 않는다. 디버그 모드에서만 한 줄 남긴다(운영에서는 요청마다
            // 기록되어 로그가 불어난다).
            if ($size > self::MAX_FILE_COMPRESS_SIZE && config('app.debug')) {
                Log::debug('[gzip] 상한을 넘어 압축하지 않았습니다 — 응답이 비압축으로 나갑니다', [
                    'size' => $size,
                    'limit' => self::MAX_FILE_COMPRESS_SIZE,
                    'path' => $file->getPathname(),
                ]);
            }

            return $size >= self::MIN_COMPRESS_SIZE && $size <= self::MAX_FILE_COMPRESS_SIZE;
        }

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
        // RFC 9110 §12.5.3 — 코딩마다 가중치(`q`)가 붙고, `q=0` 은 **명시 거부**다. 종전의
        // `str_contains('gzip')` 은 `gzip;q=0` 에도 압축본을 보냈다 — 거부한 클라이언트가
        // gzip 바이트를 받는다. `*` 는 「따로 적지 않은 모든 코딩」이라 gzip 이 명시되지 않은
        // 경우에만 그 가중치를 쓴다 (15차 G3 라운드).
        $acceptEncoding = (string) $request->header('Accept-Encoding', '');

        $gzipWeight = null;
        $wildcardWeight = null;

        foreach (explode(',', $acceptEncoding) as $member) {
            $parts = array_map('trim', explode(';', $member));
            $coding = strtolower((string) array_shift($parts));

            if ($coding === '') {
                continue;
            }

            // qvalue 문법은 RFC 9110 §12.4.2 그대로다 — `0`~`0.999` 또는 `1`~`1.000`. 그 밖의
            // 형태(`q=2`·`q=.5`·`q=1.5`)는 유효한 가중치가 아니므로 파라미터를 무시하고 기본
            // 가중치 1 로 둔다 — 느슨한 정규식은 `q=2` 를 2.0 으로 읽어 「명시 거부인가」의
            // 판정 근거를 규격 밖 값에 두게 된다 (16차 G3 라운드 A13).
            // 한 멤버 안에 `q` 가 여러 번 오면 **가장 낮은 값**을 취한다 — 바깥 두 축과 같은
            // fail-closed 규율이다. 종전 대입은 마지막 것이 이겨 `gzip;q=0;q=1` 을 압축으로
            // 뒤집었다: 명시 거부가 뒤 파라미터로 되살아나는 방향이라, 축 간 비대칭을 없앤
            // 바로 아래 `min()` 들과 한 단계 아래에서 어긋나 있었다. Accept-Encoding 문법에는
            // accept-ext 가 없으므로 두 번째 `q` 는 규격 밖 입력이고, 규격 밖 입력에서 거부를
            // 무시하는 쪽으로 넘어지지 않는다 (23차 G3 라운드 B23-10).
            $weight = null;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/i', $parameter, $matched)) {
                    $weight = min($weight ?? (float) $matched[1], (float) $matched[1]);
                }
            }
            $weight ??= 1.0;

            // `gzip` 과 `x-gzip` 은 같은 코딩의 두 이름이다(§18.6). 두 이름이 따로 오거나 같은
            // 이름이 중복으로 오면 **가장 낮은 가중치**를 취한다 — 어느 이름이든 `q=0` 이면
            // 명시 거부이므로 fail-closed 로 거부한다. 종전 `max()` 는 `gzip;q=0, x-gzip` 을
            // 압축으로 뒤집어, gzip 을 거부한 클라이언트에게 gzip 바이트를 보냈고, 중복
            // (`gzip, gzip;q=0`)에서도 거부를 무시했다 (17차 G3 라운드 B-13·B-14).
            if ($coding === 'gzip' || $coding === 'x-gzip') {
                $gzipWeight = min($gzipWeight ?? $weight, $weight);
            } elseif ($coding === '*') {
                // 와일드카드도 같은 규칙으로 접는다(fail-closed). 종전 대입은 마지막 값이
                // 앞의 명시 거부를 덮어써 `*;q=0, *` 를 압축으로 뒤집었다 — gzip 축만
                // `min()` 이고 이 축만 last-write-wins 인 비대칭이었다 (22차 G3 라운드 B5).
                $wildcardWeight = min($wildcardWeight ?? $weight, $weight);
            }
        }

        $effective = $gzipWeight ?? $wildcardWeight;

        return $effective !== null && $effective > 0;
    }

    /**
     * 응답의 Content-Type이 압축 가능한지 확인합니다.
     *
     * @param  Response  $response  HTTP 응답
     * @return bool 압축 가능 여부
     */
    private function isCompressibleContentType(Response $response): bool
    {
        return self::isCompressibleMimeType($response->headers->get('Content-Type', ''));
    }

    /**
     * 응답 본문을 gzip으로 압축합니다.
     *
     * @param  Response  $response  HTTP 응답
     * @return Response 압축된 HTTP 응답
     */
    private function compressResponse(Response $response): Response
    {
        // php-ext-guard: shouldCompress() 가 isGzipAvailable()(function_exists gzencode) 로 먼저 거른다
        // BinaryFileResponse 는 본문을 setContent() 로 교체할 수 없으므로
        // 파일 내용을 읽어 일반 Response 로 치환한 뒤 압축한다 (헤더는 승계).
        if ($response instanceof BinaryFileResponse) {
            return $this->compressBinaryFileResponse($response);
        }

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

        $this->finalizeEncodedResponse($response);

        return $response;
    }

    /**
     * 압축본이 공통으로 갖춰야 할 표현 축 헤더를 정리합니다.
     *
     * 두 압축 경로(일반 응답 · `BinaryFileResponse` 치환)가 **같은 자리**를 쓴다.
     * 종전에는 `Accept-Ranges` 제거만 파일 응답 경로에 있었다 — 오늘은 무증상이지만
     * (일반 응답 경로에 그 헤더를 세우는 지점이 현재 없다) 비대칭 자체가 「이 축은
     * 파일 응답에만 해당한다」는 오판의 근거가 되고, 어느 한 경로가 그 헤더를 갖게 되는
     * 순간 그 사실이 조용히 결함이 된다. 표현 축 처리는 압축 여부가 정하는 것이지
     * 응답 클래스가 정하는 것이 아니다 (11차 G3 라운드 B11-11).
     *
     * @param  Response  $response  압축을 마친 응답
     * @return void 반환값 없음
     */
    private function finalizeEncodedResponse(Response $response): void
    {
        // 범위 광고는 승계하지 않는다. 그 오프셋은 **identity 표현**의 것이다 — 같은 URL 에
        // `Range` 가 오면 `shouldCompress()` 가 206 을 제외해 identity 로 나가므로, 압축본
        // 길이로 오프셋을 계산한 재개 다운로드·시킹 클라이언트가 다른 표현의 바이트를
        // 이어붙인다. 정적 서빙(nginx `gzip` 필터 · Apache `mod_deflate`)은 압축 시 이
        // 헤더를 제거한다 — 남겨 두면 같은 사이트가 압축 주체에 따라 다른 것을 광고한다.
        $response->headers->remove('Accept-Ranges');

        $this->markEncodedEtag($response);
        $this->ensureVaryAcceptEncoding($response);
    }

    /**
     * BinaryFileResponse 를 gzip 압축된 일반 Response 로 치환합니다.
     *
     * BinaryFileResponse 는 파일 스트림 기반이라 setContent() 로 본문을 교체할 수 없으므로,
     * 파일 내용을 읽어 gzip 압축한 새 Response 를 생성하고 기존 헤더(Content-Type,
     * ETag, Cache-Control, Expires 등)를 승계한다. Content-Encoding/Content-Length 는
     * 압축 결과로 재설정한다.
     *
     * @param  BinaryFileResponse  $response  파일 응답
     * @return Response gzip 압축된 응답 (실패 시 원본 반환)
     */
    private function compressBinaryFileResponse(BinaryFileResponse $response): Response
    {
        // php-ext-guard: shouldCompress() 가 isGzipAvailable()(function_exists gzencode) 로 먼저 거른다
        $file = $response->getFile();

        if ($file === null) {
            return $response;
        }

        $content = @file_get_contents($file->getPathname());

        if ($content === false) {
            // 파일 읽기 실패 시 원본(비압축) 반환
            return $response;
        }

        $compressed = gzencode($content, self::COMPRESSION_LEVEL);

        if ($compressed === false) {
            return $response;
        }

        // 기존 헤더를 승계한 새 Response 생성 (본문만 압축된 내용으로 교체)
        $compressedResponse = new Response(
            $compressed,
            $response->getStatusCode(),
            $response->headers->all()
        );

        $compressedResponse->headers->set('Content-Encoding', 'gzip');
        $compressedResponse->headers->set('Content-Length', (string) strlen($compressed));

        // 표현 축 헤더 정리는 두 압축 경로가 공유하는 단일 지점이 소유한다.
        // 이 경로에서 특히 중요한 이유: `BinaryFileResponse::prepare()` 가 안전 메서드에
        // `Accept-Ranges: bytes` 를 세우고 라우터가 미들웨어보다 먼저 그것을 돌리므로,
        // 위 `headers->all()` 승계가 그 헤더를 압축본에 그대로 싣는다.
        $this->finalizeEncodedResponse($compressedResponse);

        return $compressedResponse;
    }

    /**
     * 압축본의 ETag 에 `-gzip` 접미사를 붙입니다.
     *
     * ETag 는 **표현**을 식별한다 — content-coding 이 다르면 다른 표현이므로 같은 값을
     * 쓰면 안 된다(RFC 9110 §8.8.1). 종전에는 원본(identity) ETag 를 그대로 승계해, 같은
     * URL 이 `Accept-Encoding` 에 따라 다른 본문을 **같은 ETag** 로 내보냈다. 재검증 쪽은
     * `BaseApiController::fileResponse()` 가 이 접미사를 벗겨 비교하므로 304 가 그대로
     * 성립한다 — Apache `mod_deflate` 와 같은 규약이다 (3차 G3 라운드 L3).
     *
     * @param  Response  $response  압축된 응답
     * @return void 반환값 없음
     */
    private function markEncodedEtag(Response $response): void
    {
        $etag = $response->headers->get('ETag');
        if ($etag === null || $etag === '' || str_contains($etag, '-gzip"')) {
            return;
        }

        $response->headers->set('ETag', preg_replace('/"$/', '-gzip"', $etag) ?? $etag);
    }

    /**
     * 응답에 `Vary: Accept-Encoding` 헤더를 보장합니다 (캐시 프록시 지원).
     *
     * @param  Response  $response  HTTP 응답
     * @return void 반환값 없음
     */
    private function ensureVaryAcceptEncoding(Response $response): void
    {
        if (! $response->headers->has('Vary')) {
            $response->headers->set('Vary', 'Accept-Encoding');
        } elseif (! str_contains($response->headers->get('Vary', ''), 'Accept-Encoding')) {
            $currentVary = $response->headers->get('Vary');
            $response->headers->set('Vary', $currentVary.', Accept-Encoding');
        }
    }
}
