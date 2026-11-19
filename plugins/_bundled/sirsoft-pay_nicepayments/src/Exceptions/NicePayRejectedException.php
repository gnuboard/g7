<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Exceptions;

/**
 * 나이스페이먼츠가 요청을 받아 거절 결과를 돌려준 예외
 *
 * 통신은 성공했고 응답의 ResultCode 가 성공 코드가 아닐 때 던진다. 메시지는 나이스페이먼츠가 돌려준
 * ResultMsg 라 운영자에게 그대로 보여도 되는 사유다. 통신 실패(연결·시간 초과·HTTP 오류)는
 * 이 예외가 아니며, 그 원문에는 결제대행사 API 주소와 cURL 오류 문구가 담기므로 화면에 싣지 않는다.
 */
class NicePayRejectedException extends NicePayApiException {}
