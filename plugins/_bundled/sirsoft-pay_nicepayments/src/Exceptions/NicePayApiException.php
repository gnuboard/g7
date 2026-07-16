<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Exceptions;

use RuntimeException;

/**
 * 나이스페이먼츠 PG API 호출 실패 예외
 *
 * NicePay 외부 API 호출 단계에서 발생하는 모든 실패 (HTTP 오류, ResultCode 비정상,
 * 응답 파싱 실패, SSRF 방어 차단 등) 를 단일 도메인 예외로 통합한다.
 *
 * 베이스 \Exception / \RuntimeException 직접 throw 대신 본 클래스를 사용해
 * 외부 소비자가 NicePay 도메인 오류만 선택적으로 catch 할 수 있도록 한다.
 */
class NicePayApiException extends RuntimeException
{
}
