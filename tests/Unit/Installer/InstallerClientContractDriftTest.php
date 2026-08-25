<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 화면 배선이 서버 계약에서 갈라진 지점들 (13차 E1·E7·보안#10·F4·F5).
 *
 * 여기서 잠그는 것은 전부 **한 모드에서만 드러나는 비대칭**이다. 어느 것도 예외나 로그를
 * 남기지 않고, 다른 모드에서는 정상으로 보이기 때문에 사람 눈으로는 발견되지 않는다.
 *
 *  - E1: 정지 감지 창이 모드마다 다르다. 서버가 서브프로세스 스폰 동안 스스로에게 주는
 *    창은 600초인데 그 구간에는 emit 이 0 이다 — SSE·폴링만 90초로 끊어, 정상적으로 긴
 *    `migrate`·`db:seed` 가 두 모드에서만 「응답이 없습니다」가 된다.
 *  - E7: 실패 이벤트의 `target` 을 SSE·단계별 실행이 버린다 — 어느 확장이 실패했는지가
 *    경로에 따라 보이기도 안 보이기도 한다.
 *  - 보안#10: 인라인 JS 로 나가는 값 하나가 무이스케이프다 — 같은 블록의 형제는 전부
 *    `json_encode` 를 지난다.
 *  - F4·F5: 모드 확정 응답 필드 둘 중 하나만 소비되고, 소비되는 쪽은 서버 로그와 겹친다.
 *
 * 커버 축: drift stuck_window·failure_target·inline_escape·mode_notice
 */
class InstallerClientContractDriftTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    private function installerJs(): string
    {
        return $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installer.js'));
    }

    /**
     * `(` 에서 시작해 짝이 맞는 `)` 까지의 인자 텍스트. 문자열 안의 괄호는 세지 않는다.
     */
    private function callArguments(string $js, int $openParen): string
    {
        $depth = 0;
        $length = strlen($js);
        $quote = null;

        for ($i = $openParen; $i < $length; $i++) {
            $ch = $js[$i];

            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;

                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;

                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($js, $openParen + 1, $i - $openParen - 1);
                }
            }
        }

        return '';
    }

    public function test_세_모니터가_같은_정지_감지_창을_받는다(): void
    {
        $js = $this->installerJs();

        foreach (['SseMonitor', 'PollingMonitor', 'StepwiseMonitor'] as $monitor) {
            $needle = 'new MonitorNS.'.$monitor.'(';
            $pos = strpos($js, $needle);

            $this->assertIsInt($pos, $monitor.' 생성 지점을 찾지 못했다 — 이 검사가 사각이 된다');

            $args = $this->callArguments($js, $pos + strlen($needle) - 1);

            $this->assertStringContainsString(
                'stuckThresholdMs',
                $args,
                $monitor." 가 정지 감지 창을 받지 못한다.\n"
                .'그 모니터만 자기 기본값(90초 또는 660초)을 쓰게 되어, 서버가 서브프로세스 스폰으로 '
                .'최대 600초 동안 아무것도 내보내지 않는 구간에서 그 모드만 정상 설치를 정지로 뒤집는다.',
            );
        }
    }

    public function test_정지_감지_창의_근거가_서버_상수에서_온다(): void
    {
        $view = $this->stripComments((string) file_get_contents($this->installRoot().'/views/5-installation.php'));

        $this->assertMatchesRegularExpression(
            '/window\.INSTALLER_SERVER_BLOCKING_WINDOW_MS\s*=.*INSTALLER_SUBPROCESS_TIME_LIMIT/s',
            $view,
            '정지 감지 창의 근거가 서버 상수(INSTALLER_SUBPROCESS_TIME_LIMIT)에서 파생되지 않는다 — '
            .'숫자를 화면에 다시 적으면 서버가 창을 바꿔도 화면이 따라오지 않는다.',
        );

        $this->assertStringContainsString(
            'INSTALLER_SERVER_BLOCKING_WINDOW_MS',
            $this->installerJs(),
            '화면이 주입된 서버 창을 읽지 않는다 — 주입만 하고 읽지 않으면 값은 죽은 채로 남는다',
        );
    }

    public function test_실패_이벤트의_확장_식별자가_버려지지_않는다(): void
    {
        $js = $this->installerJs();

        $pos = strpos($js, 'onError: (data) =>');
        $this->assertIsInt($pos, 'onError 콜백을 찾지 못했다 — 이 검사가 사각이 된다');

        // 다음 콜백 키까지가 이 콜백의 본문이다 — 고정 길이로 자르면 주석이 늘어날 때마다
        // 창이 코드에 닿지 못해 검사가 조용히 무의미해진다.
        $end = strpos($js, 'onConnectionTimeout:', $pos);
        $this->assertIsInt($end, 'onError 콜백의 끝을 찾지 못했다 — 이 검사가 사각이 된다');

        $body = substr($js, $pos, $end - $pos);

        $this->assertStringContainsString(
            'data.target',
            $body,
            "실패 이벤트의 `target` 을 읽지 않는다.\n"
            .'서버는 `error{task: 순수 id, target}` 로 나눠 보내는데 폴링·새로고침 경로는 합성키'
            ."(`id:target`)를 그대로 쓴다 — 같은 실패가 「모듈 설치」와 「모듈 설치 (sirsoft-board)」로\n"
            .'갈려, 어느 확장이 실패했는지 알 수 있기도 없기도 하다.',
        );
    }

    public function test_인라인_j_s_로_나가는_값이_전부_이스케이프를_지난다(): void
    {
        $index = $this->stripComments((string) file_get_contents($this->installRoot().'/index.php'));

        // 짧은 echo 태그로 인라인 JS 문자열 리터럴 안에 직접 박히는 값을 찾는다.
        preg_match_all('/window\.[A-Z_]+\s*=\s*[\'"]<\?=\s*([^?]+?)\s*\?>/', $index, $raw);

        $this->assertSame(
            [],
            $raw[1],
            '인라인 JS 문자열에 무이스케이프로 박히는 값: '.implode(', ', $raw[1])
            ."\n같은 블록의 형제는 전부 json_encode(JSON_HEX_*) 를 지난다 — 한 곳만 빠지면"
            ."\n그 값이 스크립트 탈출 통로가 된다.",
        );
    }

    public function test_모드_확정_응답_필드가_대칭으로_소비된다(): void
    {
        $js = $this->installerJs();

        $this->assertStringContainsString(
            'installation_mode_downgraded',
            $js,
            '단계별 실행 → 실시간 스트리밍 강등을 화면이 읽지 않는다 — 형제 필드'
            .'(installation_mode_forced)와 비대칭이고, 강등된 모드에서는 서버 진행 기록도 재생되지 않아'
            .'사용자가 자신이 고른 방식이 바뀐 사실을 알 방법이 없다.',
        );

        $this->assertStringNotContainsString(
            "addLogMessage(lang('log_installation_mode_forced_stepwise'))",
            $js,
            '강제 전환 안내를 서버와 화면이 각각 기록한다 — 단계별 실행은 새로고침 재개 시'
            .'displayLogs(state.logs) 가 서버 줄을 다시 그리므로 같은 문장이 두 번 보인다.',
        );
    }

    /**
     * 서버가 「확인 불가」(3상태)를 내보내는 항목은 화면도 그 갈래를 그린다 (14차 #1).
     *
     * 앞의 검사는 요구사항 응답의 **최상위 키**만 본다 — `data.disk_space` 가 JS 어딘가에
     * 있기만 하면 초록이다. 그래서 그 한 단계 아래에서 다음이 통과했다: 서버가
     * `disk_free_space` 차단 환경을 `current_mb: null · unknown: true · passed: true` 로
     * 내보내는데 화면이 그 필드를 읽지 않아 `(null/1024).toFixed(2)` 가 **"0.00"** 이 되고,
     * `passed` 가 참이라 **초록 통과 카드**가 됐다 — 이 안이 지원하려는 바로 그 호스팅
     * (cPanel/CloudLinux)에서 「디스크 여유 공간 ✅ 0.00 GB」라는 자기모순이 뜬다.
     *
     * 형제 항목(`opcache`·`php_limits`)은 같은 3상태를 이미 경고로 그리고 있었다 — 규칙이
     * 한 목록 안에서 갈려 있었다. 서버가 새 항목에 `unknown` 을 붙일 때마다 같은 사각이
     * 생기므로 「unknown 을 내보내는 항목 → 화면이 읽는가」를 대조한다.
     */
    public function test_서버가_확인_불가로_표시하는_항목은_화면도_그_갈래를_읽는다(): void
    {
        $server = $this->stripComments(
            (string) file_get_contents($this->installRoot().'/api/check-configuration.php')
        );

        $start = strpos($server, '$requirements = [');
        $this->assertNotFalse($start, '요구사항 목록 정의를 찾지 못했다 — 이 검사의 기준점이 사라졌다');
        $end = strpos($server, '];', $start);
        $this->assertNotFalse($end);

        // key => 그 값을 만드는 메서드 이름
        preg_match_all(
            "/'([a-z0-9_]+)'\s*=>\s*\\\$this->([A-Za-z0-9_]+)\(/",
            substr($server, $start, $end - $start),
            $m,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($m, '요구사항 항목과 그 판정 메서드의 대응을 하나도 읽지 못했다');

        $js = $this->installerJs();
        $missing = [];

        foreach ($m as [, $key, $method]) {
            $body = $this->methodBody($server, $method);
            if ($body === null || ! str_contains($body, "'unknown' => true")) {
                continue;
            }

            if (! $this->jsReadsUnknownFlag($js, $key)) {
                $missing[] = $key.' (판정: '.$method.')';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "서버가 「확인 불가」로 내보내는데 화면이 그 갈래를 읽지 않는다\n"
            .'— 값을 재지 못한 항목이 0 으로 그려지면, 그 검사는 존재하지 않는 것보다 나쁘다.',
        );
    }

    /**
     * 클래스 소스에서 메서드 본문을 중괄호 균형으로 잘라 낸다.
     *
     * @param  string  $source  주석이 제거된 PHP 소스
     * @param  string  $method  메서드 이름
     * @return string|null 본문(중괄호 포함), 찾지 못하면 null
     */
    private function methodBody(string $source, string $method): ?string
    {
        if (! preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $brace = strpos($source, '{', $m[0][1]);
        if ($brace === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return null;
    }

    /**
     * 화면이 그 항목의 `unknown` 을 읽는지 본다 — 직접 참조와 지역 별칭 경유를 모두 인정한다.
     *
     * @param  string  $js  주석이 제거된 installer.js
     * @param  string  $key  요구사항 항목 키
     */
    private function jsReadsUnknownFlag(string $js, string $key): bool
    {
        if (str_contains($js, 'data.'.$key.'.unknown')) {
            return true;
        }

        // `const disk = data.disk_space;` 처럼 지역에 담아 읽는 형태
        if (preg_match_all(
            '/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*data\.'.preg_quote($key, '/').'\s*;/',
            $js,
            $aliases
        )) {
            foreach ($aliases[1] as $alias) {
                if (preg_match('/\b'.preg_quote($alias, '/').'\.unknown\b/', $js)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 요구사항 응답에 실린 항목은 **전부** 화면에 그려진다 (13차 환경#8).
     *
     * 값이 화면에 없으면 그 검사는 존재하지 않는 것과 같다. 실제로 `php_limits`(memory_limit ·
     * max_execution_time)가 응답에만 실리고 렌더 분기가 없었다 — 128M 공유호스팅에서 in-process
     * 부팅 + migrate + seed 가 죽는데, 운영자는 요구사항을 전부 통과하고서 중간에 멈추는 설치를
     * 만난다. 서버가 항목을 하나 더 실을 때마다 같은 사각이 생기므로 목록을 대조한다.
     */
    public function test_요구사항_응답의_모든_항목이_화면에_그려진다(): void
    {
        $server = $this->stripComments(
            (string) file_get_contents($this->installRoot().'/api/check-configuration.php')
        );

        $start = strpos($server, '$requirements = [');
        $this->assertNotFalse($start, '요구사항 목록 정의를 찾지 못했다 — 이 검사의 기준점이 사라졌다');

        $end = strpos($server, '];', $start);
        $this->assertNotFalse($end);

        preg_match_all("/'([a-z0-9_]+)'\s*=>/", substr($server, $start, $end - $start), $m);
        $keys = array_values(array_unique($m[1]));

        $this->assertNotEmpty($keys, '요구사항 항목 이름을 하나도 읽지 못했다');

        $js = $this->installerJs();

        foreach ($keys as $key) {
            $this->assertStringContainsString(
                'data.'.$key,
                $js,
                '서버가 요구사항 응답에 '.$key." 를 싣는데 화면이 그리지 않는다\n"
                .'— 값이 화면에 없으면 그 검사는 존재하지 않는 것과 같다.',
            );
        }
    }
}
