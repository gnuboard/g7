<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Exceptions;

/**
 * KG이니시스가 요청을 받아 거절 결과를 돌려준 예외
 *
 * 통신은 성공했고 응답의 resultCode 가 성공 코드(00)가 아닐 때 던진다. 메시지는 KG이니시스가 돌려준
 * resultMsg 라 운영자·구매자에게 그대로 보여도 되는 사유다. 통신 실패(연결·시간 초과·HTTP 오류)는
 * 이 예외가 아니며, 그 원문에는 결제대행사 API 주소와 cURL 오류 문구가 담기므로 화면에 싣지 않는다.
 */
class KgInicisRejectedException extends KgInicisApiException {}
