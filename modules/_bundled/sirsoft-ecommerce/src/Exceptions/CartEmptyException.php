<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

/**
 * 빈 장바구니 예외
 *
 * 주문서 생성 시 장바구니가 비어 있을 때 발생합니다.
 * 컨트롤러는 i18n 문자열 매칭 대신 이 타입으로 분기해 400 을 반환합니다 (U14/MP06).
 */
class CartEmptyException extends TempOrderException {}
