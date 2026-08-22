<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Exceptions;

use RuntimeException;

/**
 * 토스페이먼츠 PG API 호출 실패 예외
 *
 * 결제 승인/취소, 현금영수증 발급/취소 등 토스페이먼츠 API 호출 단계에서 발생하는
 * 모든 실패 (HTTP 오류, 응답 파싱 실패 등) 를 단일 도메인 예외로 통합한다.
 *
 * 베이스 \Exception 직접 throw 대신 본 클래스를 사용해 외부 소비자가 토스페이먼츠
 * 도메인 오류만 선택적으로 catch 할 수 있도록 한다. RuntimeException 을 상속하므로
 * 기존의 catch (\Exception) 소비자는 그대로 동작한다.
 */
class TossPaymentsApiException extends RuntimeException {}
