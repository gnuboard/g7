<?php

/**
 * 그누보드7 웹 인스톨러 — SSE 호환성 사전 체크 API
 *
 * 클라이언트가 본 엔드포인트로 EventSource 연결을 만들고, 서버가 보내는 두 phase
 * "probe" 이벤트의 도착 timing 차이로 환경의 streaming 호환성을 판정한다.
 *
 *  Phase 1: 메시지 1 송신 + flush
 *  Sleep 2 초
 *  Phase 2: 메시지 2 송신 + flush
 *
 * 호환 환경은 두 메시지가 약 2초 간격으로 도착. mod_fcgid/proxy 등이 응답 종료까지
 * buffer 보유하는 환경에서는 두 메시지가 거의 동시에 도착 (응답 종료 시점에 일괄 flush).
 * 이 timing 차이로 client 가 streaming 가능 여부를 정확히 판정.
 *
 * 본 endpoint 는 lock / state.json 갱신 / task 실행을 일체 수행하지 않는다.
 * 사이드 이펙트 없음.
 *
 * 응답 형식:
 *  event: probe
 *  data: {"phase":1,"server_ts":1234567890.123}
 *  (sleep 2s)
 *  event: probe
 *  data: {"phase":2,"server_ts":1234567892.456}
 *
 * @method GET
 * @package G7\Installer
 */

// 설치 완료 시 인스톨러 비즈니스 로직 진입 차단
require_once __DIR__ . '/_guard.php';
installer_guard_or_410();

// SSE 헤더 설정 (install-worker.php 와 동일 — 호환성 검증의 정확도 보장)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// 출력 버퍼 비활성화 — 응답 즉시 송신
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ob_implicit_flush(true);

// 짧은 timeout 으로 충분 — probe 자체가 즉시 종료 (sleep 2초 포함)
@set_time_limit(10);
ignore_user_abort(false);

// Phase 1 — 즉시 송신
echo "event: probe\n";
echo 'data: ' . json_encode(['phase' => 1, 'server_ts' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n\n";
@flush();

// 클라이언트는 두 phase 도착 시각 차이로 streaming 호환성 판정.
// streaming 환경은 약 2초 간격으로 도착 / buffer 환경은 거의 동시 도착.
sleep(2);

// Phase 2 — 송신 후 즉시 종료
echo "event: probe\n";
echo 'data: ' . json_encode(['phase' => 2, 'server_ts' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n\n";
@flush();

exit;
