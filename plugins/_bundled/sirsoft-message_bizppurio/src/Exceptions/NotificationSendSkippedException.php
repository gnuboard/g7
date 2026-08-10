<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Exceptions;

use RuntimeException;

/**
 * 발송 준비 미비로 인한 발송 건너뜀 예외.
 *
 * 알림톡 템플릿 미연결, SMS 템플릿 없음, 수신자 전화번호 없음 등 비즈뿌리오
 * API 호출 자체를 시도하지 못하고 채널 드라이버 단계에서 건너뛴 경우 던진다.
 * 코어 NotificationDispatcher::sendToNotifiable()의 catch(\Exception)가 이를
 * core.notification.channel_send_failed 훅으로 연결해, 발송 이력 화면에
 * "성공"이 아닌 "실패"로 정확히 기록되게 한다.
 */
class NotificationSendSkippedException extends RuntimeException {}
