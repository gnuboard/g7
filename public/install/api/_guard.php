<?php

declare(strict_types=1);

/**
 * G7 인스톨러 공통 진입 가드
 *
 * 설치가 완료된 시스템에서는 인스톨러의 모든 비즈니스 로직 진입을 차단한다.
 * 운영 환경에서 인스톨러 노출형 취약점의 공격 표면을 영구 제거하기 위한
 * 코드 레벨 안전장치이며, 웹 서버 단의 /install 경로 차단(또는 디렉토리 삭제)
 * 이 1차 방어선이라는 점은 별도 운영 가이드로 안내한다.
 *
 * 판정 신호 (OR):
 *   1. {BASE_PATH}/storage/app/g7_installed 락 파일 존재
 *   2. .env 의 INSTALLER_COMPLETED=true (또는 1, yes)
 *
 * 둘 중 하나라도 참이면 HTTP 410 Gone 으로 즉시 종료.
 */
if (! function_exists('installer_resolve_base_path')) {
    function installer_resolve_base_path(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH;
        }

        // 진입점이 BASE_PATH 를 정의하기 전에 호출된 경우의 폴백.
        // 본 파일은 public/install/api/ 에 위치하므로 3단계 상위가 프로젝트 루트.
        return dirname(__DIR__, 3);
    }
}

if (! function_exists('installer_env_completed_flag_fallback')) {
    /**
     * parse_ini_file 실패 시의 2차 판정 (22차 N-3).
     *
     * 운영자가 손으로 편집한 `.env`(따옴표 불일치·섹션 괄호)는 파서를 깨뜨릴 수 있는데,
     * 그때 fail-open 으로 새면 완료된 사이트에서 가드가 열린다 — finalize 계열은 락 파일
     * 폴백조차 없어 run-task(Artisan 실행)가 열리는 통로가 된다. 줄 앵커라 주석 줄
     * (`# INSTALLER_…`)은 걸리지 않는다. installer-state.php 의 isInstallationCompleted()
     * 가 같은 규칙의 사본을 갖는다(이 파일은 독립 실행이라 상호 의존 불가).
     */
    function installer_env_completed_flag_fallback(string $envFile): bool
    {
        $raw = (string) @file_get_contents($envFile);

        return preg_match('/^\s*INSTALLER_COMPLETED\s*=\s*["\']?(?:true|1|yes)\b["\']?\s*$/mi', $raw) === 1;
    }
}

if (! function_exists('installer_is_completed')) {
    function installer_is_completed(): bool
    {
        $basePath = installer_resolve_base_path();

        $lockFile = $basePath.'/storage/app/g7_installed';
        if (is_file($lockFile)) {
            return true;
        }

        $envFile = $basePath.'/.env';
        if (is_file($envFile)) {
            $env = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
            if (is_array($env)) {
                $flag = strtolower(trim((string) ($env['INSTALLER_COMPLETED'] ?? '')));
                // parse_ini_file 은 따옴표가 보존된 raw 모드이므로 양 끝 따옴표 제거
                $flag = trim($flag, "\"'");
                if (in_array($flag, ['true', '1', 'yes'], true)) {
                    return true;
                }
            } elseif (installer_env_completed_flag_fallback($envFile)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('installer_guard_completed_message')) {
    /**
     * 완료 가드가 운영자에게 보낼 문구를 돌려준다.
     *
     * 사실 관계 (13차 G3 W1-12): API 진입점 12곳 전부가 이 가드를 `$translations` 를 싣기
     * **전에** 부른다. 그래서 이 함수가 실제로 돌려주는 값은 언제나 **영문 폴백**이고, 아래
     * 번역 분기는 현재 어느 진입점에서도 도달하지 않는다. 화면이 그 사유를 운영자 언어로
     * 보여 주는 통로는 문구가 아니라 응답의 `message_key`(`installer_disabled_completed`)다 —
     * `installer.js` 가 그 키를 `INSTALLER_LANG` 으로 번역해 그린다. 이 폴백 문구가 그대로
     * 보이는 경우는 브라우저 주소창으로 API 를 **직접 연** 때뿐이다.
     *
     * 번역 분기를 남겨 두는 이유는 판정 술어의 역사다. 종전 술어 `function_exists('lang')` 은
     * 언제나 참이라(진입점이 `functions.php` 를 가드보다 먼저 싣는다) `lang()` 이 미설정
     * `$translations` 앞에서 **원시 키**를 돌려줬다 — 폴백을 둔 목적이 뒤집혔다. 그래서
     * 판정 대상을 「번역이 실렸는가」로 바꿨다(12차 G3 E-1). 번역이 실린 뒤에 부르는 진입점이
     * 생기면 그때 이 분기가 산다 — 그 전까지는 「번역이 실렸으면 번역문」이 아니라
     * 「지금은 영문 폴백뿐」이 이 함수의 실제 계약이다.
     *
     * @return string 영문 폴백 (번역이 실린 뒤 불리는 경로가 생기면 그때는 번역문)
     */
    function installer_guard_completed_message(): string
    {
        global $translations;

        if (function_exists('lang') && isset($translations) && is_array($translations) && $translations !== []) {
            return lang('installer_disabled_completed');
        }

        return 'Installer is disabled because installation has been completed.';
    }
}

if (! function_exists('installer_guard_or_410')) {
    function installer_guard_or_410(): void
    {
        if (! installer_is_completed()) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Installer: disabled');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'success' => false,
            // 운영자 화면에 도달할 수 있는 문구다 — 판정은 단일 지점이 소유한다.
            'message' => installer_guard_completed_message(),
            'message_key' => 'installer_disabled_completed',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if (! function_exists('installer_finalize_window_open')) {
    /**
     * 완료 표식 뒤 마무리 구간에 설치 소유 세션이 설치 화면(Step 5)을 다시 열 수 있는지 판정한다.
     *
     * complete_flag 작업이 `g7_installed` 를 만든 뒤에도 설정·라우트 캐시 굽기·정적 게시가 이어지고,
     * 그 뒤 설치 화면이 finalize-env 로 `.env` 를 확정한다. 이 구간에 새로고침한 소유 세션까지
     * 410 으로 막으면 `.env` 확정이 영영 실행되지 않으므로, 아래를 모두 만족할 때만 연다.
     *
     *   - GET 요청 (화면 렌더만 — 어떤 POST 처리도 열지 않는다)
     *   - `g7_installed` 있음 + `.env` 완료 플래그 없음
     *   - 상태 파일 있음 + installation_status=completed
     *   - 요청 세션이 그 설치의 소유 세션 (터미널 소유 설치는 브라우저 세션이 소유자일 수 없다)
     *
     * 소유권 판정 함수가 로드되지 않은 진입점에서는 항상 false(닫힘)다.
     *
     * @return bool 설치 화면을 다시 열어도 되면 true
     */
    function installer_finalize_window_open(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return false;
        }

        $basePath = installer_resolve_base_path();

        if (! is_file($basePath.'/storage/app/g7_installed')) {
            return false;
        }

        if (installer_env_completed_flag($basePath.'/.env')) {
            return false;
        }

        if (! function_exists('installer_session_is_owner')) {
            return false;
        }

        $stateFile = $basePath.'/storage/installer-state.json';
        clearstatcache(true, $stateFile);
        if (! is_file($stateFile)) {
            return false;
        }

        $state = json_decode((string) @file_get_contents($stateFile), true);
        if (! is_array($state) || ($state['installation_status'] ?? null) !== 'completed') {
            return false;
        }

        if (($state['owner_kind'] ?? 'web') === 'cli') {
            return false;
        }

        return installer_session_is_owner($state);
    }
}

if (! function_exists('installer_guard_index_or_410')) {
    /**
     * 설치 화면(index.php) 전용 진입 가드.
     *
     * 설치가 끝났으면 일반 가드처럼 410 으로 끝내되, 완료 표식 뒤 마무리 구간에 설치 소유 세션이
     * 다시 연 GET 요청만 통과시킨다(installer_finalize_window_open). 통과하면 호출자는 Step 5 화면만
     * 렌더해야 한다 — 그 화면이 마무리 작업을 기다렸다가 finalize-env 로 `.env` 를 확정한다.
     *
     * @return bool 마무리 구간으로 통과했으면 true, 설치 전이라 통과했으면 false (완료면 410 으로 종료)
     */
    function installer_guard_index_or_410(): bool
    {
        if (! installer_is_completed()) {
            return false;
        }

        if (installer_finalize_window_open()) {
            return true;
        }

        installer_guard_or_410();

        return false;
    }
}

if (! function_exists('installer_finalize_is_completed')) {
    /**
     * finalize-env.php 전용 멱등 차단 신호.
     *
     * 본 함수는 `.env` 의 `INSTALLER_COMPLETED=true` 단독으로만 차단을 판정한다.
     * `storage/app/g7_installed` 락 파일은 finalize 호출 직전 단계인
     * `complete_flag` task 가 먼저 생성하므로 차단 사유에서 제외한다.
     */
    function installer_finalize_is_completed(): bool
    {
        $basePath = installer_resolve_base_path();
        $envFile = $basePath.'/.env';

        if (! is_file($envFile)) {
            return false;
        }

        $env = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if (! is_array($env)) {
            // 파싱 실패 fail-open 금지 (22차 N-3) — 이 함수는 락 파일 폴백이 설계상 없어
            // (docblock), 여기가 열리면 완료된 사이트의 run-task·finalize 가드가 통째로 열린다.
            return installer_env_completed_flag_fallback($envFile);
        }

        $flag = strtolower(trim((string) ($env['INSTALLER_COMPLETED'] ?? '')));
        $flag = trim($flag, "\"'");

        return in_array($flag, ['true', '1', 'yes'], true);
    }
}

if (! function_exists('installer_installation_finished_without_env_flag')) {
    /**
     * 설치는 끝났는데 `.env` 완료 표식만 없는 상태인가 (13차 보안#8).
     *
     * `installer_finalize_is_completed()` 는 `.env` 의 `INSTALLER_COMPLETED` **단독**으로
     * 판정하는데, 그 값을 쓰는 곳은 finalize 하나뿐이다. `.env` 쓰기가 권한 문제로 실패하면
     * DB·관리자 계정이 완비된 **운영 사이트**에서 인스톨러 엔드포인트가 영구히 열린 채 남는다.
     * §16.8 #10 은 「토큰이 필요해 위험이 낮다」로 존치했으나, 그 토큰이 무세션 GET 한 번으로
     * 유출된다는 것이 이번 라운드에서 확인됐으므로(보안#3) 그 근거가 성립하지 않는다.
     *
     * 그래서 세 번째 신호를 둔다: 락 파일(`g7_installed`) **과** 상태 파일의 완료 마커
     * (`completed_tasks ∋ complete_flag`)가 **둘 다** 참이면 설치는 실제로 끝난 것이다.
     * 하나만으로는 판정하지 않는다 — 락 파일은 마지막 태스크가 먼저 만들고, 상태 파일은
     * finalize 가 지우므로 각각 단독으로는 정상 진행 창과 구분되지 않는다.
     *
     * @return bool 설치가 끝났는데 `.env` 표식만 없으면 true
     */
    function installer_installation_finished_without_env_flag(): bool
    {
        if (installer_finalize_is_completed()) {
            // `.env` 표식이 이미 있으면 일반 가드가 담당한다 — 이 신호의 대상이 아니다.
            return false;
        }

        $basePath = installer_resolve_base_path();

        if (! is_file($basePath.'/storage/app/g7_installed')) {
            return false;
        }

        // 경로 SSoT 는 installer-state.php 의 STATE_PATH 다 — 이 가드가 그보다 먼저 로드될 수
        // 있어 상수가 없을 때만 같은 규칙으로 역산한다 (session.php 와 같은 규율).
        $statePath = defined('STATE_PATH') ? (string) STATE_PATH : $basePath.'/storage/installer-state.json';

        if (! is_file($statePath) || ! is_readable($statePath)) {
            return false;
        }

        $decoded = json_decode((string) @file_get_contents($statePath), true);

        if (! is_array($decoded)) {
            return false;
        }

        return in_array('complete_flag', (array) ($decoded['completed_tasks'] ?? []), true);
    }
}

if (! function_exists('installer_guard_post_complete_or_410')) {
    /**
     * 설치가 끝난 뒤에도 열려 있는 파괴적 엔드포인트를 닫는다 (13차 보안#8).
     *
     * `.env` 표식이 없어 `installer_guard_finalize_or_410()` 이 통과시키는 창에서도, 설치가
     * 실제로 끝났다면(위 세 번째 신호) 재시작·중단·초기화·워커 진입은 성립하지 않는다.
     *
     * `finalize-env.php` 는 이 가드를 쓰지 않는다 — 그 엔드포인트가 바로 표식을 박는 복구
     * 경로다. `state-management.php?action=get` 도 대상이 아니다: 마지막 태스크가 락 파일을
     * 만든 직후에도 폴링이 `completed` 를 한 번 읽어야 완료 화면으로 전환되고 그 화면이
     * finalize 를 부른다 — 여기서 막으면 정상 설치가 완료 화면에 도달하지 못한다.
     *
     * 같은 이유로 `run-task.php` 와 `index.php` 도 대상이 아니다. 이 신호는 **「finalize 가
     * 실패했다」와 「finalize 가 아직 안 왔다」를 구분하지 못한다** — 실측하면 `complete_flag`
     * 태스크가 락 파일을 만들고 완료 마커를 남긴 그 순간부터 참이 되는데, 정상 설치는 그
     * 뒤에 아직 두 가지가 남아 있다:
     *
     *  - 태스크 목록의 마지막은 `complete_flag` 가 아니라 `config_cache` 다
     *    (`cache_clear → complete_flag → config_cache`). 단계별 실행에서 그 마지막 태스크를
     *    나르는 것이 `run-task.php` 다 — 여기에 이 가드를 걸면 **모든 정상 stepwise 설치가
     *    마지막 태스크에서 410 으로 끝난다**.
     *  - 그 창에서 새로고침하면 `index.php` 가 완료 화면을 복원하고, 그 화면이 finalize 를
     *    다시 부른다 — `.env` 쓰기가 한 번 실패했을 때 브라우저에서 되살릴 수 있는 유일한
     *    경로다. 여기를 막으면 평문 DB 비밀번호를 담은 `runtime.php` 가 그대로 남는다.
     *
     * 두 엔드포인트의 진입 조건은 `installer_guard_finalize_or_410()` + 발급 토큰이 담당한다.
     * 토큰은 이제 설치를 시작한 세션에만 실리므로(13차 보안#3) 「토큰이 유출되니 이 가드로
     * 대신 막자」는 근거도 성립하지 않는다.
     */
    function installer_guard_post_complete_or_410(): void
    {
        if (! installer_installation_finished_without_env_flag()) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Installer: disabled');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'success' => false,
            'message' => installer_guard_completed_message(),
            'message_key' => 'installer_disabled_completed',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if (! function_exists('installer_guard_finalize_or_410')) {
    /**
     * 락 파일이 이미 만들어진 뒤에도 진행해야 하는 엔드포인트용 진입 가드.
     *
     * 차단 조건: `.env` 의 `INSTALLER_COMPLETED=true` 단독. `g7_installed` 락 파일
     * 존재는 차단 사유가 아니다 — complete_flag task 가 락 파일을 먼저 만든 직후에도
     * `.env` 머지(finalize-env)와 남은 태스크(run-task 의 config_cache)가 돌아야 한다.
     *
     * 소비자 6곳: `finalize-env.php` · `state-management.php` · `run-task.php` +
     * **복구 진입점 3곳** `install-process.php`(재시작·[다시 시도]) · `install-worker.php`(SSE
     * 재연결) · `index.php`(새로고침). 앞의 셋만 완화하면 그 창에서 무언가 어긋났을 때
     * 운영자가 실패 화면에서 나갈 수 없다 — 재시도·재연결·새로고침이 전부 410 이고, 정작
     * 설치는 끝나지 않은 상태(`.env` 미머지)로 남는다. 인스톨러 흐름의 완료 표식은
     * `.env` 의 `INSTALLER_COMPLETED` 이며 이 가드가 보는 것이 정확히 그것이다.
     * 이 중 `run-task.php` 는 Artisan 커맨드를 실행하는 **인스톨러에서 공격 표면이 가장
     * 큰 엔드포인트**다 — 이 가드만으로 지키지 않고 발급 토큰(`X-Installer-Token`,
     * runtime.php 0600 전용)을 함께 요구한다. 이 가드의 통과 폭을 넓히면 그 엔드포인트의
     * 진입 조건도 함께 넓어지므로, 조건을 바꿀 때는 여섯 소비자를 모두 보고 판단한다.
     *
     * 락 파일 생성 전에 끝나는 일반 인스톨러 엔드포인트는 계속 `installer_guard_or_410()`
     * 을 쓴다.
     */
    function installer_guard_finalize_or_410(): void
    {
        if (! installer_finalize_is_completed()) {
            return;
        }

        if (! headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Installer: disabled');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'success' => false,
            'message' => installer_guard_completed_message(),
            'message_key' => 'installer_disabled_completed',
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}
