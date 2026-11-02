<?php

// audit:allow api-doc-coverage 609 후속 — 메서드 PHPDoc(목적 설명, @param, @return)만 더했다. 라우트, 요청, 응답 모양은 그대로라 API 문서에 반영할 표면이 없다.

namespace App\Http\Controllers\Api\Base;

use App\Extension\Support\LifecycleWarnings;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\FileServingMeta;
use App\Http\Responses\NotModifiedResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API 컨트롤러의 최상위 베이스 클래스
 *
 * 모든 API 컨트롤러가 공통으로 사용하는 기능을 제공합니다.
 * Admin, Auth, Public 컨트롤러는 이 클래스를 상속받습니다.
 */
abstract class BaseApiController extends Controller
{
    /**
     * 유형을 알 수 없는 파일의 Content-Type — 정적 서빙이 미지 확장자에 주는 값과 같다.
     *
     * 두 파일 응답 헬퍼가 공유한다. 알 수 없는 것을 내용으로 추정하면(스니핑) HTML 형태
     * 업로드물이 문서로 실행되므로, 알 수 없으면 **내려받기**로 준다.
     */
    public const UNKNOWN_MIME_TYPE = 'application/octet-stream';

    /**
     * 성공 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  mixed  $data  응답 데이터
     * @param  int  $statusCode  HTTP 상태 코드
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function success(
        string $messageKey = 'common.success',
        mixed $data = null,
        int $statusCode = 200,
        array $messageParams = []
    ) {
        return $this->withLifecycleWarnings(ResponseHelper::success($messageKey, $data, $statusCode, $messageParams, 'core'));
    }

    /**
     * 실패 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  int  $statusCode  HTTP 상태 코드
     * @param  mixed  $errors  오류 정보
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function error(
        string $messageKey = 'common.failed',
        int $statusCode = 400,
        mixed $errors = null,
        array $messageParams = []
    ) {
        return ResponseHelper::error($messageKey, $statusCode, $errors, $messageParams, 'core');
    }

    /**
     * 리소스와 함께 성공 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  mixed  $resource  JSON 리소스
     * @param  int  $statusCode  HTTP 상태 코드
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function successWithResource(
        string $messageKey = 'common.success',
        mixed $resource = null,
        int $statusCode = 200,
        array $messageParams = []
    ) {
        return $this->withLifecycleWarnings(ResponseHelper::successWithResource($messageKey, $resource, $statusCode, $messageParams, 'core'));
    }

    /**
     * 이 요청에서 확장 생명주기 작업이 남긴 부분 실패 경고를 `data.warnings` 로 싣습니다 (7.1.3).
     *
     * 설치·활성화·업데이트·레이아웃 갱신은 항목 하나가 실패해도 성공으로 끝난다. 그 실패를 화면이 노란 경고
     * 결과로 보이도록 응답에 목록을 붙인다. 경고가 없으면 응답을 그대로 둔다(기존 키·모양 하위 호환).
     *
     * @param  JsonResponse  $response  성공 응답
     * @return JsonResponse 경고를 실은 응답
     */
    private function withLifecycleWarnings(JsonResponse $response): JsonResponse
    {
        $warnings = LifecycleWarnings::current();
        if ($warnings->isEmpty()) {
            return $response;
        }

        // 객체(stdClass)로 풀어 고친다 — 연관 배열로 풀면 빈 객체 `{}` 가 다시 쓸 때 `[]` 로 바뀐다(#758 같은 결함군, #822)
        $payload = $response->getData();
        if (! is_object($payload)) {
            return $response;
        }

        $data = $payload->data ?? null;
        if ($data === null || $data === []) {
            $data = new \stdClass;
        }

        if (! is_object($data)) {
            // 목록 응답은 모양을 바꾸지 않는다 — 생명주기 응답은 객체다
            return $response;
        }

        $data->warnings = $warnings->all();
        $payload->data = $data;

        return $response->setData($payload);
    }

    /**
     * 현재 인증된 사용자를 반환합니다.
     *
     * @return User|null
     */
    protected function getCurrentUser()
    {
        return Auth::user();
    }

    /**
     * Not Found 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function notFound(
        string $messageKey = 'common.not_found',
        array $messageParams = []
    ) {
        return $this->error($messageKey, 404, null, $messageParams);
    }

    /**
     * Unauthorized 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function unauthorized(
        string $messageKey = 'common.unauthorized',
        array $messageParams = []
    ) {
        return $this->error($messageKey, 401, null, $messageParams);
    }

    /**
     * Forbidden 응답을 생성합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function forbidden(
        string $messageKey = 'common.forbidden',
        array $messageParams = []
    ) {
        return $this->error($messageKey, 403, null, $messageParams);
    }

    /**
     * Validation Error 응답을 생성합니다.
     *
     * @param  mixed  $errors  검증 오류
     * @param  string  $messageKey  메시지 키
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse
     */
    protected function validationError(
        mixed $errors,
        string $messageKey = 'common.validation_failed',
        array $messageParams = []
    ) {
        return $this->error($messageKey, 422, $errors, $messageParams);
    }

    /**
     * 파일 응답을 반환합니다 (ETag 및 캐싱 헤더 포함).
     *
     * @param  string  $filePath  파일 경로
     * @param  string  $mimeType  MIME 타입
     * @param  int  $maxAge  캐시 유지 시간 (초, 기본: 1년)
     * @param  bool|null  $immutable  URL 이 내용을 고유하게 가리키는가.
     *                                `null`(기본)이면 **요청에서 판정한다** — `?v=` 가 실려 있으면 true.
     *                                `immutable` 은 「만료 전까지 재확인하지 말라」는 지시라, 버전이 없는
     *                                URL 에 붙이면 교체된 파일이 방문자 화면에 만료 시간(기본 1년)만큼
     *                                옛것으로 남는다. 종전에는 기본값이 무조건 `true` 였는데 확장 자산
     *                                생성기(`AssetUrl::{module,plugin,template}Asset()`)의 `$version` 은
     *                                기본이 `null` 이고 코어 호출부 다수가 버전을 넘기지 않아, **버전 없는
     *                                URL 이 immutable 로 나가고 있었다** — 확장을 업데이트해도 방문자
     *                                브라우저가 옛 파일을 계속 썼다 (11차 G3 라운드).
     *                                명시 `true`/`false` 는 그대로 존중한다(공개 스토리지는 `false` 고정).
     * @param  bool  $skipCompression  이 응답을 압축 미들웨어에서 제외할지 — 형제 `streamedFileResponse()`
     *                                 와 같은 인자. 종전에는 이 헬퍼에만 없어 공개 스토리지 컨트롤러가
     *                                 요청 속성을 **직접** 세웠다. 선언 수단이 통로마다 갈리면 한쪽만
     *                                 바뀌므로 두 헬퍼가 같은 인자를 갖는다 (15차 G3 라운드)
     * @return BinaryFileResponse|Response 파일 응답 또는 304 응답
     */
    protected function fileResponse(string $filePath, string $mimeType, int $maxAge = 31536000, ?bool $immutable = null, bool $skipCompression = false): BinaryFileResponse|Response
    {
        $immutable ??= $this->requestCarriesAssetVersion();

        // 형제 `streamedFileResponse()` 와 같은 규칙 — 유형을 알 수 없으면 내려받기다.
        // 빈 `Content-Type` 을 그대로 내보내면 브라우저가 내용으로 판정한다(스니핑).
        if (trim($mimeType) === '') {
            $mimeType = self::UNKNOWN_MIME_TYPE;
        }

        // 압축 제외는 **선언**으로 한다 — 판정보다 앞에 두어 응답 종류(200/304)와 무관하게 선다.
        if ($skipCompression) {
            request()->attributes->set(GzipEncodeResponse::SKIP_ATTRIBUTE, true);
        }

        // ETag 생성 (파일 수정 시간 + 파일 크기 기반)
        //
        // 값은 반드시 큰따옴표로 감싼다 — RFC 9110 §8.8.3 의 `entity-tag` 문법이 그렇고,
        // 정적 서빙(Apache·nginx)도 항상 따옴표를 붙인다. 따옴표 없는 값은 규격상 유효한
        // entity-tag 가 아니라 중간 프록시·CDN 이 그 헤더를 버리거나 다시 쓸 수 있다.
        // 판정과 서빙 사이에 파일이 사라질 수 있다 — 방문자가 자산을 요청한 사이에 관리
        // 화면이 그 파일을 지우는 흐름이 실재한다. 가드 없이 부르면 `stat failed` 경고가
        // `ErrorException` 으로 승격되어 **무인증 표면이 500 + 스택 로그**가 된다. 링크가
        // 되는 서버의 정적 서빙은 같은 경합에서 404 를 주므로 그쪽에 맞춘다.
        $modifiedAt = @filemtime($filePath);
        $size = @filesize($filePath);

        if ($modifiedAt === false || $size === false) {
            abort(404);
        }

        // 실재하지만 읽을 수 없는 파일은 **403** 이다. 그대로 두면 `BinaryFileResponse` 가
        // `File must be readable` 로 던져 무인증 표면이 500 + 스택 로그가 된다 — 링크가 되는
        // 서버의 정적 서빙은 같은 파일에 403 을 준다. 공개 스토리지 통로는 서비스가 먼저
        // 판정해 같은 코드로 돌려주지만(`PublicStorageService::isReadable()`), 나머지 세
        // 통로(template·module·plugin 자산)는 이 자리가 유일한 방어선이다 (15차 G3 라운드).
        if (! is_readable($filePath)) {
            abort(403);
        }

        $etag = '"'.md5($modifiedAt.$size).'"';
        $lastModified = gmdate('D, d M Y H:i:s', $modifiedAt).' GMT';

        // 조건부 요청 처리 — If-None-Match 가 있으면 그것이 우선이고(RFC 9110 §13.1.2),
        // 없을 때만 If-Modified-Since 를 본다. 종전에는 `Last-Modified` 를 내보내면서
        // 그 조건부 요청은 무시해, ETag 를 보내지 않는 프록시가 매번 전문을 받아 갔다 —
        // 링크가 되는 서버의 정적 서빙에는 없던 성질이다 (3차 G3 라운드 L1).
        // 환경별 캐싱 정책 — 200 과 304 가 **같은** 정책을 실어야 한다. 프레임워크 기본
        // 304 는 `private, must-revalidate` 라, 200 이 `public` 인 자산이 재검증 한 번으로
        // 비공유로 격하된다(공유 캐시·CDN 이 항목을 버리거나 재사용하지 않는다). 정적
        // 서빙은 304 에도 200 과 같은 `Cache-Control` 을 재전송한다.
        $productionPolicy = $immutable
            ? "public, max-age={$maxAge}, immutable"          // 버전이 URL 에 담긴 자산
            : "public, max-age={$maxAge}, must-revalidate";   // 같은 URL 의 내용이 교체되는 자산
        $cacheControl = app()->environment('production')
            ? $productionPolicy
            : 'no-cache';  // 개발: 캐싱 비활성화

        $ifNoneMatch = request()->header('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            if ($this->etagMatches($ifNoneMatch, $etag)) {
                return $this->notModified(
                    $this->negotiatedEtag($ifNoneMatch, $etag),
                    $lastModified,
                    $cacheControl,
                    $maxAge,
                    $mimeType
                );
            }
        } elseif (($ifModifiedSince = request()->header('If-Modified-Since')) !== null) {
            $since = strtotime((string) $ifModifiedSince);
            if ($since !== false && $modifiedAt <= $since) {
                return $this->notModified($etag, $lastModified, $cacheControl, $maxAge, $mimeType);
            }
        }

        $response = response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Expires' => gmdate('D, d M Y H:i:s', time() + $maxAge).' GMT',
            'ETag' => $etag,
        ]);

        // Cache-Control 헤더를 수동으로 설정 (기본 헤더 덮어쓰기)
        $response->headers->set('Cache-Control', $cacheControl);
        $this->declareNoSniff($response);

        // 파일 이름을 선언한다 — **네 통로가 공유하는 이 자리**가 붙인다.
        //
        // 무확장자 자산 URL 모드에서는 URL 의 마지막 세그먼트가 `{identifier}` 또는
        // `public-storage` 라, 이름 선언이 없으면 내려받기가 그 이름으로 저장된다. 같은
        // 파일이 자산 URL 모드에 따라 다른 이름을 갖고, 링크가 되는 서버의 정적 서빙과도
        // 어긋난다 — 이름은 서버가 정하므로 두 모드가 갈릴 이유가 없다. 6차에 공개
        // 스토리지 통로에만 들어가 있었고 형제 셋(template·module·plugin)은 빠져 있었다
        // (7차 G3 라운드 C2). 호출부로 내리면 다시 통로마다 갈리므로 여기서 붙인다.
        //
        // `inline` 으로 둔다 — 이 통로는 이미지·CSS 를 화면에 그리는 자리이고 정적 서빙도
        // 첨부를 강제하지 않는다. `attachment` 로 바꾸면 그 화면들이 전부 다운로드가 된다.
        //
        // 이름에서 경로 구분자를 지운다. `HeaderUtils::makeDisposition()` 은 filename 에
        // `/` 나 `\` 가 있으면 예외를 던지는데, POSIX 의 `basename()` 은 백슬래시를
        // 구분자로 보지 않으므로 `a\b.png` 같은 정상 파일 이름이 그대로 넘어가 **무인증
        // 표면이 500** 이 된다(Windows 개발 환경에서는 `basename()` 이 먼저 벗겨
        // 재현되지 않는다). 이름 선언은 편의이지 계약이 아니므로, 예외를 던지느니
        // 이름을 다듬는다.
        $filename = str_replace(['\\', '/'], '_', basename($filePath));

        if ($filename !== '') {
            $response->setContentDisposition(HeaderUtils::DISPOSITION_INLINE, $filename);
        }

        return $response;
    }

    /**
     * 304 Not Modified 응답을 만듭니다.
     *
     * 검증자(ETag·Last-Modified)를 함께 싣는다 — 304 는 캐시 항목을 갱신하는 응답이므로
     * 검증자가 빠지면 클라이언트가 다음 요청에서 조건부 GET 을 걸 근거를 잃는다.
     *
     * 그리고 200 과 **같은 캐싱 정책**을 실어야 한다. RFC 9110 §15.4.5 는 200 에 실렸을
     * `Cache-Control`·`Expires` 를 304 에도 보내라고 요구하는데, 싣지 않으면 프레임워크
     * 기본값 `private, must-revalidate` 가 나간다 — `public` 이던 자산이 재검증 한 번으로
     * 비공유로 격하되어 공유 캐시·CDN 이 항목을 버린다.
     *
     * `Last-Modified` 는 한 겹 더 필요하다. Laravel 라우터는 304 응답에
     * `Response::setNotModified()` 를 호출하고 그것이 `Last-Modified` 를 **제거**한다 —
     * 여기서 헤더를 붙여도 응답이 나갈 때는 사라져 있었다(즉 종전 구현은 죽은 코드였다).
     * 정적 서빙은 304 에도 그 헤더를 재전송하므로, 링크 유무로 응답 형태가 갈리지 않도록
     * 라우터가 벗기고 난 뒤 다시 붙이는 응답 객체를 쓴다.
     *
     * `Expires` 와 `Vary` 도 같은 이유로 함께 싣는다. 전자는 §15.4.5 가 명시한 목록에 있고,
     * 후자가 빠지면 공유 캐시가 **어떤 축으로 갈린 표현인지 모른 채** 항목을 갱신한다 —
     * 압축본에는 `-gzip` 접미사가 붙어 표현이 갈리므로(`GzipEncodeResponse`) 그 축을 잃으면
     * 인코딩이 다른 표현이 하나의 재검증으로 재사용될 여지가 생긴다. 다만 `Vary` 는 200 이
     * 실었을 때만 싣는다 — 압축 대상이 아닌 유형(이미지 등)의 200 에는 그 헤더가 없고,
     * 304 에만 붙이면 반대 방향의 비대칭이 된다. 압축 대상 판정은 미들웨어가 소유한 목록
     * 단일 출처(`GzipEncodeResponse::isCompressibleMimeType`)를 그대로 부른다.
     *
     * @param  string  $etag  따옴표를 포함한 entity-tag
     * @param  string  $lastModified  RFC 7231 형식의 GMT 시각
     * @param  string  $cacheControl  200 응답과 동일한 Cache-Control 값
     * @param  int  $maxAge  200 응답과 동일한 만료 기간(초)
     * @param  string  $mimeType  200 응답의 Content-Type (Vary 축 판정용)
     * @return Response 304 응답
     */
    private function notModified(
        string $etag,
        string $lastModified,
        string $cacheControl,
        int $maxAge,
        string $mimeType
    ): Response {
        $response = new NotModifiedResponse('', 304);

        $response->headers->set('ETag', $etag);
        $response->headers->set('Last-Modified', $lastModified);
        $response->headers->set('Cache-Control', $cacheControl);
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge).' GMT');
        $this->declareNoSniff($response);

        if (GzipEncodeResponse::isCompressibleMimeType($mimeType)) {
            $response->headers->set('Vary', 'Accept-Encoding');
        }

        return $response;
    }

    /**
     * `X-Content-Type-Options: nosniff` 를 붙입니다 — 파일 서빙 응답 **전부**가 지나는 자리.
     *
     * 이 통로들은 사용자가 올린 파일(SVG·HTML 형태 업로드물 포함)을 앱 오리진에서 내보낸다.
     * 브라우저가 선언 유형을 무시하고 내용으로 판정하면 이미지로 올린 것이 문서로 실행된다.
     * 링크가 되는 서버의 정적 서빙 설정은 이 헤더를 붙이므로, 이 통로만 빠지면 링크 유무로
     * 위험이 갈린다. 200 과 304 가 같은 자리를 지난다 — 304 는 200 이 실었을 헤더를 함께
     * 싣는다(RFC 9110 §15.4.5). 호출부로 내리면 열 통로 중 하나가 반드시 빠진다 (15차 G3 라운드).
     *
     * @param  Response|BinaryFileResponse|StreamedResponse  $response  파일 서빙 응답 (200 또는 304)
     * @return void 반환값 없음
     */
    protected function declareNoSniff(Response|BinaryFileResponse|StreamedResponse $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }

    /**
     * 이 요청의 URL 이 자산 버전(`?v=`)을 싣고 있는지 판정합니다.
     *
     * `immutable` 을 붙여도 되는지의 **유일한 근거**다. 붙인다는 것은 「만료 전까지 서버에
     * 다시 묻지 말라」는 뜻이라, 내용이 교체될 수 있는 주소에 붙이면 그 교체가 방문자에게
     * 만료 시간(기본 1년) 동안 도달하지 않는다. 오류도 경고도 남지 않고 「업데이트했는데
     * 화면이 그대로」로만 나타난다.
     *
     * 판정을 호출부가 각자 하지 않고 여기 한 곳에 두는 이유: 종전에는 두 파일 응답 헬퍼의
     * 기본값이 무조건 `true` 였고, 「`?v=` 가 붙으니 괜찮다」는 **가정**이 독블록에만 있었다.
     * 실제로는 확장 자산 생성기의 `$version` 이 기본 `null` 이고 코어 호출부 다수가 버전을
     * 넘기지 않아 가정이 성립하지 않았다 (11차 G3 라운드).
     *
     * 파생 근거는 **요청자가 통제하는 값**이다 — `?v=` 는 서버가 발급한 버전이 아니라
     * URL 에 실려 온 문자열이라, 누구든 아무 값이나 붙여 보낼 수 있다. 그것으로 얻는 것은
     * 「자기 브라우저가 그 응답을 1년간 재확인하지 않는다」뿐이고, 공유 캐시는 그 URL
     * (쿼리 포함)을 별개 항목으로 저장하므로 다른 방문자의 정상 URL 항목은 건드리지
     * 않는다. 즉 요청자가 자기 자신에게만 stale 을 허용하는 형태라 서버측 위험은 없다.
     * 그럼에도 **공개 스토리지**(`PublicStorageController`)는 이 파생에서 제외되어
     * `immutable: false` 고정이다 — 그 통로는 같은 경로의 내용이 교체되는 사용자 업로드물이라
     * 「버전이 붙었으니 불변」이라는 추정 자체가 성립하지 않는다. 같은 성질의 통로(첨부·
     * 확장 이미지 서빙)도 명시 `false` 를 넘긴다. 이 파생은 서버가 버전을 발급하는 자산
     * URL(확장 번들·템플릿 자산)에만 의미가 있다 (14차 G3 라운드).
     *
     * @return bool `?v=` 가 비어 있지 않게 실려 있으면 true
     */
    private function requestCarriesAssetVersion(): bool
    {
        $version = request()->query('v');

        return is_scalar($version) && (string) $version !== '';
    }

    /**
     * `If-None-Match` 헤더가 현재 entity-tag 와 일치하는지 판정합니다.
     *
     * 헤더는 콤마로 구분된 여러 값일 수 있고(`*` 포함), 약한 검증자(`W/`) 접두사가 붙을 수
     * 있다. 그리고 gzip 미들웨어가 압축본을 내보낼 때 표현이 달라졌음을 알리려 `-gzip`
     * 접미사를 붙이므로(RFC 9110 §8.8.1 — content-coding 이 다르면 다른 표현이다), 그
     * 접미사를 단 값도 같은 파일로 인정해야 압축 클라이언트의 재검증이 304 로 끝난다.
     * Apache `mod_deflate` 가 쓰는 규약과 같다.
     *
     * @param  string  $header  If-None-Match 헤더 원문
     * @param  string  $etag  따옴표를 포함한 현재 entity-tag
     * @return bool 일치하면 true
     */
    private function etagMatches(string $header, string $etag): bool
    {
        return $this->matchedEtagCandidate($header, $etag) !== null;
    }

    /**
     * 매치를 **성립시킨 후보**를 원문 그대로 돌려줍니다 (없으면 `null`).
     *
     * 매치 판정과 「어느 표현인가」는 같은 후보에서 나와야 한다. `If-None-Match` 는
     * 목록이라(RFC 9110 §13.1.2) 클라이언트가 identity 와 낡은 압축 검증자를 함께
     * 보낼 수 있는데, 둘을 갈라 놓으면 identity 로 매치된 요청에 압축 표현의 검증자를
     * 돌려주게 된다 — 공유 캐시가 identity 본문을 그 검증자로 갱신한다.
     *
     * @param  string  $header  `If-None-Match` 헤더 원문
     * @param  string  $etag  이 표현의 identity ETag (따옴표 포함)
     * @return string|null 매치된 후보 원문 (`*` 매치는 `$etag` 자신)
     */
    private function matchedEtagCandidate(string $header, string $etag): ?string
    {
        $normalize = static function (string $value): string {
            $value = trim($value);
            if (str_starts_with($value, 'W/')) {
                $value = substr($value, 2);
            }
            $value = trim($value, '"');

            return preg_replace('/-gzip$/', '', $value) ?? $value;
        };

        $current = $normalize($etag);

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === '*') {
                // 와일드카드는 표현을 지목하지 않는다 — 이 표현 자신을 쓴다.
                return $etag;
            }

            if ($normalize($candidate) === $current) {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * 304 에 실을 ETag — 클라이언트가 **보유한 표현**의 검증자를 그대로 돌려줍니다.
     *
     * 304 는 「같은 요청의 200 이 실었을」 ETag 를 실어야 한다(RFC 9110 §15.4.5). 압축본은
     * `-gzip` 접미사로 표현이 갈리는데(`GzipEncodeResponse::markEncodedEtag`), 그 접미사는
     * 본문이 있는 응답에만 붙는다 — 304 는 본문이 없어 압축 미들웨어가 아예 건너뛴다.
     * 그래서 종전에는 같은 URL·같은 요청이 200 에서는 `"abc-gzip"`, 304 에서는 `"abc"` 를
     * 내보냈고, 공유 캐시가 gzip 본문을 identity 검증자로 갱신했다 — 200 에서 표현을 갈라
     * 놓은 이유가 그 자리에서 사라진다 (7차 G3 라운드 C5).
     *
     * 어느 표현인지는 **클라이언트가 알려 준다**. 이 자리는 이미 매치가 성립한 뒤이므로,
     * 매치된 후보가 접미사를 달고 있었다면 그 클라이언트가 가진 것이 압축본이다. 압축
     * 여부를 여기서 다시 추정하지 않는다(크기 상한·MIME 목록·zlib 가용성까지 재현해야
     * 하고, 그 사본은 곧 원본과 갈린다).
     *
     * @param  string  $header  `If-None-Match` 헤더 원문
     * @param  string  $etag  이 표현의 identity ETag (따옴표 포함)
     * @return string 304 에 실을 ETag
     */
    private function negotiatedEtag(string $header, string $etag): string
    {
        $matched = $this->matchedEtagCandidate($header, $etag);

        if ($matched === null) {
            return $etag;
        }

        if (str_starts_with($matched, 'W/')) {
            $matched = substr($matched, 2);
        }

        if (! str_ends_with(trim($matched, '"'), '-gzip')) {
            return $etag;
        }

        return preg_replace('/"$/', '-gzip"', $etag) ?? $etag;
    }

    /**
     * 스토리지 스트림 응답에 ETag/캐싱 헤더를 부착합니다 (fileResponse 의 디스크 인지 대응).
     *
     * fileResponse() 는 로컬 절대 경로 전제(filemtime/filesize)라 S3 등 원격 디스크
     * 행에는 쓸 수 없습니다. 원격/로컬 공통 서빙은 StorageInterface::response() 가
     * 만든 스트림에 본 메서드로 동일한 캐싱 계약(ETag/304/Cache-Control/Expires)을
     * 입힙니다. ETag 는 파일 stat 대신 호출자가 준 결정적 소스(행 메타)로 만듭니다.
     *
     * `Content-Disposition` 은 건드리지 않는다 — 스토리지가 `response()`(inline) 또는
     * `download()`(attachment) 로 이미 선언한 것을 그대로 승계한다. 그래서 다운로드 통로
     * (코어 비이미지 첨부 · 게시판 첨부)도 같은 헬퍼를 지나며, 304 에는 그 헤더가 없다.
     *
     * @param  StreamedResponse  $response  스토리지 스트림 응답 (inline 또는 attachment)
     * @param  string  $etagSource  ETag 소스 문자열 (디스크·경로·수정시각·크기 등 행 메타)
     * @param  int  $maxAge  캐시 유지 시간 (초)
     * @param  bool  $immutable  URL 이 내용을 고유하게 가리키는가 (형제 `fileResponse` 와 같은 뜻)
     * @param  int|null  $lastModifiedAt  마지막 수정 시각(Unix time). 호출자가 알 수 있을 때만 준다
     * @param  string|null  $mimeType  200 응답의 Content-Type. 형제 `fileResponse()` 와 같은 근거로
     *                                 `Vary` 축을 판정하며 200 응답에도 그대로 세운다. `null`·빈 문자열이면
     *                                 스트림 응답에 이미 세워진 `Content-Type` 을 쓰고, 그것마저
     *                                 없으면 `application/octet-stream`(알 수 없으면 내려받기) —
     *                                 추정(스니핑)하지 않으며, 200/304 가 같은 값을 근거로 쓴다
     * @param  bool  $skipCompression  이 응답을 압축 미들웨어에서 제외할지. 사용자 업로드물처럼
     *                                 크기·건수가 유계가 아닌 통로는 `true` (형제 `PublicStorageController`
     *                                 와 같은 선언). 스트림 응답은 오늘 압축 경로를 지나지 않지만 그것은
     *                                 미들웨어가 응답 **클래스**를 보고 건너뛰는 우연이라, 계약은 선언으로 둔다
     * @return StreamedResponse|Response 스트림 응답 또는 304
     */
    protected function streamedFileResponse(
        StreamedResponse $response,
        string $etagSource,
        int $maxAge,
        ?bool $immutable = null,
        ?int $lastModifiedAt = null,
        ?string $mimeType = null,
        bool $skipCompression = false
    ): StreamedResponse|Response {
        // 형제(`fileResponse`)와 같은 기본값 규칙 — `null` 이면 요청의 `?v=` 로 판정한다.
        $immutable ??= $this->requestCarriesAssetVersion();

        // `Vary` 축의 근거는 **하나**다. 종전에는 304 에서만 스트림 응답의 `Content-Type`
        // 헤더를 읽었는데, 호출부가 그 헤더를 세우지 않으면(스토리지 어댑터가 확장자로
        // 추정하거나 아예 비운다) 200 은 미들웨어가 실제 유형으로 판정하고 304 는 빈
        // 문자열로 판정해 같은 URL 의 두 응답이 다른 축을 광고했다. 형제 `fileResponse()`
        // 가 `$mimeType` 인자를 근거로 쓰듯 여기서도 같은 값을 받고, 200 에도 그 값을 세워
        // 두 응답이 같은 근거에서 나오게 한다. 어느 쪽에도 없으면 추정하지 않는다 —
        // 추정은 곧 사본이고, 그 사본이 갈리는 순간 이 비대칭이 되돌아온다 (14차 G3 라운드).
        //
        // 빈 문자열·공백만의 인자는 「모름」과 같다. `??=` 는 `null` 만 폴백해, 행 `mime_type` 이
        // 빈 문자열인 첨부(구형 업로드·외부 이관)에서 여기가 예외를 던져 무인증 표면이 500
        // 이었다 (15차 G3 라운드).
        //
        // 어느 쪽도 비어 있으면 `application/octet-stream` 이다 — 정적 서빙이 **확장자 표에
        // 없는 파일**에 주는 유형과 같다(제품 결정, 16차 G3 라운드). 종전에는 이 자리가
        // `LogicException` 을 던졌는데, 그 예외는 개발자에게 「인자를 넘겨라」를 말하는 것이지
        // 방문자에게는 무인증 표면의 500 일 뿐이고, 행 `mime_type` 이 비어 있는 첨부는 코드
        // 결함이 아니라 데이터 상태다. 알 수 없으면 내려받기 — 내용을 **스니핑하지 않는다**는
        // 형제 규칙(`PublicStorageService` 의 미지 확장자)과 같은 결이며, `nosniff` 와 함께
        // 나가므로 HTML 형태 업로드물이 이 폴백으로 실행될 여지는 없다.
        $mimeType = trim((string) $mimeType);

        if ($mimeType === '') {
            $mimeType = trim((string) $response->headers->get('Content-Type', ''));
        }

        if ($mimeType === '') {
            $mimeType = self::UNKNOWN_MIME_TYPE;
        }

        $response->headers->set('Content-Type', $mimeType);

        // 압축 제외는 **선언**으로 한다. 표식은 컨테이너에 바인딩된 요청에 단다(FormRequest
        // 인스턴스는 attributes 를 값으로 복사한 별개 객체라 거기 달면 미들웨어에 닿지 않는다).
        if ($skipCompression) {
            request()->attributes->set(GzipEncodeResponse::SKIP_ATTRIBUTE, true);
        }

        // 형제 메서드(`fileResponse`)와 **같은 규칙**을 쓴다. 종전에는 이쪽만 따옴표 없는
        // 값에 완전 일치 비교였다 — RFC 9110 §8.8.3 위반이자, 같은 사이트에서 디스크 설정
        // (`FILESYSTEM_DISK=s3`)만 바꾸면 따옴표 있는 ETag 와 없는 ETag 가 공존하는 상태였다.
        // 완전 일치 비교는 다중 값·`W/` 접두·gzip 의 `-gzip` 접미사를 전부 놓친다.
        $etag = '"'.md5($etagSource).'"';

        // 환경별 캐싱 정책 (fileResponse 와 동일 — 인자도 같은 뜻이다)
        //
        // 형제 메서드에만 `$immutable` 을 두면 스트림 경로의 소비처는 「같은 URL 의 내용이
        // 교체되는 자산에는 immutable 을 붙이지 않는다」는 규정을 **지킬 수단이 없다.**
        // 현재 두 소비처(첨부·레이아웃 첨부)는 행 단위 주소(hash·row id)를 쓰고 그 행을
        // 제자리에서 갈아 끼우는 경로가 없어(업로드=새 행, 삭제=행 제거) 기본값이 맞다.
        $productionPolicy = $immutable
            ? "public, max-age={$maxAge}, immutable"
            : "public, max-age={$maxAge}, must-revalidate";
        $cacheControl = app()->environment('production')
            ? $productionPolicy
            : 'no-cache';

        $expires = gmdate('D, d M Y H:i:s', time() + $maxAge).' GMT';
        $lastModified = $lastModifiedAt !== null
            ? gmdate('D, d M Y H:i:s', $lastModifiedAt).' GMT'
            : null;

        // 조건부 요청 판정 순서는 형제 `fileResponse()` 와 **같다** — `If-None-Match` 가
        // 우선이고(RFC 9110 §13.1.2), 없을 때만 `If-Modified-Since` 를 본다. 종전에는 이
        // 경로만 `If-Modified-Since` 를 보지도, `Last-Modified` 를 내보내지도 않았다:
        // ETag 를 쓰지 않고 시각으로만 재검증하는 프록시가 이 통로(첨부·레이아웃 첨부)에서
        // 영원히 전문 200 을 받아 갔다. 링크가 되는 서버의 정적 서빙에는 없는 성질이다.
        $notModified = false;
        $ifNoneMatch = request()->header('If-None-Match');

        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $notModified = $this->etagMatches($ifNoneMatch, $etag);
        } elseif ($lastModifiedAt !== null && ($ifModifiedSince = request()->header('If-Modified-Since')) !== null) {
            $since = strtotime((string) $ifModifiedSince);
            $notModified = $since !== false && $lastModifiedAt <= $since;
        }

        if ($notModified) {
            // 304 는 200 이 실었을 헤더를 함께 싣는다 (RFC 9110 §15.4.5). 형제
            // `fileResponse()` 는 그렇게 하는데 이 경로만 `ETag`+`Cache-Control` 뿐이라,
            // 같은 URL 이 200 과 304 에서 서로 다른 만료 근거를 광고하고 있었다.
            // `ETag` 는 **클라이언트가 보유한 표현**의 검증자를 그대로 돌려준다.
            //
            // 매치 판정(`etagMatches()`)은 `-gzip` 접미사를 흡수하는데 응답은 흡수 전
            // identity 값을 싣고 있었다 — 즉 `"abc-gzip"` 으로 매치시켜 놓고 `"abc"` 를
            // 돌려준다. 그러면 공유 캐시가 gzip 표현의 항목을 identity 검증자로 갱신해,
            // 200 에서 표현을 갈라 놓은 이유가 그 자리에서 사라진다.
            //
            // 형제 두 지점(`fileResponse()`·`notModifiedResponse()`)은 둘 다
            // `negotiatedEtag()` 를 거치고 그 사유를 독블록에 적어 두었는데 이 경로만
            // 빠져 있었다 (10차 G3 라운드 B10-1).
            $notModifiedResponse = new NotModifiedResponse('', 304);
            $notModifiedResponse->headers->set(
                'ETag',
                $ifNoneMatch !== null && $ifNoneMatch !== ''
                    ? $this->negotiatedEtag($ifNoneMatch, $etag)
                    : $etag
            );
            $notModifiedResponse->headers->set('Cache-Control', $cacheControl);
            $notModifiedResponse->headers->set('Expires', $expires);
            $this->declareNoSniff($notModifiedResponse);

            if ($lastModified !== null) {
                $notModifiedResponse->headers->set('Last-Modified', $lastModified);
            }

            // `Vary` 축은 200 이 싣는 것과 **같은 근거**(위에서 확정한 `$mimeType`)로 판정한다.
            if (GzipEncodeResponse::isCompressibleMimeType($mimeType)) {
                $notModifiedResponse->headers->set('Vary', 'Accept-Encoding');
            }

            return $notModifiedResponse;
        }

        $response->headers->set('ETag', $etag);
        $response->headers->set('Expires', $expires);
        $response->headers->set('Cache-Control', $cacheControl);
        $this->declareNoSniff($response);

        if ($lastModified !== null) {
            $response->headers->set('Last-Modified', $lastModified);
        }

        return $response;
    }

    /**
     * 서비스가 돌려준 서빙 메타(배열)를 코어 단일 지점에 넘겨 캐시 계약을 입힙니다.
     *
     * 열한 통로(코어 첨부·레이아웃 첨부·사이트맵·게시판 3·페이지 2·에디터 이미지·이커머스 4)가
     * 전부 「서비스 메타 → `streamedFileResponse()`」 형태인데, 그 사이의 두 가지가 통로마다
     * 사본이었다 (16차 G3 라운드 E-6·E-7):
     *
     *  - **키 검증** — `array_key_exists` 가드가 두 곳에만 있었고 나머지는 없었다. 서비스가
     *    키를 빠뜨리면 가드 없는 통로는 배열 접근 경고가 `ErrorException` 으로 올라 무인증
     *    표면이 500 이 되거나, `last_modified` 축이 소리 없이 사라졌다. 판정은
     *    `FileServingMeta` 값 객체가 소유한다.
     *  - **`servedWithCacheContract()` 사본 여섯 벌** — 같은 일곱 줄이 여섯 컨트롤러에 있었다.
     *    한 곳에 들어간 보강(예: 압축 제외 선언)이 나머지에서 빠지는 형태라 접는다.
     *
     * 키가 빠지면 **명시 실패**다 — 로그에 누락 키와 호출부 문맥을 남기고 500 을 돌려준다.
     * 이 분기는 서비스 구현 결함이라 방문자가 고칠 수 있는 것이 없고, 조치 근거는 로그가
     * 나른다. 조용히 폴백하지 않는다.
     *
     * `immutable`·`skipCompression` 은 기본값이 없다 — 통로의 성질(행 주소인가·사용자 업로드물인가)
     * 은 호출부만 알고, 생략은 오류가 아니라 잘못된 캐싱으로만 나타나기 때문이다
     * (사용자 업로드물은 `immutable: false`·`skipCompression: true`).
     *
     * @param  array<string, mixed>  $meta  서빙 정보 — `response`·`etag_source`·`mime_type`·`last_modified`[·`max_age`]
     * @param  int|null  $maxAge  캐시 유지 시간(초). `null` 이면 메타의 `max_age` 를 쓴다(그때는 그 키도 필수)
     * @param  bool  $immutable  URL 이 내용을 고유하게 가리키는가 (형제 헬퍼와 같은 뜻)
     * @param  bool  $skipCompression  압축 미들웨어에서 제외할지 (형제 헬퍼와 같은 뜻)
     * @param  array<string, mixed>  $logContext  누락 로그에 실을 호출부 문맥 (hash·slug 등)
     * @param  string  $errorMessageKey  누락 시 500 응답의 메시지 키
     * @return StreamedResponse|Response|JsonResponse 스트림·304 또는 메타 누락 시 500
     */
    protected function streamedFileResponseFromMeta(
        array $meta,
        ?int $maxAge,
        bool $immutable,
        bool $skipCompression,
        array $logContext = [],
        string $errorMessageKey = 'errors.500.message'
    ): StreamedResponse|Response|JsonResponse {
        $missing = FileServingMeta::missingKey($meta, requireMaxAge: $maxAge === null);

        if ($missing !== null) {
            Log::error('파일 서빙 메타 누락', $logContext + ['missing' => $missing]);

            return $this->error($errorMessageKey, 500);
        }

        $serving = FileServingMeta::fromArray($meta, requireMaxAge: $maxAge === null);

        return $this->streamedFileResponse(
            $serving->response,
            $serving->etagSource,
            $maxAge ?? $serving->maxAge,
            immutable: $immutable,
            lastModifiedAt: $serving->lastModifiedAt,
            mimeType: $serving->mimeType,
            skipCompression: $skipCompression
        );
    }

    /**
     * 메모리에 있는 텍스트 자산(생성·가공된 CSS 등)을 인라인 응답으로 돌려줍니다.
     *
     * 파일이 아니라 문자열이라 두 파일 응답 헬퍼를 지날 수 없는 통로의 자리다. 그래도 파일
     * 서빙 응답 계약 중 **유형 선언과 `nosniff`** 는 같아야 한다 — 편집기 미리보기 CSS 통로가
     * `response($css)->header('Content-Type', …)` 로 직접 만들어 그 헤더가 빠져 있었다
     * (16차 G3 라운드 E-8). 캐싱 헤더는 붙이지 않는다 — 이 통로의 캐시 키(mtime·확장 캐시
     * 버전)는 컨트롤러가 소유하고, 응답은 매 요청 재생성 가능한 값이다.
     *
     * @param  string  $content  응답 본문
     * @param  string  $mimeType  Content-Type (빈 값이면 미지 유형과 같이 다룬다)
     * @param  int  $status  HTTP 상태 코드
     * @return Response 본문·유형·`nosniff` 를 실은 응답
     */
    protected function inlineContentResponse(string $content, string $mimeType, int $status = 200): Response
    {
        if (trim($mimeType) === '') {
            $mimeType = self::UNKNOWN_MIME_TYPE;
        }

        $response = response($content, $status);
        $response->headers->set('Content-Type', $mimeType);
        $this->declareNoSniff($response);

        return $response;
    }

    /**
     * JSON 응답을 반환합니다 (조건부 캐싱 헤더 포함).
     *
     * ETag 기반 조건부 캐시(If-None-Match → 304)와 환경별 Cache-Control 분기를
     * 적용한다 — 프로덕션은 `public, max-age`, 그 외 환경은 `no-cache`(파일 수정
     * 즉시 반영 — `fileResponse` 의 환경 분기와 동일 사상).
     *
     * @param  mixed  $data  JSON으로 변환할 데이터
     * @param  int  $maxAge  캐시 유지 시간 (초, 기본: 1시간)
     * @param  int  $status  HTTP 상태 코드
     * @return JsonResponse|Response JSON 응답 또는 304 응답
     */
    protected function cachedJsonResponse(mixed $data, int $maxAge = 3600, int $status = 200): JsonResponse|Response
    {
        $etag = $this->generateETag($data);

        $cacheControl = app()->environment('production')
            ? "public, max-age={$maxAge}"
            : 'no-cache';

        if ($status === 200 && $this->isNotModified($etag)) {
            $response = $this->notModifiedResponse($etag, $maxAge);
            $response->headers->set('Cache-Control', $cacheControl);
            $response->headers->set('Vary', 'Accept-Encoding');

            return $response;
        }

        return response()->json($data, $status, [
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
        ], ResponseHelper::JSON_ENCODE_OPTIONS);
    }

    /**
     * ETag를 생성합니다.
     *
     * @param  mixed  $data  해시할 데이터
     * @return string ETag 값 (따옴표 포함)
     */
    protected function generateETag(mixed $data): string
    {
        $content = is_string($data) ? $data : json_encode($data);

        return '"'.md5($content).'"';
    }

    /**
     * 클라이언트 캐시가 유효한지 확인합니다.
     *
     * @param  string  $etag  현재 ETag 값
     * @return bool 캐시가 유효하면 true
     */
    protected function isNotModified(string $etag): bool
    {
        $clientEtag = request()->header('If-None-Match');

        // 비교는 `etagMatches()` 단일 지점이 한다. 직접 `===` 로 비교하면 gzip 미들웨어가
        // 압축본에 붙이는 `-gzip` 접미사(`GzipEncodeResponse::markEncodedEtag`)를 벗기지
        // 못해, 브라우저가 되돌려 보낸 `"<md5>-gzip"` 이 영원히 불일치한다 — 그 URL 의
        // 재검증이 304 대신 매번 전문 200 이 되고, 오류가 아니라 조용한 대역폭 회귀로만
        // 나타난다. 다중값·`W/` 약한 검증자도 같은 이유로 그 함수가 함께 흡수한다.
        return $clientEtag !== null && $this->etagMatches($clientEtag, $etag);
    }

    /**
     * 304 Not Modified 응답을 반환합니다.
     *
     * @param  string  $etag  ETag 값
     * @param  int  $maxAge  캐시 TTL (초)
     * @return Response 304 응답
     */
    protected function notModifiedResponse(string $etag, int $maxAge = 3600): Response
    {
        // `Vary` 는 200 과 같은 축을 실는다. 304 는 「그 표현이 아직 유효하다」는 응답인데
        // 어떤 축으로 갈린 표현인지 말하지 않으면, 공유 캐시가 언어·인코딩이 다른 항목을
        // 하나의 재검증으로 갱신할 수 있다 (RFC 9110 §15.4.5 는 304 에 `Vary` 를 싣도록
        // 요구한다). 200 쪽은 `successWithCache()` 가 같은 값을 싣는다.
        // `ETag` 도 200 과 같은 축이다 — 형제 `fileResponse()` 와 같은 사유로, 클라이언트가
        // 보유한 표현의 검증자를 그대로 돌려준다. JSON 은 압축 대상이라 200 에는 `-gzip`
        // 접미사가 붙는데 304 는 압축 미들웨어가 건너뛰므로(본문이 없다), 그대로 두면 같은
        // 요청이 두 값을 광고한다 (7차 G3 라운드 C5).
        $clientEtag = request()->header('If-None-Match');
        $responseEtag = $clientEtag !== null && $clientEtag !== ''
            ? $this->negotiatedEtag($clientEtag, $etag)
            : $etag;

        return response('', 304)
            ->header('ETag', $responseEtag)
            ->header('Cache-Control', "public, max-age={$maxAge}")
            ->header('Vary', 'Accept-Encoding, Accept-Language');
    }

    /**
     * 캐시 헤더와 함께 성공 응답을 반환합니다.
     *
     * 클라이언트의 ETag가 일치하면 304 Not Modified를 반환합니다.
     *
     * @param  string  $messageKey  메시지 키
     * @param  mixed  $data  응답 데이터
     * @param  int  $maxAge  캐시 TTL (초, 기본: 1시간)
     * @param  array  $messageParams  메시지 매개변수
     * @return JsonResponse|Response JSON 응답 또는 304 응답
     */
    protected function successWithCache(
        string $messageKey = 'common.success',
        mixed $data = null,
        int $maxAge = 3600,
        array $messageParams = []
    ): JsonResponse|Response {
        $etag = $this->generateETag($data);

        // 환경별 캐싱 정책 — 프로덕션 외 환경은 no-cache 로 파일/데이터 수정 즉시 반영
        // (`fileResponse`/`cachedJsonResponse` 와 동일 사상, #122 작업 D)
        $cacheControl = app()->environment('production')
            ? "public, max-age={$maxAge}"
            : 'no-cache';

        // 304 Not Modified 처리
        if ($this->isNotModified($etag)) {
            $response = $this->notModifiedResponse($etag, $maxAge);
            $response->headers->set('Cache-Control', $cacheControl);

            return $response;
        }

        return response()->json([
            'success' => true,
            // ResponseHelper 와 같은 번역 — 치환값의 `count` 로 `단수|복수` 문구를 고른다.
            'message' => trans_count($messageKey, $messageParams),
            'data' => $data,
        ], 200, [], ResponseHelper::JSON_ENCODE_OPTIONS)
            ->header('ETag', $etag)
            ->header('Cache-Control', $cacheControl)
            ->header('Vary', 'Accept-Encoding, Accept-Language');
    }
}
