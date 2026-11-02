<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 터미널 설정 명령이 저장 전에 거부한 사유 (알 수 없는 키·계산 키·.env 고정·관리자 화면 전용 등).
 *
 * 메시지는 이미 운영자 로케일로 번역된 문장이다 — 명령은 그대로 한 줄 출력하고 종료 코드 1 로 끝낸다.
 */
class SettingsConsoleException extends RuntimeException {}
