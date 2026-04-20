<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class ResponseHelper
{
    /**
     * 성공 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키 (기본값: 'messages.success')
     * @param mixed $data 응답 데이터
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 메시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse JSON 응답
     */
    public static function success(
        string $messageKey = 'messages.success',
        mixed $data = null,
        int $statusCode = 200,
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => self::trans($messageKey, $messageParams, $domain),
            'data' => $data
        ], $statusCode);
    }

    /**
     * 실패 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키 (기본값: 'messages.failed')
     * @param int $statusCode HTTP 상태 코드 (기본값: 400)
     * @param mixed $errors 오류 정보
     * @param array $messageParams 메시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse JSON 응답
     */
    public static function error(
        string $messageKey = 'messages.failed',
        int $statusCode = 400,
        mixed $errors = null,
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain),
        ];

        if ($errors instanceof \Throwable) {
            if (config('app.debug')) {
                // messageParams에 이미 에러 메시지가 포함된 경우 중복 방지
                if (empty($messageParams)) {
                    $response['message'] .= ': ' . $errors->getMessage();
                }
                $response['debug'] = self::formatException($errors);
            }
        } elseif ($errors !== null) {
            if ($statusCode >= 500 && is_string($errors) && !config('app.debug')) {
                // 프로덕션 500+ 에러의 string errors 차단 (내부 예외 메시지 노출 방지)
            } else {
                $response['errors'] = $errors;
            }
        }

        return response()->json($response, $statusCode);
    }

    /**
     * 입력 검증 실패 응답을 생성합니다.
     *
     * @param mixed $errors 검증 오류 정보
     * @param string $messageKey 메시지 키 (기본값: 'messages.validation_failed')
     * @param array $messageParams 메시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 422 상태 코드를 가진 JSON 응답
     */
    public static function validationError(
        mixed $errors,
        string $messageKey = 'messages.validation_failed',
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain),
            'errors' => $errors
        ], 422);
    }

    /**
     * 인증 실패 응답을 생성합니다.
     *
     * @param string $messageKey 메시지 키 (기본값: 'messages.unauthorized')
     * @param array $messageParams 메시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 401 상태 코드를 가진 JSON 응답
     */
    public static function unauthorized(
        string $messageKey = 'messages.unauthorized',
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain)
        ], 401);
    }

    /**
     * 권한 부족 응답을 생성합니다.
     *
     * @param string $messageKey 멤시지 키 (기본값: 'messages.forbidden')
     * @param array $messageParams 멤시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 403 상태 코드를 가진 JSON 응답
     */
    public static function forbidden(
        string $messageKey = 'messages.forbidden',
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain)
        ], 403);
    }

    /**
     * 리소스를 찾을 수 없음 응답을 생성합니다.
     *
     * @param string $messageKey 멤시지 키 (기본값: 'messages.not_found')
     * @param array $messageParams 멤시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 404 상태 코드를 가진 JSON 응답
     */
    public static function notFound(
        string $messageKey = 'messages.not_found',
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain)
        ], 404);
    }

    /**
     * 서버 내부 오류 응답을 생성합니다.
     *
     * @param string $messageKey 멤시지 키 (기본값: 'messages.error_occurred')
     * @param mixed $error 오류 정보 (디버그 모드에서만 표시)
     * @param array $messageParams 멤시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 500 상태 코드를 가진 JSON 응답
     */
    public static function serverError(
        string $messageKey = 'messages.error_occurred',
        mixed $error = null,
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => self::trans($messageKey, $messageParams, $domain),
        ];

        if ($error instanceof \Throwable) {
            if (config('app.debug')) {
                $response['message'] .= ': ' . $error->getMessage();
                $response['debug'] = self::formatException($error);
            }
        } elseif ($error !== null && config('app.debug')) {
            $response['error'] = $error;
        }

        return response()->json($response, 500);
    }

    /**
     * 다국어 메시지를 변환합니다.
     *
     * @param string $key 번역 키
     * @param array $params 번역 매개변수
     * @param string $domain 번역 도메인
     * @return string 번역된 메시지
     */
    private static function trans(
        string $key,
        array $params = [],
        string $domain = 'core'
    ): string {
        $locale = self::getUserLocale();
        
        // 도메인별로 다른 경로에서 번역 파일 로드
        $translationKey = $domain === 'core' ? $key : "{$domain}::{$key}";
        
        return __($translationKey, $params, $locale);
    }

    /**
     * 사용자의 언어 설정을 가져옵니다.
     *
     * @return string 사용자 언어 코드 (ko, en)
     */
    private static function getUserLocale(): string
    {
        // 인증된 사용자의 언어 설정 확인
        if (Auth::check() && Auth::user()->language) {
            return Auth::user()->language;
        }

        // 헤더에서 언어 설정 확인
        $acceptLanguage = request()->header('Accept-Language');
        if ($acceptLanguage) {
            $locale = self::parseAcceptLanguage($acceptLanguage);
            if (in_array($locale, ['ko', 'en'])) {
                return $locale;
            }
        }

        // 기본값 반환
        return config('app.locale', 'ko');
    }

    /**
     * Accept-Language HTTP 헤더를 파싱합니다.
     *
     * @param string $acceptLanguage Accept-Language 헤더 값
     * @return string 파싱된 언어 코드
     */
    private static function parseAcceptLanguage(string $acceptLanguage): string
    {
        $languages = explode(',', $acceptLanguage);
        $firstLanguage = trim($languages[0]);

        // 언어-지역 형태에서 언어만 추출 (예: ko-KR -> ko)
        if (strpos($firstLanguage, '-') !== false) {
            return explode('-', $firstLanguage)[0];
        }

        return $firstLanguage;
    }

    /**
     * 예외 정보를 디버그용 배열로 변환합니다.
     *
     * @param \Throwable $e 예외 인스턴스
     * @return array 디버그 정보 배열
     */
    private static function formatException(\Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->take(10)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ])->toArray(),
        ];
    }

    /**
     * 모듈별 성공 응답을 생성합니다.
     *
     * @param string $module 모듈명
     * @param string $messageKey 멤시지 키
     * @param mixed $data 응답 데이터
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function moduleSuccess(
        string $module,
        string $messageKey,
        mixed $data = null,
        int $statusCode = 200,
        array $messageParams = []
    ): JsonResponse {
        return self::success($messageKey, $data, $statusCode, $messageParams, $module);
    }

    /**
     * 모듈별 실패 응답을 생성합니다.
     *
     * @param string $module 모듈명
     * @param string $messageKey 멤시지 키
     * @param int $statusCode HTTP 상태 코드 (기본값: 400)
     * @param mixed $errors 오류 정보
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function moduleError(
        string $module,
        string $messageKey,
        int $statusCode = 400,
        mixed $errors = null,
        array $messageParams = []
    ): JsonResponse {
        return self::error($messageKey, $statusCode, $errors, $messageParams, $module);
    }

    /**
     * 플러그인별 성공 응답을 생성합니다.
     *
     * @param string $plugin 플러그인명
     * @param string $messageKey 멤시지 키
     * @param mixed $data 응답 데이터
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function pluginSuccess(
        string $plugin,
        string $messageKey,
        mixed $data = null,
        int $statusCode = 200,
        array $messageParams = []
    ): JsonResponse {
        return self::success($messageKey, $data, $statusCode, $messageParams, $plugin);
    }

    /**
     * 플러그인별 실패 응답을 생성합니다.
     *
     * @param string $plugin 플러그인명
     * @param string $messageKey 멤시지 키
     * @param int $statusCode HTTP 상태 코드 (기본값: 400)
     * @param mixed $errors 오류 정보
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function pluginError(
        string $plugin,
        string $messageKey,
        int $statusCode = 400,
        mixed $errors = null,
        array $messageParams = []
    ): JsonResponse {
        return self::error($messageKey, $statusCode, $errors, $messageParams, $plugin);
    }

    /**
     * JSON Resource를 사용한 성공 응답을 생성합니다.
     *
     * @param string $messageKey 멤시지 키 (기본값: 'messages.success')
     * @param JsonResource|ResourceCollection|null $resource JSON 리소스
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 멤시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse JSON 응답
     */
    public static function successWithResource(
        string $messageKey = 'messages.success',
        JsonResource|ResourceCollection|null $resource = null,
        int $statusCode = 200,
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        $data = $resource ? $resource->resolve() : null;
        
        return response()->json([
            'success' => true,
            'message' => self::trans($messageKey, $messageParams, $domain),
            'data' => $data
        ], $statusCode);
    }

    /**
     * JSON Resource를 사용한 모듈 성공 응답을 생성합니다.
     *
     * @param string $module 모듈명
     * @param string $messageKey 멤시지 키
     * @param JsonResource|ResourceCollection|null $resource JSON 리소스
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function moduleSuccessWithResource(
        string $module,
        string $messageKey,
        JsonResource|ResourceCollection|null $resource = null,
        int $statusCode = 200,
        array $messageParams = []
    ): JsonResponse {
        return self::successWithResource($messageKey, $resource, $statusCode, $messageParams, $module);
    }

    /**
     * JSON Resource를 사용한 플러그인 성공 응답을 생성합니다.
     *
     * @param string $plugin 플러그인명
     * @param string $messageKey 멤시지 키
     * @param JsonResource|ResourceCollection|null $resource JSON 리소스
     * @param int $statusCode HTTP 상태 코드 (기본값: 200)
     * @param array $messageParams 멤시지 매개변수
     * @return JsonResponse JSON 응답
     */
    public static function pluginSuccessWithResource(
        string $plugin,
        string $messageKey,
        JsonResource|ResourceCollection|null $resource = null,
        int $statusCode = 200,
        array $messageParams = []
    ): JsonResponse {
        return self::successWithResource($messageKey, $resource, $statusCode, $messageParams, $plugin);
    }

    /**
     * 페이지네이션된 리소스 응답을 생성합니다.
     *
     * @param string $messageKey 멤시지 키 (기본값: 'messages.success')
     * @param ResourceCollection|null $collection 페이지네이션된 리소스 컬렉션
     * @param array $messageParams 멤시지 매개변수
     * @param string $domain 번역 도메인 (기본값: 'core')
     * @return JsonResponse 페이지네이션 메타 데이터를 포함한 JSON 응답
     */
    public static function successWithPagination(
        string $messageKey = 'messages.success',
        ResourceCollection|null $collection = null,
        array $messageParams = [],
        string $domain = 'core'
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => self::trans($messageKey, $messageParams, $domain)
        ];

        if ($collection) {
            $paginationData = $collection->resolve();
            $response['data'] = $paginationData['data'];
            $response['meta'] = [
                'current_page' => $paginationData['current_page'] ?? null,
                'from' => $paginationData['from'] ?? null,
                'last_page' => $paginationData['last_page'] ?? null,
                'path' => $paginationData['path'] ?? null,
                'per_page' => $paginationData['per_page'] ?? null,
                'to' => $paginationData['to'] ?? null,
                'total' => $paginationData['total'] ?? null,
            ];
            $response['links'] = $paginationData['links'] ?? null;
        }

        return response()->json($response);
    }
}
