<?php

namespace Plugins\Sirsoft\Sso\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * SCIM 2.0 오류 — 응답은 SCIM 오류 스키마(RFC 7644 §3.12)로 낸다.
 *
 * 메시지(`detail`)에는 개인정보를 싣지 않는다. 오류 종류(`scimType`)는 규격 목록의 값만 쓴다.
 *
 * IdP 가 보낸 요청의 정상 거절(없는 자원, 중복, 잘못된 값)이라 예외 보고기에 남기지 않는다(`ShouldntReport` — 응답은 그대로).
 *
 * @since 1.0.0
 */
class ScimException extends RuntimeException implements ShouldntReport
{
    /** SCIM 오류 스키마 URN */
    public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    /** SCIM 응답 Content-Type */
    public const CONTENT_TYPE = 'application/scim+json';

    /**
     * @param  int  $status  HTTP 상태 코드
     * @param  string|null  $scimType  오류 종류 (invalidFilter, uniqueness, invalidValue, invalidSyntax, invalidPath, noTarget, mutability, tooMany 등)
     * @param  string  $detail  사람이 읽는 설명 (개인정보 금지)
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $scimType = null,
        string $detail = '',
    ) {
        parent::__construct($detail);
    }

    /**
     * SCIM 오류 응답으로 바꿉니다 (Laravel 예외 처리기가 부른다).
     *
     * @return JsonResponse SCIM 오류 응답
     */
    public function render(): JsonResponse
    {
        return self::response($this->status, $this->scimType, $this->getMessage());
    }

    /**
     * SCIM 오류 응답을 만듭니다.
     *
     * @param  int  $status  HTTP 상태 코드
     * @param  string|null  $scimType  오류 종류
     * @param  string  $detail  설명
     * @return JsonResponse 응답
     */
    public static function response(int $status, ?string $scimType, string $detail): JsonResponse
    {
        $body = ['schemas' => [self::ERROR_SCHEMA], 'status' => (string) $status];
        if ($scimType !== null) {
            $body['scimType'] = $scimType;
        }
        if ($detail !== '') {
            $body['detail'] = $detail;
        }

        return response()->json($body, $status, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
