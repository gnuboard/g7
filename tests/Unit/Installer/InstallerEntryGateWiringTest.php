<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 13차가 신설한 판정들이 **실제로 배선되어 있는지** 고정한다.
 *
 * 판정 함수 자체는 행위 테스트가 덮는다(InstallerEnvironmentJudgmentTest). 여기서 보는 축은
 * 「그 판정을 부르는 줄」이다 — 그 줄이 사라지면 함수는 멀쩡한 채로 기능만 없어지고, 전
 * 스위트가 초록으로 남는다(10차 #G1 이 실측한 형태).
 *
 * 대조는 예외 없이 **주석을 지운 뒤** 한다 — 원문에는 주석 처리된 배선도 글자로 남으므로
 * 원문 대조는 「배선이 살아 있다」를 증명하지 못한다.
 *
 * 손 목록은 파일이 늘어도 스스로 자라지 않는다. 그래서 목록마다 **역방향 도출**을 둔다 —
 * 「그 판정을 실제로 부르는 파일」과 「POST 를 받는 파일」을 소스에서 뽑아, 목록이 그것을
 * 전부 덮는지 본다. 모집단은 `api/` 한 겹이 아니라 `public/install/**` 전수다 (14차 C11-9·C11-10).
 *
 * 커버 축: gate origin_check·post_complete_guard·worker_state_gate·function_availability·bundle_usability·probe_rate_limit·https_ssoT·state_file_presence / entry install_process·install_worker·state_management·save_extensions·finalize_env·check_configuration·session·config·request_handler·index
 */
class InstallerEntryGateWiringTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 설치기 소스 디렉터리의 절대경로를 돌려준다.
     *
     * @return string 설치기 디렉터리 절대경로
     */
    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 주석을 제거한 생산 소스.
     *
     * @param  string  $relative  public/install 기준 상대 경로
     * @return string 주석을 지운 소스
     */
    private function source(string $relative): string
    {
        $path = $this->installRoot().'/'.$relative;
        $this->assertFileExists($path);

        return $this->stripComments((string) file_get_contents($path));
    }

    /**
     * 상태를 바꾸는 엔드포인트는 **전부** 출처를 확인한다 (13차 보안#4).
     *
     * 인스톨러의 csrf_token 검증은 정의만 있고 호출부가 저장소 전체에 0건이었다 — 그런데
     * 세션 쿠키 SameSite 완화의 근거로는 그 검증이 인용돼 있었다. 한 엔드포인트만 빠져도
     * 그 지점이 우회로가 되므로 목록으로 고정한다.
     *
     * @effects installer_state_changing_endpoints_check_request_origin
     *
     * @return void 반환값 없음
     */
    public function test_state_changing_endpoints_check_request_origin(): void
    {
        $endpoints = [
            'api/install-process.php' => '설치 시작 + 단계별 토큰 발급을 다른 사이트가 부를 수 있다',
            'api/state-management.php' => '중단(진행 중 태스크 롤백)·초기화(완료 기록 전소거)를 다른 사이트가 부를 수 있다',
            'api/save-extensions.php' => '설치할 확장 목록을 다른 사이트가 바꿀 수 있다',
            'api/finalize-env.php' => '완료 표식 기록·runtime.php 삭제를 다른 사이트가 부를 수 있다',
            'api/install-worker.php' => '크로스오리진 이미지 태그 하나가 실제 설치를 시작한다',
            // 역방향 도출이 드러낸 자리 — 이 파일은 손 목록이 만들어진 뒤에 같은 가드를
            // 부르게 됐고, 그동안 그 호출을 재는 검사가 0건이었다 (10차 G3 라운드 C10-9).
            'api/run-task.php' => '단계별 실행(요청 1 = 태스크 1)을 다른 사이트가 부를 수 있다',
        ];

        foreach ($endpoints as $relative => $why) {
            $this->assertStringContainsString(
                'installerCrossOriginRejection(',
                $this->source($relative),
                $relative.' 이 출처 확인을 부르지 않는다 — '.$why
            );
        }

        // 목록의 **반대 방향**도 잰다.
        //
        // 위 다섯은 손으로 적혀 있었고, 그 사이 `api/run-task.php` 가 같은 가드를
        // 부르게 됐는데 목록에 들어오지 않았다 — 그래서 그 파일의 출처 확인은
        // **어느 검사도 보지 않는 상태**였다(그 줄을 지워도 전부 초록). 손 목록은
        // 파일이 늘어도 스스로 자라지 않는다 (10차 G3 라운드 C10-9).
        //
        // 그래서 「그 가드를 실제로 부르는 엔드포인트」를 소스에서 도출해, 목록이
        // 그것을 전부 덮는지 본다. 새 파일이 그 가드를 부르면 여기서 멈춘다.
        //
        // 모집단은 `api/` 한 겹이 아니라 **인스톨러 전수**다. 종전에는 `glob('api/*.php')`
        // 로 고정돼 있어, 그 밖의 POST 진입점은 구조적으로 보이지 않았다 — `index.php` 의
        // POST 처리(`includes/request-handler.php` 위임)가 정확히 그 자리다 (14차 C11-9).
        // 모집단을 넓히면 **판정을 선언하는 파일**(`includes/execution-mode.php`)도 그 글자를
        // 갖는다. 선언은 호출이 아니므로 토큰 판정으로 가른다.
        $callers = [];

        foreach ($this->installerPhpFiles() as $relative => $source) {
            if ($this->directFunctionCalls($source, ['installerCrossOriginRejection']) !== []) {
                $callers[] = $relative;
            }
        }

        sort($callers);

        $this->assertNotSame([], $callers, '출처 확인을 부르는 엔드포인트가 하나도 없다 — 이 검사가 아무것도 재지 않는다');

        foreach ($callers as $relative) {
            $this->assertArrayHasKey(
                $relative,
                $endpoints,
                $relative.' 이 출처 확인을 부르는데 이 목록에 없다 — 그 호출을 지워도 아무 검사도 red 가 되지 않는다. '
                .'목록에 사유와 함께 등재할 것'
            );
        }

        // **가드가 없는 POST 진입점의 원장.**
        //
        // 위 대조는 「가드를 부르는 파일」만 본다 — 부르지 **않는** POST 진입점은 어느
        // 방향에서도 보이지 않는다. 그래서 POST 를 받는 파일을 소스에서 도출하고, 그것이
        // 가드 목록이나 이 원장 중 한쪽에 반드시 있게 한다. 원장 항목은 「빠뜨린 것」이
        // 아니라 **사유와 함께 남긴 사실**이며, 다음 회차가 그 사유를 다시 읽는다.
        //
        // 제품 결정(14차 (4)): `index.php`·`request-handler.php` 에 가드를 **추가하지 않는다**
        // (동작 불변). 검사 범위만 넓히고 사실을 원장에 남긴다.
        $ledger = [
            'index.php' => '설치 첫 화면과 각 단계의 폼 제출이 같은 진입점을 쓴다 — 여기에 출처 확인을 '
                .'걸면 프록시·리버스프록시 뒤에서 첫 화면 자체가 막힐 수 있어, 이 저장소가 잴 수 없는 '
                .'환경에서 설치가 통째로 불가능해진다. 오늘 악용이 성립하지 않는 근거는 세션 쿠키의 '
                .'`SameSite=Lax` 다 — 교차 사이트 POST 에는 쿠키가 실리지 않아 그 요청은 설치 세션을 '
                .'얻지 못한다. 가드 추가 여부는 G8 브라우저 실측이 소유한다',
            'includes/request-handler.php' => 'index.php 의 POST 를 받아 처리하는 본체다 — 위와 같은 사유이며, '
                .'그 진입점에 걸지 않기로 한 판단이 여기까지 이어진다. 이 파일만 따로 가드를 걸면 '
                .'같은 요청이 두 층에서 다르게 판정된다',
            'api/check-configuration.php' => 'POST action 셋(`test-db`·`test-php-binary`·`test-composer`·'
                .'`detect-execution-mode`)은 **상태를 바꾸지 않는 probe** 다. 남용 축은 출처가 아니라 '
                .'호출 횟수이고 `installerProbeRateLimitExceeded()` 가 그 축을 소유한다',
            'includes/installer-ownership.php' => '요청을 직접 받는 진입점이 아니라 소유권·CSRF 판정 라이브러리다 — '
                .'`$_POST` 는 `installer_request_csrf_token()` 이 폼 필드의 csrf_token 을 읽는 한 자리뿐이다. '
                .'출처 확인은 이 파일을 싣는 엔드포인트(위 목록)가 각자 소유하고, 이 파일에 걸면 '
                .'싣는 쪽마다 같은 요청을 두 번 판정하게 된다',
        ];

        $postEntries = [];

        foreach ($this->installerPhpFiles() as $relative => $source) {
            $handlesPost = (str_contains($source, 'REQUEST_METHOD') && str_contains($source, "'POST'"))
                || str_contains($source, '$_POST');

            if ($handlesPost) {
                $postEntries[] = $relative;
            }
        }

        sort($postEntries);

        $this->assertNotSame([], $postEntries, 'POST 를 받는 인스톨러 파일을 하나도 찾지 못했다 — 이 대조가 아무것도 재지 않는다');

        foreach ($postEntries as $relative) {
            $this->assertTrue(
                isset($endpoints[$relative]) || isset($ledger[$relative]),
                $relative.' 이 POST 를 받는데 출처 확인도, 그것을 두지 않은 사유도 없다 — '
                .'둘 중 하나를 반드시 남긴다. 사유 없이 비어 있으면 다음 회차가 「검토했는데 '
                .'불필요하다」와 「보지 못했다」를 구분할 수 없다'
            );
        }

        // 원장에 **죽은 항목**을 남기지 않는다 — 그 파일이 더는 POST 를 받지 않거나 가드를
        // 갖게 되면 사유가 사실과 어긋난다. 그때 red 가 되어 원장을 정리하게 한다.
        foreach ($ledger as $relative => $why) {
            $this->assertContains(
                $relative,
                $postEntries,
                $relative.' 이 원장에 있는데 POST 진입점이 아니다 — 사유가 사실과 어긋났다: '.$why
            );

            $this->assertArrayNotHasKey(
                $relative,
                $endpoints,
                $relative.' 이 출처 확인을 갖게 됐다 — 원장에서 빼고 가드 목록으로 옮긴다'
            );
        }
    }

    /**
     * 인스톨러 PHP 파일 전수 (상대 경로 => 주석 제거 소스).
     *
     * 모집단을 손으로 적거나 한 겹(`api/*.php`)으로 고정하면 그 밖에 생긴 진입점이
     * 구조적으로 사각이 된다.
     *
     * @return array<string, string> public/install 기준 상대 경로 => 주석 제거 소스
     */
    private function installerPhpFiles(): array
    {
        $root = $this->installRoot();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = strtr(substr($file->getPathname(), strlen($root) + 1), [DIRECTORY_SEPARATOR => '/']);
            $files[$relative] = $this->stripComments((string) file_get_contents($file->getPathname()));
        }

        ksort($files);

        $this->assertNotSame([], $files, '인스톨러 PHP 파일을 하나도 찾지 못했다 — 도출이 비었다');

        return $files;
    }

    /**
     * 설치가 끝난 뒤에도 열려 있던 파괴적 엔드포인트를 닫았는가 (13차 보안#8).
     *
     * `.env` 쓰기가 실패하면 종전 가드는 계속 통과시켰다 — DB·관리자 계정이 완비된 운영
     * 사이트에서 재시작·중단·초기화가 영구히 열린 채 남는다.
     *
     * @effects installer_post_complete_endpoints_are_closed
     *
     * @return void 반환값 없음
     */
    public function test_post_complete_guard_is_wired_to_destructive_entries(): void
    {
        foreach (['api/install-process.php', 'api/install-worker.php', 'api/state-management.php'] as $relative) {
            $this->assertStringContainsString(
                'installer_guard_post_complete_or_410(',
                $this->source($relative),
                $relative.' 이 완료 후 진입 가드를 부르지 않는다'
            );
        }

        // 이 가드를 **걸면 안 되는** 곳과 그 근거. 신호는 「finalize 가 실패했다」와 「finalize 가
        // 아직 안 왔다」를 구분하지 못한다 — complete_flag 가 락 파일을 만든 순간부터 참이므로,
        // 그 뒤에 정상적으로 남아 있는 흐름을 나르는 엔드포인트에 걸면 정상 설치가 끝나지 못한다.
        $mustStayOpen = [
            'api/finalize-env.php' => '표식을 박는 복구 경로 자신이다 — 걸면 .env 쓰기 실패 뒤 재시도가 불가능해진다',
            'api/run-task.php' => '태스크 목록의 마지막은 complete_flag 가 아니라 config_cache 다 — 걸면 모든 정상 stepwise 설치가 마지막 태스크에서 410 으로 끝난다',
            'index.php' => '그 창의 새로고침이 완료 화면을 복원해 finalize 를 다시 부른다 — 걸면 평문 DB 비밀번호를 담은 runtime.php 가 그대로 남는다',
        ];

        foreach ($mustStayOpen as $relative => $why) {
            $this->assertStringNotContainsString(
                'installer_guard_post_complete_or_410(',
                $this->source($relative),
                $relative.' 에 완료 후 가드를 걸었다 — '.$why
            );
        }
    }

    /**
     * 스트리밍 워커의 진입 전제 (13차 보안#5·F6).
     *
     * @scenario installation_mode=sse
     *
     * @effects installer_sse_worker_requires_running_installation
     *
     * @return void 반환값 없음
     */
    public function test_sse_worker_state_gate_is_wired(): void
    {
        $src = $this->source('api/install-worker.php');

        $this->assertStringContainsString(
            'installerSseWorkerStateRejection(',
            $src,
            '상태 전제가 없으면 설정이 저장되기도 전에 GET 하나로 전 태스크가 돈다'
        );
        $this->assertStringContainsString(
            'installerSseWorkerRejected(',
            $src,
            '형제 판정(stepwise 거부)의 배선은 그대로 유지한다'
        );
    }

    /**
     * 오류 출력 차단이 **상태 파일 읽기보다 앞** 이어야 한다 (13차 E6).
     *
     * 12차 #A6 은 이 파일을 「이미 try 진입 전에 끈다」고 기록했으나 실물은 그렇지 않았다 —
     * 상태 읽기·번역 로드를 켜진 채로 돌고 헤더 송출 뒤에야 껐다. 그 구간의 warning 한 줄이면
     * `text/event-stream` 헤더가 서지 못해 EventSource 는 사유 대신 "연결 실패" 만 본다.
     *
     * @effects installer_display_errors_is_disabled_before_state_read
     *
     * @return void 반환값 없음
     */
    public function test_display_errors_is_disabled_before_the_state_file_is_read(): void
    {
        $src = $this->source('api/install-worker.php');

        $disableAt = strpos($src, "ini_set('display_errors'");
        $stateReadAt = strpos($src, 'getInstallationState(');

        $this->assertNotFalse($disableAt, '오류 출력 차단이 없다');
        $this->assertNotFalse($stateReadAt, '상태 읽기를 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertLessThan(
            $stateReadAt,
            $disableAt,
            '상태 파일을 읽기 전에 오류 출력을 꺼야 한다 — 그 사이의 warning 한 줄이 SSE 헤더를 무너뜨린다'
        );
    }

    /**
     * 호스팅이 흔히 막는 함수의 호출 지점에 가드가 있는가 (13차 환경#1).
     *
     * @effects installer_blocked_function_call_sites_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_blocked_function_call_sites_are_guarded(): void
    {
        $checkConfiguration = $this->source('api/check-configuration.php');

        $this->assertStringContainsString(
            "installerHasFunction('disk_free_space')",
            $checkConfiguration,
            'disk_free_space 가드가 없으면 그 호스팅에서 요구사항 목록이 통째로 뜨지 않는다'
        );

        // 배선 단언은 문자열 채널까지 벗긴 소스로 잰다 (G3 회차 15).
        $session = $this->sourceWithoutCommentsAndStrings($this->installRoot().'/includes/session.php');

        $this->assertStringContainsString('installerIniSet(', $session, 'ini_set 무가드 호출은 첫 화면을 백지로 만든다');
        $this->assertStringNotContainsString(
            "ini_set('session.",
            $session,
            '세션 설정의 ini_set 이 아직 무가드로 남아 있다'
        );

        // 진입점은 호출 **리터럴**을 그대로 둔다 — 그 줄을 문자로 고정하는 소스 게이트가 이미
        // 있어서 래퍼로 감싸면 그 게이트가 배선을 못 본다. 대신 같은 파일에 가용성 판정이
        // 있어야 한다 (`@` 는 undefined function Error 를 막지 못하므로 가드가 아니다).
        $entryPoints = [
            'api/install-worker.php' => ['ini_set', 'set_time_limit', 'ignore_user_abort'],
            'api/sse-probe.php' => ['ini_set', 'set_time_limit', 'ignore_user_abort'],
            'api/install-process.php' => ['ini_set', 'set_time_limit', 'ignore_user_abort'],
            'api/state-management.php' => ['ini_set'],
        ];

        foreach ($entryPoints as $relative => $functions) {
            $src = $this->source($relative);

            foreach ($functions as $function) {
                $this->assertStringContainsString(
                    "installerHasFunction('".$function."')",
                    $src,
                    $relative.' 이 '.$function.' 을(를) 가용성 판정 없이 부른다 — 그 호스팅에서 이 엔드포인트가 치명 종료한다'
                );
            }
        }

        // **역방향 도출.**
        //
        // 위 목록은 손으로 적혀 있다. 그 사이 같은 함수를 부르게 된 파일은 목록에 스스로
        // 들어오지 않는다 — 형제 축(출처 확인)이 정확히 그 형태로 한 파일을 놓쳤고, 이
        // 목록에는 그 방향이 아예 없었다 (14차 C11-10).
        //
        // 그래서 그 함수들을 **실제로 부르는** 파일을 소스에서 도출하고, 그 파일이 같은
        // 파일 안에 가용성 판정을 갖는지 본다. 손 목록은 「리터럴 호출을 유지하기로 한
        // 진입점」이라는 의도를 남기는 자리로만 남고, 강제는 도출이 한다.
        $blocked = ['ini_set', 'set_time_limit', 'ignore_user_abort'];
        $derived = 0;

        foreach ($this->installerPhpFiles() as $relative => $source) {
            foreach ($this->directFunctionCalls($source, $blocked) as $function) {
                $this->assertStringContainsString(
                    "installerHasFunction('".$function."')",
                    $source,
                    $relative.' 이 '.$function.' 을(를) 가용성 판정 없이 부른다 — 그 호스팅에서 이 파일이 '
                    .'치명 종료한다. `@` 는 가드가 아니다 (PHP 8 은 차단 함수를 함수 테이블에서 제거한다)'
                );

                $derived++;
            }
        }

        $this->assertGreaterThanOrEqual(
            array_sum(array_map('count', $entryPoints)),
            $derived,
            '도출한 호출 지점이 손 목록보다 적다 — 도출식이 그 표기를 모르거나 모집단이 줄었다'
        );

        // 손 목록이 **사문화되지 않았는가** — 그 파일이 더는 그 함수를 부르지 않으면
        // 「리터럴 호출을 유지한다」는 의도가 사실과 어긋난다.
        foreach ($entryPoints as $relative => $functions) {
            $this->assertSame(
                $functions,
                $this->directFunctionCalls($this->source($relative), $blocked),
                $relative.' 의 손 목록이 실제 호출과 어긋난다 — 목록을 실물에 맞춘다'
            );
        }
    }

    /**
     * 「번들이 있다」가 아니라 「번들을 쓸 수 있다」를 보는가 (13차 환경#4).
     *
     * @scenario bundle_state=present
     *
     * @effects installer_vendor_bundle_usability_requires_ziparchive
     *
     * @return void 반환값 없음
     */
    public function test_vendor_bundle_usability_is_judged_by_the_shared_helper(): void
    {
        // 소비처 전수 — 한 곳만 사본을 쓰면 그 화면·그 태스크만 낙관 판정을 낸다. Step 2 가
        // 「CLI 없이 설치 진행」 파랑으로 통과시키고 Step 3 가 bundled 를 고를 수 있게 열어
        // 둔 뒤, 실제 추출이 그 자리에서 멈추는 것이 종전 상태였다.
        $consumers = [
            'api/check-configuration.php' => '요구사항 화면이 ZipArchive 없는 환경을 통과시킨다',
            'includes/execution-mode.php' => '실행 모드 감지가 inprocess 를 선택 가능으로 본다',
            'views/3-configuration.php' => 'Step 3 가 고를 수 없는 Vendor 방식(bundled)을 열어 둔다',
            'includes/task-runner.php' => 'auto 폴백이 풀 수 없는 번들로 내려가 그 자리에서 멈춘다',
        ];

        foreach ($consumers as $relative => $why) {
            $src = $this->source($relative);

            $this->assertStringContainsString(
                'installerVendorBundleUsable(',
                $src,
                $relative.' 이 번들 가용성 판정을 부르지 않는다 — '.$why
            );

            // execution-mode.php 는 판정 **정의** 자신이라 그 안의 file_exists 가 정답이다.
            if ($relative === 'includes/execution-mode.php') {
                continue;
            }

            $this->assertStringNotContainsString(
                "file_exists(BASE_PATH.'/vendor-bundle.zip')",
                $src,
                $relative.' 에 파일 존재만 보는 사본이 남아 있다 — '.$why
            );
        }

        // **역방향 도출** — 이 판정을 부르는 파일이 손 목록에 전부 있는가.
        //
        // 손 목록은 파일이 늘어도 스스로 자라지 않는다. 새 소비처가 생기면 그 파일의
        // 사본 금지(`file_exists`) 축이 통째로 사각이 된다 (14차 C11-10).
        $derived = [];

        foreach ($this->installerPhpFiles() as $relative => $source) {
            if (str_contains($source, 'installerVendorBundleUsable(')) {
                $derived[] = $relative;
            }
        }

        sort($derived);

        $this->assertNotSame([], $derived, '번들 가용성 판정을 부르는 파일이 하나도 없다 — 이 대조가 아무것도 재지 않는다');

        foreach ($derived as $relative) {
            $this->assertArrayHasKey(
                $relative,
                $consumers,
                $relative.' 이 번들 가용성 판정을 부르는데 이 목록에 없다 — 그 파일의 사본 금지 축이 '
                .'측정되지 않는다. 목록에 사유와 함께 등재할 것'
            );
        }
    }

    /**
     * 무인증 probe 엔드포인트의 호출 횟수 상한 (13차 보안#6·보안#7).
     *
     * @scenario endpoint_guard=general
     *
     * @effects installer_probe_endpoints_are_rate_limited
     *
     * @return void 반환값 없음
     */
    public function test_probe_endpoints_are_rate_limited(): void
    {
        $src = $this->source('api/check-configuration.php');

        $this->assertStringContainsString(
            'installerProbeRateLimitExceeded(',
            $this->sourceWithoutCommentsAndStrings($this->installRoot().'/api/check-configuration.php'),
            '무인증 probe 에 상한이 없다'
        );

        // 연결 실패 응답에서 드라이버 원문을 걷었는가 (보안#6). 판정 범위는 PDO 예외 분기로
        // 좁힌다 — 형제 분기(`catch (Exception)`)가 돌려주는 것은 이 파일이 스스로 던진
        // **이미 번역된** 검증 문구라서 같은 규칙의 대상이 아니다.
        $pdoCatchAt = strpos($src, 'catch (PDOException $e)');
        $nextCatchAt = strpos($src, 'catch (Exception $e)', $pdoCatchAt === false ? 0 : $pdoCatchAt);

        $this->assertNotFalse($pdoCatchAt, 'PDO 예외 분기를 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $this->assertNotFalse($nextCatchAt, '형제 분기를 찾지 못했다');

        $pdoBranch = substr($src, $pdoCatchAt, $nextCatchAt - $pdoCatchAt);

        $this->assertStringContainsString(
            'classifyDbConnectionFailure(',
            $pdoBranch,
            '연결 실패 사유를 분류하지 않는다'
        );
        // 로그 채널은 원문을 그대로 남긴다 — 걷어야 하는 것은 **응답**에 싣는 자리다.
        $responseLines = array_values(array_filter(
            explode("\n", $pdoBranch),
            static fn (string $line): bool => ! str_contains($line, 'logInstallationError'),
        ));

        $this->assertStringNotContainsString(
            '$e->getMessage()',
            implode("\n", $responseLines),
            '예외 원문을 응답에 실으면 임의 host:port 의 상태를 읽는 오라클이 된다 (로그 채널은 예외)'
        );
    }

    /**
     * 예외 원문이 무인증 응답으로 나가지 않는가 (13차 보안#9).
     *
     * @scenario endpoint_guard=general
     *
     * @effects installer_exception_text_is_not_returned_to_unauthenticated_callers
     *
     * @return void 반환값 없음
     */
    public function test_exception_text_is_not_returned_to_unauthenticated_callers(): void
    {
        foreach ([
            'api/state-management.php',
            'api/install-process.php',
            'api/scan-extensions.php',
        ] as $relative) {
            $this->assertStringNotContainsString(
                "'message' => \$e->getMessage()",
                $this->source($relative),
                $relative.' 이 예외 원문을 응답 제목 자리에 싣는다'
            );
        }
    }

    /**
     * 판정이 두 벌이면 한쪽만 프록시를 고려하게 된다 (13차 환경#17·환경#21·D10).
     *
     * @scenario request_scheme=forwarded_https
     *
     * @effects installer_https_judgment_is_proxy_aware, installer_finalize_idempotent_recall_uses_state_file_presence
     *
     * @return void 반환값 없음
     */
    public function test_shared_judgments_replace_their_local_copies(): void
    {
        $this->assertStringContainsString(
            'installerRequestIsHttps(',
            $this->source('includes/session.php'),
            '세션 쿠키의 secure 판정이 요구사항 화면과 갈려 있다'
        );
        $this->assertStringContainsString(
            'installerRequestIsHttps(',
            $this->source('api/check-configuration.php'),
            '요구사항 화면의 HTTPS 판정이 공용 함수를 쓰지 않는다'
        );

        $this->assertStringContainsString(
            "'pdo_mysql'",
            $this->source('includes/config.php'),
            'pdo 만 검사하면 드라이버 없는 환경이 요구사항을 통과한 뒤 DB 연결에서야 막힌다'
        );

        $this->assertStringContainsString(
            'is_file(STATE_PATH)',
            $this->source('api/finalize-env.php'),
            'finalize 의 멱등 재호출 판정이 상태 파일을 직접 보지 않는다 (getInstallationState 는 부재 시 기본 상태를 돌려준다)'
        );
    }

    /**
     * 사문화된 CSRF 헬퍼가 남아 있지 않은가 (13차 보안#4).
     *
     * 죽은 헬퍼를 남겨 두면 다음 라운드가 다시 「방어가 있다」고 읽는다 — 실제로 SameSite
     * 완화 주석이 그것을 근거로 삼고 있었다.
     *
     * @effects installer_state_changing_endpoints_check_request_origin
     *
     * @return void 반환값 없음
     */
    public function test_dead_csrf_helpers_are_gone(): void
    {
        $session = $this->source('includes/session.php');

        foreach (['function verifyCsrfToken', 'function getCsrfToken', 'function generateCsrfToken'] as $dead) {
            $this->assertStringNotContainsString($dead, $session, $dead.' 이 호출부 없이 남아 있다');
        }
    }

    /**
     * 운영자가 직접 지정한 비-CLI 바이너리를 저장하지 않는가 (13차 보안#7 ②).
     *
     * @effects installer_non_cli_php_binary_is_rejected_at_save_time
     *
     * @return void 반환값 없음
     */
    public function test_non_cli_binary_is_rejected_at_save_time(): void
    {
        $this->assertStringContainsString(
            'error_php_binary_not_cli_rejected',
            $this->source('includes/request-handler.php'),
            '비-CLI 경로가 경고만 받고 state 에 그대로 박힌다 — 그 바이너리는 artisan 을 받지 못해 전 태스크가 실패한다'
        );
    }

    /**
     * 단계별 실행 토큰의 소유자 표식이 발급 지점에서 심어지는가 (13차 보안#3 — 절반).
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_stepwise_token_is_rendered_only_for_the_owning_session
     *
     * @return void 반환값 없음
     */
    public function test_stepwise_token_records_its_owning_session(): void
    {
        $this->assertStringContainsString(
            'installer_session_owner',
            $this->source('api/install-process.php'),
            '소유자 표식이 없으면 세션 없는 요청과 설치를 시작한 세션을 구분할 수 없다'
        );
    }
}
