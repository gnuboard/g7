<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Exceptions;

use RuntimeException;

/**
 * KG 이니시스 PG API 호출 실패 예외
 *
 * KG 이니시스 표준결제창/CBT/모바일/에스크로/현금영수증 API 호출 단계에서 발생하는
 * 모든 실패 (HTTP 오류, resultCode 비정상, 응답 파싱 실패 등) 를 단일 도메인
 * 예외로 통합한다.
 *
 * 베이스 \Exception 직접 throw 대신 본 클래스를 사용해 외부 소비자가 KG 이니시스
 * 도메인 오류만 선택적으로 catch 할 수 있도록 한다.
 */
class KgInicisApiException extends RuntimeException
{
}
