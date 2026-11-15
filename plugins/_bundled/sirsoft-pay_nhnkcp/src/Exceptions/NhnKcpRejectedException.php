<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Exceptions;

/**
 * NHN KCP 가 요청을 받아 거절 결과를 돌려준 예외
 *
 * KCP 응답의 res_cd 가 성공 코드(0000)가 아닐 때 던진다. 메시지는 KCP 가 돌려준 res_msg 라 운영자에게
 * 그대로 보여도 되는 사유다. CLI 실행 실패(바이너리 누락·실행 권한·위험 문자 거부)나 통신 실패는
 * 이 예외가 아니며, 그 문구에는 서버 절대 경로 등 내부 정보가 담기므로 화면에 싣지 않는다.
 */
class NhnKcpRejectedException extends NhnKcpApiException {}
