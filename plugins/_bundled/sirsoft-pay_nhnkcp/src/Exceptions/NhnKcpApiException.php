<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Exceptions;

use RuntimeException;

/**
 * NHN KCP PG API 호출 실패 예외
 *
 * KCP CLI / Standard Pay API 호출 단계에서 발생하는 모든 실패 (CLI 비정상 종료,
 * 응답 res_cd 비정상, 응답 파싱 실패 등) 를 단일 도메인 예외로 통합한다.
 *
 * 베이스 \Exception / \RuntimeException 직접 throw 대신 본 클래스를 사용해
 * 외부 소비자가 NHN KCP 도메인 오류만 선택적으로 catch 할 수 있도록 한다.
 */
class NhnKcpApiException extends RuntimeException
{
}
