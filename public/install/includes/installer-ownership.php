<?php

/**
 * 인스톨러 설치 소유권 헬퍼
 *
 * 웹 인스톨러의 상태변경 요청을 "설치를 시작한 브라우저 세션"에 묶는다.
 *
 * - 소유자: Step 3 를 최초로 통과한 세션. state.json 에는 그 세션 토큰의 해시(owner_hash)만 남는다.
 * - CSRF: 세션 csrf_token 을 헤더(X-CSRF-Token)·JSON 본문·폼 필드로 대조한다.
 * - DB 연결 지문: DB 테스트를 통과한 연결설정과 Step 3 제출 설정이 같은지 HMAC 으로 확인한다.
 * - 기존 테이블 삭제 확인: 서버가 발급한 확인 토큰 + 탐지 테이블 불변일 때만 drop_tables 를 허용한다.
 * - 설치 계획 서명: 세션이 없는 SSE 워커가 소유 게이트를 통과한 설치 계획인지 확인한다.
 * - 복구 토큰: 세션이 유실된 소유자가 다른 세션에서 소유권을 되찾는 1회성 비밀.
 *
 * 세션 토큰 원문은 페이지·요청 본문에 싣지 않는다. 세션 쿠키가 이미 세션을 식별하므로
 * 서버는 세션에 보관한 토큰의 해시만 대조한다. 사용자에게 보이는 원문 비밀은 복구 토큰뿐이다.
 *
 * 순수 함수 위주로 구성해 단위 테스트가 가능하다 — 세션은 $_SESSION 전역만 읽고 쓴다.
 */
if (! defined('INSTALLER_DROP_CONFIRM_TTL')) {
    // 기존 테이블 삭제 확인 토큰 유효 시간(초) — DB 테스트 후 설치 시작까지의 여유
    define('INSTALLER_DROP_CONFIRM_TTL', 21600);
}

if (! defined('INSTALLER_RECLAIM_MAX_FAILURES')) {
    // 복구 토큰 입력 실패 허용 횟수 (창 안에서 초과 시 잠금)
    define('INSTALLER_RECLAIM_MAX_FAILURES', 5);
}

if (! defined('INSTALLER_RECLAIM_WINDOW')) {
    // 복구 토큰 실패 집계 창(초)
    define('INSTALLER_RECLAIM_WINDOW', 900);
}

if (! function_exists('installer_ownership_lang')) {
    /**
     * 인스톨러 번역을 반환한다 (lang() 미로드 환경 폴백 포함).
     *
     * @param  string  $key  번역 키
     * @param  string  $fallback  lang() 이 없을 때 쓸 문구
     * @return string 번역 문구
     */
    function installer_ownership_lang(string $key, string $fallback): string
    {
        if (function_exists('lang')) {
            $message = lang($key);
            if ($message !== $key) {
                return $message;
            }
        }

        return $fallback;
    }
}

if (! function_exists('installer_json_error')) {
    /**
     * 인스톨러 API 오류 JSON 을 응답하고 종료한다.
     *
     * 프론트는 install-process 계열이 message, save-extensions 가 error 를 읽으므로 두 키를 모두 싣는다.
     *
     * @param  int  $status  HTTP 상태 코드
     * @param  string  $code  기계 판독용 오류 코드
     * @param  string  $message  사용자 표시 문구
     */
    function installer_json_error(int $status, string $code, string $message): never
    {
        if (! headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $payload = [
            'success' => false,
            'error_code' => $code,
            'message' => $message,
            'error' => $message,
        ];

        echo function_exists('installer_json_encode')
            ? installer_json_encode($payload, JSON_UNESCAPED_UNICODE)
            : json_encode($payload, JSON_UNESCAPED_UNICODE); // json-encode:allow — utf8.php 미로드 폴백

        exit;
    }
}

// ---------------------------------------------------------------------------
// 소유권
// ---------------------------------------------------------------------------

if (! function_exists('installer_owner_token')) {
    /**
     * 현재 세션의 소유 토큰을 반환한다 (없으면 생성).
     *
     * @return string 64자 hex 토큰
     */
    function installer_owner_token(): string
    {
        $token = $_SESSION['install_owner'] ?? '';

        if (! is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['install_owner'] = $token;
        }

        return $token;
    }
}

if (! function_exists('installer_owner_hash')) {
    /**
     * 소유 토큰의 해시를 반환한다 (state.json 저장용).
     *
     * @param  string  $token  소유 토큰 원문
     * @return string sha256 hex
     */
    function installer_owner_hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

if (! function_exists('installer_state_is_claimed')) {
    /**
     * state 에 소유자가 각인돼 있는지 확인한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return bool 소유자 존재 여부
     */
    function installer_state_is_claimed(array $state): bool
    {
        return is_string($state['owner_hash'] ?? null) && $state['owner_hash'] !== '';
    }
}

if (! function_exists('installer_session_is_owner')) {
    /**
     * 현재 세션이 state 의 소유자인지 확인한다.
     *
     * 미주장 state 는 false — 소유자가 없으면 누구도 소유자가 아니다.
     * 세션 토큰이 없으면 새로 만들지 않는다 (판정만 수행).
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return bool 소유자 여부
     */
    function installer_session_is_owner(array $state): bool
    {
        if (! installer_state_is_claimed($state)) {
            return false;
        }

        $token = $_SESSION['install_owner'] ?? '';
        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) $state['owner_hash'], installer_owner_hash($token));
    }
}

if (! function_exists('installer_normalize_recovery_token')) {
    /**
     * 사용자가 입력한 복구 토큰을 대조 가능한 형태로 정규화한다 (공백·하이픈 제거, 소문자).
     *
     * @param  string  $input  입력값
     * @return string 정규화된 토큰
     */
    function installer_normalize_recovery_token(string $input): string
    {
        return strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $input));
    }
}

if (! function_exists('installer_format_recovery_token')) {
    /**
     * 복구 토큰을 읽기 쉬운 4자 묶음으로 표시한다.
     *
     * @param  string  $token  32자 hex 토큰
     * @return string xxxx-xxxx-… 형식
     */
    function installer_format_recovery_token(string $token): string
    {
        return implode('-', str_split($token, 4));
    }
}

if (! function_exists('installer_issue_recovery_token')) {
    /**
     * 복구 토큰을 새로 발급해 state 에 해시를 각인하고, 원문은 소유 세션에만 둔다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, mixed> recovery_hash 가 갱신된 state
     */
    function installer_issue_recovery_token(array $state): array
    {
        $token = bin2hex(random_bytes(16));
        $state['recovery_hash'] = hash('sha256', $token);
        $_SESSION['install_recovery_token'] = $token;

        return $state;
    }
}

if (! function_exists('installer_recovery_token_matches')) {
    /**
     * 입력한 복구 토큰이 state 에 각인된 해시와 일치하는지 확인한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @param  string  $input  사용자 입력
     * @return bool 일치 여부
     */
    function installer_recovery_token_matches(array $state, string $input): bool
    {
        $stored = $state['recovery_hash'] ?? '';
        $normalized = installer_normalize_recovery_token($input);

        if (! is_string($stored) || $stored === '' || strlen($normalized) !== 32) {
            return false;
        }

        return hash_equals($stored, hash('sha256', $normalized));
    }
}

if (! function_exists('installer_claim_owner')) {
    /**
     * 미주장 state 에 현재 세션을 소유자로 각인한다 (최초 소유 주장).
     *
     * 이미 소유자가 있으면 state 를 그대로 반환한다 — 소유자 교체는 reclaim 으로만.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, mixed> 소유자가 각인된 state
     */
    function installer_claim_owner(array $state): array
    {
        if (installer_state_is_claimed($state)) {
            return $state;
        }

        $state['owner_hash'] = installer_owner_hash(installer_owner_token());

        return installer_issue_recovery_token($state);
    }
}

if (! function_exists('installer_reclaim_owner')) {
    /**
     * 복구 토큰을 확인한 세션에 소유권을 재발급한다.
     *
     * 새 소유 토큰을 만들고 owner_hash 를 교체하며, 사용한 복구 토큰은 회전한다.
     * 호출부가 복구 토큰 대조와 세션 ID 재발급을 먼저 수행한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return array<string, mixed> 소유자가 교체된 state
     */
    function installer_reclaim_owner(array $state): array
    {
        unset($_SESSION['install_owner']);
        $state['owner_hash'] = installer_owner_hash(installer_owner_token());

        return installer_issue_recovery_token($state);
    }
}

if (! function_exists('installer_require_owner')) {
    /**
     * 현재 세션이 소유자가 아니면 409 로 종료한다.
     *
     * 미주장 state 도 거부한다 — 이 게이트를 쓰는 엔드포인트(설치 시작·확장 저장·초기화·중단)는
     * Step 3 소유 주장 뒤에만 의미가 있다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     */
    function installer_require_owner(array $state): void
    {
        if (installer_session_is_owner($state)) {
            return;
        }

        if (function_exists('addLog')) {
            addLog('[ownership] 비소유 세션의 상태변경 요청 거부: '.($_SERVER['SCRIPT_NAME'] ?? 'unknown'));
        }

        installer_json_error(
            409,
            'ownership_conflict',
            installer_ownership_lang(
                'error_ownership_conflict',
                'Another browser session owns this installation. Continue in that browser or use the recovery token.'
            )
        );
    }
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

if (! function_exists('installer_csrf_token')) {
    /**
     * 세션 CSRF 토큰을 반환한다 (없으면 생성). session.php 의 generateCsrfToken 과 같은 저장소를 쓴다.
     *
     * @return string CSRF 토큰
     */
    function installer_csrf_token(): string
    {
        if (! is_string($_SESSION['csrf_token'] ?? null) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (! function_exists('installer_request_json_body')) {
    /**
     * 요청 본문 JSON 을 배열로 반환한다 (실패·비JSON 이면 빈 배열).
     *
     * @return array<string, mixed> 파싱된 본문
     */
    function installer_request_json_body(): array
    {
        static $cached = null;

        if ($cached === null) {
            $raw = @file_get_contents('php://input');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $cached = is_array($decoded) ? $decoded : [];
        }

        return $cached;
    }
}

if (! function_exists('installer_request_csrf_token')) {
    /**
     * 요청이 제시한 CSRF 토큰을 반환한다 — 헤더 → JSON 본문 → 폼 필드 순.
     *
     * sendBeacon 은 헤더를 붙일 수 없어 JSON 본문 경로가 필요하다.
     *
     * @return string 제시된 토큰 (없으면 빈 문자열)
     */
    function installer_request_csrf_token(): string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $body = installer_request_json_body();
        if (is_string($body['csrf_token'] ?? null) && $body['csrf_token'] !== '') {
            return $body['csrf_token'];
        }

        $post = $_POST['csrf_token'] ?? '';

        return is_string($post) ? $post : '';
    }
}

if (! function_exists('installer_csrf_is_valid')) {
    /**
     * 제시된 CSRF 토큰이 세션 토큰과 일치하는지 확인한다.
     *
     * @param  string  $provided  요청이 제시한 토큰
     * @return bool 일치 여부
     */
    function installer_csrf_is_valid(string $provided): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';

        return is_string($expected) && $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}

if (! function_exists('installer_require_csrf')) {
    /**
     * CSRF 토큰이 맞지 않으면 403 으로 종료한다.
     */
    function installer_require_csrf(): void
    {
        if (installer_csrf_is_valid(installer_request_csrf_token())) {
            return;
        }

        installer_json_error(
            403,
            'csrf_invalid',
            installer_ownership_lang('error_csrf_invalid', 'Invalid request token. Refresh the page and try again.')
        );
    }
}

// ---------------------------------------------------------------------------
// DB 연결 지문
// ---------------------------------------------------------------------------

if (! function_exists('installer_normalize_db_prefix')) {
    /**
     * 지문·삭제 확인에 쓰는 테이블 접두사 정규화 — 프론트 DB 테스트와 같은 규칙(빈 값 → g7_).
     *
     * @param  mixed  $prefix  입력 접두사
     * @return string 정규화된 접두사
     */
    function installer_normalize_db_prefix(mixed $prefix): string
    {
        $prefix = is_scalar($prefix) ? (string) $prefix : '';

        return $prefix === '' ? 'g7_' : $prefix;
    }
}

if (! function_exists('installer_db_fingerprint')) {
    /**
     * 정규화한 DB 연결설정의 HMAC 지문을 만든다.
     *
     * @param  array<string, mixed>  $conn  host, port, database, username, password (+ write 는 prefix)
     * @param  string  $key  HMAC 키 (세션 소유 토큰)
     * @return string HMAC-SHA256 hex
     */
    function installer_db_fingerprint(array $conn, string $key): string
    {
        $str = static fn ($value): string => is_scalar($value) ? (string) $value : '';

        $host = $str($conn['host'] ?? '');
        $port = $str($conn['port'] ?? '');

        $canonical = [
            'host' => $host === '' ? 'localhost' : $host,
            'port' => $port === '' ? '3306' : $port,
            'database' => $str($conn['database'] ?? ''),
            'username' => $str($conn['username'] ?? ''),
            'password' => $str($conn['password'] ?? ''),
        ];

        if (array_key_exists('prefix', $conn)) {
            $canonical['prefix'] = installer_normalize_db_prefix($conn['prefix']);
        }

        // json-encode:allow — 지문 입력 직렬화(출력 아님), 값은 모두 문자열
        return hash_hmac('sha256', (string) json_encode($canonical), $key);
    }
}

if (! function_exists('installer_step3_db_connections')) {
    /**
     * Step 3 제출 폼에서 지문 대상 연결설정을 꺼낸다.
     *
     * use_read_db 가 꺼져 있으면 read 연결은 null — 폼에 남은 read 값은 무시한다(이슈 #63).
     *
     * @param  array<string, mixed>  $formData  Step 3 폼 데이터
     * @return array{write: array<string, mixed>, read: array<string, mixed>|null}
     */
    function installer_step3_db_connections(array $formData): array
    {
        $write = [
            'host' => $formData['db_write_host'] ?? '',
            'port' => $formData['db_write_port'] ?? '',
            'database' => $formData['db_write_database'] ?? '',
            'username' => $formData['db_write_username'] ?? '',
            'password' => $formData['db_write_password'] ?? '',
            'prefix' => $formData['db_prefix'] ?? '',
        ];

        $read = null;
        if (! empty($formData['use_read_db'])) {
            $read = [
                'host' => $formData['db_read_host'] ?? '',
                'port' => $formData['db_read_port'] ?? '',
                'database' => $formData['db_read_database'] ?? '',
                'username' => $formData['db_read_username'] ?? '',
                'password' => $formData['db_read_password'] ?? '',
            ];
        }

        return ['write' => $write, 'read' => $read];
    }
}

if (! function_exists('installer_db_test_status')) {
    /**
     * Step 3 제출 설정이 이 세션에서 DB 테스트를 통과한 설정과 같은지 판정한다.
     *
     * @param  string  $type  write|read
     * @param  array<string, mixed>  $conn  제출 연결설정
     * @return string 'ok' | 'not_tested' | 'changed'
     */
    function installer_db_test_status(string $type, array $conn): string
    {
        if (($_SESSION["db_{$type}_tested"] ?? false) !== true) {
            return 'not_tested';
        }

        $stored = $_SESSION["db_{$type}_fp"] ?? '';
        $token = $_SESSION['install_owner'] ?? '';
        if (! is_string($stored) || $stored === '' || ! is_string($token) || $token === '') {
            return 'not_tested';
        }

        return hash_equals($stored, installer_db_fingerprint($conn, $token)) ? 'ok' : 'changed';
    }
}

if (! function_exists('installer_forget_db_test')) {
    /**
     * DB 테스트 통과 기록을 지운다 (실패 시).
     *
     * `false` 를 남기면 isset 계열 검사가 실패도 통과로 본다 — 키 자체를 제거한다.
     *
     * @param  bool  $isReadDb  read 연결이면 true
     */
    function installer_forget_db_test(bool $isReadDb): void
    {
        if ($isReadDb) {
            unset($_SESSION['db_read_tested'], $_SESSION['db_read_fp']);

            return;
        }

        unset($_SESSION['db_write_tested'], $_SESSION['db_write_fp'], $_SESSION['drop_confirm']);
    }
}

// ---------------------------------------------------------------------------
// 기존 테이블 삭제 확인
// ---------------------------------------------------------------------------

if (! function_exists('installer_tables_hash')) {
    /**
     * 테이블 목록의 순서 무관 해시.
     *
     * @param  array<int, string>  $tables  테이블 목록
     * @return string sha256 hex
     */
    function installer_tables_hash(array $tables): string
    {
        $tables = array_map('strval', array_values($tables));
        sort($tables, SORT_STRING);

        return hash('sha256', implode("\n", $tables));
    }
}

if (! function_exists('installer_issue_drop_confirm')) {
    /**
     * 기존 테이블 삭제 확인 토큰을 발급한다.
     *
     * @param  string  $prefix  삭제 대상 접두사 (정규화 전 값 허용)
     * @param  array<int, string>  $prefixTables  접두사로 거른 탐지 테이블
     * @param  int|null  $now  기준 시각 (테스트 주입)
     * @return array{token: string, record: array<string, mixed>}
     */
    function installer_issue_drop_confirm(string $prefix, array $prefixTables, ?int $now = null): array
    {
        $token = bin2hex(random_bytes(16));
        $now ??= time();

        return [
            'token' => $token,
            'record' => [
                'hash' => hash('sha256', $token),
                'prefix' => installer_normalize_db_prefix($prefix),
                'tables_hash' => installer_tables_hash($prefixTables),
                'expires_at' => $now + INSTALLER_DROP_CONFIRM_TTL,
                'consumed' => false,
            ],
        ];
    }
}

if (! function_exists('installer_verify_drop_confirm')) {
    /**
     * drop_tables 요청의 확인 토큰을 검증한다.
     *
     * 최초 사용: 해시·접두사·탐지 테이블 불변·미만료 모두 만족해야 한다.
     * 이미 소비된 확인(같은 소유자의 재시도): 해시·접두사만 확인한다 — 이전 시도가 일부 테이블을
     * 지우거나 만든 뒤 재시도하는 경로(이슈 #319)에서 테이블 목록이 달라지는 것은 정상이다.
     *
     * @param  mixed  $record  state.drop_confirm
     * @param  string  $token  요청이 제시한 확인 토큰
     * @param  string  $prefix  설치 접두사
     * @param  array<int, string>|null  $currentPrefixTables  현재 재탐지 테이블(접두사 필터 후), 조회 실패 시 null
     * @param  int|null  $now  기준 시각 (테스트 주입)
     * @return array{ok: bool, reason: string}
     */
    function installer_verify_drop_confirm(mixed $record, string $token, string $prefix, ?array $currentPrefixTables, ?int $now = null): array
    {
        if (! is_array($record) || ! is_string($record['hash'] ?? null) || $record['hash'] === '') {
            return ['ok' => false, 'reason' => 'not_issued'];
        }

        if ($token === '' || ! hash_equals($record['hash'], hash('sha256', $token))) {
            return ['ok' => false, 'reason' => 'token_mismatch'];
        }

        if (($record['prefix'] ?? null) !== installer_normalize_db_prefix($prefix)) {
            return ['ok' => false, 'reason' => 'prefix_changed'];
        }

        if (! empty($record['consumed'])) {
            return ['ok' => true, 'reason' => 'retry'];
        }

        if ((int) ($record['expires_at'] ?? 0) < ($now ?? time())) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        if ($currentPrefixTables === null) {
            return ['ok' => false, 'reason' => 'tables_unavailable'];
        }

        if (! hash_equals((string) ($record['tables_hash'] ?? ''), installer_tables_hash($currentPrefixTables))) {
            return ['ok' => false, 'reason' => 'tables_changed'];
        }

        return ['ok' => true, 'reason' => 'first_use'];
    }
}

if (! function_exists('installer_apply_drop_confirm')) {
    /**
     * drop_tables 요청에 확인 토큰 검증 결과를 적용해 실제로 수행할 액션을 정한다.
     *
     * 통과하면 확인을 소비(consumed)로 표시한다 — DROP 직전 재검증(task-runner)은 소비된 확인만
     * 인정하므로, 표시가 빠지면 소유자의 삭제 동의가 무시되고 설치가 기존 테이블 때문에 실패한다.
     * 불충족이면 삭제 없이(skip) 진행하도록 강등하고 기록은 그대로 둔다.
     *
     * @param  mixed  $record  state.drop_confirm
     * @param  string  $token  요청이 제시한 확인 토큰
     * @param  string  $prefix  설치 접두사
     * @param  array<int, string>|null  $currentPrefixTables  현재 재탐지 테이블(접두사 필터 후), 조회 실패 시 null
     * @param  int|null  $now  기준 시각 (테스트 주입)
     * @return array{action: string, record: array<string, mixed>|null, rejected: bool, reason: string}
     */
    function installer_apply_drop_confirm(mixed $record, string $token, string $prefix, ?array $currentPrefixTables, ?int $now = null): array
    {
        $verdict = installer_verify_drop_confirm($record, $token, $prefix, $currentPrefixTables, $now);
        $record = is_array($record) ? $record : null;

        if (! $verdict['ok']) {
            return ['action' => 'skip', 'record' => $record, 'rejected' => true, 'reason' => $verdict['reason']];
        }

        $record['consumed'] = true;

        return ['action' => 'drop_tables', 'record' => $record, 'rejected' => false, 'reason' => $verdict['reason']];
    }
}

// ---------------------------------------------------------------------------
// 설치 계획 서명 (무세션 워커 검증용)
// ---------------------------------------------------------------------------

if (! function_exists('installer_plan_payload')) {
    /**
     * 서명 대상 설치 계획을 정규 직렬화한다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return string 직렬화 문자열
     */
    function installer_plan_payload(array $state): string
    {
        $dropConfirm = is_array($state['drop_confirm'] ?? null) ? $state['drop_confirm'] : [];

        // json-encode:allow — 서명 입력 직렬화(출력 아님)
        return (string) json_encode([
            'owner_hash' => (string) ($state['owner_hash'] ?? ''),
            'existing_db_action' => (string) ($state['existing_db_action'] ?? 'skip'),
            'db_prefix' => (string) ($state['config']['db_prefix'] ?? ''),
            'drop_confirm_hash' => (string) ($dropConfirm['hash'] ?? ''),
            'generation' => (int) ($state['generation'] ?? 0),
        ]);
    }
}

if (! function_exists('installer_plan_signature')) {
    /**
     * 설치 계획 서명을 만든다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state (generation 은 커밋될 값)
     * @param  string  $key  runtime.php 의 plan_key
     * @return string HMAC-SHA256 hex
     */
    function installer_plan_signature(array $state, string $key): string
    {
        return hash_hmac('sha256', installer_plan_payload($state), $key);
    }
}

if (! function_exists('installer_plan_verify')) {
    /**
     * 무세션 워커가 실행해도 되는 설치 계획인지 판정한다.
     *
     * 소유자 게이트를 통과한 install-process 만 running 상태와 서명을 쓸 수 있다는 불변식에 기댄다.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @param  array<string, mixed>|null  $runtime  runtime.php 배열
     * @return string 'ok' | 'not_claimed' | 'not_running' | 'no_key' | 'bad_signature'
     */
    function installer_plan_verify(array $state, ?array $runtime): string
    {
        if (! installer_state_is_claimed($state)) {
            return 'not_claimed';
        }

        if (($state['installation_status'] ?? null) !== 'running') {
            return 'not_running';
        }

        $key = $runtime['plan_key'] ?? '';
        if (! is_string($key) || $key === '') {
            return 'no_key';
        }

        $signature = $state['install_plan_sig'] ?? '';
        if (! is_string($signature) || $signature === '' || ! hash_equals(installer_plan_signature($state, $key), $signature)) {
            return 'bad_signature';
        }

        return 'ok';
    }
}

// ---------------------------------------------------------------------------
// 복구 토큰 입력 실패 제한
// ---------------------------------------------------------------------------

if (! function_exists('installer_reclaim_throttle_path')) {
    /**
     * 복구 실패 집계 파일 경로 — state.json 과 분리해 설치 워커 쓰기와 경합하지 않게 한다.
     *
     * @return string 절대 경로
     */
    function installer_reclaim_throttle_path(): string
    {
        return BASE_PATH.'/storage/installer/reclaim-throttle.json';
    }
}

if (! function_exists('installer_reclaim_is_locked')) {
    /**
     * 복구 토큰 입력이 잠겨 있는지 확인한다.
     *
     * @param  int|null  $now  기준 시각
     * @return bool 잠금 여부
     */
    function installer_reclaim_is_locked(?int $now = null): bool
    {
        $now ??= time();
        $data = @json_decode((string) @file_get_contents(installer_reclaim_throttle_path()), true);
        if (! is_array($data)) {
            return false;
        }

        $windowStart = (int) ($data['window_start'] ?? 0);

        return ($now - $windowStart) < INSTALLER_RECLAIM_WINDOW
            && (int) ($data['failures'] ?? 0) >= INSTALLER_RECLAIM_MAX_FAILURES;
    }
}

if (! function_exists('installer_reclaim_record_failure')) {
    /**
     * 복구 토큰 입력 실패를 집계한다.
     *
     * @param  int|null  $now  기준 시각
     */
    function installer_reclaim_record_failure(?int $now = null): void
    {
        $now ??= time();
        $path = installer_reclaim_throttle_path();
        $data = @json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || ($now - (int) ($data['window_start'] ?? 0)) >= INSTALLER_RECLAIM_WINDOW) {
            $data = ['window_start' => $now, 'failures' => 0];
        }

        $data['failures'] = (int) ($data['failures'] ?? 0) + 1;

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // json-encode:allow — 정수만 담는 내부 집계 파일
        @file_put_contents($path, (string) json_encode($data), LOCK_EX);
    }
}

if (! function_exists('installer_reclaim_reset_throttle')) {
    /**
     * 복구 실패 집계를 지운다 (성공·설치 종료 시).
     */
    function installer_reclaim_reset_throttle(): void
    {
        $path = installer_reclaim_throttle_path();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
