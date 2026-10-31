<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 서버측 결함 12차 라운드의 나머지 계약을 고정한다 (#A5·#A6·#A7·#A8·#A9·#A11·#A12).
 *
 * 여기 모인 결함은 전부 「예외도 오류도 남기지 않는」 종류다 — 응답 앞에 붙은 warning 한 줄,
 * 진행 기록에 섞인 영문, 잡히지 않은 `Error`, 상태를 남기지 않는 최상위 catch. 실행으로
 * 재현하려면 fatal 을 실제로 내야 하거나 브라우저가 필요해서, 계약을 소스 수준에서 고정한다.
 *
 * 커버 축: endpoint_guard general / task_outcome failed
 *
 * 효과 요약(마커 아님 — 평문): display_errors_off_precedes_guarded_region, progress_record_has_no_hardcoded_english, task_body_catches_throwable, worker_toplevel_catch_records_state, installation_mode_downgrade_is_observable, best_effort_key_is_never_reported_as_user_skip
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerServerHardeningContractTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 저장소 루트 절대경로를 돌려준다.
     *
     * @return string 저장소 루트 경로
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 프로세스마다 한 번 임시 기준 경로와 저장 디렉터리를 준비하고 설치기 설정, 상태, 런타임, 진행 이벤트, 태스크 러너 소스를 읽어 들인다.
     * 기준 경로 상수가 시스템 임시 경로 밖으로 이미 정의돼 있으면 실제 상태 파일을 지키려고 건너뛰기 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerServerHardeningContractTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-hardening-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = self::root();
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
    }

    /**
     * 설치기 소스를 올리고 건너뛰기 사유가 있으면 건너뛰며, 남은 상태 파일을 지우고 진행 이벤트 수집기를 버퍼 방식으로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        if (is_file(self::$base.'/storage/installer-state.json')) {
            @unlink(self::$base.'/storage/installer-state.json');
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 대조용 소스 — 주석을 지운 뒤 돌려준다.
     *
     * 원문에는 주석 처리된 배선도 글자로 남아, 「그 방어가 살아 있다」를 증명하지 못한다.
     * 줄 수는 보존되므로 순서 비교(보호 구간 표식보다 앞/뒤)는 그대로 성립한다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @return string 주석이 제거된 소스
     */
    private function source(string $relative): string
    {
        return $this->stripComments((string) file_get_contents(self::root().'/'.$relative));
    }

    // ------------------------------------------------------------------
    // #A6 — display_errors 차단은 보호 구간보다 앞에 온다
    // ------------------------------------------------------------------

    /**
     * JSON 이나 SSE 를 내보내는 설치기 진입점과 그보다 뒤에 와야 하는 보호 구간 시작 표식을 짝지어 공급한다.
     *
     * @return array<string, array{0: string, 1: string}> 사례 이름별 [저장소 상대 경로, 보호 구간 시작 표식]
     */
    public static function jsonEndpointProvider(): array
    {
        return [
            // 파일 → 그보다 뒤에 와야 하는 「보호 구간 시작」 표식
            'run-task' => ['public/install/api/run-task.php', "\ntry {"],
            'install-process' => ['public/install/api/install-process.php', "\ntry {"],
            'install-worker' => ['public/install/api/install-worker.php', "\ntry {"],
            // 폴링 채널 — 1초 간격 호출이라 warning 한 줄이 매 초 res.json() 을 깨뜨린다
            'state-management' => ['public/install/api/state-management.php', '$api->handleRequest();'],
        ];
    }

    /**
     * 진입점 소스에서 오류 화면 출력을 끄는 설정이 보호 구간 시작보다 앞에 있어 경고 문구가 응답 본문 앞에 붙지 않는지 확인한다.
     *
     * @scenario endpoint_guard=general
     *
     * @effects display_errors_off_precedes_guarded_region
     *
     * @param  string  $relative  검사할 진입점의 저장소 상대 경로
     * @param  string  $marker  보호 구간이 시작되는 소스 표식
     * @return void 반환값 없음
     */
    #[DataProvider('jsonEndpointProvider')]
    public function test_display_errors_is_disabled_before_the_guarded_region(string $relative, string $marker): void
    {
        $source = $this->source($relative);

        $ini = strpos($source, "ini_set('display_errors'");
        $this->assertNotFalse($ini, "{$relative} 이 display_errors 를 끄지 않는다.");

        $guard = strpos($source, $marker);
        $this->assertNotFalse($guard, "{$relative} 에서 보호 구간 시작을 찾지 못했다.");

        $this->assertLessThan(
            $guard,
            $ini,
            "{$relative} 은 보호 구간 안에서 warning 이 나면 그 텍스트가 JSON/SSE 본문 앞에 붙는다 — "
            .'클라이언트는 실제 사유 대신 통신 오류만 본다.'
        );
    }

    // ------------------------------------------------------------------
    // #A7·#A8 — 진행 기록은 사람 말로 쓴다 (영문 하드코딩 금지)
    // ------------------------------------------------------------------

    /**
     * 과거에 진행 기록으로 나갔던 영문 하드코딩 문구와 그 문구가 있던 소스 파일을 하나씩 공급한다.
     *
     * @return array<int, array{0: string, 1: string}> [저장소 상대 경로, 되살아나면 안 되는 영문 문구] 목록
     */
    public static function hardcodedEnglishProvider(): array
    {
        return [
            ['public/install/includes/progress-emitter.php', '=== SSE Worker lock lost'],
            ['public/install/includes/progress-emitter.php', '=== SSE Worker disconnected'],
            ['public/install/includes/progress-emitter.php', '=== Polling Worker lock lost'],
            ['public/install/includes/progress-emitter.php', '=== Stepwise task lock lost'],
            ['public/install/includes/task-runner.php', '.json created'],
            ['public/install/includes/task-runner.php', '.json skipped (no defaults)'],
            ['public/install/api/install-worker.php', '=== Install Worker SSE Started ==='],
            ['public/install/api/install-worker.php', '=== SSE Worker rejected'],
            ['public/install/api/install-worker.php', 'Worker lock acquired: '],
            ['public/install/api/install-process.php', '=== Install Worker Polling Started ==='],
            ['public/install/api/install-process.php', '=== Polling Worker rejected'],
            ['public/install/api/install-process.php', 'Worker lock acquired: '],
        ];
    }

    /**
     * 과거 위반 문구 하나가 해당 소스에 다시 나타나지 않는지 확인한다.
     *
     * @effects progress_record_has_no_hardcoded_english
     *
     * @param  string  $relative  검사할 소스의 저장소 상대 경로
     * @param  string  $literal  그 소스에 없어야 하는 영문 문구
     * @return void 반환값 없음
     */
    #[DataProvider('hardcodedEnglishProvider')]
    public function test_progress_record_carries_no_hardcoded_english(string $relative, string $literal): void
    {
        $this->assertStringNotContainsString(
            $literal,
            $this->source($relative),
            "{$relative} 이 진행 기록에 영문 문구를 그대로 넣는다 — 진행 기록의 독자는 운영자다."
        );
    }

    /**
     * 19차 G#2 — 모집단을 **소스에서 도출한다**.
     *
     * 위 provider 는 과거 위반 12개를 손으로 열거한다. 그것들이 되살아나는지는 보지만
     * **새로 추가되는** 영문 문구는 영원히 보지 못한다 — `@effects` 와 §12.0 W3(「진행 기록은
     * 사람 말로 쓴다」)이 선언한 것은 전칭 계약인데 단언은 12개 존재 부정뿐이었다.
     *
     * 그래서 진행 기록으로 나가는 호출(`addLog(` · `sendSSEEvent('log'`)이 있는 줄에서
     * **직접 문자열 리터럴**을 뽑아, 문장으로 읽히는 라틴 문자열이면 잡는다. `lang(...)` 을
     * 지나는 것은 리터럴이 키 이름이라 공백이 없다 — 그 차이로 가른다.
     *
     * @effects progress_record_has_no_hardcoded_english
     *
     * @return void 반환값 없음
     */
    public function test_progress_record_has_no_new_hardcoded_english(): void
    {
        $offenders = [];

        foreach ([
            'public/install/includes/progress-emitter.php',
            'public/install/includes/task-runner.php',
            'public/install/includes/installer-state.php',
            'public/install/api/install-worker.php',
            'public/install/api/install-process.php',
            'public/install/api/run-task.php',
            'public/install/api/state-management.php',
        ] as $relative) {
            foreach (explode("\n", $this->source($relative)) as $line) {
                if (! str_contains($line, 'addLog(') && ! str_contains($line, "sendSSEEvent('log'")) {
                    continue;
                }

                if (! preg_match_all('/([\'"])((?:(?!\1).){8,}?)\1/', $line, $m, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($m as $hit) {
                    $literal = $hit[2];

                    // 다국어 키·이벤트 이름·경로는 공백이 없다.
                    if (! str_contains($literal, ' ')) {
                        continue;
                    }
                    // 한글이 섞이면 이 저장소의 운영자 문구다 (검사 대상 아님).
                    if (preg_match('/[\x{AC00}-\x{D7A3}]/u', $literal) === 1) {
                        continue;
                    }
                    // 문장으로 읽히는 라틴 문자열만 남긴다.
                    if (preg_match('/[A-Za-z]{3,}\s+[A-Za-z]{2,}/', $literal) !== 1) {
                        continue;
                    }

                    $offenders[] = basename($relative).': '.$literal;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "진행 기록에 영문 문구가 직접 들어갔다 — 독자는 운영자이고 화면 언어는 ko/en 둘 다다.\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * 영문 하드코딩을 대신하려고 새로 만든 설치기 다국어 키를 하나씩 공급한다.
     *
     * @return array<int, array{0: string}> [다국어 키] 목록
     */
    public static function newLangKeyProvider(): array
    {
        return [
            ['log_worker_lock_lost_retreat'],
            ['log_worker_disconnected_abort'],
            ['log_worker_started'],
            ['log_worker_client_ip'],
            ['log_worker_rejected_busy'],
            ['log_worker_lock_acquired'],
            ['log_settings_json_category_created'],
            ['log_settings_json_category_skipped'],
            ['log_installation_mode_downgraded_sse'],
            ['error_worker_terminated'],
        ];
    }

    /**
     * 새 다국어 키가 ko 와 en 두 언어 파일 모두에 정의돼 있는지 확인한다.
     *
     * @scenario locale=en
     *
     * @effects progress_record_has_no_hardcoded_english
     *
     * @param  string  $key  두 언어 파일에 있어야 하는 다국어 키
     * @return void 반환값 없음
     */
    #[DataProvider('newLangKeyProvider')]
    public function test_new_lang_keys_exist_in_both_locales(string $key): void
    {
        foreach (['ko', 'en'] as $locale) {
            $messages = require self::root().'/public/install/lang/'.$locale.'.php';

            $this->assertArrayHasKey(
                $key,
                $messages,
                "{$locale}.php 에 {$key} 가 없다 — 키가 한쪽에만 있으면 그 로케일에서 원시 키가 노출된다."
            );
        }
    }

    /**
     * 19차 G#3 — 그 키들이 **실제로 배선돼 있는지**도 본다.
     *
     * ko/en 대칭은 `InstallerLanguageParityTest` 가 전수로 이미 덮는다. 그래서 위 검사가
     * 추가로 잡는 것은 「이 10개가 사라졌는가」뿐이고 **그 키를 쓰는 코드**는 보지 않았다 —
     * 호출을 통째로 지우고 lang 파일의 정의만 남겨도 초록이다. 그러면 죽은 lang 키가
     * 게이트에 의해 보존되는 형태가 된다(1차 라운드가 「죽은 lang 키 3」을 결함으로 처리한
     * 것과 방향이 반대다).
     *
     * @effects progress_record_has_no_hardcoded_english
     *
     * @param  string  $key  설치기 코드 어딘가에서 쓰여야 하는 다국어 키
     * @return void 반환값 없음
     */
    #[DataProvider('newLangKeyProvider')]
    public function test_new_lang_keys_are_actually_wired(string $key): void
    {
        $wired = false;

        foreach ($this->installerPhpSources() as $source) {
            if (str_contains($source, "'".$key."'")) {
                $wired = true;

                break;
            }
        }

        $this->assertTrue(
            $wired,
            $key.' 를 쓰는 코드가 없다 — 배선이 사라진 뒤에도 이 키가 게이트에 의해 보존된다',
        );
    }

    /**
     * 인스톨러 PHP 소스 전수 (주석 제거, 다국어 파일 제외).
     *
     * @return array<int, string> 주석을 지운 설치기 PHP 소스 목록
     */
    private function installerPhpSources(): array
    {
        $out = [];
        $root = self::root().'/public/install';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), '/lang/')) {
                continue;
            }

            $out[] = $this->stripComments((string) file_get_contents($file->getPathname()));
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // #A9 — 태스크 본문은 Throwable 을 잡는다
    // ------------------------------------------------------------------

    /**
     * 태스크 러너 소스에 Exception 만 잡는 catch 가 남아 있지 않아 Error 계열도 실패 처리로 들어가는지 확인한다.
     *
     * @effects task_body_catches_throwable
     *
     * @return void 반환값 없음
     */
    public function test_task_bodies_catch_throwable_not_exception(): void
    {
        $this->assertStringNotContainsString(
            'catch (Exception ',
            $this->source('public/install/includes/task-runner.php'),
            '태스크 본문이 Exception 만 잡으면 in-process 실행에서 정상 범위인 Error/TypeError 가 '
            .'handleInstallationTaskFailure 를 통째로 건너뛴다.'
        );
    }

    // ------------------------------------------------------------------
    // #A11 — 최상위 catch 도 상태를 남긴다
    // ------------------------------------------------------------------

    /**
     * SSE 설치 워커의 마지막 Throwable catch 가 실행 예외를 상태에 기록하는 헬퍼를 부르는지 확인한다.
     *
     * @scenario installation_mode=sse
     *
     * @effects worker_toplevel_catch_records_state
     *
     * @return void 반환값 없음
     */
    public function test_sse_worker_toplevel_catch_records_the_failure_state(): void
    {
        $source = $this->source('public/install/api/install-worker.php');

        $tail = substr($source, (int) strrpos($source, '} catch (Throwable'));

        $this->assertStringContainsString(
            'recordInstallationRunException(',
            $tail,
            'SSE 워커의 최상위 catch 가 상태를 남기지 않는다 — 화면만 빨갛고 서버는 running 으로 남아 '
            .'재개도 건너뛰기도 불가능해진다.'
        );
    }

    // ------------------------------------------------------------------
    // #A5 — 진행 방식 하향은 흔적을 남긴다
    // ------------------------------------------------------------------

    /**
     * 폴링 진입점이 진행 방식 하향 판정을 실제로 읽고 하향 사실을 응답 필드와 진행 기록 키로 남기는지 확인한다.
     *
     * @scenario installation_mode=sse
     *
     * @effects installation_mode_downgrade_is_observable
     *
     * @return void 반환값 없음
     */
    public function test_installation_mode_downgrade_is_logged_and_reported(): void
    {
        $source = $this->source('public/install/api/install-process.php');

        $this->assertStringContainsString(
            "\$installationDecision['downgraded']",
            $source,
            '진행 방식 하향 판정이 생산되기만 하고 소비되지 않는다 — 저장된 선택이 조용히 뒤집힌다.'
        );
        $this->assertStringContainsString('installation_mode_downgraded', $source);
        $this->assertStringContainsString('log_installation_mode_downgraded_sse', $source);
    }

    // ------------------------------------------------------------------
    // #A12 — best-effort 로 지나간 태스크는 「운영자가 건너뛴 것」이 아니다
    // ------------------------------------------------------------------

    /**
     * 이미 완료로 기록된 최선노력 태스크를 다시 만났을 때 진행 기록이 운영자 건너뛰기 문구가 아니라 최선노력 문구와 같은지 확인한다.
     *
     * @scenario task_outcome=best_effort_failed, resume_point=already_completed
     *
     * @effects best_effort_key_is_never_reported_as_user_skip
     *
     * @return void 반환값 없음
     */
    public function test_best_effort_key_is_never_reported_as_user_skip(): void
    {
        $task = [
            'id' => 'language_pack_install',
            'target' => 'g7-core-ja',
            'key' => 'language_pack_install:g7-core-ja',
            'function' => 'installerNeverCalledInThisTest',
            'args' => [],
            'best_effort' => true,
            'skippable' => true,
        ];

        $emitter = new \BufferEmitter;
        setProgressEmitter($emitter);

        $run = runSingleInstallationTask(
            $task,
            ['language_pack_install:g7-core-ja'],
            ['language_pack_install:g7-core-ja'],
            ['language_pack_install:g7-core-ja'],
        );

        $this->assertSame('already_completed', $run['outcome']);

        $events = $emitter->drain();
        $this->assertNotSame([], $events);

        $message = (string) ($events[0]['data']['message'] ?? '');
        $displayName = installerTaskDisplayName($task);

        // 19차 G#12 — 부정 부분문자열이 아니라 **동일성**으로 본다.
        //
        // 종전 needle 은 `lang('log_task_skipped_by_user', ['task' => ''])` 였다. 지금 ko 값이
        // `':task — 운영자가 건너뛰었습니다.'` 로 자리표시자가 맨 앞이라 **우연히** 부분문자열
        // 관계가 성립해 동작했을 뿐이다. 번역문을 자연스럽게 다시 쓰면(`'운영자가 :task 를
        // 건너뛰었습니다.'`) needle 이 `'운영자가  를 건너뛰었습니다.'` 가 되어 실제 메시지의
        // 부분문자열이 아니게 되고, match 팔 순서를 뒤집어도 초록이 된다 — **다국어 개선
        // 커밋 하나가 이 게이트를 소리 없이 끈다**.
        //
        // 양쪽이 같은 `lang()` 을 지나므로 문구를 어떻게 바꾸든 판정은 그대로 성립한다.
        $this->assertSame(
            lang('log_task_best_effort_skipped', ['task' => $displayName]),
            $message,
            'best-effort 실패로 지나간 태스크가 「운영자가 건너뛰었습니다」로 표시된다.'
        );

        $this->assertNotSame(
            lang('log_task_skipped_by_user', ['task' => $displayName]),
            $message,
            'match 팔 순서가 뒤집히면 두 사유가 뒤바뀐다',
        );
    }

    /**
     * 태스크 러너 소스에 최선노력 키를 운영자 건너뛰기 목록에 넣는 분기가 남아 있지 않은지 확인한다.
     *
     * @scenario task_outcome=best_effort_failed
     *
     * @effects best_effort_key_is_never_reported_as_user_skip
     *
     * @return void 반환값 없음
     */
    public function test_best_effort_loop_does_not_write_into_the_user_skip_list(): void
    {
        $source = $this->source('public/install/includes/task-runner.php');

        $this->assertStringNotContainsString(
            '$skippedTasks[] = $bestEffortKey',
            $source,
            'best-effort 키를 운영자 건너뛰기 목록에 넣는 분기는 정의상 읽히지 않는다 — '
            .'주석이 말하는 규칙과 실제 동작이 어긋난 채 남는다.'
        );
    }
}
