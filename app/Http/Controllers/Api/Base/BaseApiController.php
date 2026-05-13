<?php

namespace App\Http\Controllers\Api\Base;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API 컨트롤러의 최상위 베이스 클래스
 *
 * 모든 API 컨트롤러가 공통으로 사용하는 기능을 제공합니다.
 * Admin, Auth, Public 컨트롤러는 이 클래스를 상속받습니다.
 */
abstract class BaseApiController extends Controller
{
    /**
     * 성공 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키
     * @param mixed $data 응답 데이터
     * @param int $statusCode HTTP 상태 코드
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
     */
    protected function success(
        string $messageKey = 'common.success',
        mixed $data = null,
        int $statusCode = 200,
        array $messageParams = []
    ) {
        return ResponseHelper::success($messageKey, $data, $statusCode, $messageParams, 'core');
    }

    /**
     * 실패 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키
     * @param int $statusCode HTTP 상태 코드
     * @param mixed $errors 오류 정보
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
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
     * @param string $messageKey 메시지 키
     * @param mixed $resource JSON 리소스
     * @param int $statusCode HTTP 상태 코드
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
     */
    protected function successWithResource(
        string $messageKey = 'common.success',
        mixed $resource = null,
        int $statusCode = 200,
        array $messageParams = []
    ) {
        return ResponseHelper::successWithResource($messageKey, $resource, $statusCode, $messageParams, 'core');
    }

    /**
     * 현재 인증된 사용자를 반환합니다.
     *
     * @return \App\Models\User|null
     */
    protected function getCurrentUser()
    {
        return Auth::user();
    }

    /**
     * Not Found 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
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
     * @param string $messageKey 메시지 키
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
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
     * @param string $messageKey 메시지 키
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
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
     * @param mixed $errors 검증 오류
     * @param string $messageKey 메시지 키
     * @param array $messageParams 메시지 매개변수
     * @return \Illuminate\Http\JsonResponse
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
     * @param string $filePath 파일 경로
     * @param string $mimeType MIME 타입
     * @param int $maxAge 캐시 유지 시간 (초, 기본: 1년)
     */
    protected function fileResponse(string $filePath, string $mimeType, int $maxAge = 31536000): BinaryFileResponse|Response
    {
        // ETag 생성 (파일 수정 시간 + 파일 크기 기반)
        $etag = md5(filemtime($filePath).filesize($filePath));

        // If-None-Match 헤더 확인 (ETag 비교)
        if (request()->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);  // 304 Not Modified
        }

        // 환경별 캐싱 정책
        $cacheControl = app()->environment('production')
            ? "public, max-age={$maxAge}, immutable"  // 프로덕션: immutable 추가
            : 'no-cache';  // 개발: 캐싱 비활성화

        $response = response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Expires' => gmdate('D, d M Y H:i:s', time() + $maxAge).' GMT',
            'ETag' => $etag,
        ]);

        // Cache-Control 헤더를 수동으로 설정 (기본 헤더 덮어쓰기)
        $response->headers->set('Cache-Control', $cacheControl);

        return $response;
    }

    /**
     * JSON 응답을 반환합니다 (캐싱 헤더 포함).
     *
     * @param mixed $data JSON으로 변환할 데이터
     * @param int $maxAge 캐시 유지 시간 (초, 기본: 1시간)
     * @param int $status HTTP 상태 코드
     * @return JsonResponse JSON 응답
     */
    protected function cachedJsonResponse(mixed $data, int $maxAge = 3600, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [
            'Cache-Control' => "public, max-age={$maxAge}",
        ]);
    }

    /**
     * ETag를 생성합니다.
     *
     * @param mixed $data 해시할 데이터
     * @return string ETag 값 (따옴표 포함)
     */
    protected function generateETag(mixed $data): string
    {
        $content = is_string($data) ? $data : json_encode($data);

        return '"' . md5($content) . '"';
    }

    /**
     * 클라이언트 캐시가 유효한지 확인합니다.
     *
     * @param string $etag 현재 ETag 값
     * @return bool 캐시가 유효하면 true
     */
    protected function isNotModified(string $etag): bool
    {
        $clientEtag = request()->header('If-None-Match');

        return $clientEtag !== null && $clientEtag === $etag;
    }

    /**
     * 304 Not Modified 응답을 반환합니다.
     *
     * @param string $etag ETag 값
     * @param int $maxAge 캐시 TTL (초)
     * @return Response 304 응답
     */
    protected function notModifiedResponse(string $etag, int $maxAge = 3600): Response
    {
        return response('', 304)
            ->header('ETag', $etag)
            ->header('Cache-Control', "public, max-age={$maxAge}");
    }

    /**
     * 캐시 헤더와 함께 성공 응답을 반환합니다.
     *
     * 클라이언트의 ETag가 일치하면 304 Not Modified를 반환합니다.
     *
     * @param string $messageKey 메시지 키
     * @param mixed $data 응답 데이터
     * @param int $maxAge 캐시 TTL (초, 기본: 1시간)
     * @param array $messageParams 메시지 매개변수
     * @return JsonResponse|Response JSON 응답 또는 304 응답
     */
    protected function successWithCache(
        string $messageKey = 'common.success',
        mixed $data = null,
        int $maxAge = 3600,
        array $messageParams = []
    ): JsonResponse|Response {
        $etag = $this->generateETag($data);

        // 304 Not Modified 처리
        if ($this->isNotModified($etag)) {
            return $this->notModifiedResponse($etag, $maxAge);
        }

        return response()->json([
            'success' => true,
            'message' => __($messageKey, $messageParams),
            'data' => $data,
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', "public, max-age={$maxAge}")
            ->header('Vary', 'Accept-Encoding, Accept-Language');
    }
}
