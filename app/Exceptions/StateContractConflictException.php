<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 전역 상태 공개 계약 이름이 다른 소유자와 겹칠 때 발생하는 예외
 *
 * 확장이 manifest `state_contracts` 로 선언한 이름을 코어 예약 계약이나 다른 활성 확장이 이미 소유하면
 * 설치·활성화를 막습니다. 메시지에 이미 가진 소유자를 담습니다.
 *
 * 설치 경로(ZIP·GitHub 포함)가 도메인 오류로 다루는 RuntimeException 을 상속해, 충돌이 일반 실패(500)로 빠지지 않고
 * 사유와 함께 안내되게 합니다.
 *
 * @since 7.1.0
 */
class StateContractConflictException extends RuntimeException {}
