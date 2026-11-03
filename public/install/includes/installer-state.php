<?php

/**
 * G7 인스톨러 상태 관리 시스템
 *
 * 설치 진행 상태를 storage/installer-state.json에 저장하고 조회합니다.
 */

// 로그 정규화의 mbstring 가용성 판정에 쓴다 (16차 B-1). 해석은 호출 시점에 일어나므로
// 이 파일을 단독 로드하는 경로에서도 함수를 부르지 않는 한 문제가 되지 않는다 —
// functions.php 가 이미 같은 클래스를 같은 방식으로 쓴다.
use App\Support\ExecutionEnvironment;

if (! defined('BASE_PATH')) {
    // `realpath` 도 호스팅이 막을 수 있는 함수다 — 이 파일을 단독 로드하는 경로에서는 이 줄이
    // 첫 코드라, 막힌 환경에서 무가드로 부르면 어떤 봉투도 서기 전에 치명 종료한다.
    // 형태는 `config.php` 의 폴백과 같다(사본이 갈리면 진입점에 따라 백지가 갈린다 — 15차 G3 W1-10).
    define('BASE_PATH', function_exists('realpath')
        ? (realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3))
        : dirname(__DIR__, 3)); // public/install/includes에서 프로젝트 루트로
}

// UTF-8 정규화 헬퍼 — scan-extensions 경로에서는 config.php 보다 먼저 로드된다.
require_once __DIR__.'/utf8.php';

if (! defined('STATE_PATH')) {
    define('STATE_PATH', BASE_PATH.'/storage/installer-state.json');
}

if (! defined('INSTALLER_STATE_LOCK_SUFFIX')) {
    /**
     * 상태 파일 배타 구간의 잠금 파일 접미사 (`installer-state.json.lock`).
     *
     * 생산자(`installerWithStateLock`)·finalize 정리·정리 주체(`StorageLeftoverPruneService`)가
     * 같은 이름을 봐야 한다. 종전에는 세 곳이 각자 `'.lock'` 리터럴이었고 프루너는 이 파일을
     * 몰라, 설치를 포기한 사이트에 영구 잔존했다 (15차 G3 W1-4). 인스톨러는 Laravel 부팅 전
     * 코드라 값이 두 곳에 있고 그 일치는 `StorageLeftoverPruneServiceTest` 가 잠근다.
     */
    define('INSTALLER_STATE_LOCK_SUFFIX', '.lock');
}
if (! defined('INSTALLER_DIR')) {
    define('INSTALLER_DIR', BASE_PATH.'/storage/installer');
}

// 로그 정규화가 `isWindows()` 를 쓴다 (13차 환경#16 — 판정 사본 제거). 이 파일만 단독으로
// 로드하는 경로(단위 테스트·일부 API)가 있으므로 의존을 명시한다. functions.php 는 최상위
// 부수효과 없이 함수·상수만 선언하므로 순서 의존이 생기지 않는다. 임시 파일 이름의 중간
// 조각·바이트 수·판정 패턴(`INSTALLER_TMP_*`)도 그 파일 소유라 아래 정의보다 먼저 싣는다.
require_once __DIR__.'/functions.php';

if (! defined('INSTALLER_STATE_TMP_INFIX')) {
    /**
     * 상태 파일 원자 교체 임시 이름의 중간 조각 (`installer-state.json.tmp.<hex>`).
     *
     * 값은 이름 생성 SSoT(`functions.php` 의 `INSTALLER_TMP_INFIX`)를 그대로 가리킨다 —
     * 리터럴 사본은 갈려도 아무것도 울리지 않는다 (14차 G3 W2-4).
     */
    define('INSTALLER_STATE_TMP_INFIX', INSTALLER_TMP_INFIX);
}

if (! defined('INSTALLER_STATE_RENAME_ATTEMPTS')) {
    /**
     * 상태 파일 원자 교체의 재시도 횟수·간격 (D4).
     *
     * Windows 는 대상 파일을 다른 핸들이 열고 있으면 `rename()` 이 실패한다(POSIX 는 성공).
     * 인스톨러는 같은 상태 파일을 여러 진입점이 동시에 읽으므로 그 찰나에 부딪힌다 —
     * 실측(2026-09-11)에서 44초짜리 태스크 도중 1건 났고, 그 저장이 조용히 유실됐다.
     *
     * 경합은 밀리초 단위라 5회 × 20 ms(최대 80 ms 대기)면 덮고도 남는다. 리눅스 공유
     * 호스팅에서는 첫 시도가 성공하므로 비용이 0 이다. **재시도로 없앨 수 없는 실패**
     * (권한 소실·디스크 풀·경로 부재)는 이 값과 무관하게 끝까지 false 로 남는다.
     */
    define('INSTALLER_STATE_RENAME_ATTEMPTS', 5);
}

if (! defined('INSTALLER_STATE_RENAME_DELAY_US')) {
    define('INSTALLER_STATE_RENAME_DELAY_US', 20000);
}

if (! defined('INSTALLER_STATE_TMP_SUFFIX_PATTERN')) {
    /**
     * 임시 파일 접미사의 **생성 규칙**(16진) — 만드는 쪽과 지우는 쪽이 같은 값을 본다.
     *
     * 값은 이름 생성 SSoT(`functions.php` 의 `INSTALLER_TMP_SUFFIX_PATTERN`)를 그대로
     * 가리킨다. 파일마다 리터럴을 두면 한쪽만 갈린다 — 실제로 형제(`installer-runtime.php`)가
     * `{8}` 로 갈려 그쪽 스윕 모집단이 0 이었다 (13차 G3 W1-1).
     *
     * 형식을 보지 않으면 `<대상>.tmp.*` 에 걸린 것을 무조건 지운다 — 운영자가 같은 이름
     * 공간에 둔 파일(`installer-state.json.tmp.backup` 등)이 300초 뒤 조용히 사라진다.
     * 형제 두 지점(`RootHtaccessUpdater::TEMP_SUFFIX_PATTERN` ·
     * `StorageLeftoverPruneService::pruneRootEntryTemp`)이 같은 규칙을 쓰는데, 이 파일은
     * 그 둘에게 **선례로 인용까지 되면서** 자기만 게이트가 없었다 (11차 G3 라운드 E11-2).
     */
    define('INSTALLER_STATE_TMP_SUFFIX_PATTERN', INSTALLER_TMP_SUFFIX_PATTERN);
}

if (! defined('INSTALLER_STATE_CORRUPT_INFIX')) {
    /**
     * 파손 상태 사본 이름의 중간 조각 (`installer-state.json.corrupt.<YmdHis>.<sha8>`).
     *
     * 생산자(`installerPreserveCorruptState`)는 상한 5 로 만들기만 하고 지우는 지점이 없었다 —
     * 마스킹 전 비밀을 담을 수 있는 파일이 영구 잔존한다. 정리 주체(`StorageLeftoverPruneService`)
     * 가 같은 이름 규칙을 봐야 하며, 인스톨러는 Laravel 부팅 전 코드라 값이 두 곳에 있고
     * 그 일치는 `StorageLeftoverPruneServiceTest` 가 잠근다 (13차 G3 W1-2).
     */
    define('INSTALLER_STATE_CORRUPT_INFIX', '.corrupt.');
}

if (! defined('INSTALLER_STATE_CORRUPT_STAMP_FORMAT')) {
    /** 파손 사본의 시각 조각 형식 (`date()` 형식 문자열, 14자리). */
    define('INSTALLER_STATE_CORRUPT_STAMP_FORMAT', 'YmdHis');
}

if (! defined('INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN')) {
    /** 파손 사본 접미사(`<YmdHis>.<sha1 앞 8자>`)의 판정 패턴 — 첫 그룹이 시각 조각이다. */
    define('INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN', '/^(\d{14})\.[0-9a-f]{8}$/');
}

// 이 파일은 `glob`·`chmod` 를 쓴다 — 둘 다 하드닝 프로필이 막을 수 있고, 막히면 PHP 8 은
// 함수 테이블에서 지우므로 그 호출은 `Error`(치명)다. 하필 **설치 진행 상태를 저장하는
// 경로**라 한 줄이 죽으면 설치가 한 걸음도 나아가지 못한다. 가드 판정은 저장소 단일
// SSoT(`installerHasFunction` → `ExecutionEnvironment::hasFunction`)를 그대로 쓴다 —
// 여기서 `function_exists` 로 사본을 만들면 판정 지점이 둘로 갈린다. execution-mode.php 는
// 최상위 부수효과 없이 함수만 선언하므로 functions.php 와 같은 이유로 순서 의존이 없다.
require_once __DIR__.'/execution-mode.php';

/**
 * 상태 파일 파손 여부를 기억한다 (13차 #D13).
 *
 * 파싱 실패는 종전에 「부재」와 같은 취급이었다 — 기본 상태를 돌려주고 끝. 그런데 파일은
 * 그대로 남아 있으므로, 그 뒤 어떤 헬퍼든(heartbeat·완료 마커·current_task) 저장하는 순간
 * 기본 상태가 원본을 덮어써 `completed_tasks`·`config`·`installation_mode` 가 영구히
 * 사라진다. 예외도 로그도 남지 않고 설치가 처음부터 다시 도는 것으로만 보인다.
 *
 * @param  bool|null  $set  내부 갱신용 (호출부는 인수 없이 조회만 한다)
 * @return bool 파손 상태인지
 */
function installerStateIsCorrupt(?bool $set = null): bool
{
    static $corrupt = false;

    if ($set !== null) {
        $corrupt = $set;
    }

    return $corrupt;
}

/**
 * 파손된 상태 파일을 타임스탬프 사본으로 보존한다.
 *
 * 원본은 그대로 둔다 — 운영자가 초기화(파일 삭제)를 선택하기 전까지는 「파손된 설치가
 * 진행 중」이라는 사실 자체가 상태다. 사본은 원인 분석 근거로 남긴다.
 *
 * @param  string  $content  파손된 원본 내용
 */
function installerPreserveCorruptState(string $content): void
{
    // 폴링 클라이언트는 같은 파손 파일을 2초마다 다시 읽는다 — 요청마다 새 프로세스라
    // 정적 플래그가 초기화되므로 사본이 무한 증식할 수 있다. 그래서 **같은 내용**은 한 번만
    // 남긴다 (17차 #C6) — 종전처럼 「사본이 하나라도 있으면 중단」하면 두 번째 이후의 **다른**
    // 파손은 근거가 남지 않아, 「과거 어느 시점에 한 번 깨졌다」밖에 알 수 없었다.
    $digest = substr(sha1($content), 0, 8);
    $existing = installerListCorruptStateCopies();

    foreach ($existing as $candidate) {
        if (str_ends_with((string) $candidate, '.'.$digest)) {
            return;
        }
    }

    // 서로 다른 파손이 계속 나는 병리적 환경에서 디렉토리가 잠식되지 않도록 상한을 둔다.
    //
    // 상한에 닿으면 **가장 오래된 사본을 내보내고 새 것을 남긴다** (14차 G3 W2-8). 종전처럼
    // 조용히 `return` 하면 다섯이 찬 뒤로는 새 파손의 근거가 0 이고, 프루너가 백업 등급
    // (30일 + 최신 1개)으로 걷을 때까지 그 침묵이 이어진다 — 상한의 목적은 잠식 방지이지
    // 최신 근거의 폐기가 아니다. 목록은 이름순(= 타임스탬프순)이라 첫 항목이 가장 오래된
    // 것이다 — 다만 **같은 초 안에서는 sha8 사전순**이라 생성 순서와 무관하고, 그 구간에서
    // 성립하는 약속은 「열거 순서와 무관하게 언제나 같은 하나가 나간다 + 새 사본은 남는다」
    // 뿐이다 (15차 G3 W1-8). 내보내지 못하면(삭제 불가) 잠식을 막기 위해 종전대로 남기지
    // 않되, 그 사실은 로그에 남긴다 — 어느 쪽이든 조용하지 않다.
    if (count($existing) >= 5) {
        $oldest = (string) $existing[0];

        if (! @unlink($oldest)) {
            installerErrorLog('[installer-state] Corrupt state copy cap (5) reached and the oldest copy could not be evicted; new copy not preserved: '.$oldest);

            return;
        }

        installerErrorLog('[installer-state] Corrupt state copy cap (5) reached; evicted oldest copy: '.$oldest);
    }

    $backup = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX.date(INSTALLER_STATE_CORRUPT_STAMP_FORMAT).'.'.$digest;

    // `installerWroteFully` 의 출처는 installer-runtime.php 다. 이 파일을 싣는 진입점 중
    // 그것까지 싣는 것은 일부뿐이라(`index.php`·`save-extensions.php`·`session.php` 는 싣지
    // 않는다), 로드 순서에 전이 의존하면 **파손 상태 파일을 만난 순간** fatal 이 된다 —
    // 이 함수가 존재하는 이유가 바로 그 상황이라, 복구 안내를 띄워야 할 자리에서 화면이
    // 통째로 백지가 된다. 형제 지점(`buildEnvContent` 의 `replaceEnvLine`)이 같은 이유로
    // 같은 형태를 쓴다 (21차 6c). 내부 함수 가용성 판정이라 차단 함수 축의 대상이 아니다.
    if (! function_exists('installerWroteFully')) {
        require_once __DIR__.'/installer-runtime.php';
    }

    // 손상 상태 파일의 보존 사본이다 — 잘리면 진단 근거로써의 값이 없다.
    if (installerWroteFully(@file_put_contents($backup, $content), $content)) {
        if (installerHasFunction('chmod')) {
            @chmod($backup, 0600);
        }
        installerErrorLog('[installer-state] Corrupt state preserved at: '.$backup);

        return;
    }

    // 성공만 적으면 **실패는 아무 데도 남지 않는다** (D7 형제 — 같은 침묵, 같은 원인).
    //
    // 이 사본을 못 쓰는 가장 흔한 이유가 디스크 풀이고, 그때는 「파손을 만났다」는 사실조차
    // 기록이 0 이 된다 — 상태 파일은 이미 못 읽고, 사본도 없고, 이 함수는 조용히 끝난다.
    // 다음 세션은 원인을 처음부터 찾는다. 그래서 다른 매체(PHP 오류 로그)에 남기고, 원인을
    // 가르는 값(여유 공간)을 함께 적는다.
    installerErrorLog(
        '[installer-state] Corrupt state copy could not be written — this line is the only record of '.
        'that corruption. target='.$backup.' '.installerFreeSpaceNote()
    );
}

/**
 * 파손 상태 사본을 나열한다 — **생성 규칙과 같은 형태만**.
 *
 * 상한(5) 판정과 「같은 내용 1회」 판정이 이 목록을 근거로 하므로, 형식이 다른 파일
 * (운영자가 같은 이름 공간에 둔 메모 등)이 끼면 상한이 조기 소진돼 새 파손의 근거가
 * 남지 않는다.
 *
 * `glob` 이 막힌 호스팅에서는 `scandir` 로 나열한다 — 형제 `installerListExposureProbeFiles()`
 * 와 같은 폴백이다. 종전에는 그 환경에서 빈 목록이었고, 그러면 두 가드가 **동시에 증발**해
 * 폴링(2초)마다 마스킹 전 상태 파일 사본이 무제한 증식했다 (14차 G3 W2-2). 둘 다 막히면
 * 나열 수단이 없으므로 빈 목록이다(사본은 여전히 남긴다).
 *
 * 목록은 이름순(= 타임스탬프순)으로 정렬돼 있다 — 상한에서 내보낼 「가장 오래된 것」이
 * 열거 순서에 좌우되지 않도록. `glob` 도 `scandir` 도 순서를 계약하지 않는다. 같은 초 안의
 * 사본은 이름의 나머지(sha8) 사전순이다 — 결정적이지만 생성 순서와는 무관하다(초 해상도로는
 * 어느 쪽이 나중인지 알 수 없다).
 *
 * @return array<int, string> 사본 절대 경로 목록 (정렬됨)
 */
function installerListCorruptStateCopies(): array
{
    $prefix = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX;
    $candidates = [];

    if (installerHasFunction('glob')) {
        $candidates = glob($prefix.'*') ?: [];
    } elseif (installerHasFunction('scandir')) {
        $dir = dirname(STATE_PATH);
        $name = basename($prefix);

        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && str_starts_with($entry, $name)) {
                $candidates[] = $dir.'/'.$entry;
            }
        }
    }

    $copies = [];

    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;

        if (preg_match(INSTALLER_STATE_CORRUPT_SUFFIX_PATTERN, substr($candidate, strlen($prefix))) === 1) {
            $copies[] = $candidate;
        }
    }

    sort($copies, SORT_STRING);

    return $copies;
}

/**
 * 설치 상태 조회
 *
 * @return array 설치 상태 배열
 */
function getInstallationState(): array
{
    // 기본 상태 가져오기 (config.php에서 정의)
    $defaultState = DEFAULT_INSTALLATION_STATE;

    // 런타임 타임스탬프 추가
    $defaultState['last_updated'] = date('Y-m-d\TH:i:s\Z');

    // 파일 상태 캐시 초기화 (다른 프로세스의 변경 감지를 위해)
    clearstatcache(true, STATE_PATH);

    // state.json 파일이 없으면 기본 상태 반환
    // (설치 완료 후 워커가 DELETE_INSTALLER_AFTER_COMPLETE로 정상 삭제하는 경우가 있으므로
    //  부재 자체는 에러가 아님. 호출자가 g7_installed 플래그 등으로 완료 여부 판정)
    if (! file_exists(STATE_PATH)) {
        // 부재는 파손이 아니다 — 초기화·정상 삭제 후의 정상 경로이므로 판정도 함께 푼다.
        installerStateIsCorrupt(false);

        return $defaultState;
    }

    // 파일 읽기 권한 체크
    if (! is_readable(STATE_PATH)) {
        $msg = '[installer-state] State file is not readable: '.STATE_PATH;
        installerErrorLog($msg);
        addLog($msg);

        return $defaultState;
    }

    // state.json 파일 읽기
    $content = @file_get_contents(STATE_PATH);

    // 파일 읽기 실패 시 기본 상태 반환
    if ($content === false) {
        $msg = '[installer-state] Failed to read state file: '.STATE_PATH;
        installerErrorLog($msg);
        addLog($msg);

        return $defaultState;
    }

    $state = json_decode($content, true);

    // JSON 파싱 실패 시 기본 상태 반환 (재귀 호출 제거 - 무한 루프 방지)
    //
    // 파손은 「부재」와 다르다 (13차 #D13). 부재는 아직/더 이상 설치가 없다는 뜻이지만, 파손은
    // 원본이 남아 있는데 읽지 못한다는 뜻이다. 그 구분 없이 기본 상태를 돌려주면 다음 저장이
    // 원본을 덮어써 완료 마커·설정·진행 모드가 통째로 사라진다. 사본을 남기고 판정을 세워
    // 저장을 막는다 — 진행이 멈추는 대신 데이터가 남는다.
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($state)) {
        // 원문은 싣지 않는다 — 길이와 digest 만 (15차 G3 W1-9). 비밀은 상태 파일에 없어야 하지만
        // (이슈 #465) 레거시 상태 파일이 남아 있을 수 있고, 종전에는 앞 200 바이트가 무마스킹으로
        // 진행 기록과 PHP 오류 로그에 실렸다. digest 는 보존 사본 이름의 접미사와 같은 값이라
        // 로그와 사본을 대조할 수 있다.
        $contentLen = strlen($content);
        $digest = substr(sha1($content), 0, 8);
        $msg = "[installer-state] Failed to parse state file JSON (length={$contentLen} sha1={$digest}): ".json_last_error_msg();
        installerErrorLog($msg);
        addLog($msg);

        if (! installerStateIsCorrupt()) {
            installerPreserveCorruptState($content);
        }
        installerStateIsCorrupt(true);

        return $defaultState;
    }

    installerStateIsCorrupt(false);

    // 시그널로 죽은 워커의 고착 상태를 여기서 실패로 승격한다 (13차 환경#10).
    //
    // 판정을 상태를 읽는 **이 단일 지점**에 두는 이유: 세 진입점에 흩어 놓으면 한 곳만
    // 빠져도 그 모드에서만 같은 고착이 남는다. 승격 조건과 그 한계는
    // `installerWorkerBudgetExpired()` 의 주석이 소유한다.
    return installerRecoverKilledWorkerState($state);
}

/**
 * 설치 소유권 필드 목록.
 *
 * generation 이 더 높은 쪽이 이긴다 — 설치 워커·heartbeat 처럼 generation 을 올리지 않는
 * 쓰기가 옛 사본을 저장해도 소유자 교체·설치 계획 서명이 되돌아가지 않게 한다.
 *
 * @return array<int, string> 소유권 필드 키
 */
function installerOwnershipStateKeys(): array
{
    return ['owner_hash', 'owner_kind', 'recovery_hash', 'generation', 'drop_confirm', 'install_plan_sig', 'existing_db_action'];
}

/**
 * 디스크의 state.json 을 그대로 읽는다 (없거나 손상이면 null).
 *
 * @return array<string, mixed>|null 디스크 state
 */
function installerReadStateFile(): ?array
{
    clearstatcache(true, STATE_PATH);

    if (! is_file(STATE_PATH)) {
        return null;
    }

    $decoded = json_decode((string) @file_get_contents(STATE_PATH), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * 이 프로세스가 쥐고 있는 파일 락을 기록한다.
 *
 * 락 구간이 fatal 로 끊기면 finally 가 실행되지 않으므로, shutdown 단계에서 이 기록을 보고
 * 재진입하거나(installerWithStateLock) 직접 풀 수 있게 한다(installerReleaseInterruptedLocks).
 *
 * @param  string  $name  락 이름 (state | log)
 * @param  resource  $handle  flock 을 쥔 파일 핸들
 * @return void 반환값 없음
 */
function installerTrackHeldLock(string $name, $handle): void
{
    $GLOBALS['g7_installer_held_locks'][$name] = $handle;
}

/**
 * 락 기록을 지운다 (정상 해제 직전 호출).
 *
 * @param  string  $name  락 이름
 * @return void 반환값 없음
 */
function installerUntrackHeldLock(string $name): void
{
    unset($GLOBALS['g7_installer_held_locks'][$name]);
}

/**
 * 끊긴 락 구간이 남긴 파일 락을 모두 푼다.
 *
 * shutdown 단계 전용 — 그 시점에 쥐어져 있는 락은 정상 흐름이 아니라 fatal 로 중간에
 * 끊긴 구간이 남긴 것이다. 풀지 않으면 이어지는 로그·상태 쓰기가 자기 락을 기다린다.
 *
 * @return int 푼 락 수
 */
function installerReleaseInterruptedLocks(): int
{
    $held = $GLOBALS['g7_installer_held_locks'] ?? [];
    $GLOBALS['g7_installer_held_locks'] = [];
    // 끊긴 구간의 재진입 깊이도 되돌린다 — 남기면 shutdown 의 상태 쓰기가 잠금 없이 돈다.
    $GLOBALS['g7_installer_state_lock_depth'] = 0;

    $released = 0;
    foreach ($held as $handle) {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $released++;
        }
    }

    return $released;
}

/**
 * 설치 상태 저장
 *
 * 디스크 generation 이 메모리 사본보다 높으면 소유권 필드는 디스크 값을 유지한다.
 *
 * @param  array  $state  저장할 상태 배열
 * @return bool 저장 성공 여부
 */
function saveInstallationState(array $state): bool
{
    return installerWithStateLock(static function () use ($state): bool {
        $disk = installerReadStateFile();

        if ($disk !== null && (int) ($disk['generation'] ?? 0) > (int) ($state['generation'] ?? 0)) {
            foreach (installerOwnershipStateKeys() as $key) {
                if (array_key_exists($key, $disk)) {
                    $state[$key] = $disk[$key];
                } else {
                    unset($state[$key]);
                }
            }
        }

        return installerWriteStateFile($state);
    });
}

/**
 * 설치 상태를 compare-and-swap 으로 저장한다.
 *
 * 디스크 generation 이 기대값과 같을 때만 generation+1 로 저장한다. 다르면 그 사이 다른
 * 상태변경 요청이 먼저 커밋한 것이므로 false (호출부는 409 로 응답).
 *
 * @param  array  $state  저장할 상태 배열
 * @param  int  $expectedGeneration  읽을 때의 generation
 * @return bool 저장 성공 여부 (충돌·쓰기 실패 시 false)
 */
function saveInstallationStateCas(array $state, int $expectedGeneration): bool
{
    return installerWithStateLock(static function () use ($state, $expectedGeneration): bool {
        $disk = installerReadStateFile();
        $diskGeneration = $disk === null ? 0 : (int) ($disk['generation'] ?? 0);

        if ($diskGeneration !== $expectedGeneration) {
            $msg = "[installer-state] CAS 충돌: expected={$expectedGeneration}, disk={$diskGeneration}";
            installerErrorLog($msg);
            addLog($msg);

            return false;
        }

        $state['generation'] = $expectedGeneration + 1;

        return installerWriteStateFile($state);
    });
}

/**
 * state.json 을 원자적으로 기록한다 (락·병합은 호출부 책임).
 *
 * @param  array  $state  저장할 상태 배열
 * @return bool 저장 성공 여부
 */
function installerWriteStateFile(array $state): bool
{
    // 파손된 원본 위에 덮어쓰지 않는다 (13차 #D13). 일반 저장과 CAS 저장이 모두 이 기록 지점을 지나므로
    // 판정도 여기 둔다. 여기서 통과시키면 파싱 실패가 돌려준
    // 기본 상태가 원본을 대체해 완료 마커·설정이 영구 소실된다. 호출부는 이미 저장 실패를
    // 오류로 다루므로(예: error_task_marker_not_persisted) 실패 신호가 화면까지 도달한다.
    if (installerStateIsCorrupt()) {
        $msg = '[installer-state] Refusing to overwrite a corrupt state file: '.STATE_PATH;
        installerErrorLog($msg);
        addLog($msg);

        return false;
    }

    // storage 디렉토리 존재 여부 확인 (생성하지 않음)
    $storageDir = BASE_PATH.'/storage';
    if (! is_dir($storageDir)) {
        $msg = "[installer-state] Storage directory does not exist: {$storageDir}";
        installerErrorLog($msg);
        addLog($msg);

        return false;
    }

    // 디렉토리 쓰기 권한 확인
    if (! is_writable($storageDir)) {
        $msg = "[installer-state] Storage directory is not writable: {$storageDir}";
        installerErrorLog($msg);
        addLog($msg);

        return false;
    }

    // last_updated 타임스탬프 업데이트
    $state['last_updated'] = date('Y-m-d\TH:i:s\Z');

    // JSON 형식으로 저장 — 외부 유래 값(경로·확장 이름·로그)이 섞이므로 먼저 정규화한다.
    // 이 함수는 addLog 재귀 금지 구역이라 installer_json_encode 대신 자체 가드를 쓴다.
    // json-encode:allow — 정규화 + substitute 를 직접 적용한 자리
    $content = json_encode(
        installer_utf8_normalize_deep($state),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($content === false) {
        $msg = '[installer-state] Failed to encode state as JSON: '.json_last_error_msg();
        installerErrorLog($msg);
        addLog($msg);

        return false;
    }

    // 원자적 쓰기: tmp 파일에 쓰고 rename으로 교체
    // 동시 쓰기 경쟁 상황에서 부분 쓰기(half-written file)로 인한 JSON 손상 방지.
    // Linux에서 rename()은 atomic syscall이므로 다른 리더가 항상 완전한 파일만 읽는다.
    // 남겨진 tmp 를 치운다 (17차 #C7). 쓰기 성공과 rename 사이에서 프로세스가 죽으면(FPM
    // 타임아웃 · OOM-kill · 재시작) tmp 가 남는데 청소 주체가 없었다. 각 파일은 완전한 DB
    // 접속 정보를 담으므로 누적은 저장 공간이 아니라 **노출** 문제다. 요청당 한 번만 훑는다.
    static $tmpSwept = false;
    if (! $tmpSwept) {
        $tmpSwept = true;
        $tmpPrefix = STATE_PATH.INSTALLER_STATE_TMP_INFIX;

        foreach (installerHasFunction('glob') ? (glob($tmpPrefix.'*') ?: []) : [] as $stale) {
            $stale = (string) $stale;

            // **생성 규칙과 같은 형태만** 지운다 — 형제 두 지점과 같은 규율.
            if (preg_match(INSTALLER_STATE_TMP_SUFFIX_PATTERN, substr($stale, strlen($tmpPrefix))) !== 1) {
                continue;
            }

            $mtime = @filemtime($stale);

            // mtime 을 읽지 못하면 **보존**한다. `false < (time() - 300)` 은 PHP 8 에서
            // true 라, 종전 조건은 조회 실패를 「아주 오래됨」으로 읽어 지웠다(fail-open).
            // 형제 둘은 정반대다 — 한쪽은 `$mtime !== false &&`, 한쪽은 조회 실패 시 보존.
            // 진행 중인 다른 요청의 tmp 를 지우지 않도록 충분히 오래된 것만.
            if ($mtime !== false && $mtime < time() - 300) {
                @unlink($stale);
            }
        }
    }

    // 임시 이름은 고유하기만 하면 된다. 종전에는 `getmypid()` 로 갈랐는데 그 함수는 하드닝
    // 프로필의 차단 대상이라 막힌 호스팅에서 PHP 8 은 함수 테이블에서 지운다 — 즉 Error(치명)
    // 이고, 하필 **상태 저장 경로**라 그 한 줄로 설치가 한 걸음도 나아가지 못한다. 형제
    // 선례(`reserveCommandOutputFile`)와 같은 난수원으로 얻는다 (3차 G3 라운드).
    $tmpPath = STATE_PATH.INSTALLER_STATE_TMP_INFIX.installerUniqueSuffix(INSTALLER_TMP_SUFFIX_BYTES);

    $result = @file_put_contents($tmpPath, $content, LOCK_EX);
    if ($result === false || $result !== strlen($content)) {
        // 여유 공간을 **함께** 적는다 (D7). 이 실패의 현장 사례는 디스크 풀이었는데, 종전
        // 메시지는 「썼는데 안 됐다」까지만 말해 운영자도 다음 세션도 원인을 알 수 없었다.
        // 부분 쓰기(`$result !== strlen`)는 특히 디스크 풀의 전형적인 모양이다.
        $msg = '[installer-state] Failed to write state tmp file: '.$tmpPath.' '.installerFreeSpaceNote();
        installerErrorLog($msg);
        addLog($msg);
        @unlink($tmpPath);

        return false;
    }

    // 최종 파일 권한은 종전대로 0664 다. tmp 단계에서 넓히지 않는 이유는 rename 전에
    // 프로세스가 죽으면 그 권한 그대로 남기 때문이다 (17차 #C7).
    if (installerHasFunction('chmod')) {
        @chmod($tmpPath, 0664);
    }

    if (! installerReplaceFileWithRetry($tmpPath, STATE_PATH)) {
        $msg = '[installer-state] Failed to rename state tmp file: '.$tmpPath.' → '.STATE_PATH;
        installerErrorLog($msg);
        addLog($msg);
        @unlink($tmpPath);

        return false;
    }

    return true;
}

if (! function_exists('installerFreeSpaceNote')) {
    /**
     * 저장 실패 진단에 붙일 「남은 공간」 한 조각 (D7).
     *
     * 상태 저장이 실패하는 이유는 여럿이지만(권한·경로·풀), 현장에서 실제로 난 것은 **디스크
     * 풀**이었고 그때 남은 기록은 「썼는데 안 됐다」뿐이었다. 원인을 가르는 값이 한 번의
     * 호출로 얻어지는데 아무도 적지 않았다.
     *
     * `disk_free_space` 는 하드닝 프로필의 차단 대상이라 가드가 필요하다 — 이 함수가 쓰이는
     * 자리는 **이미 실패 처리 중**이라, 여기서 치명 오류를 내면 진단이 원래 오류를 덮는다.
     *
     * @return string `free=12345 bytes` 또는 `free=unknown` (항상 한 조각, 비어 있지 않다)
     */
    function installerFreeSpaceNote(): string
    {
        if (! installerHasFunction('disk_free_space')) {
            return 'free=unknown (disk_free_space unavailable)';
        }

        $base = defined('BASE_PATH') ? (string) BASE_PATH : '.';
        $free = @disk_free_space($base);

        if ($free === false || ! is_numeric($free)) {
            return 'free=unknown';
        }

        return 'free='.number_format((float) $free, 0, '.', '').' bytes';
    }
}

if (! function_exists('installerReplaceFileWithRetry')) {
    /**
     * 임시 파일을 최종 이름으로 **원자적으로** 옮긴다 — 짧은 핸들 경합은 재시도로 넘긴다 (D4).
     *
     * 배경 (실측 2026-09-11, 단계별 리허설):
     *   이커머스 모듈 설치(44초짜리 태스크) 도중 `[installer-state] Failed to rename state tmp file`
     *   이 1건 났다. 설치는 계속 진행돼 정상 완료했지만, **그 시점의 상태 저장은 유실됐다** —
     *   반환값을 보지 않는 호출부가 셋 있어(`install-process.php:612`·`functions.php`·이 파일의
     *   진행률 갱신) 그 유실은 어디에도 드러나지 않는다. 그 상태로 새로고침 재개가 걸리면
     *   재개 지점이 실제 진행보다 뒤로 간다.
     *
     * 원인: POSIX `rename()` 은 대상이 열려 있어도 원자적으로 성공하지만, **Windows 는
     * 대상 파일을 다른 핸들이 `FILE_SHARE_DELETE` 없이 열고 있으면 실패한다.** 인스톨러는
     * 같은 상태 파일을 여러 진입점이 동시에 읽는다(진행 조회·태스크 실행·세션 초기화) —
     * 그 읽기가 겹치는 찰나에 rename 이 부딪힌다. 경합은 **밀리초 단위로 끝난다.**
     *
     * 그래서 실패를 곧바로 확정하지 않고 짧게 몇 번 다시 시도한다. 이것은 재시도로 **없앨 수
     * 있는 실패**와 **없앨 수 없는 실패**(권한 소실·디스크 풀·경로 부재)를 가르는 장치이지,
     * 후자를 감추는 장치가 아니다 — 끝까지 실패하면 종전과 똑같이 false 를 돌려준다.
     *
     * 리눅스 공유 호스팅에서는 첫 시도가 성공하므로 이 경로가 비용을 더하지 않는다.
     *
     * @param  string  $from  임시 파일
     * @param  string  $to  최종 경로
     * @return bool 옮겼으면 true
     */
    function installerReplaceFileWithRetry(string $from, string $to): bool
    {
        $attempts = defined('INSTALLER_STATE_RENAME_ATTEMPTS') ? max(1, (int) INSTALLER_STATE_RENAME_ATTEMPTS) : 5;
        $delayUs = defined('INSTALLER_STATE_RENAME_DELAY_US') ? max(0, (int) INSTALLER_STATE_RENAME_DELAY_US) : 20000;

        for ($i = 0; $i < $attempts; $i++) {
            if (@rename($from, $to)) {
                return true;
            }

            // 옮길 원본이 사라졌으면 재시도해도 같다 — 경합이 아니라 다른 실패다.
            if (! is_file($from)) {
                return false;
            }

            // 마지막 시도 뒤에는 기다리지 않는다(설치를 그만큼 늦추지 않는다).
            if ($i < $attempts - 1 && function_exists('usleep')) {
                usleep($delayUs);
            }
        }

        return false;
    }
}

if (! function_exists('installerDiscardCorruptState')) {
    /**
     * 파손된 상태 원본을 치워 저장을 다시 가능하게 한다 — 운영자의 명시적 초기화 전용 (17차 #C5).
     *
     * 13차 #D13 이 「파손 원본 위에 덮어쓰지 않는다」를 세운 뒤, 파손이 나면 초기화·재시작·중단이
     * 전부 저장 거부로 500 이 됐다. `STATE_PATH` 를 지우는 제품 코드는 finalize(설치 완료 게이트
     * 뒤) 하나뿐이라 운영자에게는 **파일 시스템 접근 외의 복구 수단이 없었다.**
     *
     * 자동으로 부르지 않는다. 초기화는 진행분을 의도적으로 버리는 유일한 조작이므로 그 자리에서만
     * 쓴다 — 읽기 경로에서 부르면 13차 #D13 을 통째로 되돌리는 것이 된다. 파손 사본은 남긴다.
     *
     * @return bool 실제로 치웠으면 true (파손이 아니거나 삭제 실패면 false)
     */
    function installerDiscardCorruptState(): bool
    {
        if (! installerStateIsCorrupt()) {
            return false;
        }

        // 사본은 통상 getInstallationState() 가 이미 남겼다. 없으면 여기서 남긴다 —
        // 원인 분석 근거를 지우면서 복구하는 것이 되어선 안 된다.
        if (installerListCorruptStateCopies() === [] && is_file(STATE_PATH)) {
            installerPreserveCorruptState((string) @file_get_contents(STATE_PATH));
        }

        if (is_file(STATE_PATH) && ! @unlink(STATE_PATH)) {
            $msg = '[installer-state] Failed to discard corrupt state file: '.STATE_PATH;
            installerErrorLog($msg);
            addLog($msg);

            return false;
        }

        installerStateIsCorrupt(false);

        return true;
    }
}

if (! function_exists('installerSaveStateOrReport')) {
    /**
     * 상태를 저장하고, 실패하면 그 사실을 남긴다 (17차 #C2).
     *
     * `saveInstallationState()` 는 디스크 풀·권한 소실·파손 거부·인코딩 실패에서 `false` 를
     * 돌려주는데, 결과를 버리는 호출부가 10곳이었다. 그중 셋은 버려지면 조용히 나쁜 상태가
     * 남는다 — 실패 기록(고착: status=running + failed_task 없음 → [건너뛰고 계속] 불가) ·
     * 예외 기록(같은 형태) · 시딩 후 비밀 제거(관리자 비밀번호가 state.json 에 잔류).
     *
     * 저장 실패는 **되돌릴 수단이 없다**(파손 거부는 의도된 것이라 우회해선 안 되고, 디스크·권한은
     * 인스톨러가 고칠 수 없다). 그래서 이 헬퍼는 재시도하지 않고 **보이게만** 한다 — 이 결함의
     * 실체가 「조용함」이기 때문이다. 호출부는 반환값으로 후속 판단을 할 수 있다.
     *
     * @param  array<string, mixed>  $state  저장할 상태
     * @param  string  $context  실패 시 기록에 남길 지점 이름 (예: 'task_failure')
     * @return bool 저장 성공 여부
     */
    function installerSaveStateOrReport(array $state, string $context): bool
    {
        if (saveInstallationState($state)) {
            return true;
        }

        $msg = '[installer-state] Failed to persist state at: '.$context;
        installerErrorLog($msg);
        addLog($msg);

        return false;
    }
}

if (! function_exists('installerSecretConfigKeys')) {
    /**
     * state.json 에 절대 기록해서는 안 되는 config 비밀 키 목록.
     *
     * 이 키들의 값은 storage/installer/runtime.php (0600) 로만 전달한다.
     * state.json 은 0664 라 웹 서버 그룹 전체가 읽을 수 있고, 설치 실패/중단 시
     * 무기한 잔존하므로 평문 비밀의 보관처로 부적합하다 (이슈 #465).
     *
     * @return array<int, string> 비밀 config 키 목록
     */
    function installerSecretConfigKeys(): array
    {
        return [
            'db_write_password',
            'db_read_password',
            'admin_password',
            'admin_password_confirm',
            // GitHub Personal Access Token — 프라이빗 저장소 접근 권한을 갖는 password 입력이다.
            // 다른 비밀 4종과 같은 이유로 state.json 에 두지 않는다 (runtime.php 0600 이 보관처).
            'core_update_github_token',
        ];
    }
}

if (! function_exists('sanitizeConfigForState')) {
    /**
     * state.json 에 저장하기 전 config 배열을 정제한다.
     *
     * 1) 비밀 5종 제거 (이슈 #465 — GitHub 토큰 포함)
     * 2) use_read_db 미사용 시 read 접속 필드 잔존값 제거 (이슈 #63 2단계 방어)
     *    — use_read_db=false 인데 db_read_host 등이 남아 있으면 하류
     *      (installer-runtime.php) 로 유입되어 잘못된 read 커넥션을 만들 수 있다.
     *
     * @param  array<string, mixed>  $config  폼/세션에서 온 원본 config
     * @return array<string, mixed> state 저장용 안전 config
     */
    function sanitizeConfigForState(array $config): array
    {
        foreach (installerSecretConfigKeys() as $secretKey) {
            unset($config[$secretKey]);
        }

        if (empty($config['use_read_db'])) {
            unset(
                $config['db_read_host'],
                $config['db_read_port'],
                $config['db_read_database'],
                $config['db_read_username']
            );
        }

        return $config;
    }
}

if (! function_exists('redactInstallationStateSecrets')) {
    /**
     * 이미 기록된 state 배열에서 비밀 5종만 제거한다 (레거시/실패 경로 방어).
     *
     * sanitizeConfigForState 와 달리 read 접속 필드(비밀 아님) 는 건드리지 않는다 —
     * 롤백/db_cleanup 이 그 값을 사용하기 때문.
     *
     * @param  array<string, mixed>  $state  현재 인스톨러 state
     * @return array<string, mixed> 비밀이 제거된 state
     */
    function redactInstallationStateSecrets(array $state): array
    {
        if (! isset($state['config']) || ! is_array($state['config'])) {
            return $state;
        }

        foreach (installerSecretConfigKeys() as $secretKey) {
            unset($state['config'][$secretKey]);
        }

        return $state;
    }
}

if (! function_exists('installer_site_language')) {
    /**
     * 설치되는 사이트의 기본 언어를 state 에서 정한다.
     *
     * `.env APP_LOCALE`·`APP_FAKER_LOCALE` 과 설정 `general.language` 가 모두 이 값을 쓴다.
     * 세션(UI 언어)을 읽지 않는다 — SSE 워커·터미널 설치처럼 세션이 없는 실행 경로가 있어,
     * 세션을 읽으면 실행 경로마다 결과가 달라진다(설정은 ko, .env 는 en 으로 어긋나던 결함).
     *
     * 우선순위: config.site_language(Step 3 숨김 필드·`--site-language`) → g7_locale(설치 UI 언어) → ko.
     *
     * @param  array<string, mixed>  $state  인스톨러 state
     * @return string 지원 언어 코드
     */
    function installer_site_language(array $state): string
    {
        $supported = defined('SUPPORTED_LANGUAGES') ? array_keys(SUPPORTED_LANGUAGES) : ['ko', 'en'];

        foreach ([$state['config']['site_language'] ?? null, $state['g7_locale'] ?? null] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return 'ko';
    }
}

/**
 * 설치 완료 여부 확인
 *
 * @return bool 설치 완료 여부
 */
function isInstallationCompleted(): bool
{
    $state = getInstallationState();

    // installation_status가 completed인지 확인
    if (isset($state['installation_status']) && $state['installation_status'] === 'completed') {
        return true;
    }

    // 또는 current_step이 5(완료 단계)이고 step_status[5]가 completed인지 확인
    if (isset($state['current_step']) && $state['current_step'] >= 5) {
        if (isset($state['step_status']['5']) && $state['step_status']['5'] === 'completed') {
            return true;
        }
    }

    // g7_installed 파일 존재 여부 확인 (추가 안전장치)
    $installedFlagPath = BASE_PATH.'/storage/app/g7_installed';
    if (file_exists($installedFlagPath)) {
        return true;
    }

    // .env 파일의 INSTALLER_COMPLETED 플래그 확인 — 판정은 API 게이트(_guard.php 의
    // installer_is_completed)와 **같은 파서**여야 한다 (21차 #21-1). 종전의 strpos 부분문자열
    // 판정은 두 게이트를 반대로 가르었다: 등호 주변 공백(`INSTALLER_COMPLETED = true`)이면
    // 화면만 미완료로 읽어 운영 사이트에 설치 화면이 재개방되고(API 는 전부 410 이라 운영자는
    // 「설치가 초기화됐다」는 거짓 신호를 본다), 주석 처리된 줄(`# INSTALLER_COMPLETED=true`)
    // 이나 접미 키(`OLD_INSTALLER_COMPLETED=true`)면 화면만 완료로 읽어 재설치가 막힌다.
    // 판정 함수는 _guard.php 가 소유한다 — 함수 정의만 담은 파일이라 여기서 읽어도 부작용이 없다.
    // `.env` 는 INI 파서로 읽지 않는다(배포 템플릿의 괄호 주석이 파싱을 깨뜨린다).
    require_once dirname(__DIR__).'/api/_guard.php';

    return installer_env_completed_flag(BASE_PATH.'/.env');
}

if (! function_exists('installerConfigSaveStatusPatch')) {
    /**
     * Step 3 설정 저장이 상태 파일에 얹을 수명주기 패치를 정한다 (21차 #21-9 ①).
     *
     * 진행 이력이 있는 상태(running/failed/aborted)는 되감지 않는다 — 다른 탭의 Step 3
     * 재저장이 `failed` 를 `ready` 로 덮으면, 열려 있던 실패 화면의 [다시 시도]가 신규
     * 설치로 판정되어 완료 마커·롤백 기준선·실패 로그가 소거된다. 설정(config) 갱신
     * 자체는 정상 동작이므로 상태 필드만 보존한다.
     *
     * @param  string|null  $currentStatus  현재 installation_status
     * @return array{installation_status?: string} 상태 파일에 병합할 패치
     */
    function installerConfigSaveStatusPatch(?string $currentStatus): array
    {
        if (in_array($currentStatus, ['running', 'failed', 'aborted'], true)) {
            return [];
        }

        return ['installation_status' => 'ready'];
    }
}

if (! function_exists('installerIsRetryRun')) {
    /**
     * 이번 시작 요청이 「재시도」인지 판정한다 (21차 #21-9 ②).
     *
     * 상태 문자열만 보면 안 된다 — 그 필드는 Step 3 재저장 같은 바깥 경로가 갈아치울 수
     * 있다(보존 패치가 막지만, 한 겹으로 두면 그 겹이 새는 순간 같은 사고가 재발한다).
     * 진짜 근거는 **이번 회전이 이미 한 일**이다: 완료 마커가 남아 있으면 그 태스크들을
     * 다시 돌리면 안 되고(이미 테이블이 있는 DB 에 migrate/seed 재실행 — 관리자 중복 생성),
     * 롤백 기준선이 남아 있으면 지우는 순간 이후 실패의 롤백 범위를 계산할 수 없다.
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return bool 재시도면 true (마커·기준선·로그를 보존해야 한다)
     */
    function installerIsRetryRun(array $state): bool
    {
        if (in_array($state['installation_status'] ?? null, ['running', 'failed', 'aborted'], true)) {
            return true;
        }

        if (! empty($state['completed_tasks'])) {
            return true;
        }

        return isset($state['db_migrate_batch_baseline']) && $state['db_migrate_batch_baseline'] !== null;
    }
}

if (! function_exists('installerWithStateLock')) {
    /**
     * 상태 파일의 read-modify-write 를 배타 구간 안에서 수행한다 (12차 #A3).
     *
     * 상태 파일은 여러 요청이 동시에 만진다 — 새로고침 직후의 끊긴 요청과 재개 요청,
     * 워커와 폴링 조회가 같은 창에 겹친다. 읽기·판정·쓰기가 보호 밖이면 나중에 쓴 쪽이
     * 상대의 갱신을 통째로 덮어쓰고, 사라지는 것은 완료 마커·건너뜀·워커 점유다.
     * 완료 마커가 사라지면 그 태스크가 다시 뽑히고, DB 정리가 다시 돌면 방금 만든
     * 테이블이 DROP 된다 — 예외도 오류도 남지 않는다.
     *
     * 잠금을 얻지 못해도(파일 시스템이 flock 을 지원하지 않는 공유 호스팅 등) 콜백은
     * 그대로 수행한다 — 보호가 없던 종전 동작으로 떨어질 뿐, 진행이 막히지는 않는다.
     *
     * 구간의 경계: **짧은 상태 헬퍼만** 감싼다. task-runner 의 흐름 코드(실패 기록 · 예외
     * 기록 · 마무리)도 상태를 읽고 고쳐 쓰지만, 그 읽기와 쓰기 사이에 진행 이벤트 송출(SSE
     * flush)이 끼어 있어 구간에 넣으면 네트워크 대기 동안 파일 잠금을 쥐게 된다. 그 경로들은
     * 워커 lock 을 이미 점유한 요청 안에서만 돌고, 겹치는 상대(중단·건너뛰기 엔드포인트)와의
     * 경합은 그쪽이 재로드하는 것(#A1)으로 닫는다.
     *
     * 재진입과 쥔 잠금은 프로세스 전역에 기록한다. 구간 안의 쓰기가 fatal(실행 시간 초과 등)로
     * 끊기면 finally 가 돌지 않아 잠금이 쥐어진 채 shutdown 함수로 넘어간다. 거기서 상태를 저장하려고
     * 새 핸들로 LOCK_EX 를 요청하면 같은 프로세스가 쥔 잠금을 기다리며 영원히 멈추고(Windows·Linux
     * 공통), 그 뒤 다른 요청의 상태 쓰기(재연결 워커·재시도·중단)도 모두 같은 잠금에서 멈춘다.
     * 기록이 남아 있으면 재진입으로 처리되고, shutdown 처리기는 installerReleaseInterruptedLocks()
     * 로 그 잠금을 풀고 깊이를 되돌린다.
     *
     * @template T
     *
     * @param  callable(): T  $mutator  구간 안에서 수행할 읽기·판정·쓰기
     * @return T 콜백의 반환값
     */
    function installerWithStateLock(callable $mutator)
    {
        // 재진입 가드 — 같은 프로세스가 같은 파일에 두 번 flock 하면 자기 자신과 교착한다.
        // (건너뛰기 → 완료 마커처럼 구간 안에서 다른 헬퍼를 부르는 경로가 실제로 있다.)
        if ((int) ($GLOBALS['g7_installer_state_lock_depth'] ?? 0) > 0
            || isset($GLOBALS['g7_installer_held_locks']['state'])) {
            return $mutator();
        }

        $guard = @fopen(STATE_PATH.INSTALLER_STATE_LOCK_SUFFIX, 'c');
        $locked = $guard !== false && @flock($guard, LOCK_EX);

        // 잠금을 못 얻으면 보호 없이 진행한다(위 docblock) — 그러나 **그 사실은 남긴다** (17차 #C9).
        // 종전에는 완전 무음이라, 13개 헬퍼의 배타 구간이 통째로 사라진 채 12차 #A3 이전 동작으로
        // 되돌아가도 어디에도 흔적이 없었다. 요청마다 반복 기록되지 않도록 프로세스당 한 번만.
        if (! $locked) {
            static $lockWarned = false;
            if (! $lockWarned) {
                $lockWarned = true;
                installerErrorLog('[installer-state] flock unavailable — state writes proceed without the exclusive section: '.STATE_PATH.INSTALLER_STATE_LOCK_SUFFIX);
            }
        } else {
            installerTrackHeldLock('state', $guard);
        }

        $GLOBALS['g7_installer_state_lock_depth'] = (int) ($GLOBALS['g7_installer_state_lock_depth'] ?? 0) + 1;

        try {
            return $mutator();
        } finally {
            $GLOBALS['g7_installer_state_lock_depth'] = max(0, (int) ($GLOBALS['g7_installer_state_lock_depth'] ?? 1) - 1);
            if ($guard !== false) {
                if ($locked) {
                    installerUntrackHeldLock('state');
                    @flock($guard, LOCK_UN);
                }
                @fclose($guard);
            }
        }
    }
}

/**
 * 특정 작업을 완료로 표시
 *
 * @param  string  $task  작업 식별자
 * @return bool 업데이트 성공 여부
 */
function markTaskCompleted(string $task): bool
{
    return (bool) installerWithStateLock(static function () use ($task): bool {
        $state = getInstallationState();

        // completed_tasks 배열에 추가 (중복 방지)
        //
        // `?? []` 는 형제 지점들이 이미 쓰는 방어다 (17차 #C8). 파싱은 성공했지만 그 키가 없는
        // state.json — 구버전·수동 편집·외부 도구 생성 — 에서 `in_array($task, null)` 은 PHP 8
        // 에서 TypeError 이고, 그러면 워커가 태스크 완료를 기록하다 즉사한다.
        $completed = $state['completed_tasks'] ?? [];
        if (! in_array($task, $completed, true)) {
            $completed[] = $task;
        }
        $state['completed_tasks'] = $completed;

        // 현재 작업이 완료된 작업과 같으면 초기화 — `?? null`·엄격 비교는 형제
        // (markTaskBestEffortFailed)와 같은 방어다 (17차 #C8 형제, 22차 마이너).
        if (($state['current_task'] ?? null) === $task) {
            $state['current_task'] = null;
            $state['current_task_name'] = null;
        }

        return saveInstallationState($state);
    });
}

/**
 * best-effort 작업의 실패를 기록한다 — completed 에는 넣지 않되 "지나간 작업" 으로 셈한다.
 *
 * 단계별 실행은 completed_tasks 에 없는 첫 작업을 다음 작업으로 뽑으므로, 실패한 best-effort
 * 작업을 아무 데도 기록하지 않으면 같은 작업을 영원히 다시 뽑는다(무한 루프). 재시도 때는
 * install-process.php 가 이 목록을 비워 다시 시도하게 한다.
 *
 * @param  string  $task  작업 식별자 (taskId 또는 taskId:target)
 * @return bool 업데이트 성공 여부
 */
function markTaskBestEffortFailed(string $task): bool
{
    return (bool) installerWithStateLock(static function () use ($task): bool {
        $state = getInstallationState();
        $failed = $state['best_effort_failed_tasks'] ?? [];

        if (! in_array($task, $failed, true)) {
            $failed[] = $task;
        }
        $state['best_effort_failed_tasks'] = $failed;

        if (($state['current_task'] ?? null) === $task) {
            $state['current_task'] = null;
            $state['current_task_name'] = null;
        }

        return saveInstallationState($state);
    });
}

/**
 * 특정 작업을 완료 목록에서 제거
 *
 * @param  string  $task  작업 식별자
 * @return bool 업데이트 성공 여부
 */
function removeTaskCompleted(string $task): bool
{
    return (bool) installerWithStateLock(static function () use ($task): bool {
        $state = getInstallationState();

        // 형제 지점과 같은 방어 (17차 #C8) — 키가 없으면 array_filter 가 TypeError 다.
        $state['completed_tasks'] = array_values(
            array_filter($state['completed_tasks'] ?? [], fn ($t) => $t !== $task)
        );

        return saveInstallationState($state);
    });
}

if (! function_exists('installerClientFailureFields')) {
    /**
     * 화면이 실패·건너뜀을 그리는 데 쓰는 필드 묶음 (조회 응답 공용 SSoT).
     *
     * 같은 데이터를 두 엔드포인트가 내보낸다 — `state-management.php?action=get`(폴링·새로고침)
     * 과 `run-task.php` 의 상태 요약(단계별 실행)이다. 각자 배열 리터럴을 갖고 있으면 한쪽에만
     * 필드가 추가되고, 그 차이는 예외도 오류도 없이 **한 진행 모드에서만 문구가 비는 것**으로만
     * 나타난다(치환값 누락 → `:errors` 원문 노출이 실제로 그 형태였다).
     *
     * @param  array<string, mixed>  $state  설치 상태
     * @return array<string, mixed> 화면 공용 필드
     */
    function installerClientFailureFields(array $state): array
    {
        return [
            'current_task' => $state['current_task'] ?? null,
            'completed_tasks' => $state['completed_tasks'] ?? [],
            'skipped_tasks' => $state['skipped_tasks'] ?? [],
            'best_effort_failed_tasks' => $state['best_effort_failed_tasks'] ?? [],
            'failed_task' => $state['failed_task'] ?? null,
            'failed_task_skippable' => (bool) ($state['failed_task_skippable'] ?? false),
            'error_message_key' => $state['error_message_key'] ?? null,
            // 키를 내보내는 곳은 치환값도 함께 내보낸다 — 화면은 키를 우선 번역하므로
            // 이것이 빠지면 자리표시자가 원문 그대로 나간다.
            'error_message_params' => $state['error_message_params'] ?? null,
            'error_detail' => $state['error_detail'] ?? null,
            'rollback_failure' => $state['rollback_failure'] ?? null,
            'manual_commands' => $state['manual_commands'] ?? null,
            // 중단 사유 — 서버는 「사람이 눌렀다」와 「연결이 끊겼다」를 구분해 기록해 왔는데
            // 그 값을 아무도 읽지 않아 중단 화면이 두 경우에 같은 문구만 보여 줬다 (11차 #A12).
            // 다국어 키로 싣는다(원문 문자열이 아니라) — 화면이 그대로 번역해 그린다.
            'abort_reason' => $state['abort_reason'] ?? null,
        ];
    }
}

/**
 * 다른 워커가 아직 살아 있는지 판정한다 (lock 획득·상태 가드 공용 SSoT).
 *
 * 점유자가 스스로 선언한 유효 시간(`worker_stale_after`)을 존중한다 — 태스크 실행 중에는
 * heartbeat 를 갱신할 수 없으므로(exec/Artisan::call 이 블로킹), 호출자 기본값만 보면 자기
 * 시간 예산이 더 긴 요청(in-process·subprocess 부팅은 600s, stepwise 는 180s)이 아직 살아
 * 있는데도 stale 로 판정한다. 이 판정을 두 곳에서 다르게 하면 한쪽은 "살아 있다" 로 물러나고
 * 다른 쪽은 "죽었다" 로 그 워커의 완료 마커를 지운다.
 *
 * @param  array<string, mixed>  $state  현재 상태
 * @param  int  $fallbackStaleSeconds  점유자가 선언하지 않았을 때 쓸 기준(초)
 * @return bool 살아 있는 다른 워커가 점유 중인지
 */
function installerActiveWorkerIsAlive(array $state, int $fallbackStaleSeconds): bool
{
    // 「살아 있다」와 「얼마나 남았는가」를 **한 셈에서** 낸다 (D7).
    //
    // 종전에는 이 함수만 있었고, 화면 안내가 남은 시간을 말하려면 같은 식을 다시 써야 했다.
    // 사본을 두면 판정은 「아직 점유 중」인데 안내는 「0초 남음」이라고 말하는 상태가 조용히
    // 생긴다 — D1 에서 승격 규칙을 한 함수로 뽑은 것과 같은 축이다.
    return installerWorkerLockRemainingSeconds($state, $fallbackStaleSeconds) > 0;
}

/**
 * 점유 중인 lock 이 자동으로 풀리기까지 남은 초 (D7 — 판정과 안내의 공용 SSoT).
 *
 * 점유자가 스스로 선언한 유효 시간(`worker_stale_after`)을 존중한다 — 근거는
 * `installerActiveWorkerIsAlive` 의 설명과 같다.
 *
 * **이 값이 왜 필요한가.** 워커가 치명 종료(디스크 풀·OOM·시그널)로 죽으면 해제 기록이
 * 남지 않아, 점유는 오직 이 시간이 지나야 풀린다. 그동안 화면은 「다른 창에서 진행 중」이라고
 * 단정했는데 그 창은 없을 수 있다 — 우리가 아는 사실은 「아직 예산 안이다」뿐이다(현장 제보
 * 2026-09-12, 이 값이 600 이라 10분간 그 단정이 노출됐다). 아는 것만 말하려면 안내가 이
 * 숫자를 가져야 한다.
 *
 * @param  array<string, mixed>  $state  현재 상태
 * @param  int  $fallbackStaleSeconds  점유자가 선언하지 않았을 때 쓸 기준(초)
 * @return int 남은 초 (점유자가 없거나 이미 지났으면 0)
 */
function installerWorkerLockRemainingSeconds(array $state, int $fallbackStaleSeconds): int
{
    $activeId = $state['active_worker_id'] ?? null;
    if ($activeId === null) {
        return 0;
    }

    // 구 상태 파일에는 이 키가 없으므로 종전 동작(호출자 기본값)으로 떨어진다.
    $staleAfter = max($fallbackStaleSeconds, (int) ($state['worker_stale_after'] ?? 0));
    $elapsed = time() - (int) ($state['last_heartbeat'] ?? 0);

    // 시계가 뒤로 가면(NTP 보정·컨테이너 시각 점프) 경과가 음수가 되어 항상 alive 로 판정된다
    // — 살아 있는 워커가 하나도 없는데 모든 진입점이 영구 busy 를 받고, 운영자에게는 화면이
    // 멈춘 것으로만 보인다 (13차 환경#19). 미래 heartbeat 는 신뢰할 수 없는 값이므로 stale 로 본다.
    if ($elapsed < 0) {
        return 0;
    }

    return max(0, $staleAfter - $elapsed);
}

/**
 * 워커 lock 획득 시도 (시나리오 B — 동시 실행 race 차단).
 *
 * SSE 워커가 backgroud 진행 중인 상태에서 폴링 워커가 진입하면 두 워커가
 * 동일 인스톨러 state + DB 를 동시 조작하여 cleanup → migrate race 발생.
 * state.active_worker_id + state.last_heartbeat 를 통해 동시 실행을 차단한다.
 *
 * 발동 조건:
 * - last_heartbeat 가 staleSeconds 이내면 다른 워커가 활동 중 → 거부
 * - staleSeconds 초과 또는 active_worker_id 부재 → takeover 또는 신규 획득
 *
 * @param  int  $staleSeconds  heartbeat 가 이 시간 이상 갱신 안 되면 stale 판정 (default 15초)
 * @return array{acquired: bool, worker_id: string|null, reason: string}
 *                                                                       reason: 'available' | 'takeover_stale' | 'busy'
 */
function acquireWorkerLock(int $staleSeconds = 15): array
{
    // 읽기·확인·쓰기를 state 쓰기 락 안에서 한 번에 한다 — 따로 하면 두 워커(웹 SSE·폴링·터미널)가
    // 같은 순간 둘 다 "비어 있음" 을 읽고 둘 다 잠금을 얻는다. 새로고침 직후
    // 끊긴 요청과 재개 요청이 같은 태스크를 겹쳐 돌리는 창도 같다. 파일 잠금으로 둘을 한 구간에 묶는다.
    // 그 구간은 상태 파일을 고쳐 쓰는 모든 헬퍼가 공유한다 (12차 #A3) — 여기만 잠그면
    // heartbeat·해제·완료 마커 쪽이 그대로 유실 창으로 남는다.
    return installerWithStateLock(static function () use ($staleSeconds): array {
        $state = getInstallationState();
        $now = time();

        $activeId = $state['active_worker_id'] ?? null;

        if (installerActiveWorkerIsAlive($state, $staleSeconds)) {
            return ['acquired' => false, 'worker_id' => null, 'reason' => 'busy'];
        }

        $reason = $activeId !== null ? 'takeover_stale' : 'available';
        // 이 값은 비밀이 아니라 **겹치지 않으면 되는 이름**이다 — `installerUniqueSuffix()`
        // 가 그 구분을 명문화한 단일 지점이고, CSPRNG 를 얻지 못하는 호스팅에서
        // `random_bytes()` 는 던진다. 무가드로 두면 그 한 줄로 단계별 설치가 500 이 된다.
        $newWorkerId = installerUniqueSuffix(8);

        $state['active_worker_id'] = $newWorkerId;
        $state['last_heartbeat'] = $now;
        $state['worker_stale_after'] = $staleSeconds;
        // 시그널 종료(php-fpm request_terminate_timeout · LiteSpeed lsapi_max_process_time ·
        // cgroup OOM-kill)는 shutdown 훅을 돌리지 않는다 — 그 경우 상태를 되살릴 근거는
        // 「이 워커가 언제 시작해 얼마를 선언했는가」뿐이다 (13차 환경#10).
        // 선언 예산은 `worker_stale_after` 가 그대로 소유한다 — 호출자가 넘기는 값이 이미
        // `installerSetTimeLimit()` 의 **실적용치**이므로, 같은 값을 두 키에 복제하면
        // 둘이 어긋날 자리만 생긴다.
        $state['worker_started_at'] = $now;
        saveInstallationState($state);

        return ['acquired' => true, 'worker_id' => $newWorkerId, 'reason' => $reason];
    });
}

/**
 * 워커 heartbeat 갱신 — 자기 worker_id 만 갱신 가능.
 *
 * task 진행 중 주기적으로 호출하여 lock 점유 유지. 다른 worker_id 가 active 면
 * 자기는 stale loser 이므로 false 반환 (호출자는 즉시 종료해야 함).
 *
 * @param  string  $workerId  자기 worker_id
 * @return bool 갱신 성공 (자기가 owner) 또는 실패 (다른 워커가 takeover)
 */
function refreshWorkerHeartbeat(string $workerId): bool
{
    return (bool) installerWithStateLock(static function () use ($workerId): bool {
        $state = getInstallationState();
        $activeId = $state['active_worker_id'] ?? null;

        if ($activeId !== $workerId) {
            return false;
        }

        $state['last_heartbeat'] = time();
        saveInstallationState($state);

        return true;
    });
}

/**
 * 워커 lock 해제 — 자기 worker_id 가 active 일 때만.
 *
 * 정상 종료 시 호출. 다른 워커가 takeover 했으면 무시 (자기가 점유자가 아니므로).
 *
 * **해제는 「상태 파일에 쓰기」다.** 그래서 쓸 수 없는 상황(디스크 풀·권한 소실)에서는
 * 해제도 실패한다 — 그리고 종전에는 이 함수가 `void` 라 그 실패를 **아무도 보지 않았다.**
 * 현장(2026-09-12): 디스크가 차서 태스크가 죽었고, shutdown 훅이 돌아 이 함수를 불렀지만
 * 그 쓰기가 함께 실패해 점유가 남았다. 운영자는 공간을 비운 뒤에도 10분 동안
 * 「다른 창에서 진행 중」을 봤다. 성공 여부를 돌려주어 호출부가 그것을 기록할 수 있게 한다.
 *
 * @param  string  $workerId  자기 worker_id
 * @return bool 해제를 **기록까지** 마쳤으면 true (점유자가 아니어서 할 일이 없었으면 true)
 */
function releaseWorkerLock(string $workerId): bool
{
    return (bool) installerWithStateLock(static function () use ($workerId): bool {
        $state = getInstallationState();

        if (($state['active_worker_id'] ?? null) !== $workerId) {
            // 이미 남이 이어받았다 — 이 요청이 할 일은 없고, 그것은 실패가 아니다.
            return true;
        }

        unset(
            $state['active_worker_id'],
            $state['last_heartbeat'],
            $state['worker_stale_after'],
            // 시작 시각도 함께 지운다 — 남기면 다음 점유자의 예산 판정이 남의 시각을 기준으로 돈다.
            $state['worker_started_at'],
        );

        return saveInstallationState($state);
    });
}

/**
 * 워커 lock 흔적을 상태 배열에서 제거한다 (소유권 검사 없음).
 *
 * releaseWorkerLock() 은 점유자 본인만 해제할 수 있어, 프로세스가 강제 종료되면
 * (php-fpm kill · OOM · 재시작) active_worker_id 와 그 요청이 선언한 시간 예산
 * (worker_stale_after, in-process 부팅은 600s)이 상태 파일에 그대로 남는다. 그러면
 * 이후 모든 진입점이 stale 판정의 하한을 그 값으로 받아 최대 10분간 busy 로 튕기고,
 * 운영자에게는 화면이 멈춘 것으로만 보인다.
 *
 * 그래서 "이 설치 실행은 더 이상 유효하지 않다" 를 이미 확정한 경로 — 중단·초기화 —
 * 에서만 소유권과 무관하게 흔적을 지운다. 진행 중 요청을 뺏는 용도가 아니다.
 *
 * 재시작(install-process) 경로에는 넣지 않는다 — 그 직후 폴링 분기가 스스로 lock 을
 * 잡으므로, 미리 비우면 동시 실행 보호가 무너진다. 재시작 직전의 중단·초기화가
 * 이미 흔적을 지웠으므로 복구 수단은 그 두 경로로 충분하다.
 *
 * @param  array  $state  현재 설치 상태
 * @return array 워커 키가 제거된 상태
 */
function clearWorkerLock(array $state): array
{
    unset(
        $state['active_worker_id'],
        $state['last_heartbeat'],
        $state['worker_stale_after'],
        // 시작 시각도 흔적의 일부다 — 하나만 남기면 예산 판정이 남의 시각을 기준으로 돈다.
        $state['worker_started_at'],
    );

    return $state;
}

/**
 * 설치를 '중단' 상태로 전이한다 (상태 배열만 다룬다 — 저장은 호출자 책임).
 *
 * 중단은 "이 실행은 더 이상 유효하지 않다" 가 확정된 지점이므로 워커 lock 흔적도
 * 함께 지운다. 지우지 않으면 죽은 in-process 워커가 선언한 시간 예산(600s)이 남아
 * 중단 화면의 [계속 진행] 이 최대 10분간 거짓 busy 로 막힌다 — 그 상태에서 운영자가
 * 쓸 수 있는 탈출구는 완료 마커를 전부 지우는 [설정으로 돌아가기] 뿐이다.
 *
 * completed_tasks 는 보존한다 — 이미 끝난 작업은 롤백 대상이 아니다.
 *
 * @param  array  $state  현재 설치 상태
 * @param  string  $reason  중단 사유 **다국어 키** (화면이 그대로 번역해 표시한다 — 11차 #A12)
 * @return array 중단 상태가 적용된 상태 배열
 */
function installerApplyAbortedState(array $state, string $reason = 'abort_reason_user'): array
{
    $state['installation_status'] = 'aborted';
    // current_step 은 5 를 유지한다 (중단 화면을 보여주기 위해)
    $state['current_task'] = null;
    $state['aborted_at'] = date('Y-m-d H:i:s');
    $state['abort_reason'] = $reason;

    // 흔적 제거는 소유권과 무관하게 **항상** 한다. 11차에 「살아 있는 워커면 남긴다」로
    // 좁히려다 되돌렸다 — heartbeat 는 태스크 실행 중 갱신되지 않으므로(exec/Artisan::call
    // 이 블로킹) 「살아 있음」 판정은 죽은 in-process 워커가 선언한 600초 예산과 구분되지
    // 않는다. 좁히면 8차가 닫은 「중단 후 [계속 진행] 이 최대 10분간 거짓 busy」가 그대로
    // 되살아난다(InstallerWorkerLockRecoveryTest 가 그 계약을 고정한다).
    // 살아 있는 워커와의 겹침은 lock 이 아니라 `aborted` 표식 + 태스크 경계의 물러남으로
    // 다룬다 — 그 경로의 잔여 위험은 §16.17 에 기록했다.
    return clearWorkerLock($state);
}

if (! function_exists('installerFinalizeAbortedState')) {
    /**
     * 중단으로 전이된 상태를 저장한다 (중단 두 경로 공용 SSoT — 12차 #A1).
     *
     * 호출자는 **롤백 직후 다시 읽은** 상태에 `installerApplyAbortedState()` 를 적용해
     * 넘긴다 — `installerApplyAbortedState(getInstallationState(), …)` 한 형태다.
     * 중단 경로 둘(운영자 [중단] · SSE 연결 끊김)은 종전에 상태를 한 번만 읽고, 그 스냅샷을
     * 수 초 블로킹하는 롤백에 넘긴 뒤 **같은 스냅샷**에 중단 표식을 얹어 저장했다. 그 사이
     * 워커가 쓴 완료 마커 · 건너뜀 · best-effort 기록은 통째로 사라진다 — 예외도 오류도 없이
     * 진행이 되감긴다. 실패 경로(handleInstallationTaskFailure)는 같은 이유로 이미 재로드한다.
     *
     * 중단 상태는 무기한 잔존할 수 있으므로 보존되는 config 의 비밀도 함께 지운다
     * (이슈 #465) — 두 경로 모두. 종전에는 API 경로에만 있어 연결 끊김 경로가 비대칭이었다.
     *
     * @param  array<string, mixed>  $abortedState  중단 전이가 적용된 상태 (재로드본 기준)
     * @param  array<string, mixed>|null  $rollbackFailure  롤백 실패 정보 (없으면 null)
     * @return bool 저장 성공 여부
     */
    function installerFinalizeAbortedState(array $abortedState, ?array $rollbackFailure = null): bool
    {
        return (bool) installerWithStateLock(static function () use ($abortedState, $rollbackFailure): bool {
            if ($rollbackFailure !== null) {
                $abortedState['rollback_failure'] = $rollbackFailure;
            }

            return saveInstallationState(redactInstallationStateSecrets($abortedState));
        });
    }
}

if (! function_exists('installerFatalShutdownErrorTypes')) {
    /**
     * 프로세스를 그 자리에서 끝내는 오류 종류 — 이 경우에만 상태를 실패로 승격한다.
     *
     * @return array<int, int> 치명 오류 레벨 목록
     */
    function installerFatalShutdownErrorTypes(): array
    {
        return [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    }
}

if (! function_exists('installerPromoteFatalShutdownToFailure')) {
    /**
     * 치명 오류로 죽은 요청의 상태를 「실패」로 승격한다 (12차 #A2).
     *
     * 세 진입점의 shutdown 훅이 lock 해제만 했으므로, fatal / OOM / 시간초과로 프로세스가
     * 중간에 죽으면 `installation_status` 는 `running` 인 채 `failed_task` 가 비어 남았다.
     * [건너뛰고 계속] 은 `failed` + `failed_task` 를 요구하므로 그 조합에서는 건너뛰기가
     * **원리상 불가능**해지고, 화면은 영원히 진행 중으로 보인다.
     *
     * 그래서 정상 실패 경로(handleInstallationTaskFailure)와 **같은 필드 집합**을 기록해
     * 운영자에게 같은 선택지를 준다. 이미 끝난 설치(완료 · 중단 · 이미 실패)는 건드리지
     * 않는다 — 재호출해도 결과가 같다.
     *
     * 다른 워커가 이미 이어받았으면 승격하지 않는다 — 죽은 쪽이 승자의 진행 위에 실패를 덮지 않게 한다.
     * 끊긴 작업은 작업 루프가 남긴 실행 중 작업(대상 포함, `g7_installer_running_task`)을 우선
     * 쓰고, 없으면 상태의 current_task 다. 실행 중 작업 기록은 이 요청 자신의 것이라 이 채널에서만 쓴다
     * (다음 요청이 덮는 안전망 채널은 남의 요청을 판정하므로 쓰지 않는다).
     *
     * @param  array<string, mixed>|null  $lastError  error_get_last() 결과
     * @param  string|null  $workerId  이 요청이 점유한 worker_id (null 이면 점유자 대조 생략)
     * @return bool 승격했으면 true
     */
    function installerPromoteFatalShutdownToFailure(?array $lastError, ?string $workerId = null): bool
    {
        if (! is_array($lastError)) {
            return false;
        }

        if (! in_array((int) ($lastError['type'] ?? 0), installerFatalShutdownErrorTypes(), true)) {
            return false;
        }

        return (bool) installerWithStateLock(static function () use ($lastError, $workerId): bool {
            $state = getInstallationState();

            // 진행 중이던 설치만 승격 대상이다 (멱등)
            if (($state['installation_status'] ?? '') !== 'running') {
                return false;
            }

            $activeId = $state['active_worker_id'] ?? null;
            if ($workerId !== null && $activeId !== null && $activeId !== '' && $activeId !== $workerId) {
                return false;
            }

            return saveInstallationState(installerApplyWorkerFailureFields(
                $state,
                'error_worker_terminated',
                (string) ($lastError['message'] ?? ''),
                installerRunningTaskKey(),
            ));
        });
    }
}

if (! function_exists('installerApplyWorkerFailureFields')) {
    /**
     * 죽은 워커의 상태에 「실패」 필드 집합을 적용한다 (승격 두 채널 공용 SSoT).
     *
     * 승격 채널은 둘이다 — 치명 오류를 그 자리에서 잡는 shutdown 훅
     * (`installerPromoteFatalShutdownToFailure`)과, 그 훅이 돌지 못한 경우를 다음 요청이
     * 덮는 안전망(`installerPromoteExpiredWorkerToFailure`). 두 채널이 각자 필드를 쓰면
     * 한쪽만 갱신되어 화면이 모르는 형태가 남는다 — 그래서 필드 집합을 한 곳이 소유한다.
     *
     * @param  array<string, mixed>  $state  진행 중이던 상태
     * @param  string  $messageKey  화면에 보일 사유 다국어 키
     * @param  string|null  $errorDetail  기술 상세 (없으면 null)
     * @param  string|null  $failedKey  끊긴 작업 키 ("id" 또는 "id:target"). null 이면 상태의 current_task
     * @return array<string, mixed> 실패 필드가 적용된 상태
     */
    function installerApplyWorkerFailureFields(array $state, string $messageKey, ?string $errorDetail, ?string $failedKey = null): array
    {
        $failedKey ??= $state['current_task'] ?? null;
        // 빈 문자열(작업 사이 구간)·문자열이 아닌 값은 「끊긴 작업 없음」이다 — 그대로 두면
        // failed_task 가 '' 로 남고 빈 id 로 수동 복구 명령을 찾는다.
        if (! is_string($failedKey) || $failedKey === '') {
            $failedKey = null;
        }
        [$failedId, $failedTarget] = ($failedKey !== null && str_contains((string) $failedKey, ':'))
            ? explode(':', (string) $failedKey, 2)
            : [$failedKey, null];

        $state['installation_status'] = 'failed';
        $state['failed_task'] = $failedKey;
        $state['failed_task_target'] = $failedTarget;
        $state['failed_task_skippable'] = $failedId !== null
            && in_array($failedId, installerSkippableTaskIds(), true);
        $state['error_message_key'] = $messageKey;
        // 직전 실패의 치환값을 남기면 새 키의 자리표시자가 엉뚱한 값으로 채워진다
        $state['error_message_params'] = null;
        $state['error_detail'] = $errorDetail;
        $state['manual_commands'] = ($failedId !== null && function_exists('getManualCommands'))
            ? getManualCommands($failedId, $failedTarget)
            : [];

        return $state;
    }
}

if (! defined('INSTALLER_WORKER_BUDGET_GRACE_SECONDS')) {
    /**
     * 선언 예산을 넘겼다고 보기까지의 여유(초).
     *
     * 경계에서 곧바로 승격하면 정상적으로 시간 초과한 요청의 shutdown 훅(치명 오류 채널)과
     * 경합한다 — 그쪽이 오류 원문까지 실은 더 정확한 기록이므로 몇 초를 양보한다.
     */
    define('INSTALLER_WORKER_BUDGET_GRACE_SECONDS', 5);
}

if (! function_exists('installerWorkerBudgetExpired')) {
    /**
     * 점유 중인 워커가 자기 선언 예산을 이미 넘겼는지 판정한다 (13차 환경#10).
     *
     * ## 무엇을 덮는가
     *
     * 프로세스가 **시그널로** 죽는 경우 — php-fpm 의 `request_terminate_timeout`(SIGTERM/
     * SIGKILL) · LiteSpeed 의 `lsapi_max_process_time` · 컨테이너 cgroup 의 OOM-kill · 스크립트
     * 자신의 `exit()`/`die()` — 는 `register_shutdown_function` 이 돌지 않거나 `error_get_last()`
     * 가 비어 있어 치명 오류 채널이 발동하지 않는다. 그러면 `installation_status` 는 running 인
     * 채 `failed_task` 가 비어 남고, [건너뛰고 계속] 은 그 둘을 요구하므로 **원리상 불가능**해진다.
     * 시그널에 영향받지 않는 유일한 채널은 「다음 요청」이므로, 그 요청이 이 판정으로 승격한다.
     *
     * ## 무엇을 못 덮는가
     *
     *  - **선언 예산이 서버의 실제 종료 시점보다 클 수 있다.** `request_terminate_timeout` 과
     *    `lsapi_max_process_time` 은 PHP 에서 읽을 수 없어 예산에 반영되지 않는다
     *    (`installerEffectiveTimeLimit()` 은 `ini_get` 이 말하는 값까지만 좁힌다). 그 경우
     *    승격이 예산만큼 늦어진다 — 그러나 **영영 되지 않는 것보다는 낫다**는 것이 이 판정의 취지다.
     *  - **구 상태 파일**에는 시작 시각이 없어 판정 근거가 없다 — 종전 동작을 유지한다.
     *  - **실측 미검증**: 이 저장소의 P0 검증 환경은 Windows + Apache mod_php 라 시그널 종료가
     *    원리상 발생하지 않는다. 실제 php-fpm 환경에서의 동작 확인은 G8 실측 소관이다
     *    (계획서 §16.19.7).
     *
     * ## 살아 있는 워커는 죽이지 않는다
     *
     * 판정은 기존 생존 SSoT(`installerActiveWorkerIsAlive`)와 **모순되지 않는다** — heartbeat 가
     * 아직 예산 안이면 살아 있는 워커이므로 승격하지 않는다. 블로킹 구간에서 heartbeat 가 갱신되지
     * 않는다는 사실 때문에 기준은 heartbeat 가 아니라 **선언된 예산**이지만, 두 조건을 함께 요구해
     * 어느 쪽으로도 살아 있는 워커를 앞지르지 않게 한다. 경과가 음수면(시계 역행) 승격하지 않는다
     * (13차 환경#19 와 같은 규칙).
     *
     * @param  array<string, mixed>  $state  현재 상태
     * @param  string|null  $selfWorkerId  이 요청이 점유한 worker_id (자기 자신은 승격 대상이 아니다)
     * @param  int  $graceSeconds  예산을 넘겼다고 보기까지의 여유(초)
     * @return bool 죽은 워커의 고착 상태로 판정되면 true
     */
    function installerWorkerBudgetExpired(
        array $state,
        ?string $selfWorkerId = null,
        int $graceSeconds = INSTALLER_WORKER_BUDGET_GRACE_SECONDS,
    ): bool {
        // 진행 중이던 설치만 대상이다 — 완료·중단·이미 실패는 결말이 확정됐다 (멱등).
        if (($state['installation_status'] ?? '') !== 'running') {
            return false;
        }

        $activeId = $state['active_worker_id'] ?? null;

        // 점유자가 없는 running 은 「요청과 요청 사이」다 (stepwise 는 태스크마다 lock 을 놓는다).
        if ($activeId === null || $activeId === '') {
            return false;
        }

        // 자기 자신은 살아 있다 — 블로킹 태스크로 heartbeat 가 멈춰 있을 뿐이다.
        if ($selfWorkerId !== null && $activeId === $selfWorkerId) {
            return false;
        }

        $startedAt = (int) ($state['worker_started_at'] ?? 0);
        $budget = (int) ($state['worker_stale_after'] ?? 0);

        // 판정 근거가 없으면 판정하지 않는다 (구 상태 파일 · 예산 미선언).
        if ($startedAt <= 0 || $budget <= 0) {
            return false;
        }

        $elapsed = time() - $startedAt;

        // 시계가 뒤로 갔다 — 워커가 죽은 것이 아니라 시각이 튄 것이다.
        if ($elapsed < 0) {
            return false;
        }

        if ($elapsed <= $budget + max(0, $graceSeconds)) {
            return false;
        }

        // 기존 생존 판정과의 정합 — heartbeat 가 아직 예산 안이면 살아 있는 워커다.
        return ! installerActiveWorkerIsAlive($state, 0);
    }
}

if (! function_exists('installerPromoteExpiredWorkerToFailure')) {
    /**
     * 예산을 넘긴 점유자의 상태를 「실패」로 승격한다 — 시그널 종료의 안전망 (13차 환경#10).
     *
     * 치명 오류 채널(`installerPromoteFatalShutdownToFailure`)과 배타가 아니라 보완이다:
     * 그쪽은 fatal 을 **즉시** 잡는 빠른 경로고, 이쪽은 그 훅이 돌지 못한 경우를 다음 요청이
     * 덮는다. 같은 상태에 둘 다 적용해도 안전하다 — 승격 뒤에는 status 가 running 이 아니므로
     * 어느 채널도 다시 쓰지 않는다.
     *
     * 승격과 함께 죽은 워커의 점유 흔적을 지운다 — 남기면 이후 재개가 그 예산만큼 거짓
     * busy 로 튕겨, 방금 만든 탈출구를 스스로 막는다.
     *
     * @return array<string, mixed>|null 승격된 상태 (승격하지 않았으면 null)
     */
    function installerPromoteExpiredWorkerToFailure(): ?array
    {
        $promoted = installerWithStateLock(static function (): ?array {
            // 판정은 잠금 구간 안에서 **다시 읽은** 상태로 한다 — 그 사이 워커가 물러났거나
            // 다른 요청이 이미 승격했을 수 있다.
            $state = getInstallationState();

            if (! installerWorkerBudgetExpired($state, installerCurrentWorkerId())) {
                return null;
            }

            $state = clearWorkerLock(
                installerApplyWorkerFailureFields($state, 'error_worker_terminated', null)
            );

            return saveInstallationState($state) ? $state : null;
        });

        return is_array($promoted) ? $promoted : null;
    }
}

if (! function_exists('installerRecoverKilledWorkerState')) {
    /**
     * 상태를 읽는 단일 지점에서 시그널 종료를 실패로 승격한다 (`getInstallationState()` 전용).
     *
     * 진입점마다 배선하지 않는 이유는 하나다 — 흩어 놓으면 한 곳만 빠져도 그 모드에서만 같은
     * 고착이 남고, 그 결함은 오류도 로그도 남기지 않아 눈에 띄지 않는다.
     *
     * 승격은 상태 파일을 고쳐 쓰므로 그 안에서 다시 상태를 읽는다 — 재진입 가드로 한 번만 돈다.
     *
     * @param  array<string, mixed>  $state  방금 읽은 상태
     * @return array<string, mixed> 승격됐으면 승격된 상태, 아니면 원본 그대로
     */
    function installerRecoverKilledWorkerState(array $state): array
    {
        static $judging = false;

        if ($judging) {
            return $state;
        }

        // 값싼 사전 판정 — 대부분의 읽기는 여기서 끝나므로 파일 잠금까지 가지 않는다.
        if (! installerWorkerBudgetExpired($state, installerCurrentWorkerId())) {
            return $state;
        }

        $judging = true;

        try {
            $promoted = installerPromoteExpiredWorkerToFailure();
        } finally {
            $judging = false;
        }

        return $promoted ?? $state;
    }
}

if (! function_exists('installerRunningTaskKey')) {
    /**
     * 이 요청의 작업 루프가 지금 돌리고 있는 작업 키 ("id" 또는 "id:target").
     *
     * 작업 루프(runSingleInstallationTask)가 작업을 시작할 때 `g7_installer_running_task` 에 남긴다.
     * shutdown 처리기가 어느 작업(대상 포함)에서 끊겼는지 기록하는 데 쓴다.
     *
     * @return string|null 작업 키 (작업 루프 밖이면 null)
     */
    function installerRunningTaskKey(): ?string
    {
        $running = $GLOBALS['g7_installer_running_task'] ?? null;

        if (! is_array($running) || empty($running['id'])) {
            return null;
        }

        $target = isset($running['target']) && $running['target'] !== '' ? (string) $running['target'] : null;

        return $target !== null ? $running['id'].':'.$target : (string) $running['id'];
    }
}

if (! function_exists('installerHandleWorkerShutdown')) {
    /**
     * 워커 진입점 공용 shutdown 훅 — 치명 종료를 실패로 승격한 뒤 lock 을 푼다.
     *
     * 세 진입점(run-task · install-worker · install-process 폴링)이 같은 함수를 등록한다.
     * 한 곳만 배선하면 그 모드에서만 상태가 `running` 에 고착한다.
     *
     * 순서:
     *  1. fatal 로 끊긴 잠금 구간(상태·진행 기록)이 남긴 파일 잠금을 먼저 푼다. 구간 안의 fatal 은
     *     finally 를 건너뛰므로, 풀지 않으면 아래 상태·기록 쓰기가 같은 프로세스가 쥔 잠금을 기다리며
     *     영원히 멈추고 그 뒤 같은 사이트의 모든 상태 쓰기도 함께 멈춘다.
     *  2. 치명 종료(실행 시간 초과 등)면 상태를 실패로 승격한다. SSE 워커면 error 이벤트를 직접
     *     보낸다 — 화면이 「진행 중」에 멈추지 않고 실패 안내와 재시도(끊긴 작업부터 이어서)를 보인다.
     *  3. 치명 종료 없이 연결이 끊겨 끝난 SSE 워커(ignore_user_abort(false) 라, 끊긴 연결을 다음 출력에서
     *     감지한 PHP 가 스크립트를 오류 없이 끝낸다)는 상태를 running 으로 두고 그 사실만 진행 기록에
     *     남긴다 — 화면이 lock 이 빈 running 상태를 보고 새 워커로 다시 연결해 남은 작업을 이어 받는다.
     *  4. lock 을 푼다.
     *
     * **이 훅이 도는 것과 그 결과가 남는 것은 다른 일이다** (D7 · 현장 2026-09-12).
     * 승격도 해제도 결국 상태 파일 쓰기라, 쓸 수 없어서 죽은 경우(디스크 풀)에는 훅이
     * 정상으로 돌고도 아무것도 남기지 못한다. 그러면 점유가 남아 다음 시도가 막히는데,
     * **그 사실을 아는 통로가 하나도 없었다.** 상태 파일은 이미 쓸 수 없으므로 다른 매체
     * (PHP 오류 로그)에 남기고, 그 순간의 여유 공간을 함께 적어 원인이 곧바로 읽히게 한다.
     *
     * @param  string  $workerId  이 요청이 점유한 worker_id
     * @param  string  $mode  진행 모드 (sse | polling | 그 밖 — SSE 만 이벤트를 보낸다)
     * @param  int|null  $connectionStatus  연결 상태(CONNECTION_* 비트). null 이면 connection_status()
     */
    function installerHandleWorkerShutdown(string $workerId, string $mode = '', ?int $connectionStatus = null): void
    {
        // shutdown 처리에 쓸 시간을 따로 확보한다 (fatal 직후에는 남은 시간이 없다).
        // 막힌 호스팅에서는 부르지 않는다 — `@` 는 차단 함수의 Error 를 삼키지 않는다 (13차 환경#1).
        if (installerHasFunction('set_time_limit')) {
            @set_time_limit(30);
        }

        installerReleaseInterruptedLocks();

        $lastError = error_get_last();
        $isFatal = is_array($lastError)
            && in_array((int) ($lastError['type'] ?? 0), installerFatalShutdownErrorTypes(), true);

        if ($isFatal) {
            if (installerPromoteFatalShutdownToFailure($lastError, $workerId)) {
                $failed = getInstallationState();
                $failedKey = $failed['failed_task'] ?? null;

                addLog(lang('log_worker_terminated', [
                    'error' => (string) ($lastError['message'] ?? ''),
                    'task' => (string) ($failedKey ?? '-'),
                ]));

                if ($mode === 'sse') {
                    [$failedId, $failedTarget] = ($failedKey !== null && str_contains((string) $failedKey, ':'))
                        ? explode(':', (string) $failedKey, 2)
                        : [$failedKey, null];

                    // SseEmitter::emit() 은 heartbeat 갱신·연결 확인 뒤 exit 할 수 있어 직접 쓴다.
                    echo "event: error\n";
                    echo 'data: '.installer_json_encode([
                        'message' => lang('error_worker_terminated'),
                        'message_key' => 'error_worker_terminated',
                        'error' => (string) ($lastError['message'] ?? ''),
                        'task' => $failedId,
                        'target' => $failedTarget,
                        'skippable' => (bool) ($failed['failed_task_skippable'] ?? false),
                        'manual_commands' => $failed['manual_commands'] ?? [],
                    ])."\n\n";
                    if (ob_get_level()) {
                        @ob_flush();
                    }
                    @flush();
                }
            }
        } elseif ($mode === 'sse' && ((($connectionStatus ?? connection_status()) & CONNECTION_ABORTED) !== 0)) {
            $taskKey = installerRunningTaskKey() ?? (string) (getInstallationState()['current_task'] ?? '-');
            $message = lang('log_worker_connection_closed', ['task' => $taskKey]);
            if ($message === 'log_worker_connection_closed') {
                // 번역이 실리기 전(이 파일 단독 로드) 경로의 폴백 — 원시 키 대신 읽히는 문장을 남긴다.
                $message = '=== SSE worker ended: connection closed (task: '.$taskKey.') ===';
            }
            addLog($message);
        }

        if (! releaseWorkerLock($workerId)) {
            installerErrorLog(
                '[installer-state] worker lock release was not persisted — the next attempt will be rejected '.
                'until the lock window expires. worker='.$workerId.' '.installerFreeSpaceNote()
            );
        }
    }
}

/**
 * 이 요청이 점유한 워커 lock 의 id 를 기억/조회한다.
 *
 * lock 을 잡은 진입점(run-task.php)과 그 뒤 태스크를 돌리는 코드가 서로 인자를 주고받지
 * 않으므로, 시간 예산을 바꾸는 지점이 자기 lock 을 가리킬 수 있게 한 곳에 둔다.
 *
 * @param  string|null  $workerId  기억할 worker_id (null 이면 조회만)
 * @return string|null 현재 요청의 worker_id
 */
function installerCurrentWorkerId(?string $workerId = null): ?string
{
    static $current = null;

    if ($workerId !== null) {
        $current = $workerId;
    }

    return $current;
}

/**
 * 점유 중인 lock 의 stale 판정 시간을 늘린다 — 자기가 점유자일 때만.
 *
 * 태스크 실행 중에는 heartbeat 를 갱신할 수 없으므로, 시간 예산을 늘리는 쪽(in-process 부팅이
 * set_time_limit 을 올리는 지점)이 그 사실을 lock 에 알려야 다른 요청이 성급히 탈취하지 않는다.
 * 줄이지는 않는다.
 *
 * @param  string  $workerId  자기 worker_id
 * @param  int  $seconds  새 stale 판정 시간(초)
 * @return bool 갱신 성공 (자기가 owner) 또는 실패
 */
function extendWorkerStaleWindow(string $workerId, int $seconds): bool
{
    return (bool) installerWithStateLock(static function () use ($workerId, $seconds): bool {
        $state = getInstallationState();

        if (($state['active_worker_id'] ?? null) !== $workerId) {
            return false;
        }

        if ($seconds <= (int) ($state['worker_stale_after'] ?? 0)) {
            return true;
        }

        $state['worker_stale_after'] = $seconds;
        $state['last_heartbeat'] = time();
        saveInstallationState($state);

        return true;
    });
}

if (! defined('INSTALLER_ABORT_WAIT_MAX_SECONDS')) {
    /**
     * 중단이 「작업이 물러날 때까지」 기다리는 상한(초) — 제품 결정(13차).
     *
     * P0 실측 최장 태스크는 vendor 추출 ≈10~15s, module_install ≈10s 다. 그보다 오래
     * 기다릴 이유가 없고, 무한 대기는 금지다 — 중단은 어떤 경우에도 완료되어야 한다.
     */
    define('INSTALLER_ABORT_WAIT_MAX_SECONDS', 15);
}

if (! defined('INSTALLER_ABORT_WAIT_POLL_MS')) {
    /** 물러남 확인 간격(ms). 짧으면 상태 파일을 헛되이 자주 읽고, 길면 불필요하게 더 기다린다. */
    define('INSTALLER_ABORT_WAIT_POLL_MS', 250);
}

if (! defined('INSTALLER_ABORT_REQUEST_TIME_LIMIT')) {
    /**
     * 중단 요청이 스스로에게 요청하는 실행 시간(초) — 대기 + 되돌리기를 담을 만큼.
     *
     * 이 값이 그대로 보장되지는 않는다 — `installerSetTimeLimit()` 이 **실제 적용치**를
     * 돌려주고 대기 상한은 그 값에서 도출된다 (`installerAbortWaitCapSeconds`).
     */
    define('INSTALLER_ABORT_REQUEST_TIME_LIMIT', 60);
}

if (! function_exists('installerMarkAbortRequested')) {
    /**
     * 중단 표식만 먼저 기록한다 — 워커가 다음 태스크 경계에서 물러나게 하는 신호 (제품 결정 13차).
     *
     * `installerApplyAbortedState()` 와 다른 점이 둘 있고, 둘 다 의도한 것이다:
     *
     *  - **워커 lock 흔적을 지우지 않는다.** 지우면 「그 작업이 실제로 물러났는가」를 볼
     *    근거가 사라진다. 흔적 정리는 되돌리기가 끝난 뒤 `installerApplyAbortedState()` 가 한다.
     *  - **`current_task` 를 비우지 않는다.** 워커의 중단 로그와 되돌리기 대상이 그 값이다.
     *
     * 이 표식이 되돌리기보다 **먼저** 기록되어야 워커가 물러날 기회를 얻는다. 종전에는
     * 되돌리기를 즉시 불러, 아직 실행 중인 그 태스크와 되돌리기가 겹칠 수 있었다.
     *
     * @param  string  $reason  중단 사유 다국어 키
     * @return bool 표식을 새로 기록했으면 true (이미 끝났거나 진행 중이 아니면 false)
     */
    function installerMarkAbortRequested(string $reason = 'abort_reason_user'): bool
    {
        return (bool) installerWithStateLock(static function () use ($reason): bool {
            $state = getInstallationState();

            // 진행 중인 설치만 대상이다 (멱등) — 완료·이미 중단·실패는 건드리지 않는다.
            if (($state['installation_status'] ?? '') !== 'running') {
                return false;
            }

            $state['installation_status'] = 'aborted';
            $state['aborted_at'] = date('Y-m-d H:i:s');
            $state['abort_reason'] = $reason;

            return saveInstallationState($state);
        });
    }
}

if (! function_exists('installerAbortWaitCapSeconds')) {
    /**
     * 이 요청의 시간 예산 안에서 안전하게 기다릴 수 있는 상한(초)을 정한다 (제품 결정 13차).
     *
     * 대기 중에도 응답은 살아 있어야 한다 — 요청 수명을 대기가 다 써 버리면 되돌리기가
     * 시작되기도 전에 요청이 끊긴다. 그래서 예산의 1/3 만 대기에 쓰고 나머지는 되돌리기에
     * 남긴다. 예산을 알 수 없거나 무제한(0)이면 상한을 그대로 쓴다 — CLI 가 그렇다.
     *
     * @param  int  $requestTimeBudget  이 요청에 실제로 적용된 실행 시간(초). 0 이하면 무제한·판정 불가
     * @return int 대기 상한(초)
     */
    function installerAbortWaitCapSeconds(int $requestTimeBudget): int
    {
        if ($requestTimeBudget <= 0) {
            return INSTALLER_ABORT_WAIT_MAX_SECONDS;
        }

        return max(0, min(INSTALLER_ABORT_WAIT_MAX_SECONDS, intdiv($requestTimeBudget, 3)));
    }
}

if (! function_exists('installerWaitForWorkerToRetreat')) {
    /**
     * 진행 중인 작업이 물러날 때까지 기다린다 (제품 결정 13차 — 11차 이월분).
     *
     * 「물러났다」의 판정은 **워커 lock 이 해제되었거나 그 시간 예산이 지났는가** 다
     * (`installerActiveWorkerIsAlive`). 워커는 태스크 경계에서만 중단 표식을 확인하므로,
     * 표식을 기록한 직후 이 대기를 거쳐야 되돌리기가 아직 실행 중인 태스크와 겹치지 않는다.
     *
     * **무한 대기는 하지 않는다.** 상한을 넘으면 기다리지 않고 그대로 진행한다 — 중단은
     * 어떤 경우에도 완료되어야 하기 때문이다(11차가 좁히려다 철회한 이유가 「탈출구가
     * 막히는 것」이었다). 상한 초과 사실은 호출자가 진행 기록에 남긴다.
     *
     * 시계 대신 **누적한 대기 시간**으로 상한을 판정한다 — 주입한 sleeper 로 검사할 수 있고,
     * 시각 점프(NTP 보정·컨테이너)에 흔들리지 않는다.
     *
     * @param  int  $maxWaitSeconds  대기 상한(초)
     * @param  callable|null  $stateReader  상태 조회기 (기본 `getInstallationState`)
     * @param  callable|null  $sleeper  대기기 — `fn (int $milliseconds)` (기본 `usleep`)
     * @return array{retreated: bool, waited_ms: int} 물러났는지와 실제 대기 시간(ms)
     */
    function installerWaitForWorkerToRetreat(
        int $maxWaitSeconds,
        ?callable $stateReader = null,
        ?callable $sleeper = null,
    ): array {
        $readState = $stateReader ?? static fn (): array => getInstallationState();
        $sleep = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };

        $intervalMs = INSTALLER_ABORT_WAIT_POLL_MS;
        $maxWaitMs = max(0, $maxWaitSeconds) * 1000;
        $waitedMs = 0;

        while (true) {
            $state = $readState();

            // 점유자가 선언한 시간 예산을 존중한다 — 구 상태 파일에 그 키가 없으면
            // lock 획득 기본값(15초)으로 떨어진다.
            if (! installerActiveWorkerIsAlive(is_array($state) ? $state : [], 15)) {
                return ['retreated' => true, 'waited_ms' => $waitedMs];
            }

            if ($waitedMs >= $maxWaitMs) {
                return ['retreated' => false, 'waited_ms' => $waitedMs];
            }

            $sleep($intervalMs);
            $waitedMs += $intervalMs;
        }
    }
}

/**
 * 워커 lock 을 쥔 워커가 있는지 — 상태 조회 응답의 worker_active.
 *
 * 워커는 끝날 때(shutdown) lock 을 푼다. running 인데 lock 이 비어 있으면 앞 워커가 끝났고
 * 이어 받을 워커가 없다는 뜻이라, SSE 화면은 새 워커로 다시 연결한다. lock 이 남아 있으면
 * 앞 워커가 아직 작업(외부 명령 실행 등)을 돌리고 있을 수 있어 같은 작업이 겹치지 않게 기다린다.
 * heartbeat 신선도는 보지 않는다 — 외부 명령을 기다리는 동안에는 heartbeat 가 갱신되지 않는다.
 *
 * @param  array<string, mixed>  $state  인스톨러 state
 * @return bool lock 보유 워커가 있으면 true
 */
function installerHasActiveWorker(array $state): bool
{
    return isset($state['active_worker_id']) && $state['active_worker_id'] !== '';
}

/**
 * 설치 워커가 lock 을 풀 때까지 기다린다 — 설치 완료 직후 .env 최종 병합(finalize-env.php)용.
 *
 * 워커는 상태를 completed 로 기록한 「설치 완료 처리」 뒤에도 설정 캐시·라우트 캐시·정적 게시를 돈다.
 * 완료 화면은 completed 를 보는 즉시 병합을 부르므로, 병합이 워커의 config:cache 도중에 끼어들면
 * 병합 전 .env 값이 설정 캐시에 굳고(사이트 503), 상태 파일이 지워진 워커는 남은 작업을 건너뛴다.
 * 상태 파일이 없거나 lock 이 비면 바로 돌아간다. heartbeat 신선도는 보지 않는다(외부 명령 실행 중에는
 * 갱신되지 않는다) — 대신 상한 시간이 지나면 기다리기를 그만둔다(워커 강제 종료로 lock 이 남은 경우).
 *
 * @param  int  $maxSeconds  최대 대기 시간(초)
 * @param  int  $intervalMs  상태 확인 간격(밀리초)
 * @return array{released: bool, waited_seconds: float} lock 이 풀렸는지(또는 처음부터 없었는지), 기다린 시간
 */
function installerWaitForWorkerRelease(int $maxSeconds, int $intervalMs = 200): array
{
    $startedAt = microtime(true);

    while (true) {
        $state = installerReadStateFile();
        $waited = microtime(true) - $startedAt;

        if ($state === null || ! installerHasActiveWorker($state)) {
            return ['released' => true, 'waited_seconds' => $waited];
        }
        if ($waited >= $maxSeconds) {
            return ['released' => false, 'waited_seconds' => $waited];
        }

        usleep(max(10, $intervalMs) * 1000);
    }
}

/**
 * 데이터베이스를 건드리기 전에 도는 설치 작업 목록.
 *
 * 작업 순서는 고정이다(runInstallationTasks) — 이 다섯 작업 뒤의 db_cleanup 부터 DB 를 바꾼다.
 *
 * @return list<string> 작업 ID 목록
 */
function installerPreDatabaseTaskIds(): array
{
    return ['composer_check', 'composer_install', 'env_update', 'key_generate', 'dependency_precheck'];
}

/**
 * 상태 초기화(reset) 때 「DB 는 자동으로 초기화되지 않는다」 안내가 필요한지.
 *
 * 이번 설치 시도에서 DB 작업(db_cleanup 이후)이 끝났거나, DB 작업에서 멈췄거나(실패·중단),
 * 그 작업을 알 수 없으면 true. 의존성 사전 검증 실패처럼 DB 를 건드리기 전에 멈춘 설치는 false.
 *
 * @param  array<string, mixed>  $state  초기화 직전 인스톨러 state
 * @return bool 안내가 필요하면 true
 */
function installerResetNeedsDbNotice(array $state): bool
{
    $preDatabase = installerPreDatabaseTaskIds();
    $isPreDatabase = static function ($taskKey) use ($preDatabase): bool {
        if (! is_string($taskKey) || $taskKey === '') {
            return false;
        }
        $taskId = explode(':', $taskKey, 2)[0];

        return in_array($taskId, $preDatabase, true);
    };

    foreach ((array) ($state['completed_tasks'] ?? []) as $task) {
        if (! $isPreDatabase($task)) {
            return true;
        }
    }

    $stoppedAt = $state['failed_task'] ?? null;
    if (! is_string($stoppedAt) || $stoppedAt === '') {
        $stoppedAt = $state['current_task'] ?? null;
    }
    if (is_string($stoppedAt) && $stoppedAt !== '' && ! $isPreDatabase($stoppedAt)) {
        return true;
    }

    // 실패·중단인데 멈춘 작업을 모르고 DB 전 작업이 모두 끝나 있었다면, 다음 작업(db_cleanup)부터는 DB 를 바꾼다
    $status = $state['installation_status'] ?? null;
    if (in_array($status, ['failed', 'aborted', 'running'], true) && (! is_string($stoppedAt) || $stoppedAt === '')) {
        return array_diff($preDatabase, (array) ($state['completed_tasks'] ?? [])) === [];
    }

    return false;
}

/**
 * db_cleanup 으로 무효화되는 task 인지 판정한다.
 *
 * db_cleanup 은 db_prefix 로 시작하는 테이블을 전부 DROP 한다. 따라서 "효과가 DB 행으로만
 * 남는" task 는 마커가 completed 로 남아 있어도 실제 결과물이 사라진 상태가 된다. 재개 시
 * runInstallationTasks() 는 completed 마커만 보고 건너뛰므로, 마커를 함께 제거하지 않으면
 * 그 확장은 파일만 있고 DB 등록은 없는 유령 상태로 설치가 끝난다.
 *
 * 회귀 (이슈 #599 후속): 1차 시도에서 sirsoft-board 가 db_cleanup 보다 먼저 설치·활성화된 뒤
 * 사용자가 재시도하면서 drop_tables 를 선택 → g7_modules 가 DROP 되어 board 행이 소멸.
 * 그러나 module_install:sirsoft-board 마커는 살아남아 재설치가 스킵됨 → 뒤이은 사용자 템플릿
 * 설치가 "필수 의존성 sirsoft-board (module)이 충족되지 않았습니다" 로 실패.
 *
 * 무효화 대상:
 *  - db_cleanup / db_migrate / db_seed — 스키마·시드 자체
 *  - {module,plugin,template,user_template}_{install,activate} — 확장 등록 행
 *  - language_pack_install — 언어팩 등록 행
 *  - create_settings_json / complete_flag / config_cache / route_cache / static_publish —
 *    DB 상태를 읽어 산출물을 만드는 마무리 단계
 *
 * 보존 대상 (파일시스템·환경 효과라 DROP 과 무관):
 *  - composer_check / composer_install / env_update / key_generate / dependency_precheck
 *
 * @param  string  $task  completed_tasks 항목 ("id" 또는 "id:target")
 */
function isTaskInvalidatedByDbCleanup(string $task): bool
{
    // "module_install:sirsoft-board" → "module_install"
    $taskId = strstr($task, ':', true);
    if ($taskId === false) {
        $taskId = $task;
    }

    static $invalidated = [
        'db_cleanup' => true,
        'db_migrate' => true,
        'db_seed' => true,
        'template_install' => true,
        'template_activate' => true,
        'module_install' => true,
        'module_activate' => true,
        'plugin_install' => true,
        'plugin_activate' => true,
        'user_template_install' => true,
        'user_template_activate' => true,
        'language_pack_install' => true,
        'create_settings_json' => true,
        'complete_flag' => true,
        'config_cache' => true,
        'route_cache' => true,
        'static_publish' => true,
    ];

    return isset($invalidated[$taskId]);
}

/**
 * existing_db_action='drop_tables' 시 DB 관련 task 마커 일괄 재설정 가드.
 *
 * 'drop_tables' 동의는 "기존 DB 다 지우고 처음부터 다시 설치" 의도이므로,
 * 이전 시도에서 partial 진행된 db_cleanup / db_migrate / db_seed 의
 * completed 마커를 모두 제거하여 처음부터 재실행되도록 한다.
 *
 * 적용 시나리오:
 * - 첫 시도(skip) 실패 → 재시도(drop_tables): cleanup 마커 제거 → cleanup 재실행
 * - SSE 시작(drop_tables, cleanup 성공 + migrate 일부) → SSE 끊김 → 폴링
 *   재시도(drop_tables): cleanup + migrate 마커 모두 제거 → cleanup 다시 drop
 *   (이전 SSE 가 일부 만든 테이블 포함) → migrate 처음부터 → 1050 에러 회피
 *
 * 'skip' 신청이거나 액션이 없는 경우 마커 보존 (이미 진행한 결과 무효화 방지).
 *
 * @param  array<string, mixed>  $state  현재 인스톨러 state
 * @param  string  $newAction  요청에서 받은 신규 existing_db_action
 * @return array<string, mixed> 가드 적용된 state (existing_db_action 은 호출자가 별도 설정)
 */
function applyExistingDbActionStateGuard(array $state, string $newAction): array
{
    // 다른 워커가 활성 진행 중이면 state 변경 금지 — race 회피.
    // 시나리오: SSE 워커가 db_* 완료 후 module/plugin 진행 중인데 클라이언트가
    // 폴링 fallback 시도하면 install-process.php 가 다시 호출되어 본 가드를 통과.
    // 그 시점에 db_* 마커를 제거하면 SSE 워커가 다음 markTaskCompleted 시 read/write
    // 하면서 db_* 가 빠진 상태에 module_* 만 추가됨 → UI 에 DB pending 으로 잔존.
    // worker 가 살아있을 때는 그 워커의 state 진행을 신뢰하고 가드를 skip 한다.
    // 판정은 lock 획득과 같은 함수를 쓴다 — 여기만 15초로 고정하면, 오래 도는 태스크(마이그레이션)
    // 중인 워커를 이 가드만 죽은 것으로 보고 그 워커의 db_* 완료 마커를 지운다. 그러면 다음 요청이
    // db_cleanup 을 다시 뽑아 방금 만든 테이블을 DROP 한다.
    $isOtherWorkerActive = installerActiveWorkerIsAlive($state, 15);

    // DB 단계(db_seed)가 끝난 뒤의 재시도에서는 마커를 지우지 않는다. 지우면 db_cleanup 이
    // 인스톨러 자신이 만든 테이블을 다시 DROP 하는데, 그 뒤에 설치된 확장(템플릿·모듈) 의
    // 완료 마커는 그대로 남아 그 행만 사라진 채 "완료" 된다 (G2 실측 — g7_templates 가 빈 설치).
    // drop_tables 동의는 "설치 전 기존 테이블" 에 대한 것이고, 시딩까지 끝난 DB 는 설치물이다.
    $dbStageCompleted = in_array('db_seed', $state['completed_tasks'] ?? [], true);

    if ($newAction === 'drop_tables' && ! $isOtherWorkerActive && ! $dbStageCompleted) {
        // drop_tables 는 "처음부터 다시" — DB 관련 task 마커 일괄 제거.
        // SSE 가 cleanup + migrate 일부 진행 후 끊긴 상태에서 폴링 재진입 케이스를
        // 커버하려면 이전 액션이 drop_tables 였더라도 다시 reset 해야 한다.
        $state['completed_tasks'] = array_values(array_filter(
            $state['completed_tasks'] ?? [],
            fn ($t) => ! isTaskInvalidatedByDbCleanup((string) $t)
        ));
        // 기준선도 함께 비운다 (19차 R2). `db_migrate` 마커를 지웠다는 것은 이 실행이
        // 마이그레이션을 **다시** 돌린다는 뜻인데, `installerRecordMigrationBatchBaseline()` 은
        // 값이 있으면 다시 기록하지 않는다. 게다가 drop_tables 는 그 사이 테이블을 지우므로
        // 이월된 기준선은 새 DB 상태보다 **크다** — 그러면 `--step` 이 0 으로 계산되어
        // 이번 실행이 만든 배치를 **되돌리지 않은 채** 「되돌릴 것이 없다」로 끝난다.
        $state['db_migrate_batch_baseline'] = null;
    } else {
        // 키 정규화 — completed_tasks 가 부재인 경우 빈 배열 보장
        $state['completed_tasks'] = $state['completed_tasks'] ?? [];
    }

    return $state;
}

/**
 * 현재 진행 중인 작업 업데이트
 *
 * @param  string  $task  작업 식별자
 * @return bool 업데이트 성공 여부
 */
function updateCurrentTask(string $task): bool
{
    return (bool) installerWithStateLock(static function () use ($task): bool {
        $state = getInstallationState();

        $state['current_task'] = $task;
        // current_task_name은 저장하지 않음 (프론트엔드에서 task ID로 번역)

        return saveInstallationState($state);
    });
}

/**
 * 설치 로그 추가 (별도 파일에 기록)
 *
 * 페이지 새로고침/재시작 시 로그가 즉시 표시되도록
 * fflush() + clearstatcache()를 적용합니다.
 *
 * @param  string  $message  로그 메시지
 * @return bool 저장 성공 여부
 */
/**
 * 다수의 로그 라인을 1회 fopen/flock/fwrite/fclose 로 일괄 기록합니다.
 *
 * artisan 명령 출력처럼 수백~수천 라인을 동시에 emit 해야 하는 경우 매 라인마다
 * addLog 를 호출하면 매번 file lock 을 잡았다 풀어 Windows 환경에서 수 초의
 * 지연이 발생하고, 이 지연 동안 polling 클라이언트의 fetch 가 connection_abort
 * 되어 워커가 강제 종료되는 회귀가 발생함. batch write 로 lock 1회만 잡는다.
 *
 * @param  array<int, string>  $messages  로그 메시지 배열 (빈 라인은 호출자가 사전 필터링)
 * @return bool 저장 성공 여부
 */
/**
 * 로그 한 줄을 저장 가능한 형태로 정규화한다 — 단건·배치가 공유하는 단일 지점 (13차 환경#11).
 *
 * 종전에는 이 처리가 `addLog` 에만 있었고 `addLogBatch` 에는 Windows 변환만 있었다. 배치
 * 경로는 **폴링 모드**가 쓰는데, 잘못된 바이트가 그대로 남으면 폴링 응답
 * (`state-management.php`)의 `json_encode` 가 false 를 돌려 본문 없는 200 이 나가고 프론트의
 * `res.json()` 이 "Unexpected end of JSON input" 으로 폭주한다 (gnuboard/g7#62 와 같은 형태).
 *
 * Windows 판정은 **인자로 받는다** — 함수 안에서 OS 를 보면 그 분기는 Linux CI 에서 한 번도
 * 평가되지 않는다.
 *
 * ## mbstring 이 없을 때 (16차 B-1)
 *
 * 이 함수는 `addLog` 를 거쳐 **`session.php` 최상위**에서 불린다 — 즉 인스톨러 첫 요청부터
 * 매번 돈다. `mb_*` 를 무가드로 부르면 mbstring 이 없는 서버에서 `Error` 로 **첫 화면이
 * 통째로 죽어**, 정작 「mbstring 확장이 없습니다」를 알려 줄 요구사항 화면이 뜨지 못한다
 * (mbstring 은 `REQUIRED_EXTENSIONS` 항목이다 — 이 저장소의 입장이 「없을 수 있다」이다).
 *
 * 없을 때는 정규화를 건너뛰고 원문을 돌려준다. 그 상태에서 정규화가 필요한 입력은 도달할
 * 수 없기 때문이다 — 정규화가 막는 것은 **명령 출력의 깨진 바이트**인데, mbstring 이 없으면
 * 요구사항 화면이 설치를 막아 명령이 한 번도 실행되지 않는다. 그 창에서 기록되는 것은
 * 인스톨러 자신의 문구(유효 UTF-8)뿐이다.
 *
 * @param  string  $message  원본 메시지
 * @param  bool  $isWindows  Windows 환경인가 (호출부가 `isWindows()` 로 판정해 넘긴다)
 * @return string 유효 UTF-8 이 보장된 메시지
 */
function installerNormalizeLogMessage(string $message, bool $isWindows): string
{
    // 정규화 로직 자체는 코어와 공유하는 SSoT(ProcessOutputEncoding)가 소유한다 —
    // 한국어 CP949 뿐 아니라 일본어(932)·중국어(936/950)·서유럽(437/850) 콘솔 출력까지
    // 덮고, mbstring 함수가 `disable_functions` 로 막힌 호스팅에서는 원문을 그대로 돌려준다.
    //
    // 이 함수는 그 SSoT 로 가는 **인스톨러 로그의 단일 통로**로 남는다. 단건(addLog)과
    // 배치(addLogBatch) 두 경로가 같은 함수를 지나야 한쪽만 방어가 빠지는 비대칭이
    // 구조적으로 생기지 않는다.
    //
    // `$isWindows` 는 호출부 계약 유지용이다 — 플랫폼 분기는 SSoT 가 스스로 판정하며
    // (그쪽이 OS 무관 확정 감지를 먼저 시도한다), 여기서 다시 나누면 판정이 둘로 갈린다.
    unset($isWindows);

    return installer_utf8_normalize($message);
}

/**
 * 진행 기록 파일이 상한을 넘겼으면 `.1` 로 **한 번** 회전한다 (D2 L11).
 *
 * 이 파일에는 회전도 상한도 없었다. 새 설치 진입에서만 지워지고(install-process.php)
 * 재시도·재개는 그대로 이어 쓰므로, 실패한 설치를 반복하면 단조 증가한다. 한 번의 정상
 * 설치가 이미 1.1 MB(13,017 줄 · `[cmd]` 12,798 줄)를 만든다 — 실측 2026-09-11.
 *
 * 파일이 커지면 두 곳이 함께 무거워진다: 서버는 꼬리를 읽어 응답에 싣고, 화면은 재개할 때
 * 그 전량을 한 프레임에 그린다(화면 상한은 그쪽에서 따로 막는다).
 *
 * **최선 노력이다** — 회전에 실패해도 설치를 멈추지 않는다. 로그는 설치의 목적이 아니고,
 * 여기서 예외를 올리면 쓰기 한 번 실패가 설치 전체를 끝낸다.
 *
 * 호출은 진행 기록 잠금 **안에서** 한다(`installerOpenProgressLogForAppend()`). 잠금 밖에서
 * 크기를 재고 이름을 옮기면, 두 요청이 같은 파일을 함께 상한 초과로 보고 차례로 옮겨 앞
 * 요청이 만든 회전본(과 그 사이에 쓰인 줄)을 뒤 요청이 지운다.
 *
 * @param  string  $logFile  진행 기록 경로
 * @return bool 실제로 회전했으면 true
 */
function installerRotateProgressLogIfLarge(string $logFile): bool
{
    // 8 MB — 정상 설치 1회분(1.1 MB)의 약 7배. 정상 동선에서는 절대 닿지 않고,
    // 재시도를 여러 번 반복한 뒤에야 걸린다.
    $limit = defined('INSTALLER_LOG_ROTATE_BYTES') ? (int) INSTALLER_LOG_ROTATE_BYTES : 8 * 1024 * 1024;

    clearstatcache(true, $logFile);
    if (! is_file($logFile)) {
        return false;
    }

    $size = @filesize($logFile);
    if ($size === false || $size < $limit) {
        return false;
    }

    // 기존 `.1` 은 덮어쓴다 — 세대를 늘리면 상한을 둔 의미가 없다.
    $rotated = $logFile.'.1';
    if (is_file($rotated) && ! @unlink($rotated)) {
        installerErrorLog('[installer] progress log rotation could not remove the previous archive: '.$rotated);

        return false;
    }
    if (! @rename($logFile, $rotated)) {
        installerErrorLog('[installer] progress log rotation failed (rename): '.$logFile);

        return false;
    }

    clearstatcache(true, $logFile);

    // 새 파일의 첫 줄에 회전 사실을 남긴다 — 그러지 않으면 운영자에게는 기록이 **사라진** 것이다.
    // 여기서 addLogBatch 를 부르면 재귀가 되므로 직접 쓴다.
    //
    // **번역에 기대지 않는다.** `addLog()` 는 세션 초기화처럼 번역표가 아직 없는 시점에도
    // 불리고, 그때 `lang()` 은 키 문자열을 그대로 돌려준다(정의조차 없을 수 있다). 그 상태로
    // 쓰면 새 파일의 첫 줄이 `log_rotated_notice` 가 되어, 정작 알려야 할 **어디로 옮겼는지**가
    // 사라진다. 번역이 있으면 그것을 쓰고, 없으면 파일 이름을 직접 적는다.
    $notice = function_exists('lang') ? lang('log_rotated_notice') : 'log_rotated_notice';
    if ($notice === 'log_rotated_notice') {
        $notice = 'Previous progress log was moved to '.basename($rotated).' (this file continues from there)';
    }

    $microtime = microtime(true);
    $stamp = date('Y-m-d H:i:s', (int) $microtime).'.'.sprintf('%03d', (int) (($microtime - floor($microtime)) * 1000));
    $noticeLine = "\xEF\xBB\xBF[{$stamp}] {$notice}\n";
    // 덧붙여 쓴다 — 이름을 옮긴 직후 다른 요청이 새 파일에 먼저 한 줄을 썼을 수 있다(그 요청은
    // 새 파일을 잠그므로 이 요청이 쥔 옛 파일 잠금과 겹치지 않는다). 덮어쓰면 그 줄이 사라진다.
    $written = @file_put_contents($logFile, $noticeLine, FILE_APPEND);
    if ($written !== strlen($noticeLine) && installerHasFunction('error_log')) {
        // 안내 한 줄을 다 쓰지 못해도 회전은 이미 끝났다 — 새 파일은 다음 기록이 이어 쓰므로 실패로 돌리지 않는다.
        // 옛 기록을 옮긴 곳을 알리는 줄이 잘렸다는 사실만 서버 로그에 남긴다(운영자가 옛 기록을 찾을 단서).
        @error_log('[g7-installer] progress log rotated to '.basename($rotated).' but the notice line was not fully written');
    }

    return true;
}

/**
 * 진행 기록 핸들이 지금 그 경로의 파일을 가리키는가.
 *
 * 잠금을 기다리는 동안 다른 요청이 회전했으면 이 핸들은 회전본(`.1`)을 가리킨다. 그대로 쓰면
 * 새 줄이 회전본에 붙는다. 파일 번호를 알 수 없는 환경(둘 다 0)에서는 같은 파일로 본다.
 *
 * @param  resource  $handle  열린 진행 기록 핸들
 * @param  string  $logFile  진행 기록 경로
 * @return bool 같은 파일이면 true
 */
function installerProgressLogHandleIsCurrent($handle, string $logFile): bool
{
    clearstatcache(true, $logFile);
    $opened = @fstat($handle);
    $current = @stat($logFile);

    if (! is_array($opened) || ! is_array($current)) {
        return false;
    }

    return (int) $opened['ino'] === (int) $current['ino'];
}

/**
 * 진행 기록을 덧붙이기로 열고 배타 잠금을 쥔다 — 상한 회전도 이 잠금 안에서 한다.
 *
 * 잠금을 쥔 뒤 ① 다른 요청이 그 사이 회전했으면 새 파일을 다시 열고 ② 상한을 넘겼으면 이
 * 요청이 회전한 뒤 새 파일을 다시 연다. 잠금을 지원하지 않는 환경(NFS 등)에서는 잠금 없이
 * 종전처럼 진행한다 — 피해는 줄이 섞이는 가독성 한정이라 설치를 막지 않는다.
 *
 * @param  string  $logFile  진행 기록 경로
 * @return array{handle: resource|null, locked: bool} 열린 핸들(열지 못했으면 null)과 잠금 여부
 */
function installerOpenProgressLogForAppend(string $logFile): array
{
    static $lockWarned = false;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $handle = @fopen($logFile, 'a');
        if ($handle === false) {
            return ['handle' => null, 'locked' => false];
        }

        // 잠금 획득 실패를 기록한다. NFS·일부 공유 호스팅처럼 flock 이 지원되지 않는
        // 환경에서는 동시 요청의 줄이 섞여도 흔적이 0 이다. 프로세스당 한 번만 남긴다.
        $locked = flock($handle, LOCK_EX);
        if (! $locked && ! $lockWarned) {
            $lockWarned = true;
            installerErrorLog('[installer] progress log flock unavailable — concurrent writes may interleave');
        }

        $isLast = $attempt === 2;
        $stale = $locked && ! installerProgressLogHandleIsCurrent($handle, $logFile);
        $rotated = ! $stale && installerRotateProgressLogIfLarge($logFile);

        if ($isLast || (! $stale && ! $rotated)) {
            return ['handle' => $handle, 'locked' => $locked];
        }

        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    return ['handle' => null, 'locked' => false];
}

function addLogBatch(array $messages): bool
{
    if (empty($messages)) {
        return true;
    }

    $logDir = BASE_PATH.'/storage/logs';
    $logFile = $logDir.'/installation.log';

    if (! is_dir($logDir)) {
        $created = @mkdir($logDir, 0775, true);
        if (! $created) {
            $msg = "[addLogBatch] Failed to create log directory: {$logDir}";
            installerErrorLog($msg);
            addLog($msg); // 재귀 가드가 차단하므로 안전 — error_log fallback 만 수행

            return false;
        }
    }

    if (! is_writable($logDir)) {
        $msg = "[addLogBatch] Log directory is not writable: {$logDir}";
        installerErrorLog($msg);
        addLog($msg); // 재귀 가드가 차단

        return false;
    }

    $isWindows = isWindows();
    $entries = '';
    foreach ($messages as $message) {
        // 항상 유효 UTF-8 만 로그에 저장한다 (gnuboard/g7#62).
        // 단건(addLog)과 같은 통로를 지난다 — 한쪽만 빠지면 그 경로의 로그가 폴링 응답을
        // 통째로 무력화한다.
        $message = installerNormalizeLogMessage($message, $isWindows);
        $microtime = microtime(true);
        $ms = sprintf('%03d', (int) (($microtime - floor($microtime)) * 1000));
        $timestamp = date('Y-m-d H:i:s', (int) $microtime).'.'.$ms;
        $entries .= "[{$timestamp}] {$message}\n";
    }

    // 열기·잠금·상한 회전은 한 함수가 소유한다 — 회전이 잠금 밖에 있으면 두 요청이 같은 파일을
    // 차례로 옮겨 앞 요청의 회전본을 지운다. 잠금 지원이 없는 환경의 경고도 그쪽이 남긴다.
    $opened = installerOpenProgressLogForAppend($logFile);
    $handle = $opened['handle'];
    if ($handle === null) {
        $msg = "[addLogBatch] Failed to open log file: {$logFile}";
        installerErrorLog($msg);
        addLog($msg); // 재귀 가드가 차단

        return false;
    }
    if ($opened['locked']) {
        // 쥔 잠금을 기록한다 — 이 구간이 fatal 로 끊기면 shutdown 단계가 풀 수 있다
        // (installerReleaseInterruptedLocks). 풀지 않으면 shutdown 의 진행 기록이 자기 잠금을 기다린다.
        installerTrackHeldLock('log', $handle);
    }

    // 새 파일 판정은 잠금 안에서 한다 — 잠금 밖에서 재면 다른 요청이 먼저 쓴 뒤의 판정이 된다.
    $stat = @fstat($handle);
    if (! is_array($stat) || (int) $stat['size'] === 0) {
        $entries = "\xEF\xBB\xBF".$entries;
    }

    $result = fwrite($handle, $entries);
    fflush($handle);
    installerUntrackHeldLock('log');
    if ($opened['locked']) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    // 일부만 쓰인 것(디스크 가득)도 실패다 — 업그레이드 스텝의 기록 쓰기와 같은 규칙이다.
    if ($result !== strlen($entries)) {
        installerErrorLog("[addLogBatch] Failed to write log file completely: {$logFile}");

        return false;
    }

    return true;
}

function addLog(string $message): bool
{
    // 무한 재귀 가드 — addLog 내부 폴백 경로에서 자기 자신을 다시 호출하면 안 된다.
    // installer-state.php / session.php / finalize-env.php 등에서 error_log 와 addLog 를
    // 병행 호출하는 경우, addLog 내부의 인프라 실패 폴백 (storage 쓰기 실패 등) 시
    // 호출자가 다시 addLog 를 부르면 같은 인프라가 다시 실패하여 무한 재귀가 발생할 수 있다.
    static $inAddLog = false;
    if ($inAddLog) {
        // 재진입: installation.log 시도 skip, PHP error_log 만 호출하고 즉시 종료
        installerErrorLog('[addLog reentrant] '.$message);

        return false;
    }
    $inAddLog = true;

    try {
        return _addLogInternal($message);
    } finally {
        $inAddLog = false;
    }
}

/**
 * addLog 의 실제 구현 — 무한 재귀 가드를 거친 후 호출된다.
 *
 * 본 함수 안에서는 절대 addLog() 를 호출하지 말 것 (무한 재귀 위험).
 * 내부 폴백은 installerErrorLog() 만 사용.
 *
 * @param  string  $message  기록할 메시지
 * @return bool 성공 여부
 */
function _addLogInternal(string $message): bool
{
    $logDir = BASE_PATH.'/storage/logs';
    $logFile = $logDir.'/installation.log';

    // 로그 디렉토리 확인 및 생성 시도
    if (! is_dir($logDir)) {
        $created = @mkdir($logDir, 0775, true);
        if (! $created) {
            $msg = "[addLog] Failed to create log directory: {$logDir} (storage 권한 확인 필요)";
            installerErrorLog($msg);
            addLog($msg); // 재귀 가드가 차단 (본 함수는 이미 가드 안)

            return false;
        }
    }

    // 로그 디렉토리 쓰기 권한 확인
    if (! is_writable($logDir)) {
        $msg = "[addLog] Log directory is not writable: {$logDir}";
        installerErrorLog($msg);
        addLog($msg); // 재귀 가드가 차단

        return false;
    }

    // 항상 유효 UTF-8 만 로그에 저장한다 (gnuboard/g7#62).
    //
    // composer install 등 외부 프로세스 stdout 은 시스템 코드페이지(한국어 Windows = CP949)로
    // 출력되고, 진행바(\r 갱신)는 임의 바이트 경계에서 잘린다. invalid 바이트가 로그에 남으면
    // 폴링 응답 state-management.php 의 json_encode 가 false 를 반환해 빈 본문(HTTP 200)
    // → 프론트 res.json() 이 "Unexpected end of JSON input" 으로 폭주한다.
    //
    // 정규화는 복원 가능한 코드페이지 출력을 먼저 되살리고(한글 보존),
    // 복원 불가능한 바이트만 U+FFFD 로 치환한다.
    // 배치(addLogBatch)와 같은 통로를 지난다.
    $message = installerNormalizeLogMessage($message, isWindows());

    // 타임스탬프와 함께 로그 작성 — millisecond 정밀도 (hang 진단 시 정확한 timing 필요)
    $microtime = microtime(true);
    $ms = sprintf('%03d', (int) (($microtime - floor($microtime)) * 1000));
    $timestamp = date('Y-m-d H:i:s', (int) $microtime).'.'.$ms;
    $logEntry = "[{$timestamp}] {$message}\n";

    // 열기·배타 잠금·상한 회전(D2 L11)은 형제(addLogBatch)와 같은 함수가 소유한다 — 같은 지점이어야
    // 한쪽 통로로만 쓰는 모드에서 파일이 조용히 자라지 않고, 회전이 잠금 안에 있어야 두 요청이 같은
    // 파일을 차례로 옮겨 앞 요청의 회전본을 지우지 않는다. 잠금 지원이 없는 환경의 경고도 그쪽이 남긴다.
    $opened = installerOpenProgressLogForAppend($logFile);
    $handle = $opened['handle'];
    if ($handle === null) {
        $msg = "[addLog] Failed to open log file: {$logFile}";
        installerErrorLog($msg);
        addLog($msg); // 재귀 가드가 차단

        return false;
    }
    if ($opened['locked']) {
        // 쥔 잠금을 기록한다 — 이 구간이 fatal 로 끊기면 shutdown 단계가 풀 수 있다
        // (installerReleaseInterruptedLocks). 풀지 않으면 shutdown 의 진행 기록이 자기 잠금을 기다린다.
        installerTrackHeldLock('log', $handle);
    }

    // 파일이 비어 있으면 UTF-8 BOM 추가 (Windows 텍스트 편집기 호환성) — 판정은 잠금 안에서 한다.
    $stat = @fstat($handle);
    if (! is_array($stat) || (int) $stat['size'] === 0) {
        $logEntry = "\xEF\xBB\xBF".$logEntry;
    }

    // 쓰기
    $result = fwrite($handle, $logEntry);

    // 버퍼 플러시 (즉시 디스크에 기록)
    fflush($handle);

    // 잠금 해제 및 닫기
    installerUntrackHeldLock('log');
    if ($opened['locked']) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    // Windows 파일 캐시 초기화 (최신 데이터 읽기 보장)
    clearstatcache(true, $logFile);

    // 일부만 쓰인 것(디스크 가득)도 실패다 — 업그레이드 스텝의 기록 쓰기와 같은 규칙이다.
    if ($result !== strlen($logEntry)) {
        $msg = "[addLog] Failed to write log file: {$logFile}";
        installerErrorLog($msg);
        addLog($msg); // 재귀 가드가 차단

        return false;
    }

    return true;
}

/**
 * 설치 로그 조회 (파일에서 읽기)
 *
 * 페이지 새로고침 시 최신 로그를 즉시 표시하기 위해
 * clearstatcache()를 적용합니다.
 *
 * @param  int  $offset  건너뛸 로그 줄 수 (폴링 모드 증분 조회용, 기본 0)
 * @return array 로그 배열 [{timestamp, message}, ...]
 */
if (! function_exists('installerReadLogLines')) {
    /**
     * 진행 기록 파일의 비어 있지 않은 줄을 돌려준다 — 요청 내 1회만 읽는다 (17차 #C10).
     *
     * 폴링 응답 한 번이 `getInstallationLogs()` 와 `getInstallationLogCount()` 를 **둘 다**
     * 부르는데 각자 파일 전체를 `file_get_contents` + `explode` 했다. 회전·상한이 없는 파일이라
     * `composer install` 출력이 쌓이면 2초마다 전량을 두 번 읽고 배열화한다 — 응답 지연과
     * `memory_limit` 압박이 파일 크기에 선형으로 붙는다.
     *
     * 크기로 캐시를 무효화한다 — 같은 요청 안에서 `addLog` 가 덧붙이면 크기가 바뀐다.
     *
     * @return array<int, string> 비어 있지 않은 줄
     */
    function installerReadLogLines(): array
    {
        static $cachedKey = null;
        static $cachedLines = [];

        $logFile = BASE_PATH.'/storage/logs/installation.log';

        clearstatcache(true, $logFile);

        if (! file_exists($logFile)) {
            return [];
        }

        $key = (string) @filesize($logFile);

        if ($cachedKey === $key) {
            return $cachedLines;
        }

        $content = @file_get_contents($logFile);
        if ($content === false) {
            return [];
        }

        $cachedKey = $key;
        $cachedLines = array_values(array_filter(
            explode("\n", trim($content)),
            static fn ($line) => $line !== ''
        ));

        return $cachedLines;
    }
}

function getInstallationLogs(int $offset = 0): array
{
    $lines = installerReadLogLines();
    $total = count($lines);

    // 증분 조회의 절단은 **배열화보다 앞**이어야 한다 (20차 #2) — offset 뒤에 자르면 폴링
    // 1회가 회전·상한 없는 파일 전량을 연관배열로 부풀린 뒤 버린다. PHP 연관배열은 원문
    // 대비 배수로 커지므로 낮은 memory_limit 호스트에서 긴 설치 중 폴링 응답이 OOM 으로
    // 죽고, 화면은 진행 갱신만 잃는다 (폴링 진입점에는 shutdown 승격 훅이 없어 설치
    // 자체는 계속된다 — 그래서 이 죽음은 어디에도 기록되지 않았다).
    if ($offset >= $total) {
        return [];
    }
    if ($offset > 0) {
        $lines = array_slice($lines, $offset);
    }

    $logs = [];

    foreach ($lines as $line) {
        if (preg_match('/^\[(.+?)\] (.+)$/', $line, $matches)) {
            $logs[] = [
                'timestamp' => $matches[1],
                'message' => $matches[2],
            ];
        } else {
            $logs[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => $line,
            ];
        }
    }

    return $logs;
}

/**
 * 설치 로그 전체 줄 수 조회 (폴링 모드 offset 계산용).
 *
 * @return int 전체 로그 줄 수
 */
function getInstallationLogCount(): int
{
    // 같은 요청의 getInstallationLogs() 와 같은 읽기를 공유한다 (17차 #C10).
    return count(installerReadLogLines());
}

/**
 * 마지막 완료된 단계 조회
 *
 * @return int 마지막 완료된 단계 번호 (0-5)
 */
function getLastCompletedStep(): int
{
    $state = getInstallationState();

    // step_status를 역순으로 확인
    for ($step = 5; $step >= 0; $step--) {
        $stepKey = (string) $step;
        if (isset($state['step_status'][$stepKey]) && $state['step_status'][$stepKey] === 'completed') {
            return $step;
        }
    }

    // 완료된 단계가 없으면 -1 반환
    return -1;
}

if (! function_exists('installerSkippableTaskIds')) {
    /**
     * 실패해도 [건너뛰고 계속] 이 허용되는 태스크 ID 목록 (W3).
     *
     * 선택 확장 하나의 실패로 사이트 전체 설치가 막히지 않게 한다. 관리자 템플릿·환경·DB·
     * 마무리 단계는 필수라 건너뛸 수 없다.
     *
     * @return array<int, string> 건너뛰기 가능한 태스크 ID
     */
    function installerSkippableTaskIds(): array
    {
        return [
            'module_install', 'module_activate',
            'plugin_install', 'plugin_activate',
            'user_template_install', 'user_template_activate',
            'language_pack_install',
        ];
    }
}

if (! function_exists('markInstallationTaskSkipped')) {
    /**
     * 실패한 태스크를 "건너뜀" 으로 표시해 다음 재개에서 지나가게 한다 (W3 [건너뛰고 계속]).
     *
     * 건너뛸 수 있는 태스크만 허용한다 (installerSkippableTaskIds). 설치 태스크를 건너뛰면
     * 짝이 되는 활성화 태스크도 함께 건너뛴다 — 설치되지 않은 확장을 활성화할 수 없다.
     *
     * @param  array<string, mixed>  $state  현재 상태 (installation_status=failed, failed_task 보유)
     * @return array{success: bool, message_key: string, skipped: array<int, string>} 처리 결과
     */
    function markInstallationTaskSkipped(array $state): array
    {
        // 완료 마커·건너뜀 목록을 읽고 고쳐 쓴다 — 상태 파일을 만지는 다른 헬퍼와 같은
        // 배타 구간에서 해야 겹친 요청이 서로의 기록을 덮어쓰지 않는다 (12차 #A3).
        //
        // **읽기도 구간 안에서 한다** (17차 #C3). 호출부(`state-management.php`)는 토큰 판정을
        // 위해 구간 밖에서 상태를 한 번 읽는데, 그 스냅샷을 그대로 저장하면 읽기와 저장 사이에
        // 워커가 남긴 완료 마커가 통째로 덮인다 — 그 태스크가 다시 뽑히고, DB 정리 태스크가
        // 다시 돌면 방금 만든 테이블이 DROP 된다. 인자는 호출부의 의도(어느 태스크를 건너뛰는가)
        // 를 나르지만 그 판정 근거는 상태 파일이 SSoT 이므로, 중단 경로가 대기 뒤 재조회하는 것
        // (12차 #A1)과 같은 방식으로 여기서도 최신 상태를 다시 읽는다.
        return installerWithStateLock(static function () use ($state): array {
            // 구간 안에서 다시 읽는다. 읽지 못하면(파손 등) 호출부 스냅샷으로 내려가 종전 동작을
            // 유지한다 — 확인하지 못한 것을 근거로 계약을 바꾸지 않는다.
            $fresh = getInstallationState();

            return installerApplyTaskSkip(is_array($fresh) && $fresh !== [] ? $fresh : $state);
        });
    }
}

if (! function_exists('installerRecordBusyRejection')) {
    /**
     * 폴링 워커가 lock 을 못 잡았을 때 그 사유를 상태에 남긴다 (11차 #A4 · 17차 #C4).
     *
     * 이 기록은 **정의상 다른 워커가 진행 중일 때만** 일어난다 — 즉 경합이 우발이 아니라
     * 구조적이다. 종전에는 구간 밖에서 읽고 써서, 승자가 그 창에 남긴 완료 마커·건너뜀·
     * best-effort 기록이 통째로 덮였다. 읽기·쓰기를 배타 구간 안에 둬 그 유실을 없앤다.
     *
     * 사유를 남기는 것 자체(상태를 failed 로 뒤집어 폴링 조회가 읽게 하는 것)는 11차의
     * 결정이며 여기서 바꾸지 않는다 — 이 변경은 유실 창만 닫는다.
     *
     * @return bool 저장 성공 여부
     */
    function installerRecordBusyRejection(): bool
    {
        return (bool) installerWithStateLock(static function (): bool {
            $busyState = getInstallationState();
            $busyState['installation_status'] = 'failed';
            // 「다른 창에서 진행 중」이라고 단정하지 않는다 (D7). 우리가 아는 사실은 점유가
            // 아직 예산 안이라는 것뿐이고, 그 점유자는 이미 죽었을 수 있다 — 치명 종료는
            // 해제 기록을 남기지 못한다. 남은 시간을 함께 실어 안내가 할 일을 말하게 한다.
            $busyState['error_message_key'] = 'error_installation_lock_held';
            $busyState['error_message_params'] = [
                'seconds' => installerWorkerLockRemainingSeconds($busyState, 15),
            ];

            return installerSaveStateOrReport($busyState, 'busy_rejection');
        });
    }
}

if (! function_exists('installerSkipDbConfig')) {
    /**
     * 건너뛰기 판정이 쓰는 DB 설정 — 비밀은 runtime.php 가 보관처다 (16차 C-1).
     *
     * `hydrateDbSecretsFromRuntime` 는 installer-runtime.php 소유라 이 파일만 단독 로드하는
     * 경로에서는 없다. 그때는 보강 없는 설정을 그대로 돌려주고, 접속이 되지 않으면 판정기가
     * `null`(판정 불가)을 내 종전 동작이 유지된다.
     *
     * @param  array<string, mixed>  $state  현재 상태
     * @return array<string, mixed> DB 설정
     */
    function installerSkipDbConfig(array $state): array
    {
        $config = $state['config'] ?? [];

        return function_exists('hydrateDbSecretsFromRuntime')
            ? hydrateDbSecretsFromRuntime($config)
            : $config;
    }
}

if (! function_exists('installerSkipDbPrefix')) {
    /**
     * 건너뛰기 판정이 쓰는 테이블 접두사 (16차 C-1).
     *
     * @param  array<string, mixed>  $state  현재 상태
     * @return string 접두사 (미설정이면 빈 문자열)
     */
    function installerSkipDbPrefix(array $state): string
    {
        return (string) ($state['config']['db_prefix'] ?? '');
    }
}

if (! function_exists('installerActivateKeyIsForInstalledExtension')) {
    /**
     * 이 `*_activate` 태스크 키가 **실제로 설치된** 확장의 것인가 (16차 C-1).
     *
     * 건너뛰기가 활성화 태스크를 함께 지나가는 근거는 「설치되지 않은 확장은 활성화할 수
     * 없다」이고, 그 판정의 출처는 **완료 마커**다. 그런데 부수효과가 끝난 뒤 마커를 남기기
     * 전에 요청이 죽으면(12차가 실패로 승격시킨 경로) 마커의 부재는 설치의 부재를 뜻하지
     * 않는다. 그때 활성화까지 지나가면 운영자가 고른 확장이 **설치된 채 켜지지 않고** 설치가
     * 「완료」로 끝난다.
     *
     * 직접 짝(`installerApplyTaskSkip`)과 의존 파급(`installerDependentsOfSkippedTarget` 의
     * `pending`)이 **같은 규칙을 공유해야** 한다 — 한쪽만 고치면 같은 결함이 한 단계 밖에
     * 그대로 남는다. 그래서 판정을 이 한 지점에 둔다.
     *
     * 판정할 수 없으면 `false` 를 돌려준다 = 종전대로 함께 지나간다. 확인하지 못한 것을
     * 근거로 동작을 바꾸지 않는다.
     *
     * @param  string  $key  태스크 키 (`module_activate:vendor-a` 형태)
     * @param  array<string, mixed>  $state  현재 상태
     * @return bool 설치되어 있음이 **확인된** 경우에만 true
     */
    function installerActivateKeyIsForInstalledExtension(string $key, array $state): bool
    {
        if (! str_contains($key, ':') || ! function_exists('installerExtensionIsInstalled')) {
            return false;
        }

        [$taskId, $target] = explode(':', $key, 2);

        if (! str_ends_with($taskId, '_activate') || $target === '') {
            return false;
        }

        $installTaskId = substr($taskId, 0, -strlen('_activate')).'_install';

        return installerExtensionIsInstalled(
            $installTaskId,
            $target,
            installerSkipDbConfig($state),
            installerSkipDbPrefix($state)
        ) === true;
    }
}

if (! function_exists('installerApplyTaskSkip')) {
    /**
     * markInstallationTaskSkipped() 의 본문 — 배타 구간 안에서만 불린다.
     *
     * @param  array<string, mixed>  $state  현재 상태 (installation_status=failed, failed_task 보유)
     * @return array{success: bool, message_key: string, skipped: array<int, string>} 처리 결과
     */
    function installerApplyTaskSkip(array $state): array
    {
        $failedKey = (string) ($state['failed_task'] ?? '');
        if ($failedKey === '' || ($state['installation_status'] ?? '') !== 'failed') {
            return ['success' => false, 'message_key' => 'skip_task_not_failed', 'skipped' => []];
        }

        [$taskId, $target] = str_contains($failedKey, ':')
            ? explode(':', $failedKey, 2)
            : [$failedKey, null];

        if (! in_array($taskId, installerSkippableTaskIds(), true)) {
            return ['success' => false, 'message_key' => 'skip_task_not_allowed', 'skipped' => []];
        }

        $keys = [$failedKey];
        if (str_ends_with($taskId, '_install') && $target !== null) {
            $pairId = substr($taskId, 0, -strlen('_install')).'_activate';
            // 짝 활성화를 함께 지나가는 근거는 「설치되지 않은 확장은 활성화할 수 없다」인데,
            // 부수효과가 끝난 뒤 완료 마커 전에 요청이 죽으면(12차가 실패로 승격시킨 경로)
            // 그 전제가 깨진다. 그때 짝까지 지나가면 운영자가 고른 확장이 **설치되어 있는데
            // 켜지지 않은 채** 설치가 「완료」로 끝난다 — 오류도 로그도 남지 않는다 (16차 C-1).
            //
            // 판정 불가(`null`)면 종전대로 함께 지나간다 — 확인하지 못한 것을 근거로 동작을
            // 바꾸지 않는다. 판정기는 rollback-functions.php 소유이며, 그것을 로드하지 않는
            // 경로에서는 같은 이유로 종전 동작이 된다.
            if (in_array($pairId, installerSkippableTaskIds(), true)
                && ! installerActivateKeyIsForInstalledExtension("{$pairId}:{$target}", $state)
            ) {
                $keys[] = "{$pairId}:{$target}";
            }
        }

        $completed = $state['completed_tasks'] ?? [];

        // 건너뛴 확장에 **의존하는** 확장까지 함께 지나간다. 의존성 사전 검증은 설치 시작 전
        // 한 번만 판정하므로, 그 뒤 [건너뛰고 계속] 이 의존 대상을 지나가면 그 판정은 더 이상
        // 사실이 아니다 — 그대로 두면 의존이 깨진 확장이 설치·활성화된 채 「완료」된다.
        // 이미 설치가 끝난 의존 확장은 되돌릴 수 없으므로 사실을 기록해 운영자에게 알린다.
        $dependencyWarnings = $state['dependency_skip_warnings'] ?? [];
        // 이번 건너뛰기가 **새로** 만든 경고만 따로 모은다 (11차 #A8) — 누적 배열 전체를
        // 로그로 다시 쏟으면 두 번째 건너뛰기부터 앞선 경고가 진행 기록에 중복해서 찍힌다.
        $newDependencyWarnings = [];
        if ($target !== null && function_exists('installerDependentsOfSkippedTarget')) {
            $dependents = installerDependentsOfSkippedTarget($target, $completed);

            foreach ($dependents['pending'] as $dependentKey) {
                // 파급도 같은 규칙을 받는다 (16차 C-1 재귀). `pending` 판정의 근거는 **완료
                // 마커의 부재**인데, 부수효과가 끝난 뒤 마커 전에 요청이 죽으면 그 부재는
                // 「설치되지 않았다」를 뜻하지 않는다. 직접 짝만 고치고 여기를 두면 같은 결함이
                // 한 단계 밖에 그대로 남는다 — 의존 확장이 설치된 채 켜지지 않는다.
                if (installerActivateKeyIsForInstalledExtension($dependentKey, $state)) {
                    continue;
                }

                if (! in_array($dependentKey, $keys, true)) {
                    $keys[] = $dependentKey;
                }
            }

            foreach ($dependents['already'] as $dependentId) {
                $warning = ['dependent' => $dependentId, 'skipped' => $target];
                if (! in_array($warning, $dependencyWarnings, true)) {
                    $dependencyWarnings[] = $warning;
                    $newDependencyWarnings[] = $warning;
                }
            }
        }
        $skipped = $state['skipped_tasks'] ?? [];
        foreach ($keys as $key) {
            if (! in_array($key, $completed, true)) {
                $completed[] = $key;
            }
            if (! in_array($key, $skipped, true)) {
                $skipped[] = $key;
            }
        }

        $state['completed_tasks'] = $completed;
        $state['skipped_tasks'] = $skipped;
        $state['dependency_skip_warnings'] = $dependencyWarnings;
        $state['current_task'] = null;
        $state['failed_task'] = null;
        $state['failed_task_target'] = null;
        $state['failed_task_skippable'] = null;
        $state['error_message_key'] = null;
        // 치환값도 함께 비운다 — 남기면 건너뛴 뒤의 조회 응답(getState·stepwiseStateSummary)이
        // 직전 실패의 값을 계속 실어 보내 다음 문구가 엉뚱한 값으로 채워진다.
        $state['error_message_params'] = null;
        $state['error_detail'] = null;
        $state['rollback_failure'] = null;
        $state['manual_commands'] = null;

        if (! saveInstallationState($state)) {
            return ['success' => false, 'message_key' => 'state_save_failed', 'skipped' => []];
        }

        foreach ($keys as $key) {
            addLog(lang('log_task_skipped_by_user', ['task' => installerTaskKeyDisplayName($key)]));
        }

        // 되돌릴 수 없는 쪽(이미 설치된 의존 확장)은 진행 기록으로 알린다 — 조용히 두면
        // 의존이 깨진 확장이 활성 상태로 남았다는 사실이 어디에도 남지 않는다.
        foreach ($newDependencyWarnings as $warning) {
            addLog(lang('log_dependency_skipped_after_install', [
                'dependent' => $warning['dependent'] ?? '',
                'skipped' => $warning['skipped'] ?? '',
            ]));
        }

        return ['success' => true, 'message_key' => 'skip_task_done', 'skipped' => $keys];
    }
}

if (! function_exists('installerTaskKeyDisplayName')) {
    /**
     * completed_tasks 키("id" 또는 "id:target")를 사람 말 표시 이름으로 바꾼다.
     *
     * 진행 기록은 운영자가 읽는다 (W3) — 내부 태스크 키를 그대로 내보내지 않는다.
     * task-runner 의 installerTaskDisplayName() 과 같은 형태("이름 (대상)")를 만들되,
     * task-runner 가 로드되지 않는 state-management 경로에서도 쓸 수 있게 키만 받는다.
     *
     * @param  string  $key  태스크 키
     * @return string 표시 이름
     */
    function installerTaskKeyDisplayName(string $key): string
    {
        [$taskId, $target] = str_contains($key, ':') ? explode(':', $key, 2) : [$key, null];

        $taskName = function_exists('lang') ? lang("task_{$taskId}") : $taskId;
        if ($taskName === "task_{$taskId}") {
            $taskName = $taskId;
        }

        return $target !== null && $target !== '' ? "{$taskName} ({$target})" : $taskName;
    }
}
