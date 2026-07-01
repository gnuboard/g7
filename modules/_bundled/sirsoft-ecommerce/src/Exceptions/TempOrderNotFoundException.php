<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

/**
 * 임시 주문(주문서) 미발견 예외
 *
 * 임시 주문이 만료되었거나 존재하지 않을 때 발생합니다.
 * 컨트롤러는 i18n 문자열 매칭 대신 이 타입으로 분기해 404 를 반환합니다 (U14/MP06).
 */
class TempOrderNotFoundException extends TempOrderException {}
