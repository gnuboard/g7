<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 클라이언트가 보내는 헤더 집합 ↔ 엔드포인트가 요구하는 집합의 정합.
 *
 * ## 왜 이 축인가
 *
 * 종전 검사는 「가드가 배선됐는가」만 잠갔다 — 서버가 stepwise 토큰을 요구하는지는 순수 함수
 * 단위 테스트(`installerFinalizeRejection`)가 확인했고, 그 함수는 초록이었다. 그런데
 * **화면이 그 토큰을 실제로 실어 보내는지**는 아무도 보지 않았고, `finalize-env.php` 호출만
 * 토큰 없이 나가고 있었다.
 *
 * 결과는 무CLI(inprocess) 환경에서만 드러난다 — inprocess 는 stepwise 로 강제되므로
 * `installerRequiresStepwiseToken()` 이 참이 되고, finalize 가 403 으로 죽는다. 그러면
 * `.env` 에 DB 자격증명·`APP_KEY`·`INSTALLER_COMPLETED` 가 기록되지 않고, 평문 DB 비밀번호를
 * 담은 `runtime.php` 가 남고, 인스톨러가 계속 열려 무인증 재설치가 가능해진다. 화면은
 * fire-and-forget 이라 성공 화면을 그대로 띄운다 — **오류가 어디에도 남지 않는다.**
 *
 * 그래서 단위 테스트가 아니라 **양쪽 소스의 대조**로 잠근다: 서버가 토큰을 읽는 엔드포인트를
 * 전수로 뽑고, 그 엔드포인트를 향하는 클라이언트의 **변경 요청**이 전부 토큰을 싣는지 본다.
 * 서버에 토큰 게이트가 하나 더 생기면 이 검사가 red 가 되고, 그때 클라이언트도 함께 따라온다.
 *
 * 커버 축: endpoint finalize·run_task·state_mutation / direction server_requires·client_presents
 */
class InstallerStepwiseTokenClientParityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /**
     * 토큰을 싣지 않아도 되는 호출과 그 근거.
     *
     * 목록이 아니라 **판단**이므로 여기에 적는다. 새 예외가 필요해지면 이 검사가 red 가 되고,
     * 그때 「의도한 무토큰」과 「빠뜨렸다」를 사람이 한 번 가른다.
     *
     * @var array<string, string>
     */
    private const CLIENT_TOKEN_EXCLUSIONS = [
        // 이 엔드포인트가 토큰을 **발급**한다. 서버 게이트(installerStartRejection)는
        // 「진행 중인 stepwise 설치」에만 토큰을 요구하는데, 화면에는 그 상태에서 이 요청을
        // 보내는 경로가 없다 — 새로고침 재개는 resumeStepwiseInstallation() 이 담당한다.
        // 즉 그 게이트가 막는 것은 제3자의 무인증 재시작이다.
        'install-process.php' => '토큰 발급 지점 — 진행 중 stepwise 를 재시작하는 화면 경로가 없다',
    ];

    /**
     * 저장소 안 설치기 공개 폴더의 절대 경로를 돌려준다.
     *
     * @return string 설치기 폴더 경로
     */
    private function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 서버가 stepwise 토큰을 읽는 엔드포인트 파일명 전수.
     *
     * 재귀 스캔한다 — 한 겹 `glob()` 은 하위 디렉토리가 생기는 순간 그 안을 통째로 놓친다.
     *
     * @return array<int, string> 토큰을 읽는 엔드포인트 파일명 목록 (정렬됨)
     */
    private function tokenRequiringEndpoints(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->installRoot().'/api', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // 주석을 지운 뒤 본다 — 주석 처리된 배선도 원문에는 글자로 남는다.
            $code = $this->stripComments((string) file_get_contents($file->getPathname()));

            if (str_contains($code, 'HTTP_X_INSTALLER_TOKEN')) {
                $found[] = $file->getBasename();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * `(` 에서 시작해 짝이 맞는 `)` 의 위치. 문자열·템플릿 리터럴 안은 세지 않는다.
     *
     * @param  string  $js  JS 소스 (주석 제거 후)
     * @param  int  $open  여는 괄호 위치
     * @return int|null 닫는 괄호 위치 (짝이 없으면 null)
     */
    private function matchingParen(string $js, int $open): ?int
    {
        $depth = 0;
        $length = strlen($js);
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
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
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 식별자가 가리키는 엔드포인트 파일명 — 이 호출 지점 **직전**의 선언을 본다.
     *
     * 전역 맵을 만들지 않는 이유는 같은 이름(`apiUrl`)이 파일 안에서 여러 엔드포인트로
     * 재선언되기 때문이다. 가장 가까운 선언을 보면 그 모호성이 사라진다.
     *
     * @param  string  $js  JS 소스 (주석 제거 후)
     * @param  string  $name  식별자
     * @param  int  $before  이 위치보다 앞선 선언만 본다
     * @param  int  $depth  간접 참조 추적 잔여 횟수
     * @return string|null 엔드포인트 파일명 (찾지 못하면 null)
     */
    private function resolveEndpoint(string $js, string $name, int $before, int $depth = 2): ?string
    {
        $pattern = '/(?:const|let|var)\s+'.preg_quote($name, '/').'\s*=|function\s+'.preg_quote($name, '/').'\s*\(/';

        if (preg_match_all($pattern, $js, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $declaredAt = null;
        foreach ($matches[0] as $match) {
            if ($match[1] < $before) {
                $declaredAt = $match[1];
            }
        }

        if ($declaredAt === null) {
            return null;
        }

        // 선언 뒤 한 문장 정도만 본다 — 더 멀리 보면 다음 선언의 URL 을 빨아들인다.
        $body = substr($js, $declaredAt, 400);

        if (preg_match('#api/([a-z0-9-]+\.php)#', $body, $hit) === 1) {
            return $hit[1];
        }

        if ($depth <= 0) {
            return null;
        }

        // 간접 참조 — `const abortUrl = getStateApiUrl('abort')` 처럼 한 단계 건너뛴 형태.
        preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*/', $body, $identifiers);
        foreach ($identifiers[0] as $identifier) {
            if ($identifier === $name) {
                continue;
            }
            $resolved = $this->resolveEndpoint($js, $identifier, $declaredAt, $depth - 1);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * 화면이 보내는 **변경 요청**(method 가 붙은 fetch) 전수.
     *
     * @return array<int, array{endpoint: string, line: int, args: string}> 엔드포인트, 줄 번호, 인자 원문을 담은 호출 목록
     */
    private function clientMutatingCalls(): array
    {
        $js = $this->stripJsComments((string) file_get_contents($this->installRoot().'/assets/js/installer.js'));

        $calls = [];
        $offset = 0;

        while (($pos = strpos($js, 'fetch(', $offset)) !== false) {
            $offset = $pos + 6;

            // `prefetch(` 같은 접미 일치를 배제한다.
            if ($pos > 0 && preg_match('/[A-Za-z0-9_$.]/', $js[$pos - 1]) === 1) {
                continue;
            }

            $open = $pos + 5;
            $close = $this->matchingParen($js, $open);
            if ($close === null) {
                continue;
            }

            $args = substr($js, $open + 1, $close - $open - 1);
            $offset = $close;

            // 변경 요청만 본다 — 조회(GET)는 토큰을 요구하지 않는다.
            if (preg_match("/method\s*:\s*'(?!GET')/i", $args) !== 1) {
                continue;
            }

            $endpoint = null;
            if (preg_match('#api/([a-z0-9-]+\.php)#', $args, $hit) === 1) {
                $endpoint = $hit[1];
            } else {
                // 첫 인자에 등장하는 식별자로 되짚는다.
                $firstArg = explode(',', $args, 2)[0];
                preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*/', $firstArg, $identifiers);
                foreach ($identifiers[0] as $identifier) {
                    $endpoint = $this->resolveEndpoint($js, $identifier, $pos);
                    if ($endpoint !== null) {
                        break;
                    }
                }
            }

            if ($endpoint === null) {
                continue;
            }

            $calls[] = [
                'endpoint' => $endpoint,
                'line' => substr_count(substr($js, 0, $pos), "\n") + 1,
                'args' => $args,
            ];
        }

        return $calls;
    }

    /**
     * 이 호출이 토큰을 싣는가 — 헤더 직접 기입·공용 헤더 헬퍼·본문 폴백 중 하나면 참.
     *
     * @param  string  $args  호출 인자 원문
     * @return bool 토큰을 싣고 있으면 참
     */
    private function carriesToken(string $args): bool
    {
        return str_contains($args, 'X-Installer-Token')
            || str_contains($args, 'stateMutationHeaders(')
            || preg_match('/\btoken\b\s*[:,}]/', $args) === 1;
    }

    /**
     * 서버 쪽 토큰 게이트 스캔과 화면 쪽 변경 요청 스캔이 마무리와 태스크 실행 엔드포인트를 실제로 찾아내는지 확인한다.
     *
     * 어느 한쪽이라도 비면 아래 대조가 통째로 사각이 된다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_state_mutation_requests_carry_the_stepwise_token
     *
     * @return void 반환값 없음
     */
    public function test_스캐너가_토큰_요구_엔드포인트와_그_호출부를_실제로_찾는다(): void
    {
        $endpoints = $this->tokenRequiringEndpoints();

        // 이 검사가 통째로 사각이 되는 것을 막는다 — 한쪽이라도 0 이면 아래 대조가 무의미하다.
        $this->assertContains('finalize-env.php', $endpoints, '서버 토큰 게이트 스캔이 finalize 를 놓쳤다');
        $this->assertContains('run-task.php', $endpoints, '서버 토큰 게이트 스캔이 run-task 를 놓쳤다');

        $called = array_values(array_unique(array_column($this->clientMutatingCalls(), 'endpoint')));

        $this->assertContains(
            'finalize-env.php',
            $called,
            'finalize 호출을 변경 요청으로 인식하지 못했다 — 인식하지 못하면 토큰 누락도 영영 초록이다',
        );
        $this->assertContains('run-task.php', $called, 'run-task 호출을 변경 요청으로 인식하지 못했다');
    }

    /**
     * 서버가 토큰을 요구하는 엔드포인트로 가는 화면의 변경 요청이 면제분을 빼고 전부 토큰을 싣는지 대조한다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_state_mutation_requests_carry_the_stepwise_token
     *
     * @return void 반환값 없음
     */
    public function test_토큰을_요구하는_엔드포인트로_가는_변경_요청은_전부_토큰을_싣는다(): void
    {
        $endpoints = $this->tokenRequiringEndpoints();
        $missing = [];

        foreach ($this->clientMutatingCalls() as $call) {
            if (! in_array($call['endpoint'], $endpoints, true)) {
                continue;
            }
            if (array_key_exists($call['endpoint'], self::CLIENT_TOKEN_EXCLUSIONS)) {
                continue;
            }
            if ($this->carriesToken($call['args'])) {
                continue;
            }

            $missing[] = $call['endpoint'].' (installer.js:'.$call['line'].')';
        }

        $this->assertSame(
            [],
            $missing,
            "서버가 stepwise 토큰을 요구하는데 화면이 싣지 않는 요청: \n - ".implode("\n - ", $missing)
            ."\n무CLI(inprocess) 환경은 stepwise 로 강제되므로 이 요청들은 그 환경에서만 403 이 된다."
            ."\nfire-and-forget 호출이면 화면은 성공을 그대로 띄우고 오류가 어디에도 남지 않는다.",
        );
    }

    /**
     * 토큰 면제 목록에 서버가 더는 토큰을 요구하지 않는 엔드포인트가 남아 있지 않은지 확인한다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_state_mutation_requests_carry_the_stepwise_token
     *
     * @return void 반환값 없음
     */
    public function test_면제_목록에_토큰을_요구하지_않는_엔드포인트가_남아_있지_않다(): void
    {
        $stale = array_values(array_diff(array_keys(self::CLIENT_TOKEN_EXCLUSIONS), $this->tokenRequiringEndpoints()));

        $this->assertSame(
            [],
            $stale,
            '서버가 더는 토큰을 요구하지 않는데 면제 목록에 남은 엔드포인트: '.implode(', ', $stale),
        );
    }
}
