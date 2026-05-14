<?php

/**
 * 인스톨러 세션 쿠키 round-trip 사전 진단 endpoint
 *
 * Step 0 (welcome) 진입 시 클라이언트가 set → verify 두 fetch 로 세션 쿠키가
 * 브라우저에 의해 보존되는지 확인한다. 브라우저가 PHPSESSID 쿠키를 차단/유실
 * 하여 세션이 매 요청 새로 생성되는 케이스 (`session.cookie_samesite=Strict`
 * + 비표준 포트 + 일부 브라우저 정책 조합) 를 사전 감지한다.
 *
 * 엔드포인트:
 * - GET ?action=set    : 세션에 nonce 저장 후 nonce 반환
 * - GET ?action=verify : 세션의 nonce 를 반환하고 세션에서 제거 (재사용 방지)
 *
 * 본 진단으로 "설치하기" 버튼 자체를 차단하지 않는다 — 경고 배너만 표시한다.
 */

/**
 * set 액션 — 세션에 nonce 저장 후 응답 데이터 반환
 *
 * @return array{action: string, nonce: string}
 */
function sessionProbeSet(): array
{
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['_installer_session_probe'] = $nonce;

    return [
        'action' => 'set',
        'nonce' => $nonce,
    ];
}

/**
 * verify 액션 — 세션의 nonce 를 반환하고 세션에서 제거
 *
 * 세션에 nonce 가 보존되어 있으면 matched=true. 세션이 빈 상태(쿠키 round-trip
 * 실패) 면 matched=false. 한 번 verify 한 nonce 는 재사용 방지를 위해 세션에서
 * 제거한다.
 *
 * @return array{action: string, matched: bool, nonce?: string}
 */
function sessionProbeVerify(): array
{
    if (! isset($_SESSION['_installer_session_probe'])) {
        return [
            'action' => 'verify',
            'matched' => false,
        ];
    }

    $nonce = $_SESSION['_installer_session_probe'];
    unset($_SESSION['_installer_session_probe']);

    return [
        'action' => 'verify',
        'matched' => true,
        'nonce' => $nonce,
    ];
}

// 라이브러리 모드: 테스트 등에서 함수 정의만 로드. SESSION_PROBE_LIBRARY 상수가 정의되어 있으면 즉시 종료.
$sessionProbeLibraryMode = defined('SESSION_PROBE_LIBRARY') && constant('SESSION_PROBE_LIBRARY');

if (! $sessionProbeLibraryMode) {
    // 정식 진입점 — config / session / functions / guard 로드
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/_guard.php';
    installer_guard_or_410();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'GET method required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = $_GET['action'] ?? '';

    if ($action === 'set') {
        echo json_encode(sessionProbeSet(), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'verify') {
        echo json_encode(sessionProbeVerify(), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action — use ?action=set or ?action=verify'], JSON_UNESCAPED_UNICODE);
    }
}
