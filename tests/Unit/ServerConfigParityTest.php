<?php

namespace Tests\Unit;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * 서버별 보호 설정의 목록 일치 계약 (배포 레이아웃 ② — 문서 루트 = 프로젝트 루트).
 *
 * 레이아웃 ② 의 보호는 오래도록 루트 `.htaccess` **하나**에만 있었다. 그 파일을 읽는
 * 서버는 Apache 와 LiteSpeed 뿐이라, nginx·IIS·Caddy 로 같은 압축을 푼 사이트는 아무
 * 경고 없이 프로젝트 루트가 통째로 열린 채 동작했다. 그래서 같은 보호를 서버별 문법으로
 * 함께 배포한다 — IIS 는 `web.config`(동봉만으로 성립), nginx·Caddy 는 서버 블록에 넣는
 * 설정 조각이다.
 *
 * 이 테스트가 잠그는 것은 **목록의 일치**다. 네 파일이 각각 자기 문법으로 목록을 적고
 * 있으므로, 한쪽에 항목이 늘 때 나머지가 따라오지 않으면 그 서버에서만 조용히 열린다.
 * 기대값을 손으로 적지 않고 루트 `.htaccess` 에서 **도출**하는 이유가 그것이다 —
 * 형제 축(`RootEntrypointContractTest`)이 그 목록 자신을 배포 트리에서 도출해 잠그므로,
 * 두 축을 이으면 「배포 트리 → `.htaccess` → 나머지 세 파일」이 한 사슬이 된다.
 *
 * 응답 동작은 이 테스트의 대상이 아니다(PHPUnit 밖). nginx 축은 실제 서버로 응답을
 * 측정했고, IIS·Caddy 축은 실측 환경이 없어 이 목록 대조와 정적 검토까지만 확인됐다 —
 * 그 사실은 각 파일 머리말에 적혀 있다.
 *
 * 커버 축 요약(마커 아님 — 평문): derivation=release_payload 는 deploy_fragments·root_web_config 동봉 축을 함께 잰다.
 *
 * @scenario server_parity=iis, derivation=sensitive_dirs, derivation=release_payload
 */
class ServerConfigParityTest extends TestCase
{
    /** IIS 설정 파일 (프로젝트 루트, `.htaccess` 와 형제). */
    private const IIS_CONFIG = 'web.config';

    /** nginx 설정 조각. */
    private const NGINX_FRAGMENT = 'deploy/nginx/g7-layout2.conf';

    /** Caddy 설정 조각. */
    private const CADDY_FRAGMENT = 'deploy/caddy/g7-layout2.caddyfile';

    /**
     * 루트 `.htaccess` 의 `<IfModule !mod_rewrite.c>` 방어 블록을 잘라 돌려줍니다.
     *
     * 그 블록이 민감 디렉토리·확장자·확장자 없는 파일 목록의 SSoT 입니다.
     *
     * @return string 방어 블록 본문
     */
    private function htaccessFallbackBlock(): string
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        $start = strpos($htaccess, '<IfModule !mod_rewrite.c>');
        $this->assertNotFalse($start, 'mod_rewrite 부재 방어 블록이 없다 — 도출 근거가 사라졌다');

        $end = strpos($htaccess, '# END G7', $start);
        $this->assertNotFalse($end, '방어 블록이 G7 관리 블록 밖에 있다');

        return substr($htaccess, $start, $end - $start);
    }

    /**
     * 루트 `.htaccess` 가 막는 민감 디렉토리 이름을 도출합니다.
     *
     * @return list<string> 디렉토리 이름 목록
     */
    private function sensitiveDirectories(): array
    {
        $block = $this->htaccessFallbackBlock();

        preg_match('/RedirectMatch\s+403\s+\(\?i\)\/\(([^)]+)\)\//', $block, $m);
        $this->assertNotEmpty($m, '민감 디렉토리 차단 규칙을 찾지 못했다 — 규칙 본문이 사라졌는가');

        $names = array_values(array_filter(array_map('trim', explode('|', $m[1]))));

        // 하한도 **도출**한다. 종전에는 `10` 이 손으로 적혀 있었는데 실제 목록은 21 이라,
        // 목록이 절반으로 잘려도 이 검사와 그것을 소비하는 대조 전부가 조용히 초록이었다.
        //
        // 원천은 형제 축(`RootEntrypointContractTest`)이 이 목록을 잠글 때 쓰는 배포 대상
        // SSoT(`update.targets`)다. 거기서 도출한 최상위 디렉토리는 정의상 이 목록에 전부
        // 들어 있어야 하므로 하한으로 성립한다. 실제 트리(`scandir`)에서 뽑지 않는 이유는
        // 이 클래스가 배포된 설치본에서도 돌기 때문이다 — 그 루트에는 운영자가 둔 디렉토리가
        // 있어 트리 도출은 목록보다 **커질** 수 있고, 그러면 코어의 결함이 아닌데 red 가 된다.
        // 배포 대상 도출은 반대로 줄어드는 방향이라 거짓 red 를 내지 않는다.
        //
        // 이 하한이 덮지 못하는 나머지(점으로 시작하는 `.git` 과 개발 산출물 둘)는 형제 축의
        // 트리 도출이 개발 체크아웃에서 잠근다.
        $floor = $this->deployTargetDirectories();

        $this->assertGreaterThan(0, $floor, '하한 도출이 비었다 — 배포 대상 목록을 읽지 못했다');
        $this->assertGreaterThanOrEqual(
            $floor,
            count($names),
            '민감 디렉토리 목록이 배포 대상 디렉토리 수보다 적다 — 목록이 줄었다'
        );

        return $names;
    }

    /**
     * 루트 `.htaccess` 가 막는 확장자 패턴을 도출합니다.
     *
     * `ya?ml` 처럼 정규식으로 두 형태를 겸하는 항목이 있으므로 **패턴 그대로** 돌려줍니다.
     *
     * @return list<string> 확장자 패턴 목록
     */
    private function blockedExtensions(): array
    {
        $block = $this->htaccessFallbackBlock();

        preg_match('/<FilesMatch "\(\?i\)\\\\\.\(([^)]+)\)\$">/', $block, $m);
        $this->assertNotEmpty($m, '확장자 차단 규칙을 찾지 못했다 — 규칙 본문이 사라졌는가');

        $patterns = array_values(array_filter(array_map('trim', explode('|', $m[1]))));

        // 이 축의 하한은 **실측값**이다(종전 `10` → 29). 도출로 바꿀 수 없다: 확장자 목록은
        // 배포 페이로드의 실제 최상위 파일에서 출발했지만 그 파일들이 정당화하는 확장자는
        // 여덟(`md`·`json`·`lock`·`xml`·`ts`·`zip`·`js`·`config`)뿐이고, 나머지 스물한 개는
        // 「배포본 위에 생길 수 있는 것」을 미리 막는 하드닝 항목이라 원천이 없다. 여덟을
        // 하한으로 삼으면 목록이 3분의 1로 잘려도 초록이라 이 결함을 고치지 못한다.
        // 목록이 정당하게 늘어나는 것은 허용한다(`OrEqual`).
        $this->assertGreaterThanOrEqual(
            29,
            count($patterns),
            '확장자 차단 패턴이 29개 미만이다 — 목록이 줄었다'
        );

        return $patterns;
    }

    /**
     * 루트 `.htaccess` 가 막는 **확장자 없는** 최상위 파일 이름을 도출합니다.
     *
     * @return list<string> 파일 이름 목록
     */
    private function blockedBareNames(): array
    {
        $block = $this->htaccessFallbackBlock();

        preg_match('/<FilesMatch "\^\(([^)]+)\)\$">/', $block, $m);
        $this->assertNotEmpty($m, '확장자 없는 파일 차단 규칙을 찾지 못했다');

        $names = array_values(array_filter(array_map('trim', explode('|', $m[1]))));

        // 이 축도 **실측값**을 하한으로 둔다(종전 `assertNotEmpty` — 하나만 남아도 초록).
        // 도출 근거는 「배포 페이로드의 확장자 없는 최상위 파일」인데, 그것을 실제 트리에서
        // 뽑으면 배포된 설치본에서 운영자가 둔 파일 때문에 목록보다 커져 거짓 red 가 되고,
        // 배포 대상 목록(`update.targets`)에서 뽑으면 `LICENSE` 가 그 목록에 없어 하나가
        // 빠진다. 둘 다 이 두 항목을 정확히 재지 못하므로 실측값으로 고정한다.
        $this->assertGreaterThanOrEqual(
            2,
            count($names),
            '확장자 없는 파일 차단 목록이 2개 미만이다 — 목록이 줄었다'
        );

        return $names;
    }

    /**
     * 배포 대상 SSoT(`update.targets`)가 지목하는 최상위 디렉토리 개수를 도출합니다.
     *
     * 목록 하한의 원천입니다 — 형제 축(`RootEntrypointContractTest`)이 루트 `.htaccess` 의
     * 민감 디렉토리 목록을 잠글 때 쓰는 것과 같은 도출입니다. `storage`·`vendor`·
     * `node_modules` 는 배포 대상이 아니지만 배포본에 실재하고 노출되면 가장 위험해 그쪽에서
     * 손으로 더하므로 여기서도 함께 센다. `public` 은 서빙 대상이라 제외합니다.
     *
     * @return int 디렉토리 개수
     */
    private function deployTargetDirectories(): int
    {
        return collect((array) config('app.update.targets'))
            ->map(static fn ($target) => explode('/', trim((string) $target, '/'))[0])
            ->filter(static fn (string $top) => $top !== '' && is_dir(base_path($top)))
            ->merge(['storage', 'vendor', 'node_modules'])
            ->reject(static fn (string $top) => $top === 'public')
            ->unique()
            ->count();
    }

    /**
     * 중괄호 블록을 **균형 계산으로** 잘라, 주어진 문자열을 담은 것만 돌려줍니다.
     *
     * 종전 판정은 `location …\{[^}]*\}` 형태의 정규식이었다. 그 패턴은 블록 안에 중첩
     * 중괄호가 하나만 생겨도 **첫 `}` 에서 끊긴다** — nginx 에서 정당한
     * `if ($request_method = POST) { return 405; }` 를 넣으면 그 뒤의 위임 줄이 매치
     * 밖으로 나가 거짓 red 가 됐다. (그 「정당한 구성」 판단은 형제 판정
     * `assertNginxDelegationIsReached()` 와 같아야 한다 — 종전에는 그쪽이 같은 구성을
     * 순서 위반으로 읽어 두 판정이 서로 모순이었다.) Caddy 축은 반대로 matcher 와 handle 사이의 거리를
     * `[\s\S]{0,600}?` 로 고정해, 그 사이에 지시자 몇 줄만 늘어도 거짓 red 였다.
     *
     * Caddy 는 `@name` 선언과 `handle @name` 블록이 떨어져 있으므로, 이름을 매개로
     * **선언 줄 + 블록 본문**을 한 덩어리로 이어 돌려준다 — 거리에 무관해진다.
     *
     * 판정 둘을 고쳤다:
     *  · 후보를 찾으면 그 블록 끝까지 건너뛰고 있었다. 그래서 바깥 블록 하나가 매치하면
     *    **그 안의 규칙 전부가 한 번도 읽히지 않았다** — Caddy 는 사이트 블록
     *    (`example.com { … }`)이 언제나 먼저 매치하므로 안쪽 handle 이 통째로 사각이었다.
     *    이제 모든 중괄호를 훑어 바깥·안쪽 후보를 **함께** 모은다.
     *  · 이름 대조가 단순 부분문자열이라 `log` 가 `access_log` 에, `ts` 가 `test-results`
     *    에 걸렸다. 목록 원소는 `|`·`(`·`/` 로 갈린 **토큰**이므로 그 경계로 본다.
     *
     * @param  string  $body  주석을 걷어낸 설정 원문
     * @param  string  $needle  그 축을 특정하는 문자열 (도출 목록의 원소 등)
     * @param  bool  $caddy  Caddy 문법인가 (matcher 이름 짝짓기)
     * @return list<string> 후보 블록 본문 목록
     */
    private function blocksContaining(string $body, string $needle, bool $caddy): array
    {
        $blocks = [];
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] !== '{') {
                continue;
            }

            // 헤더는 직전 줄바꿈부터 `{` 까지다.
            $headStart = strrpos(substr($body, 0, $i), "\n");
            $headStart = $headStart === false ? 0 : $headStart + 1;

            $depth = 0;
            $end = null;

            for ($j = $i; $j < $length; $j++) {
                if ($body[$j] === '{') {
                    $depth++;

                    continue;
                }

                if ($body[$j] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            if ($end === null) {
                continue;
            }

            $block = substr($body, $headStart, $end - $headStart + 1);
            $head = substr($body, $headStart, $i - $headStart);

            if ($this->containsToken($block, $needle)) {
                $blocks[] = $block;

                continue;
            }

            // Caddy: `handle @name { … }` 의 헤더에는 목록이 없다. 같은 이름의
            // `@name path_regexp …` 선언 줄을 찾아 이어 붙인다 (거리 무관).
            if ($caddy && preg_match('/handle\s+(@[A-Za-z0-9_]+)/', $head, $m) === 1) {
                $declaration = '';

                foreach (preg_split('~\r\n|\r|\n~', $body) ?: [] as $line) {
                    if (preg_match('/^\s*'.preg_quote($m[1], '/').'\s+\S/', $line) === 1) {
                        $declaration = $line;
                        break;
                    }
                }

                if ($declaration !== '' && $this->containsToken($declaration, $needle)) {
                    $blocks[] = $declaration."\n".$block;
                }
            }
        }

        return $blocks;
    }

    /**
     * 대안 목록의 **토큰 경계**로 이름을 찾습니다.
     *
     * 부분문자열 대조는 목록 원소가 다른 낱말 안에 묻혀 있어도 일치로 읽는다 —
     * `log` 가 nginx 의 `access_log`·`log_not_found` 에, `ts` 가 `test-results` 에
     * 걸린다. 그러면 그 축의 규칙이 아닌 블록이 후보로 잡혀 거짓 red 가 난다.
     * 설정 파일의 목록은 `(`·`|`·`)`·`/`·공백으로 갈리므로 그 경계로 판정한다.
     *
     * @param  string  $haystack  찾을 대상
     * @param  string  $needle  목록 원소 (정규식 문자가 들어올 수 있다)
     * @return bool 토큰으로 존재하는가
     */
    private function containsToken(string $haystack, string $needle): bool
    {
        if (trim($needle) === '') {
            return false;
        }

        return preg_match(
            '/(?<![A-Za-z0-9_-])'.preg_quote($needle, '/').'(?![A-Za-z0-9_-])/i',
            $haystack
        ) === 1;
    }

    /**
     * IIS `web.config` 원문에서 **XML 주석을 걷어내고** 돌려줍니다.
     *
     * 정방향 대조는 `iisRuleSurface()`(XML 파싱)를 쓰므로 주석이 애초에 들어오지 않는다.
     * 그런데 **역방향(발산) 축**과 몇몇 순서·존재 판정은 원문을 그대로 훑고 있었고,
     * `directiveBody()` 는 `#` 로 시작하는 줄만 지운다 — web.config 의 주석은
     * `<!-- -->` 라 하나도 지워지지 않았다. 그래서 같은 파일의 같은 목록을 한 축은
     * 주석까지 보고, 다른 축은 보지 않는 상태였다.
     *
     * 그 비대칭은 양방향으로 틀린다 — 주석에만 적힌 설명 항목이 「이 서버만 막는 항목」
     * 으로 읽혀 거짓 red 가 나고, 반대로 주석 처리된 진짜 규칙이 살아 있는 것으로 읽힌다.
     *
     * @return string 주석을 제거한 web.config 원문
     */
    private function iisConfigWithoutComments(): string
    {
        $path = base_path(self::IIS_CONFIG);
        $this->assertFileExists($path, 'IIS 설정 파일이 없다 — 그 서버에서 프로젝트 루트가 통째로 열린다');

        $raw = (string) file_get_contents($path);
        $stripped = (string) preg_replace('/<!--.*?-->/s', '', $raw);

        // 주석 제거가 파일을 통째로 비우면 그 뒤 판정이 전부 「없다」가 되어 오해를 부른다.
        $this->assertNotSame(
            '',
            trim($stripped),
            'web.config 에 주석 밖 내용이 하나도 없다 — 규칙 본문이 통째로 주석 처리됐다'
        );

        return $stripped;
    }

    /**
     * IIS `web.config` 에서 목록이 실릴 수 있는 자리를 모아 한 덩어리로 돌려줍니다.
     *
     * XML 이므로 **파싱해서** 읽습니다 — 파일 전체를 문자열로 훑으면 주석에 적힌 설명만
     * 있어도 통과해, 규칙 본문이 사라진 상태를 잡지 못합니다.
     *
     * @return string 규칙 패턴 + 요청 필터링 항목을 이어 붙인 문자열
     */
    private function iisRuleSurface(): string
    {
        $path = base_path(self::IIS_CONFIG);
        $this->assertFileExists($path, 'IIS 설정 파일이 없다 — 그 서버에서 프로젝트 루트가 통째로 열린다');

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, 'web.config 가 XML 로 파싱되지 않는다 — IIS 는 그 상태에서 사이트 전체를 500 으로 만든다');

        $parts = [];

        foreach ($xml->xpath('//rules/rule') as $rule) {
            // 패턴만 걷으면 **그 규칙이 무엇을 하는지**를 한 번도 보지 않는다 —
            // `<action type="CustomResponse" statusCode="403">` 을 `<action type="None"/>`
            // 으로 바꿔도 목록 대조는 전부 초록이고, 그 서버에서만 프로젝트 루트가 열린다.
            // 패턴과 효과를 한 줄로 묶어 두면 목록 축과 효과 축이 함께 잠긴다.
            $action = (string) ($rule->action['type'] ?? '');
            $status = (string) ($rule->action['statusCode'] ?? '');
            // 리다이렉트의 **영구/임시**도 효과의 일부다. 정본 주소 고정이 302 로 바뀌면
            // 검색 색인이 두 주소를 계속 들고 있어 그 규칙의 목적이 성립하지 않는다.
            $redirectType = (string) ($rule->action['redirectType'] ?? '');
            $parts[] = trim(((string) ($rule->match['url'] ?? '')).' => '.$action.' '.$status.' '.$redirectType);
        }

        // 요청 필터링(IIS 코어)도 목록의 일부다 — 재작성 모듈 없이도 읽히는 자리다.
        foreach (['//hiddenSegments/add/@segment', '//fileExtensions/add/@fileExtension'] as $xpath) {
            foreach ($xml->xpath($xpath) as $attribute) {
                $parts[] = (string) $attribute;
            }
        }

        $surface = implode("\n", array_filter($parts));

        $this->assertNotSame('', trim($surface), 'web.config 에서 규칙 패턴을 도출하지 못했다');

        return $surface;
    }

    /**
     * nginx·Caddy 조각에서 **주석을 걷어낸** 본문을 돌려줍니다.
     *
     * 주석까지 포함해 훑으면 대응표 설명만 남고 규칙이 사라져도 통과합니다.
     *
     * @param  string  $relative  프로젝트 루트 기준 상대 경로
     * @return string 주석 제거 본문
     */
    private function directiveBody(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, $relative.' 가 없다 — 그 서버용 설정 조각이 배포되지 않는다');

        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];

        $body = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $body[] = $trimmed;
        }

        $joined = implode("\n", $body);

        $this->assertNotSame('', trim($joined), $relative.' 에 주석 밖 규칙이 하나도 없다');

        return $joined;
    }

    /**
     * 서버별 설정 파일의 「규칙 본문」을 이름과 함께 돌려줍니다.
     *
     * @return array<string, string> 파일 경로 => 규칙 본문
     */
    private function serverSurfaces(): array
    {
        return [
            self::IIS_CONFIG => $this->iisRuleSurface(),
            self::NGINX_FRAGMENT => $this->directiveBody(self::NGINX_FRAGMENT),
            self::CADDY_FRAGMENT => $this->directiveBody(self::CADDY_FRAGMENT),
        ];
    }

    /**
     * 정본 주소 고정(제2 주소 차단)이 **세 서버 전부**에 있다.
     *
     * Apache 축은 전용 검사(`RootEntrypointContractTest::test_canonical_address_rule_lives_where_it_is_evaluated`)
     * 가 규칙의 존재부터 목적지 출처·`NE`·캡처 개수·빈 세그먼트 가드까지 잠근다. 그런데
     * 형제 셋에도 같은 규칙이 실재하는데 **어느 게이트도 그것을 보지 않았다** — 이
     * 파일은 스스로 「잠그는 것은 목록의 일치」라고 선언하고 목록·숨김파일·ACME·
     * 디렉토리목록·차단효과·릴리즈페이로드만 쟀다. 그래서 nginx 조각에서 그 세 줄을
     * 지워도 전 검사가 초록이었고, 그 상태의 증상은 오류가 아니라 **그 서버에서만 사이트가
     * 두 주소로 열리는 것**이다 — P0 Apache 실측이 잡아낸 바로 그 형태다.
     *
     * 세 대응표가 모두 「`public/.htaccess` (B) `/public/…` → 301」 행을 갖는다. 이 검사는
     * 그 행이 실제 규칙을 가리키는지를 잰다.
     *
     * @scenario server_parity=canonical_address, servers=nginx+caddy+iis
     *
     * @effects every_server_config_fixes_the_canonical_address
     *
     * @return void 반환값 없음
     */
    public function test_every_server_config_carries_the_canonical_address_rule(): void
    {
        // 「301 이 파일 어딘가에 있다」로는 잴 수 없다 — 설치 마법사 슬래시 보정 같은
        // **다른 목적의 301** 이 그 조건을 충족해, 정본 주소 규칙을 지워도 초록이 된다
        // (신설 시 실측: 첫 판정식이 정확히 그 이유로 변이를 통과시켰다). 그래서 서버마다
        // **그 규칙 자신**을 잘라 내고, 잘라 낸 조각 안에서 영구 리다이렉트를 요구한다.
        //
        // 후보는 **전부** 판정한다. 종전에는 `preg_match` 의 첫 매치 하나만 봤다 — 그래서
        // 축의 모양을 흉내 낸 무해한 한 줄(`location ~ ^/public-legacy/ { return 301 /; }`)을
        // 진짜 규칙 **위**에 두면, 효과를 잃은 진짜 규칙은 한 번도 읽히지 않고 초록이었다.
        // 형제 축(효과 검사)이 앞서 이미 1:N 으로 고친 것과 같은 결함이다.
        //
        // nginx locator 도 정규식에서 **블록 도출**로 바꾼다. `…\n\}` 는 닫는 중괄호가 열 0
        // 에 있다는 서식 가정에 묶여 있어, 들여쓰기가 바뀌면 조기 종료하거나 다음 규칙까지
        // 삼킨다 — 형제 판정(`blocksContaining`)은 중괄호 균형으로 자르므로 서식과 무관하다.
        $rules = [
            self::NGINX_FRAGMENT => ['block', 'location ~ ^/public/', '~return\s+301\b~i'],
            // Caddy — `redir @<matcher> <목적지> 301` 한 줄.
            self::CADDY_FRAGMENT => ['regex', '~redir\s+@\w*public\w*\s+\S+\s+\d{3}~i', '~\b301\b~'],
            // IIS — 규칙 표면의 `^public/… => Redirect  Permanent` 한 줄.
            //
            // 후보를 **리다이렉트 규칙으로** 좁힌다. 같은 패턴(`^public/(.*)$`)을 갖는
            // 프런트 컨트롤러 규칙(`=> Rewrite`)이 형제로 실재해, 좁히지 않으면 그 줄이
            // 「효과를 잃은 정본 주소 규칙」으로 읽혀 거짓 red 가 난다. 좁혀도 축은
            // 약해지지 않는다 — 정본 주소 규칙이 사라지거나 Rewrite 로 바뀌면 후보가
            // 비어 그 자리에서 red 다.
            self::IIS_CONFIG => ['regex', '~^\^public/[^\n]*=>\s*Redirect\b[^\n]*~mi', '~Redirect\s+Permanent~i'],
        ];

        $measured = 0;

        foreach ($this->serverSurfaces() as $file => $body) {
            [$kind, $locator, $effect] = $rules[$file];

            if ($kind === 'block') {
                // nginx 는 블록 구조(중괄호)가 필요해 `directiveBody()` 의 평탄한 본문으로는
                // 잴 수 없다. 원문을 쓰되 **주석 줄은 지운다** — 이 파일 머리말의 대응표가
                // `location ~ ^/public/(.*)$` 를 문자열로 싣고 있어, 지우지 않으면 그 표가
                // 규칙 대신 매치되어 검사가 엉뚱한 조각을 판정한다(신설 시 실측).
                $haystack = (string) preg_replace('~^[ \t]*#.*$~m', '', (string) file_get_contents(base_path($file)));
                $candidates = $this->blocksContaining($haystack, $locator, false);
            } else {
                preg_match_all($locator, $body, $matches);
                $candidates = $matches[0];
            }

            $this->assertNotSame(
                [],
                $candidates,
                $file.' 에 `public` 경로를 대상으로 하는 정본 주소 규칙이 없다 — '
                .'그 서버에서만 사이트가 `/…` 와 `/public/…` 두 주소로 열린다'
            );

            foreach ($candidates as $candidate) {
                $this->assertMatchesRegularExpression(
                    $effect,
                    $candidate,
                    $file.' 의 정본 주소 규칙이 영구 리다이렉트를 내지 않는다 — '
                    .'제2 주소가 검색 색인에 그대로 남는다'
                );
            }

            $measured++;
        }

        // 모집단 대조 — 세 파일을 실제로 훑었는지 확인한다. 비면 위 반복이 0회 돌고도
        // 초록이 된다.
        $this->assertSame(3, $measured, '서버 설정 모집단이 셋이 아니다');
    }

    /**
     * 정본 주소 규칙의 **빈 세그먼트** 축이 네 서버 전부에서 판정돼 있다.
     *
     * 목적지를 경로 캡처에서 만드는 규칙은 그 캡처가 `/` 로 시작할 수 있으면 `Location`
     * 이 `//호스트/…` 가 되고, 브라우저는 그것을 **프로토콜 상대 URL** 로 읽는다 — 즉
     * 목적지 호스트를 요청자가 지정한다. Apache 는 그 실패를 실측으로 확인해
     * `RewriteCond %{REQUEST_URI} !//` 로 막고 있었는데, **그 규율이 형제 서버에 전파되지
     * 않은 채였다**.
     *
     * 이 검사가 요구하는 것은 「가드가 있다」가 아니라 **「그 축이 판정돼 있다」**다.
     * 서버마다 성립 여부가 다르기 때문이다:
     *   · Apache·nginx — 캡처가 `/` 로 시작할 수 있어 **가드가 필요**하다.
     *   · IIS — 캡처가 `public/` 뒤에서 시작해 구조적으로 성립하지 않는다. 그 판정을
     *     **주석으로 선언**하는 것이 이 서버의 의무다(없으면 「빠뜨린 것」과 구분되지 않는다).
     *   · Caddy — 성립한다(`redir @g7_public /{re.g7public.1}` 가 `/public//evil.example/p` 에서
     *     `//evil.example/p` 를 낸다). 종전에는 실측하지 않는 서버(제품 결정 2026-08-27)라는
     *     이유로 알려진 한계 선언만 요구했는데, 그 선언은 열린 리다이렉트를 열린 채 두었다.
     *     형제 둘과 같은 가드(`@g7_public` matcher 블록의 `not path_regexp ^/public//`)를
     *     **주석 밖 규칙**에서 요구한다. 실측하지 않는 결정은 그대로다 — 이 검사는 정적 대조다.
     *
     * 그래서 넷 다 「가드 또는 선언」을 요구한다. 어느 쪽도 없으면 그 서버는 이 축을 한
     * 번도 판정하지 않은 것이다.
     *
     * @scenario server_parity=empty_segment_guard, servers=apache+nginx+caddy+iis
     *
     * @effects canonical_redirects_never_emit_a_protocol_relative_destination
     *
     * @return void 반환값 없음
     */
    public function test_every_server_config_settles_the_empty_segment_axis(): void
    {
        // 각 서버에서 이 축이 판정됐음을 보이는 근거. 가드 지시어이거나, 성립하지
        // 않는다/한계로 남긴다는 **선언**이다.
        $evidence = [
            'public/.htaccess' => '~RewriteCond\s+%\{REQUEST_URI\}\s+!//~',
            // 구분자는 `#` 다 — nginx 조건 표기의 `~` 가 패턴 본문에 들어간다
            // (형제 `test_every_server_config_carries_the_canonical_address_rule` 과 같은 사유).
            // 가드 폭은 **경로 구간**이다 — `$request_uri` 는 쿼리를 포함한 원문이라
            // 문자열 전체에 걸면 형제 Apache(경로 전용 `%{REQUEST_URI}`)와 판정이
            // 갈리고, 쿼리에 URL 이 실린 정상 요청이 이 서버에서만 404 가 된다.
            self::NGINX_FRAGMENT => '#if\s*\(\s*\$request_uri\s*~\s*"\^\[\^\?\]\*//"\s*\)#',
            // Caddy 는 정본 주소 matcher 블록 **안**에 빈 세그먼트 제외 조건이 있어야 한다 —
            // 다른 matcher 에 있으면 redir 의 발동 조건을 좁히지 못한다. 주석 밖 본문에서 잰다.
            self::CADDY_FRAGMENT => '~@g7_public\s*\{[^{}]*^not\s+path_regexp\s+\^/public//\s*$[^{}]*\}~m',
            // IIS 는 「성립하지 않는다」 단정을 근거로 삼지 않는다 — 그 단정이 실측
            // 결과 **거짓**이었고(슬래시 두 겹부터 캡처가 `//` 로 시작한다), 게이트가
            // 그 문장을 판정 완료로 인정하고 있었다. 근거를 **확인 목록 항목**으로
            // 옮긴다 — 미판정을 미판정이라고 적는 것이 이 서버의 의무다.
            self::IIS_CONFIG => '~/public///evil\.example/p~',
        ];

        $measured = 0;

        foreach ($evidence as $file => $pattern) {
            $haystack = $file === self::CADDY_FRAGMENT
                ? $this->directiveBody($file)
                : (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                $pattern,
                $haystack,
                $file.' 가 빈 세그먼트 축을 판정하지 않는다 — 가드를 두거나, 그 서버에서 '
                .'성립하지 않는 근거(또는 알려진 한계)를 선언해야 한다. 선언이 없으면 '
                .'「막았다」와 「빠뜨렸다」가 구분되지 않는다'
            );

            $measured++;
        }

        $this->assertSame(4, $measured, '빈 세그먼트 축의 모집단이 넷이 아니다');
    }

    /**
     * 민감 디렉토리 목록이 세 서버 설정 전부에 도달한다.
     *
     * 한쪽에만 있으면 그 목록이 빠진 서버에서 해당 디렉토리가 그대로 서빙된다 — 오류도
     * 경고도 남지 않고, 그 서버로 배포한 운영자만 뒤늦게 알게 된다.
     *
     * @effects server_configs_share_the_htaccess_protection_lists
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_directories_reach_every_server_config(): void
    {
        $directories = $this->sensitiveDirectories();

        foreach ($this->serverSurfaces() as $file => $surface) {
            foreach ($directories as $directory) {
                $this->assertMatchesRegularExpression(
                    '/[(|]'.preg_quote($directory, '/').'[)|]/',
                    $surface,
                    $file.' 에 민감 디렉토리 `'.$directory.'` 가 없다 — 루트 `.htaccess` 는 막는데 그 서버에서는 열린다'
                );
            }
        }
    }

    /**
     * 확장자 목록이 세 서버 설정 전부에 도달한다.
     *
     * 루트 `.htaccess` 의 확장자 목록은 **배포 페이로드의 실제 최상위 파일**에서 도출된다
     * (형제 축이 잠근다). 그래서 최상위에 새 파일이 하나 늘면 그 축이 `.htaccess` 를
     * 고치게 하고, 이 축이 나머지 세 파일을 따라오게 한다.
     *
     * @effects server_configs_share_the_htaccess_protection_lists
     *
     * @return void 반환값 없음
     */
    public function test_blocked_extensions_reach_every_server_config(): void
    {
        $extensions = $this->blockedExtensions();

        foreach ($this->serverSurfaces() as $file => $surface) {
            foreach ($extensions as $extension) {
                if ($file === self::IIS_CONFIG) {
                    // IIS 는 `<fileExtensions>` 에 점을 붙인 형태(`.lock`)로도 적을 수 있어
                    // 구분자 판정이 다르다. 재작성 규칙의 대안 목록 또는 필터 항목 어느
                    // 쪽에 있어도 인정한다.
                    $this->assertMatchesRegularExpression(
                        '/[(|.]'.preg_quote($extension, '/').'[)|"\s]|[(|.]'.preg_quote($extension, '/').'$/m',
                        $surface,
                        $file.' 에 확장자 `'.$extension.'` 가 없다 — 루트 `.htaccess` 는 막는데 그 서버에서는 원문이 나간다'
                    );

                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/[(|]'.preg_quote($extension, '/').'[)|]/',
                    $surface,
                    $file.' 에 확장자 `'.$extension.'` 가 없다 — 루트 `.htaccess` 는 막는데 그 서버에서는 원문이 나간다'
                );
            }
        }
    }

    /**
     * 코어 업데이트가 `deploy/**` 옆에 두는 `.dist` 사본은 네 서버 설정 전부에서 열리지 않는다.
     *
     * 운영자 수정본을 보존할 때 새 판본이 `g7-layout2.conf.dist` 로 같은 디렉토리에 놓인다
     * (`CoreUpdateService::applyDeployFragments()`). 그 파일은 서버 설정 원문이라 비밀은
     * 아니지만, 서빙되면 배치 구조와 소켓 경로가 그대로 나간다. 두 겹으로 막힌다 — `deploy`
     * 디렉토리 자체가 차단 목록에 있고, `dist` 확장자도 목록에 있다. 어느 한 겹이 목록에서
     * 빠지면 이 테스트가 그 사실을 이름으로 말한다.
     *
     * @scenario rollback_leftover=deploy_dist_sidecar, servers=apache+nginx+caddy+iis
     *
     * @effects deploy_fragment_dist_sidecars_are_blocked_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_deploy_fragment_dist_sidecars_are_blocked_on_every_server_config(): void
    {
        $this->assertContains('deploy', $this->sensitiveDirectories(), '`deploy` 디렉토리가 차단 목록에 없다 — `.dist` 사본이 그 디렉토리째 열린다');
        $this->assertContains(
            ltrim(CoreUpdateService::DEPLOY_FRAGMENT_DIST_SUFFIX, '.'),
            $this->blockedExtensions(),
            '`.dist` 확장자가 차단 목록에 없다 — 디렉토리 차단이 풀리는 배치에서 새 판본 사본이 열린다'
        );

        foreach ($this->serverSurfaces() as $file => $surface) {
            $this->assertMatchesRegularExpression('/[(|]deploy[)|]/', $surface, $file.' 에 `deploy` 디렉토리 차단이 없다');
        }
    }

    /**
     * 확장자 없는 최상위 파일 이름이 세 서버 설정 전부에 도달한다.
     *
     * `artisan` 은 PHP 소스인데 확장자가 없어 확장자 규칙으로는 걸리지 않는다.
     *
     * @effects server_configs_share_the_htaccess_protection_lists
     *
     * @return void 반환값 없음
     */
    public function test_bare_file_names_reach_every_server_config(): void
    {
        $names = $this->blockedBareNames();

        foreach ($this->serverSurfaces() as $file => $surface) {
            foreach ($names as $name) {
                $this->assertMatchesRegularExpression(
                    '/[(|]'.preg_quote($name, '/').'[)|]/',
                    $surface,
                    $file.' 에 확장자 없는 파일 `'.$name.'` 가 없다 — 그 서버에서 PHP 소스가 평문으로 나간다'
                );
            }
        }
    }

    /**
     * 숨김 파일 차단과 ACME 예외가 세 서버 설정 전부에 있다.
     *
     * 숨김 파일 차단만 있고 `.well-known/` 예외가 없으면 인증서 자동 갱신이 **조용히**
     * 실패한다 — 만료되기 전까지 아무 증상이 없다.
     *
     * @scenario servers=nginx+caddy+iis
     *
     * @effects hidden_file_guard_keeps_the_acme_exception_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_hidden_file_guard_and_acme_exception_reach_every_server_config(): void
    {
        // 「어딘가에 `\.` 가 있다」로 재면 **확장자 차단 규칙**이 그 패턴을 이미 갖고 있어
        // 숨김 파일 규칙을 통째로 지워도 초록이다(세 파일 모두 그렇다). 그래서 각 서버에서
        // 숨김 파일을 막는 **그 규칙**을 이름으로 특정하고, 차단 효과까지 함께 잠근다.
        $guards = [
            self::IIS_CONFIG => '/\(\^\|\/\)\\\.\s*=>\s*CustomResponse\s+403/',
            self::NGINX_FRAGMENT => '/location\s+~\s+\/\\\.\s*\{\s*deny\s+all;/',
            self::CADDY_FRAGMENT => '/@g7_hidden[\s\S]{0,200}?respond\s+@g7_hidden\s+403/',
        ];

        foreach ($this->serverSurfaces() as $file => $surface) {
            $this->assertMatchesRegularExpression(
                $guards[$file],
                $surface,
                $file.' 의 숨김 파일 차단 규칙이 사라졌거나 차단 효과를 잃었다 — `.env` 가 그대로 열린다'
            );

            // 「어딘가에 `well-known` 이 있다」로 재면 **차단 규칙 자신**이 그 문자열을
            // 공급해 예외를 통째로 지워도 초록이다(Caddy 가 실제로 그 상태였다 —
            // `(?!well-known/)` 가 차단 정규식 안에 있어 `handle /.well-known/*` 블록을
            // 삭제해도 통과했다). 그래서 각 서버에서 ACME 를 **통과시키는 그 구문**을
            // 이름으로 특정한다.
            $acme = [
                // IIS 면은 `패턴 => 액션 상태` 로 접혀 온다(`iisRuleSurface()`).
                // 「통과시킨다」는 액션이 `None` 인 것이고, 차단 규칙은 `CustomResponse 403`
                // 이라 이 패턴은 차단 규칙으로 충족되지 않는다.
                self::IIS_CONFIG => '/well-known\/?\s*=>\s*None/',
                self::NGINX_FRAGMENT => '/location\s+\^~\s+\/\.well-known\//',
                self::CADDY_FRAGMENT => '/handle\s+\/\.well-known\/\*/',
            ];

            $this->assertMatchesRegularExpression(
                $acme[$file],
                $surface,
                $file.' 에 ACME 예외를 **통과시키는 구문**이 없다 — 인증서 자동 갱신이 조용히 실패한다'
            );
        }

        $this->assertIisAcmeRulePrecedesTheHiddenFileRule();
    }

    /**
     * IIS 는 **순서가 곧 판정이다** — ACME 예외가 숨김 파일 차단보다 앞에 있어야 한다.
     *
     * 형제 셋은 순서에 좌우되지 않는다: Apache 는 예외를 차단 정규식 안에 내포하고
     * (`(?!well-known/)`), nginx 는 `^~` 접두 location 이 정규식 location 보다 우선하며,
     * Caddy 는 `not path` 로 가른다. IIS 만 규칙을 위에서 아래로 훑고 `stopProcessing`
     * 에서 멈추므로, 두 규칙의 **자리**가 바뀌면 `(^|/)\.` 가 `.well-known/acme-challenge/…`
     * 를 먼저 먹어 403 을 낸다.
     *
     * 그 상태는 인증서가 만료될 때까지 아무 신호도 주지 않는다 — 갱신은 보통 만료
     * 30일 전에 조용히 시도되고 실패해도 사이트는 정상으로 보인다. 그래서 존재만
     * 재던 종전 축으로는 이 회귀가 통과했다.
     *
     * @return void 반환값 없음
     */
    private function assertIisAcmeRulePrecedesTheHiddenFileRule(): void
    {
        $config = $this->iisConfigWithoutComments();

        $acmeAt = strpos($config, '<rule name="G7 ACME passthrough"');
        $hiddenAt = strpos($config, '<rule name="G7 hidden files"');

        $this->assertIsInt($acmeAt, 'IIS ACME 규칙을 찾지 못했다 — 이 순서 판정이 성립하지 않는다');
        $this->assertIsInt($hiddenAt, 'IIS 숨김 파일 규칙을 찾지 못했다 — 이 순서 판정이 성립하지 않는다');

        $this->assertLessThan(
            $hiddenAt,
            $acmeAt,
            'IIS 에서 ACME 예외가 숨김 파일 차단보다 **뒤**에 있다 — 규칙은 위에서 아래로 '
            .'평가되고 stopProcessing 에서 멈추므로, 차단이 먼저 매치해 인증서 자동 갱신이 '
            .'403 으로 실패한다. 만료 전까지 아무 신호도 없다'
        );

        // **중간 고리도 잰다.** 위 단언은 `ACME < 숨김` 만 보므로, 두 규칙을 **함께**
        // 위임 뒤로 옮기면 그 관계가 유지된 채 통과한다. 그 상태의 실제 결과는 위임
        // 규칙(`^((?!public/).*)$`)이 `.well-known/acme-challenge/…` 를 먼저 먹어
        // `public/.well-known/…` 로 다시 쓰는 것이다 — 그 경로는 실재하지 않아 404 이고,
        // 같은 이동으로 숨김 파일 차단도 무력화된다. IIS 는 실측 서버가 아니라
        // (`web.config` 머리말) 이 순서 단언이 유일한 방어선이다.
        $delegateAt = strpos($config, '<rule name="G7 delegate to public"');

        $this->assertIsInt($delegateAt, 'IIS 위임 규칙을 찾지 못했다 — 이 순서 판정이 성립하지 않는다');

        $this->assertLessThan(
            $delegateAt,
            $hiddenAt,
            'IIS 에서 보호 규칙(ACME 예외·숨김 파일 차단)이 위임 규칙보다 **뒤**에 있다 — '
            .'위임이 먼저 매치해 stopProcessing 으로 끝나므로 두 규칙 모두 도달하지 않는다. '
            .'인증서 자동 갱신이 404 로 실패하고 `.env` 차단도 함께 무력화된다'
        );
    }

    /**
     * 디렉토리 목록 차단이 **네 설정 전부**에 있다.
     *
     * 이 축은 종전에 어느 대조에도 없었다 — `serverSurfaces()` 가 비-Apache 3종만 모으고
     * `public/.htaccess` 는 이 테스트에 등장하지 않았다. 그래서 `IndexIgnore *` 를 지워도,
     * Caddy 에 `browse` 를 켜도 아무것도 울지 않았다.
     *
     * 목록이 열리면 문서 루트 아래 파일 이름이 통째로 나열된다 — 그 자체가 정찰 정보이고,
     * 확장자 차단을 우회할 이름을 그대로 알려 준다.
     *
     * @scenario server_parity=apache, protection=directory_listing
     *
     * @effects directory_listing_is_blocked_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_directory_listing_is_blocked_on_every_server_config(): void
    {
        $guards = [
            // Apache: `Options -Indexes` 는 Options 등급이라 쓸 수 없다 — `IndexIgnore`(Indexes 등급).
            // 루트 `.htaccess` 도 모집단이다 — 레이아웃 ②에서 mod_rewrite 가 없으면 프로젝트
            // 루트가 디렉토리 요청의 응답 주체가 되는데, 종전에는 `public/` 만 재고 있었다.
            '.htaccess' => ['/^IndexIgnore\s+\*/m', $this->directiveBody('.htaccess')],
            'public/.htaccess' => ['/^IndexIgnore\s+\*/m', $this->directiveBody('public/.htaccess')],
            self::IIS_CONFIG => ['/directoryBrowse[^>]*enabled="false"/', $this->iisConfigWithoutComments()],
            self::NGINX_FRAGMENT => ['/autoindex\s+off\s*;/', $this->directiveBody(self::NGINX_FRAGMENT)],
            self::CADDY_FRAGMENT => ['/(?:^|\n)\s*file_server(?![^\n]*\bbrowse\b)/', $this->directiveBody(self::CADDY_FRAGMENT)],
        ];

        foreach ($guards as $file => [$pattern, $body]) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $body,
                $file.' 에 디렉토리 목록 차단이 없다 — 그 서버에서 문서 루트의 파일 이름이 통째로 나열된다'
            );
        }

        // Caddy 는 기본값(browse 꺼짐)에 기대므로, 누군가 켜는 순간 형제 셋과 갈린다.
        $this->assertStringNotContainsString(
            'browse',
            $this->directiveBody(self::CADDY_FRAGMENT),
            'Caddy 조각이 `browse` 를 켠다 — 형제 셋은 목록을 막는데 이 서버만 열린다'
        );
    }

    /**
     * 비-Apache 설정이 **도출 집합에 없는** 민감 디렉토리를 혼자 갖지 않는다.
     *
     * 목록 대조는 「도출 집합의 각 항목이 세 서버에 있는가」한 방향뿐이었다. 그래서 한
     * 서버에만 항목을 더해도 초록이었고, 그 상태는 「네 설정이 같은 것을 막는다」는 이
     * 파일의 전제를 조용히 깬다 — 어느 쪽이 옳은지 사람이 판단해야 하는데 그 판단을
     * 요구하는 신호가 없었다.
     *
     * @scenario server_parity=reverse_direction, derivation=sensitive_dirs
     *
     * @effects server_configs_do_not_diverge_from_the_derived_list
     *
     * @return void 반환값 없음
     */
    public function test_no_server_config_blocks_a_directory_outside_the_derived_list(): void
    {
        // 두 쪽 다 정규식 본문에서 뽑히므로 이스케이프 표기가 섞인다 (`\.git` vs `.git`).
        // 비교 전에 같은 형태로 접는다 — 안 그러면 표기 차이가 「형제와 갈렸다」로 오탐된다.
        $fold = static fn (string $name): string => strtolower(str_replace('\\', '', trim($name)));

        $derived = array_map($fold, $this->sensitiveDirectories());

        // IIS 도 같은 모집단이다. docblock 은 「**비-Apache 설정이**」라고 적는데 목록에는
        // nginx·Caddy 둘뿐이었다 — 그 파일만 항목을 하나 더해도 초록이었고, 하필 그 파일은
        // 실측 서버가 없어(머리말 「검증 상태」) 이 대조가 유일한 방어선이다.
        $lists = [
            self::NGINX_FRAGMENT => '/\^\/\(([^)]+)\)\(\/\|\$\)/',
            self::CADDY_FRAGMENT => '/\^\/\(([^)]+)\)\(\/\|\$\)/',
            self::IIS_CONFIG => '/\^\(([^)]+)\)\(\/\|\$\)/',
        ];

        foreach ($lists as $file => $pattern) {
            // IIS 는 주석 문법이 달라 `directiveBody()` 가 그 주석을 지우지 못한다
            // (`#` 줄만 본다). 그 상태로 목록을 뽑으면 **주석에만 적힌 설명 항목**이
            // 「이 서버만 막는 항목」으로 읽혀 거짓 red 가 난다.
            $body = $file === self::IIS_CONFIG
                ? $this->iisConfigWithoutComments()
                : $this->directiveBody($file);

            preg_match($pattern, $body, $m);

            $this->assertNotEmpty($m, $file.' 에서 민감 디렉토리 목록을 도출하지 못했다');

            foreach (array_filter(array_map('trim', explode('|', $m[1]))) as $name) {
                $this->assertContains(
                    $fold($name),
                    $derived,
                    $file.' 만 `'.$name.'` 를 막는다 — 도출 집합(루트 .htaccess)에 없는 항목이라 '
                    .'형제 설정과 보호 대상이 갈린다. 목록을 늘리려면 도출 근거부터 늘린다'
                );
            }
        }
    }

    /**
     * 비-Apache 설정이 **도출 집합에 없는** 확장자·확장자 없는 이름을 혼자 막지 않는다.
     *
     * 역방향 대조는 민감 디렉토리 축에만 있었다 — 확장자(29패턴)와 확장자 없는 이름은
     * 세 서버 어디에도 역방향이 없어, 한 서버에만 항목을 더해도 초록이었다. 그 상태는
     * 「네 설정이 같은 것을 막는다」는 이 파일의 전제를 조용히 깨고, 늘어난 쪽이 옳은지
     * 줄어든 쪽이 옳은지 사람이 판단할 신호가 없다.
     *
     * 목록 추출은 서버별 정규식 사본을 두지 않는다 — 세 파일의 표기(`^/[^/]+\.(…)$` ·
     * `^[^/]+\.(…)$`)가 달라 사본이 갈리면 그 서버만 조용히 무검사가 된다. 대신
     * **대안 목록 자체**를 훑어 기준 항목(`lock` · `artisan`)이 든 것을 고른다.
     *
     * @scenario server_parity=reverse_direction, derivation=extensions+bare_names
     *
     * @effects server_configs_do_not_diverge_from_the_derived_list
     *
     * @return void 반환값 없음
     */
    public function test_no_server_config_blocks_a_file_pattern_outside_the_derived_list(): void
    {
        $fold = static fn (string $name): string => strtolower(str_replace('\\', '', trim($name)));

        $axes = [
            'lock' => array_map($fold, $this->blockedExtensions()),
            'artisan' => array_map($fold, $this->blockedBareNames()),
        ];

        foreach ($this->serverSurfaces() as $file => $surface) {
            foreach ($axes as $anchor => $derived) {
                $list = $this->alternationContaining($surface, $anchor);

                $this->assertNotNull(
                    $list,
                    $file.' 에서 `'.$anchor.'` 가 든 대안 목록을 찾지 못했다 — 그 축의 차단 규칙이 사라졌는가'
                );

                foreach ($list as $item) {
                    $this->assertContains(
                        $fold($item),
                        $derived,
                        $file.' 만 `'.$item.'` 를 막는다 — 도출 집합(루트 .htaccess)에 없는 항목이라 '
                        .'형제 설정과 보호 대상이 갈린다. 목록을 늘리려면 도출 근거부터 늘린다'
                    );
                }
            }
        }
    }

    /**
     * IIS `<requestFiltering>` 도 역방향(발산) 대조를 받는다.
     *
     * 정방향은 IIS 전용 분기로 `<fileExtensions>` 항목도 「막고 있다」의 근거로 인정하는데,
     * 역방향(`alternationContaining`)은 괄호 대안 목록만 훑으므로 `.lock` 같은 **단독 토큰**은
     * 후보에 오르지도 않는다. 즉 **한 방향만 필터를 본다** — web.config 에만 항목을 더하면
     * 전 단언이 초록이었다. 하필 IIS 는 이 저장소에 실측 서버가 없어 이 대조가 유일한
     * 방어선이라고 그 파일 자신이 적는다.
     *
     * `hiddenSegments` 의 점(`.`)으로 시작하는 항목은 **숨김 파일 축**이 따로 덮으므로
     * 디렉토리 도출 집합에 없어도 정상이다(`.env` 가 그 형태다). 그 축까지 디렉토리
     * 목록으로 재면 없는 발산을 만들어 낸다.
     *
     * @scenario server_parity=reverse_direction, server_kind=iis, surface=request_filtering
     *
     * @effects server_configs_do_not_diverge_from_the_derived_list
     *
     * @return void 반환값 없음
     */
    public function test_iis_request_filtering_does_not_diverge_from_the_derived_lists(): void
    {
        $fold = static fn (string $name): string => strtolower(str_replace('\\', '', trim($name)));
        $config = $this->iisConfigWithoutComments();

        $extensions = array_map($fold, $this->blockedExtensions());
        $directories = array_map($fold, $this->sensitiveDirectories());

        preg_match_all('/<add\s+fileExtension="\.([^"]+)"/', $config, $extMatches);

        $this->assertNotEmpty(
            $extMatches[1],
            'web.config 의 fileExtensions 항목을 하나도 찾지 못했다 — 이 대조가 아무것도 재지 않는다'
        );

        foreach ($extMatches[1] as $ext) {
            $this->assertContains(
                $fold($ext),
                $extensions,
                'web.config 만 `.'.$ext.'` 를 막는다 — 도출 집합(루트 .htaccess)에 없는 항목이라 '
                .'형제 설정과 보호 대상이 갈린다. 목록을 늘리려면 도출 근거부터 늘린다'
            );
        }

        preg_match_all('/<add\s+segment="([^"]+)"/', $config, $segMatches);

        $this->assertNotEmpty(
            $segMatches[1],
            'web.config 의 hiddenSegments 항목을 하나도 찾지 못했다 — 이 대조가 아무것도 재지 않는다'
        );

        foreach ($segMatches[1] as $segment) {
            if (str_starts_with($segment, '.')) {
                // 숨김 파일·디렉토리는 형제 셋 모두 별도 축(`^\.`)으로 막는다.
                continue;
            }

            $this->assertContains(
                $fold($segment),
                $directories,
                'web.config 만 `'.$segment.'` 를 막는다 — 도출 집합에 없는 디렉토리다'
            );
        }
    }

    /**
     * 규칙 본문에서 `|` 로 갈린 대안 목록 중 기준 항목이 든 것을 돌려줍니다.
     *
     * 서버마다 앵커 표기가 다르므로 정규식 사본을 서버 수만큼 두지 않기 위한 도출입니다.
     *
     * @param  string  $surface  규칙 본문
     * @param  string  $anchor  그 목록에 반드시 들어 있는 항목 (예: `lock`)
     * @return list<string>|null 대안 항목 목록 (찾지 못하면 null)
     */
    private function alternationContaining(string $surface, string $anchor): ?array
    {
        preg_match_all('/\(([^()]*\|[^()]*)\)/', $surface, $groups);

        foreach ($groups[1] as $group) {
            $items = array_values(array_filter(array_map('trim', explode('|', $group))));

            if (in_array($anchor, $items, true)) {
                return $items;
            }
        }

        return null;
    }

    /**
     * Caddy 조각의 정규식이 그 엔진(RE2)에서 성립한다.
     *
     * `path_regexp` 는 Go 의 `regexp` = RE2 이고, RE2 에는 전방·후방탐색과 역참조가
     * **없다**. 형제 설정(Apache PCRE · IIS .NET)에서 그대로 옮겨 오면 매칭이 빗나가는
     * 정도가 아니라 **Caddyfile 이 로드되지 않아 그 서버의 사이트가 통째로 뜨지 않는다.**
     * 이 저장소에는 Caddy 바이너리가 없어 문법 검증을 실행할 수 없으므로, 옮겨 오기
     * 쉬운 구문을 이름으로 막는다.
     *
     * @scenario server_parity=caddy, derivation=regex_engine
     *
     * @effects caddy_fragment_loads_under_re2
     *
     * @return void 반환값 없음
     */
    public function test_caddy_fragment_avoids_regex_syntax_re2_cannot_compile(): void
    {
        $body = $this->directiveBody(self::CADDY_FRAGMENT);

        foreach ([
            '(?!' => '부정 전방탐색',
            '(?=' => '긍정 전방탐색',
            '(?<!' => '부정 후방탐색',
            '(?<=' => '긍정 후방탐색',
            '\1' => '역참조',
        ] as $token => $label) {
            $this->assertStringNotContainsString(
                $token,
                $body,
                'Caddy 조각이 '.$label.' `'.$token.'` 을 쓴다 — RE2 는 이 문법을 컴파일하지 못해 '
                .'Caddyfile 로드가 실패하고 그 서버의 사이트가 뜨지 않는다. '
                .'순서 무관 예외는 named matcher 안의 `not path …` 로 낸다'
            );
        }
    }

    /**
     * 민감 목록을 막는 규칙이 **실제로 막는다** (효과 축).
     *
     * 목록 대조 셋(디렉토리·확장자·무확장자)은 「그 이름이 본문 어딘가에 있는가」만 본다.
     * 그래서 IIS 의 `<action type="CustomResponse" statusCode="403">` 을 `None` 으로
     * 바꾸거나 nginx 의 `try_files /public$uri` 를 지워도 전부 초록이었다 — 목록은 그대로인데
     * 그 서버에서만 프로젝트 루트가 열린다.
     *
     * @scenario server_kind=iis, protection=effect
     *
     * @effects server_protection_rules_declare_a_blocking_effect
     *
     * @return void 반환값 없음
     */
    public function test_protection_rules_have_a_blocking_or_delegating_effect(): void
    {
        // **개수로 재지 않는다.** 종전 판본은 「차단·위임 효과가 N개 이상 있는가」였고,
        // 그 N 은 `count([헬퍼 3개]) + 1` 이라 배열 리터럴의 원소 수가 상한이었다 —
        // 「축이 늘거나 줄면 따라간다」고 적혀 있었지만 구조적으로 항상 4였다.
        //
        // 더 나쁜 것은 정족수라는 형태 자체다. 보호 규칙 하나의 효과를 지우고 403 을 내는
        // **무해한 규칙 하나를 더하면** 매치 수가 유지되어 초록이었다(변이
        // 실측에서 그대로 통과했다). 개수는 「어느 규칙이 무엇을 막는가」를 묻지 않는다.
        //
        // 그래서 **축마다 그 축의 규칙을 특정하고, 그 규칙 안에서 효과를 요구한다.**
        // 이 파일의 숨김 파일 검사(`test_hidden_file_guard_...`)가 이미 쓰는 형태이며,
        // 무해한 규칙을 아무리 더해도 지워진 축을 메울 수 없다.
        //
        // 목록 자체가 세 서버에 도달하는지는 형제 검사 셋이 따로 소유한다. 여기서 묻는
        // 것은 **그 목록을 실은 규칙이 실제로 막는가**뿐이다.
        // 축을 **내용**으로 특정한다.
        //
        // 종전 locator 는 `\(artisan[^)]*\)` 처럼 목록의 **첫 원소 이름을 리터럴로**
        // 박고 있어 원소 순서만 바꿔도 거짓 red 였고, `(/|$)`↔`($|/)` · `location ~`↔`~*`
        // 같은 동치 표기에도 흔들렸다. 반대로 `[^}]*\}` 는 블록 안에 중첩 중괄호가
        // 생기면 첫 `}` 에서 끊겨 효과 줄이 매치 밖으로 나갔다.
        //
        // 그래서 정규식 표기가 아니라 **그 축의 도출 목록이 실린 규칙**을 찾는다.
        $fold = static fn (string $name): string => strtolower(str_replace(chr(92), '', trim($name)));

        // 앵커는 **목록 전 원소**다. 종전에는 각 축의 `[0]` 하나뿐이었다 — 그래서 목록에서
        // `vendor` 를 빼고 그것만 서빙하는 전용 블록을 신설하면 목록 축(이름이 본문 어딘가에
        // 있는가)과 효과 축(첫 원소가 든 블록이 막는가)이 **둘 다 초록**이고 `/vendor/autoload.php`
        // 가 그대로 나갔다. 리터럴 폴백(`?? 'app'` 등)도 없앤다 — 도출이 비면 폴백이 그 자리를
        // 메워 「아무것도 재지 않는 초록」이 되는데, 도출 헬퍼 셋은 이미 비면 스스로 red 다.
        $axes = [
            '민감 디렉토리' => array_map($fold, $this->sensitiveDirectories()),
            '민감 확장자' => array_map($fold, $this->blockedExtensions()),
            '무확장자 이름' => array_map($fold, $this->blockedBareNames()),
        ];

        $effects = [
            self::IIS_CONFIG => '/=>\s*CustomResponse\s+403/',
            self::NGINX_FRAGMENT => '/try_files\s+\/public\$uri\s+\@g7_front;/',
            self::CADDY_FRAGMENT => '/import\s+g7_delegate/',
        ];

        $measured = 0;
        $anchorsMeasured = 0;

        foreach ($this->serverSurfaces() as $file => $surface) {
            if ($file === self::IIS_CONFIG) {
                // IIS 면은 `패턴 => 액션 상태` 한 줄로 접혀 온다 — 순서 축이 없다.
                foreach ($axes as $axis => $anchors) {
                    foreach ($anchors as $anchor) {
                        $lines = array_values(array_filter(
                            preg_split('~\r\n|\r|\n~', $surface) ?: [],
                            fn (string $l): bool => $this->containsToken($l, $anchor) && str_contains($l, '=>'),
                        ));

                        $this->assertNotSame(
                            [],
                            $lines,
                            self::IIS_CONFIG.' 에서 「'.$axis.'」 축의 `'.$anchor.'` 를 담은 규칙을 찾지 못했다'
                        );

                        foreach ($lines as $line) {
                            $this->assertMatchesRegularExpression(
                                $effects[$file],
                                $line,
                                self::IIS_CONFIG.' 의 「'.$axis.'」 규칙(`'.$anchor.'`)이 차단 효과를 잃었다',
                            );
                        }

                        $anchorsMeasured++;
                    }

                    $measured++;
                }

                continue;
            }

            $body = (string) preg_replace('~^[ 	]*#.*$~m', '', (string) file_get_contents(base_path($file)));

            foreach ($axes as $axis => $anchors) {
                foreach ($anchors as $anchor) {
                    $blocks = $this->blocksContaining($body, $anchor, $file === self::CADDY_FRAGMENT);

                    $this->assertNotSame(
                        [],
                        $blocks,
                        $file.' 에서 「'.$axis.'」 축의 `'.$anchor.'` 를 담은 규칙을 찾지 못했다 — 규칙이 '
                        .'사라졌거나 판정이 그 표기를 모른다. 어느 쪽이든 그 원소는 지금 측정되지 않는다'
                    );

                    // **모든** 후보가 효과를 가져야 한다.
                    //
                    // 종전에는 `preg_match` 의 **첫 매치**만 봤다. 그래서 축의 모양을 흉내 낸
                    // 무해한 규칙을 진짜 규칙보다 **위에** 두면, 지워진 진짜 규칙은 한 번도
                    // 읽히지 않고 초록이었다(변이 실측). 정족수를 1:1 매핑으로 바꾸면서
                    // 유일성을 잠그지 않은 자리다.
                    foreach ($blocks as $block) {
                        $this->assertMatchesRegularExpression(
                            $effects[$file],
                            $block,
                            $file.' 의 「'.$axis.'」 규칙(`'.$anchor.'`)이 차단·위임 효과를 잃었다 — 목록은 '
                            .'남아 있어도 그 서버에서 그 경로가 그대로 서빙된다'
                        );

                        if ($file === self::NGINX_FRAGMENT) {
                            $this->assertNginxDelegationIsReached($block, $file.' 의 「'.$axis.'」 블록(`'.$anchor.'`)', $effects[$file]);
                        }
                    }

                    $anchorsMeasured++;
                }

                $measured++;
            }
        }

        // 앵커 모집단도 도출로 잠근다 — 축이 셋인 것만 재면 각 축이 한 원소로 줄어도 초록이다.
        $this->assertSame(
            3 * array_sum(array_map('count', $axes)),
            $anchorsMeasured,
            '앵커 모집단이 3서버 × (도출 목록 전 원소)가 아니다'
        );

        // 위임 사슬의 **끝**도 잰다 — `@g7_front` 라는 이름만 보고 그 안이 무엇을 하는지
        // 묻지 않으면, 그 블록을 정적 서빙으로 바꿔도 위 단언이 전부 초록이다.
        $nginx = (string) preg_replace('~^[ 	]*#.*$~m', '', (string) file_get_contents(base_path(self::NGINX_FRAGMENT)));
        $frontBlocks = $this->blocksContaining($nginx, '@g7_front', false);

        $this->assertNotSame([], $frontBlocks, 'nginx 위임 대상(@g7_front) 블록을 찾지 못했다');

        $frontBody = implode('
', array_filter(
            $frontBlocks,
            static fn (string $b): bool => str_contains($b, 'location @g7_front'),
        ));

        $this->assertMatchesRegularExpression(
            '/(fastcgi_pass|proxy_pass|uwsgi_pass)\s/',
            $frontBody,
            'nginx 의 @g7_front 가 애플리케이션으로 넘기지 않는다 — 위임의 끝이 정적 서빙이면 '
            .'앞의 모든 위임이 그대로 파일을 내준다',
        );

        // Caddy 도 같은 축을 잰다. 종전에는 `import g7_delegate` 라는 **이름만** 요구하고
        // `(g7_delegate)` 스니펫 **본문을 한 번도 읽지 않았다** — 그래서 그 안의
        // `root * {$G7_ROOT}/public` 을 `{$G7_ROOT}` 로 바꿔 **프로젝트 루트를 통째로
        // 서빙**하게 만들어도, `php_fastcgi` 를 지워 PHP 원문이 나가게 만들어도 목록·효과·
        // 역방향 전 검사가 초록이었다. 네 handle 이 전부 이 스니펫을 import 하므로 그
        // 한 줄이 이 조각의 보호 전체를 결정한다. 형제 nginx 는 위에서 이미 잠갔다 —
        // 같은 축을 한 서버에만 두면 그것은 규율이 아니라 그 서버의 패치다.
        $caddy = $this->directiveBody(self::CADDY_FRAGMENT);
        $delegateBlocks = $this->blocksContaining($caddy, '(g7_delegate)', false);

        $this->assertNotSame([], $delegateBlocks, 'Caddy 위임 스니펫((g7_delegate)) 블록을 찾지 못했다');

        $delegateBody = implode('
', $delegateBlocks);

        $this->assertMatchesRegularExpression(
            '~(?:^|\s)root\s+\*\s+\{\$G7_ROOT\}/public(?:\s|$)~m',
            $delegateBody,
            'Caddy 위임 스니펫의 서빙 루트가 사이트의 공개 폴더가 아니다 — 프로젝트 루트를 '
            .'그대로 서빙하면 `.env`·`storage`·`vendor` 가 웹에 열린다',
        );

        $this->assertMatchesRegularExpression(
            '~(?:^|\s)php_fastcgi\s~m',
            $delegateBody,
            'Caddy 위임 스니펫이 애플리케이션으로 넘기지 않는다 — 위임의 끝이 정적 서빙이면 '
            .'PHP 파일이 원문으로 나간다',
        );

        // 모집단 대조 — 3서버 × 3축. 비면 위 반복이 0회 돌고도 초록이 된다.
        $this->assertSame(9, $measured, '보호 축 모집단이 3서버 × 3축이 아니다');
    }

    /**
     * nginx 블록에서 **위임이 실제로 도달하는가**를 판정합니다.
     *
     * 종전 판정은 줄머리 정규식(`/^\s*(return|try_files|…)/m`)의 첫 매치 하나였다. 그
     * 형태는 두 가지로 틀린다:
     *
     *  · **한 줄로 접은 조건 종결을 못 본다.** `if ($http_x) { return 200 "leak"; }` 를
     *    블록 첫 줄에 넣으면 그 줄은 `if` 로 시작해 줄머리 조건에 걸리지 않고, 첫 매치는
     *    그 아래 `try_files` 라 초록이 된다 — 실제로는 nginx 가 그 `if` 를 먼저 처리해
     *    위임이 도달하지 않는다.
     *  · **정당한 조건 종결을 red 로 만든다.** 형제 헬퍼(`blocksContaining`)의 독블록은
     *    여러 줄 `if ($request_method = POST) { return 405; }` 를 「정당한 구성」이라고
     *    명시하는데, 종전 판정은 그 `return 405;` 를 첫 매치로 잡아 거짓 red 를 냈다.
     *    같은 파일의 두 판정이 서로 모순이었다.
     *
     * 그래서 종결 지시자를 **토큰 경계**로 찾고, `if` 블록 안의 것은 **조건부**로 따로
     * 분류한다. 조건부 종결은 거부(4xx·5xx)나 리다이렉트(3xx)만 허용한다 — 2xx 본문
     * 응답은 위임을 건너뛰고 내용을 내주므로 이 배치에서 성립하지 않는다. 무조건 종결은
     * **첫 번째**가 위임이어야 한다.
     *
     * @param  string  $block  검사할 nginx 블록 본문
     * @param  string  $label  실패 메시지에 실을 대상 이름
     * @param  string  $effect  그 서버의 위임 효과 정규식
     * @return void 반환값 없음
     */
    private function assertNginxDelegationIsReached(string $block, string $label, string $effect): void
    {
        $terminators = $this->nginxTerminatingDirectives($block);

        foreach ($terminators as $terminator) {
            if (! $terminator['conditional']) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '~^return\s+(?:3\d\d(?:\s+\S+)?|4\d\d|5\d\d)\s*;?$~i',
                $terminator['text'],
                $label.' 의 조건부 종결(`'.$terminator['text'].'`)이 거부·리다이렉트가 아니다 — '
                .'nginx 는 `if` 를 위임보다 먼저 처리하므로, 그 자리에서 본문을 내주면 '
                .'위임은 도달하지 않고 그 경로가 그대로 서빙된다'
            );
        }

        $unconditional = array_values(array_filter(
            $terminators,
            static fn (array $t): bool => ! $t['conditional'],
        ));

        $this->assertNotSame(
            [],
            $unconditional,
            $label.' 에 처리를 끝내는 지시자가 없다 — 이 블록은 아무것도 위임하지 않는다'
        );

        $this->assertMatchesRegularExpression(
            $effect,
            $unconditional[0]['text'],
            $label.' 에서 **위임보다 먼저** 끝나는 지시자가 있다 — '
            .'nginx 는 그것을 먼저 처리하므로 위임은 도달하지 않는다'
        );
    }

    /**
     * nginx 블록 안의 **처리를 끝내는 지시자**를 등장 순서대로 돌려줍니다.
     *
     * @param  string  $block  nginx 블록 본문
     * @return list<array{text:string, conditional:bool}> 지시자 본문과 `if` 블록 소속 여부
     */
    private function nginxTerminatingDirectives(string $block): array
    {
        $conditionalRanges = $this->nginxIfBlockRanges($block);

        preg_match_all(
            '~(?:^|[;{}\n])[ \t]*(return|try_files|rewrite|proxy_pass|fastcgi_pass|uwsgi_pass|root|alias)\b[^;{}]*;?~i',
            $block,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $found = [];

        foreach ($matches[1] as $index => [, $at]) {
            $conditional = false;

            foreach ($conditionalRanges as [$start, $end]) {
                if ($at > $start && $at < $end) {
                    $conditional = true;
                    break;
                }
            }

            $found[] = [
                // 앞의 경계 문자만 걷어내고 **끝의 `;` 는 남긴다** — 그 세미콜론까지가
                // 한 지시자이고, 효과 판정식이 그 형태로 지시자를 특정한다.
                'text' => rtrim(ltrim((string) $matches[0][$index][0], " \t\r\n;{}"), " \t\r\n"),
                'conditional' => $conditional,
            ];
        }

        return $found;
    }

    /**
     * nginx 블록 안의 `if (…) { … }` 구간을 돌려줍니다.
     *
     * @param  string  $block  nginx 블록 본문
     * @return list<array{0:int, 1:int}> 여는 중괄호와 닫는 중괄호 위치 쌍
     */
    private function nginxIfBlockRanges(string $block): array
    {
        $ranges = [];
        $stack = [];
        $length = strlen($block);

        for ($i = 0; $i < $length; $i++) {
            if ($block[$i] === '{') {
                $cut = 0;

                foreach (["\n", ';', '{', '}'] as $mark) {
                    $at = strrpos(substr($block, 0, $i), $mark);

                    if ($at !== false && $at + 1 > $cut) {
                        $cut = $at + 1;
                    }
                }

                $stack[] = [preg_match('~\bif\s*\(~i', substr($block, $cut, $i - $cut)) === 1, $i];

                continue;
            }

            if ($block[$i] === '}' && $stack !== []) {
                [$isIf, $open] = array_pop($stack);

                if ($isIf) {
                    $ranges[] = [$open, $i];
                }
            }
        }

        return $ranges;
    }

    /**
     * IIS 설정 파일이 코어 업데이트 대상이고, 보호 경로에는 들어 있지 않다 (D25).
     *
     * 형제 파일 두 개(`index.php`·`.htaccess`)와 같은 취급이어야 한다. `protected_paths`
     * 에 들어가면 3-way 적용·신규파일 manifest·자동 발견 폴백 세 곳이 **로그 없이**
     * 건너뛰어 「함께 갱신」이 조용히 죽는다.
     *
     * @effects iis_web_config_is_a_core_update_target
     *
     * @return void 반환값 없음
     */
    public function test_iis_config_is_a_core_update_target_and_not_protected(): void
    {
        $targets = (array) config('app.update.targets');
        $protected = (array) config('app.update.protected_paths');

        $this->assertContains(
            self::IIS_CONFIG,
            $targets,
            'web.config 가 코어 업데이트 대상이 아니다 — 업데이트해도 IIS 규칙이 옛 판본으로 남는다'
        );

        $this->assertNotContains(
            self::IIS_CONFIG,
            $protected,
            'web.config 가 보호 경로에 있다 — 업데이트가 로그 없이 이 파일을 건너뛴다'
        );
    }

    /**
     * 서버 설정 파일이 릴리즈 페이로드에서 제외되지 않는다.
     *
     * 「저장소에 있다」만 재면 부족하다 — 배포본에 없으면 운영자가 붙여넣을 조각 자체가
     * 손에 들어오지 않는다. 제외 경로는 공개 저장소 필터와 `git archive` 의 `export-ignore`
     * (`.gitattributes`) 두 곳이 정한다. 이 테스트는 **배포본 안에서도 성립하는 후자**만
     * 잰다 — 필터 자신은 배포본에 실리지 않는 내부 도구라, 공개 테스트가 그 파일을 읽으면
     * 배포본에서는 원리상 성립할 수 없는 검사가 되고 존재 가드는 그 상태를 통과와 구분하지
     * 못한다. 그 축은 필터 옆의 내부 회귀 테스트가 같은 파일 목록으로 잰다.
     *
     * @effects server_configs_are_shipped_in_the_release_payload
     *
     * @return void 반환값 없음
     */
    public function test_server_configs_are_not_excluded_from_the_release_payload(): void
    {
        $shipped = [self::IIS_CONFIG, self::NGINX_FRAGMENT, self::CADDY_FRAGMENT];

        $exportIgnorePatterns = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents(base_path('.gitattributes'))) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, 'export-ignore')) {
                continue;
            }
            if (str_contains($line, '-export-ignore')) {
                continue;
            }
            $pattern = (string) (preg_split('/\s+/', $line)[0] ?? '');
            if ($pattern !== '') {
                $exportIgnorePatterns[] = ltrim($pattern, '/');
            }
        }

        foreach ($shipped as $relative) {
            // 최상위 구간이 통째로 제외되면 그 아래 파일도 함께 빠진다 (`deploy` 등).
            $top = explode('/', $relative)[0];

            foreach ($exportIgnorePatterns as $pattern) {
                $this->assertFalse(
                    fnmatch($pattern, $relative) || fnmatch($pattern, $top),
                    $relative.' 가 export-ignore 패턴 `'.$pattern.'` 에 걸린다 — git archive 산출물에서 빠진다'
                );
            }
        }
    }

    /**
     * 서버 설정 파일이 자기 검증 상태를 밝힌다.
     *
     * IIS·Caddy 축은 실측 환경이 없어 정적 검토까지만 확인됐다. 그 사실이 파일에 적혀
     * 있지 않으면 다음 사람이 「검증된 설정」으로 읽고 그대로 운영에 올린다.
     *
     * @effects unverified_axes_are_declared_not_narrated
     *
     * @return void 반환값 없음
     */
    public function test_unverified_server_configs_declare_their_verification_status(): void
    {
        $measured = 0;

        foreach ([self::IIS_CONFIG, self::CADDY_FRAGMENT] as $relative) {
            $body = (string) file_get_contents(base_path($relative));

            // 판정은 **선언 토큰**으로 한다. 종전에는 한국어 산문 한 문장
            // (`실측을 거치지 않았다`)을 대조했는데, 그러면 재는 대상이 사실이 아니라
            // 문구다 — 설명을 다듬기만 해도 red 가 나고, 반대로 실측을 마치고 그 문장을
            // 지우지 않으면 초록이다. 토큰은 그 자체가 선언이라 지우는 행위가 곧
            // 「실측을 마쳤다」는 뜻이 된다.
            $this->assertMatchesRegularExpression(
                '/G7-VERIFICATION-STATUS:\s*UNVERIFIED/',
                $body,
                $relative.' 에 검증 상태 선언 토큰(`G7-VERIFICATION-STATUS: UNVERIFIED`)이 없다 — '
                .'읽는 사람이 검증된 설정으로 오해한다. 실측을 마쳤다면 이 검사의 대상 목록에서 빼라'
            );

            // 문장만 남기고 **확인 목록을 지워도** 초록이면, 「직접 재라」는 안내가 잴
            // 대상 없이 남는다. 목록이 실제로 있는지까지 본다 — 형제(Apache·nginx)는
            // 실측했으므로 이 둘만의 의무다.
            $this->assertStringContainsString('확인 목록', $body, $relative.' 에 확인 목록이 없다');

            // 정족수는 **확인 목록 안에서만** 센다. 종전에는 파일 전체의 `·` 를 세서,
            // 목록 밖의 항목(대응표·주 설명의 글머리)이 정족수를 채웠다 — web.config 는
            // 13개 중 4개가, Caddy 조각은 10개 중 4개가 목록 밖이라, Caddy 확인 목록 6개
            // 중 4개를 지워도 초록이었다.
            $this->assertGreaterThanOrEqual(
                5,
                preg_match_all('/^\s*(?:#\s*)?·/mu', $this->verificationChecklistSection($body)),
                $relative.' 의 확인 목록 항목이 5개 미만이다 — 처음 올리는 사람이 무엇을 재야 하는지 알 수 없다'
            );

            $measured++;
        }

        // 모집단 대조 — 형제 축 넷(`:465`·`:528`·`:1110`·`:1319`)은 이미 갖고 있는데 이
        // 축만 없었다. 배열이 리터럴이라 원소를 지우면 그 서버의 의무가 조용히 사라진다.
        $this->assertSame(2, $measured, '미검증 선언 축의 모집단이 둘이 아니다');
    }

    /**
     * Caddy 조각이 **`public/` 안 점 파일**의 한계를 스스로 고지한다.
     *
     * 조각의 주석은 「(7) 의 `root` 가 `public/` 이라 프로젝트 루트의 `.env` 는 조회 대상에
     * 없다」고 적는데, 그것은 **프로젝트 루트 축**만이다. `public/` 안에도 점 파일이 있다
     * (어느 설치본에나 있는 `public/.htaccess`, 설치 상태에 따라 생기는 `public/storage/.gitignore` 등) — `@g7_hidden` 의 `respond` 가
     * 지시어 고정 순서에서 (7) 의 `handle` 뒤로 밀리면 `file_server` 가 그 파일을 그대로
     * 낸다. 형제 Apache 는 `RedirectMatch` 가 경로 전 구간을 보고, nginx 는 `location ~ /\.`
     * 이 먼저 잡아 같은 요청을 막는다. 규칙은 프로젝트 선례(미실측 서버는 문서만 넓힌다)에 따라
     * 바꾸지 않으므로, 한계와 조치(`file_server { hide .* }`)가 조각에 적혀 있는가를 잰다.
     *
     * 판정은 형제 축(`G7-VERIFICATION-STATUS`)과 같이 **선언 토큰**으로 한다 — 산문을 재면
     * 문구를 다듬을 때 red 가 나고, 반대로 한계를 닫은 뒤 산문을 지우지 않으면 초록이다.
     *
     * @scenario server_parity=caddy, protection=public_dotfiles
     *
     * @effects caddy_fragment_declares_public_dotfile_limit
     *
     * @return void 반환값 없음
     */
    public function test_caddy_fragment_declares_public_dotfile_limitation(): void
    {
        $body = (string) file_get_contents(base_path(self::CADDY_FRAGMENT));

        $this->assertMatchesRegularExpression(
            '/G7-KNOWN-LIMIT:\s*PUBLIC_DOTFILES/',
            $body,
            self::CADDY_FRAGMENT.' 에 `public/` 안 점 파일 한계 선언 토큰(`G7-KNOWN-LIMIT: PUBLIC_DOTFILES`)이 없다 — '
            .'「root 가 public/ 이라 새지 않는다」는 서술이 프로젝트 루트 축만 말한다는 사실을 읽는 사람이 알 수 없다'
        );

        // 한계만 적고 **조치**를 적지 않으면 운영자는 무엇을 넣어야 하는지 모른다.
        $this->assertStringContainsString(
            'hide .*',
            $body,
            self::CADDY_FRAGMENT.' 이 `file_server { hide .* }` 조치를 고지하지 않는다'
        );

        // 확인 목록에도 그 주소가 있어야 한다 — 첫 배포에서 직접 잴 항목이다. 잴 주소는
        // **어느 설치본에나 실재하는** 점 파일이어야 한다. 없는 파일을 재면 막혔든 안
        // 막혔든 404 라 판별이 되지 않는다 — 종전 목록의 `/build/.vite/manifest.json` 이
        // 그랬다(laravel-vite-plugin 은 manifest 를 `public/build/manifest.json` 에 쓴다).
        $checklist = $this->verificationChecklistSection($body);
        foreach (['/.htaccess' => 'public/.htaccess'] as $needle => $source) {
            $this->assertStringContainsString(
                $needle,
                $checklist,
                self::CADDY_FRAGMENT.' 확인 목록에 `'.$needle.'` 이 없다 — 한계를 적고도 잴 항목을 주지 않는다'
            );
            $this->assertFileExists(
                base_path($source),
                '확인 목록의 `'.$needle.'` 은 배포물에 실재하는 `'.$source.'` 을 가리켜야 한다 — 없는 파일은 차단 판별에 쓸 수 없다'
            );
        }
        $this->assertStringNotContainsString(
            '/.vite/',
            $checklist,
            self::CADDY_FRAGMENT.' 확인 목록이 빌드 산출물에 없는 `.vite/` 경로를 잰다 — 항상 404 라 차단 판별이 되지 않는다'
        );
    }

    /**
     * 「확인 목록」 절만 잘라 돌려줍니다 (헤더 ~ 다음 구분선).
     *
     * @param  string  $body  설정 파일 원문
     * @return string 확인 목록 절
     */
    private function verificationChecklistSection(string $body): string
    {
        $lines = preg_split('~\r\n|\r|\n~', $body) ?: [];
        $collected = [];
        $inside = false;

        foreach ($lines as $line) {
            // 절의 시작은 **머리글 줄** 이다 — 본문 산문이 「아래 「확인 목록」을 …」처럼
            // 그 낱말을 인용하므로, 첫 등장으로 자르면 엉뚱한 자리에서 시작한다.
            if (! $inside) {
                $inside = preg_match('/^\s*(?:#\s*)?확인 목록/u', $line) === 1;

                continue;
            }

            if (str_contains($line, '──')) {
                break;
            }

            $collected[] = $line;
        }

        $this->assertTrue($inside, '확인 목록 절의 머리글을 찾지 못했다 — 이 정족수가 아무것도 재지 않는다');

        return implode("\n", $collected);
    }

    /**
     * 역방향 패리티를 **규칙 단위**로 잰다.
     *
     * 형제 역방향 축 셋(`test_no_server_config_blocks_a_directory_outside_the_derived_list`
     * 등)은 전부 **도출 목록의 원소**만 대조한다. 그래서 「이 서버에만 있는 **규칙**」은
     * 어느 게이트에도 모집단이 없었다 — nginx 의 `(3-1)`(설치 마법사 디렉토리 정규화)을
     * 통째로 지워도 전 검사가 초록이었고, 그 결과는 그 파일이 스스로 적어 둔 것이다:
     * 위임 뒤 경로가 `Location: /public/install/` 로 공표된다.
     *
     * 규칙 텍스트를 대응표 문자열과 직접 맞추는 것은 표기에 묶여 깨지기 쉽다. 대신
     * **모든 규칙이 번호가 붙은 설명을 갖는가**를 잰다 — 두 조각 다 이미 `(N)` 으로
     * 규칙을 번호 매기고 있고, 번호 없는 규칙이 생기면 그것이 곧 「아무도 설명하지 않은
     * 규칙」이다. 대응표의 역방향 행은 그 번호를 인용하므로 사슬이 이어진다.
     *
     * @scenario server_parity=rule_level_reverse, servers=nginx+caddy
     *
     * @effects every_server_rule_is_accounted_for_in_its_correspondence_table
     *
     * @return void 반환값 없음
     */
    public function test_every_server_rule_carries_a_numbered_explanation(): void
    {
        // 규칙을 여는 구문 — 이 줄 **위쪽 주석 블록**에 `(N)` 이 있어야 한다.
        $ruleOpeners = [
            self::NGINX_FRAGMENT => '/^\s*location\s+\S/',
            self::CADDY_FRAGMENT => '/^\s*(handle\b|redir\s+@|respond\s+@)/',
        ];

        $measured = 0;

        foreach ($ruleOpeners as $file => $opener) {
            $lines = preg_split('~\r\n|\r|\n~', (string) file_get_contents(base_path($file))) ?: [];

            $unexplained = [];
            $declared = [];
            $seen = 0;

            foreach ($lines as $index => $line) {
                if (preg_match($opener, $line) !== 1) {
                    continue;
                }

                // 앞줄도 규칙 여는 줄이면 **같은 규칙의 둘째 줄**이다 — nginx (3-1) 이
                // `location = /install` 과 `location = /install/` 두 줄로 한 규칙을 이룬다.
                if ($index > 0 && preg_match($opener, $lines[$index - 1]) === 1) {
                    continue;
                }

                $seen++;

                $numbers = $this->explanationNumbersAbove($lines, $index, $opener);

                if ($numbers === null) {
                    $unexplained[] = ($index + 1).': '.trim($line);

                    continue;
                }

                $declared = array_merge($declared, $numbers);
            }

            $this->assertGreaterThan(
                3,
                $seen,
                $file.' 에서 규칙을 3개 이하로 찾았다 — 도출식이 그 문법을 모른다'
            );

            $this->assertSame(
                [],
                $unexplained,
                $file.' 에 번호 붙은 설명이 없는 규칙이 있다 — 대응표가 그 규칙을 설명하지 않으므로 '
                .'지워도 어느 검사도 red 가 되지 않는다'
            );

            // **대응표가 인용한 번호가 실재하는가.**
            //
            // 위 두 단언은 「남아 있는 규칙마다 번호가 있는가」만 본다. 그래서 규칙을
            // 통째로 지우면 `$seen` 이 줄고 `$unexplained` 는 빈 채라 초록이었다 — 이
            // 검사의 독블록이 스스로 인용한 실패 모드(nginx `(3-1)` 삭제)가 바로 그
            // 형태다. 대응표는 그 번호를 인용하므로, 인용된 번호가 실재하는지를 대조하면
            // 사슬이 닫힌다.
            $missing = array_values(array_diff($this->citedRuleNumbers($lines, $opener), array_unique($declared)));

            $this->assertSame(
                [],
                $missing,
                $file.' 의 대응표가 인용한 규칙 번호('.implode(', ', $missing).')가 실재하지 않는다 — '
                .'그 규칙이 사라졌는데 표만 남았거나, 번호가 갈렸다. 어느 쪽이든 그 규칙은 '
                .'지금 어느 검사도 재지 않는다'
            );

            $measured++;
        }

        $this->assertSame(2, $measured, '규칙 단위 역방향 축의 모집단이 둘이 아니다');
    }

    /**
     * 규칙 여는 줄 **위**의 설명 주석에서 `(N)` 을 모읍니다.
     *
     * 종전 판정은 비-주석 줄을 만나면 `continue` 로 계속 거슬러 올라갔다. 그래서 빈 줄
     * 없이 앞 규칙 본문 뒤에 새 규칙을 끼우면 **앞 규칙의 번호를 상속**해 초록이었다 —
     * 재는 것이 「설명이 있는가」가 아니라 「앞에 빈 줄을 두었는가」였다.
     *
     * 이제 위로 갈 수 있는 줄은 **같은 규칙에 속하는 것**뿐이다:
     *  · 닫는 중괄호로 끝나는 줄은 그 블록의 헤더로 건너뛴다. 헤더가 **규칙 여는 줄**이면
     *    거기서 앞 규칙이 끝난 것이므로 멈춘다(상속 차단). 헤더가 그 밖이면 같은 규칙의
     *    중첩 블록이다 — Caddy 의 `@g7_hidden { … }` 매처 블록이 그 자리다.
     *  · matcher 선언(`@name path_regexp …`) 같은 한 줄은 같은 규칙의 재료라 지나간다.
     *  · 빈 줄을 만나면 설명이 없는 것이다.
     *
     * @param  list<string>  $lines  파일 줄 목록
     * @param  int  $index  규칙 여는 줄 번호(0-기준)
     * @param  string  $opener  규칙 여는 줄 판정식
     * @return list<string>|null 설명 주석이 담은 번호 목록 (설명이 없으면 null)
     */
    private function explanationNumbersAbove(array $lines, int $index, string $opener): ?array
    {
        for ($back = $index - 1; $back >= 0; $back--) {
            $above = trim($lines[$back]);

            if ($above === '') {
                return null;
            }

            if (str_starts_with($above, '#')) {
                $numbers = [];

                for ($run = $back; $run >= 0; $run--) {
                    $line = trim($lines[$run]);

                    if ($line === '' || ! str_starts_with($line, '#')) {
                        break;
                    }

                    if (preg_match_all('/\((\d+(?:-\d+)?)\)/', $line, $found) > 0) {
                        $numbers = array_merge($numbers, $found[1]);
                    }
                }

                return $numbers === [] ? null : array_values(array_unique($numbers));
            }

            if (preg_match($opener, $lines[$back]) === 1) {
                return null;   // 앞 규칙의 여는 줄 — 그 번호를 상속하지 않는다
            }

            if (str_ends_with($above, '}')) {
                $header = $this->blockHeaderLine($lines, $back);

                if ($header === null || preg_match($opener, $lines[$header]) === 1) {
                    return null;   // 앞 규칙이 거기서 끝났다
                }

                $back = $header;   // 같은 규칙의 중첩 블록 — 그 헤더 위로 계속
            }
        }

        return null;
    }

    /**
     * 닫는 중괄호로 끝나는 줄에 대응하는 **여는 줄** 번호.
     *
     * @param  list<string>  $lines  파일 줄 목록
     * @param  int  $closeAt  닫는 줄 번호(0-기준)
     * @return int|null 여는 줄 번호 (짝이 없으면 null)
     */
    private function blockHeaderLine(array $lines, int $closeAt): ?int
    {
        $depth = 0;

        for ($i = $closeAt; $i >= 0; $i--) {
            $line = $lines[$i];

            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            $depth += substr_count($line, '}') - substr_count($line, '{');

            if ($depth <= 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * 파일 머리말의 대응표가 **인용한** 규칙 번호.
     *
     * 두 조각의 표기가 다르므로 각각에서 도출한다:
     *  · nginx 「이 조각만 갖는 규칙」 표는 첫 칸이 그 규칙의 번호다 (`# | (3-1) …`).
     *  · Caddy 대응표는 `→` **뒤**가 이 파일의 규칙이다 (앞쪽 번호는 형제 서버의 것이라
     *    `nginx (3-1)` 처럼 남의 번호를 인용하는 행이 있다).
     *
     * @param  list<string>  $lines  파일 줄 목록
     * @param  string  $opener  규칙 여는 줄 판정식 (머리말의 끝을 정한다)
     * @return list<string> 인용된 번호 목록
     */
    private function citedRuleNumbers(array $lines, string $opener): array
    {
        $cited = [];

        foreach ($lines as $index => $line) {
            if (preg_match($opener, $line) === 1) {
                break;   // 머리말은 첫 규칙 앞까지다
            }

            $trimmed = trim($line);

            if (! str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^#\s*\|\s*\((\d+(?:-\d+)?)\)/', $trimmed, $row) === 1) {
                $cited[] = $row[1];

                continue;
            }

            $arrowAt = mb_strpos($trimmed, '→');

            if ($arrowAt !== false && preg_match('/\((\d+(?:-\d+)?)\)/', mb_substr($trimmed, (int) $arrowAt), $after) === 1) {
                $cited[] = $after[1];
            }
        }

        return array_values(array_unique($cited));
    }

    /**
     * 세 조각 머리말의 「루트 `.htaccess` 와의 대응」 표 구간(줄 목록)을 돌려줍니다.
     *
     * 시작은 그 머리글 줄, 끝은 다음 구분선(`──`)·「주 —」 절·XML 주석 종료(`-->`) 중
     * 먼저 오는 것이다.
     *
     * @param  string  $relative  조각 경로
     * @return list<string> 표 구간의 줄 (주석 표식·선행 공백 제거)
     */
    private function correspondenceTableLines(string $relative): array
    {
        $lines = preg_split('~\r\n|\r|\n~', (string) file_get_contents(base_path($relative))) ?: [];
        $collected = [];
        $inside = false;

        foreach ($lines as $line) {
            $trimmed = preg_replace('/^\s*#?\s?/', '', $line) ?? '';

            if (! $inside) {
                $inside = str_contains($trimmed, '루트 `.htaccess` 와의 대응');

                continue;
            }

            if (preg_match('/^\s*(?:──|주 —)|-->/u', $trimmed) === 1) {
                break;
            }

            $collected[] = $trimmed;
        }

        $this->assertTrue($inside, $relative.' 에 「루트 `.htaccess` 와의 대응」 표가 없다 — 이 대조가 아무것도 재지 않는다');
        $this->assertNotSame([], $collected, $relative.' 의 대응표가 비어 있다');

        return $collected;
    }

    /**
     * 대응표에서 **루트 `.htaccess` 쪽을 인용하는** 토큰(지시어·환경 표식)을 모읍니다.
     *
     * 인용 단위는 **행**(nginx 는 `| 왼쪽 | 오른쪽 |` 의 첫 칸, 나머지는 `→` 앞)과 그 행에
     * 딸린 괄호 보충(`(Apache 판본은 …)`)이다. 표 아래 산문은 대상이 아니다 — 산문은
     * 폐기된 지시어를 **역사로** 언급하므로(「종전의 `DirectorySlash Off` 는 … 폐기했다」)
     * 거기까지 대조하면 사실인 서술이 거짓 red 가 된다. 반대로 행이 그 지시어를 적으면
     * 그것은 「지금 루트가 이것을 쓴다」는 인용이라 실재해야 한다.
     *
     * 토큰은 두 형태다 — Apache 지시어/태그 이름(두 마디 이상의 CamelCase: `RedirectMatch` ·
     * `IndexIgnore` · `FilesMatch`)과 환경 표식(`G7_ROUTE` · `G7_ROOTDIR`).
     *
     * @param  list<string>  $tableLines  표 구간 줄
     * @return array<string, true> 토큰 집합
     */
    private function citedRootHtaccessTokens(array $tableLines): array
    {
        $tokens = [];

        foreach ($tableLines as $line) {
            // 표 행은 `|` 로 **시작**한다 — 「`|` 를 포함한다」로 가르면 정규식 안의 대안
            // 기호(`(^|/)`)가 셀 구분자로 읽혀 인용이 반으로 잘린다.
            if (str_starts_with(trim($line), '|')) {
                $cells = array_map('trim', explode('|', $line));
                $left = $cells[1] ?? '';

                if ($left === '' || preg_match('/^-+$/', $left) === 1) {
                    continue;
                }
            } elseif (($arrow = mb_strpos($line, '→')) !== false) {
                $left = mb_substr($line, 0, (int) $arrow);
            } elseif (preg_match('/^\s*\(/u', $line) === 1) {
                $left = $line;   // 행에 딸린 괄호 보충
            } else {
                continue;   // 산문
            }

            preg_match_all('/`([^`]+)`/', $left, $quoted);

            foreach ($quoted[1] as $fragment) {
                // 단일 단어 지시어(`Header`·`Deny` …)도 잡는다. 종전 패턴은 CamelCase
                // **2단어 이상**만 봐서, 도출 쪽(`rootHtaccessRuleAxes`)이 축으로 세운
                // 단일 단어 지시어는 대응표에 적어도 인식되지 않았다 — 그 축은 어느 표에
                // 무엇을 적든 영원히 red 라, 실제로는 「추가할 수 없는 축」이었다.
                // 백틱 안(코드 인용)만 보므로 산문 단어가 섞이지 않고, 실재하지 않는
                // 토큰은 정방향 검사가 루트 원문과 대조해 걸러낸다.
                preg_match_all('/\b(?:[A-Z][a-z]+(?:[A-Z][a-z]+)*|G7_[A-Z_]+)\b/', $fragment, $m);

                foreach ($m[0] as $token) {
                    $tokens[$token] = true;
                }
            }
        }

        return $tokens;
    }

    /**
     * 루트 `.htaccess` 마커 블록의 **규칙 축**을 도출합니다 — 규칙을 여는 지시어 이름과
     * 위임이 싣는 환경 표식.
     *
     * 구조 지시어(`IfModule`·`RewriteEngine`), 조건(`RewriteCond`), 효과(`Order`·`Deny`)는
     * 축이 아니다 — 그것들이 속한 규칙은 그 규칙을 여는 지시어(`RewriteRule`·`FilesMatch`)로
     * 인용된다. 손으로 유지하는 목록은 이 제외 다섯뿐이고, 새 지시어가 블록에 들어오면
     * 그것은 자동으로 축이 되어 세 표에 행을 요구한다.
     *
     * @return array{directives: array<string, true>, markers: array<string, true>} 규칙을 여는 지시어 이름과 위임 환경 표식 집합
     */
    private function rootHtaccessRuleAxes(): array
    {
        $raw = (string) file_get_contents(base_path('.htaccess'));

        // 마커는 **줄 단위**로 찾는다 — 블록 안 주석이 마커 문자열을 언급하므로(`이 블록
        // (# BEGIN G7 ~ # END G7)은 …`) `strpos` 첫 일치는 그 언급을 END 로 읽어 블록이
        // 머리말 아홉 줄로 잘린다. 갱신기(`RootHtaccessUpdater::markerLineStart`)와 같은 판정이다.
        $this->assertSame(1, preg_match('~^# BEGIN G7[ \t]*$~m', $raw, $b, PREG_OFFSET_CAPTURE), '루트 .htaccess 에 BEGIN 마커 줄이 없다');
        $this->assertSame(1, preg_match('~^# END G7[ \t]*$~m', $raw, $e, PREG_OFFSET_CAPTURE), '루트 .htaccess 에 END 마커 줄이 없다');

        $block = substr($raw, (int) $b[0][1], (int) $e[0][1] - (int) $b[0][1]);

        $structural = ['IfModule', 'RewriteEngine', 'RewriteCond', 'Order', 'Deny'];
        $directives = [];
        $markers = [];

        foreach (preg_split('~\r\n|\r|\n~', $block) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^<?\/?([A-Z][A-Za-z]+)\b/', $trimmed, $m) === 1 && ! in_array($m[1], $structural, true)) {
                $directives[$m[1]] = true;
            }

            if (preg_match_all('/\bE=(G7_[A-Z_]+):/', $trimmed, $found) > 0) {
                foreach ($found[1] as $marker) {
                    $markers[$marker] = true;
                }
            }
        }

        // 도출 하한 — 비면 아래 대조가 0회 돌고도 초록이다. 실측값(규칙 지시어 4 · 표식 2).
        $this->assertGreaterThanOrEqual(4, count($directives), '루트 .htaccess 규칙 축 도출이 4개 미만이다 — 도출식이 블록 형태를 모른다');
        $this->assertGreaterThanOrEqual(2, count($markers), '루트 .htaccess 환경 표식 도출이 2개 미만이다');

        return ['directives' => $directives, 'markers' => $markers];
    }

    /**
     * 세 대응표가 인용하는 루트 `.htaccess` 지시어·표식은 **실제로 그 파일에 있다**.
     *
     * 대응표는 「루트가 이것을 쓴다 → 이 파일은 이렇게 한다」는 선언이다. 루트가 그 지시어를
     * 폐기했는데 표가 그대로 남으면, 그 표는 존재하지 않는 규칙에 대한 대응을 서술하는
     * 것이고 읽는 사람은 루트에 그 규칙이 있다고 믿는다. 실물이 그 상태였다 — 루트가
     * `DirectorySlash Off` 를 폐기하고 `-d` 위임 + `G7_ROOTDIR` 표식으로 바꾼 뒤 nginx·Caddy
     * 표는 따라왔는데 `web.config` 표만 폐기된 지시어를 인용한 채였다 (G3 회차 14 W4-1).
     *
     * @scenario server_parity=correspondence_table, direction=forward
     *
     * @effects correspondence_tables_cite_only_live_root_htaccess_rules
     *
     * @return void 반환값 없음
     */
    public function test_correspondence_tables_cite_only_directives_that_exist_in_the_root_htaccess(): void
    {
        $rootBody = $this->directiveBody('.htaccess');
        $measured = 0;

        foreach ([self::IIS_CONFIG, self::NGINX_FRAGMENT, self::CADDY_FRAGMENT] as $file) {
            $tokens = $this->citedRootHtaccessTokens($this->correspondenceTableLines($file));

            $this->assertNotSame([], $tokens, $file.' 의 대응표에서 루트 .htaccess 인용 토큰을 하나도 뽑지 못했다');

            foreach (array_keys($tokens) as $token) {
                $pattern = str_starts_with($token, 'G7_')
                    ? '/\bE='.preg_quote($token, '/').':/'
                    : '/\b'.preg_quote($token, '/').'\b/';

                $this->assertMatchesRegularExpression(
                    $pattern,
                    $rootBody,
                    $file.' 의 대응표가 루트 .htaccess 에 없는 `'.$token.'` 을 인용한다 — 폐기된 규칙에 대한 '
                    .'대응을 서술하는 표는 읽는 사람에게 루트에 그 규칙이 있다고 믿게 한다'
                );
            }

            $measured++;
        }

        $this->assertSame(3, $measured, '대응표 정방향 축의 모집단이 셋이 아니다');
    }

    /**
     * 루트 `.htaccess` 의 규칙 축은 **세 대응표 전부**에 행으로 있다.
     *
     * 정방향(위)은 「표가 적은 것이 실재하는가」이고, 이 검사는 「실재하는 것이 표에 있는가」다.
     * 한 방향만 재면 루트에 규칙이 하나 늘어도 어느 표도 red 가 되지 않고, 그 서버는 그
     * 규칙에 대한 대응(또는 「대응물 없음」 선언)을 영영 갖지 않는다. 실물이 그 상태였다 —
     * `G7_ROOTDIR` 표식 행이 nginx·Caddy 에는 있고 `web.config` 에는 없었다.
     *
     * @scenario server_parity=correspondence_table, direction=reverse
     *
     * @effects every_root_htaccess_rule_axis_has_a_correspondence_row_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_every_root_htaccess_rule_axis_has_a_row_in_every_correspondence_table(): void
    {
        $axes = $this->rootHtaccessRuleAxes();
        $measured = 0;

        foreach ([self::IIS_CONFIG, self::NGINX_FRAGMENT, self::CADDY_FRAGMENT] as $file) {
            $cited = $this->citedRootHtaccessTokens($this->correspondenceTableLines($file));

            foreach (array_keys($axes['directives']) as $directive) {
                $this->assertArrayHasKey(
                    $directive,
                    $cited,
                    $file.' 의 대응표에 루트 .htaccess 의 `'.$directive.'` 규칙 행이 없다 — 그 서버는 이 규칙의 '
                    .'대응(또는 대응물 없음 선언)을 갖지 않는다'
                );
            }

            foreach (array_keys($axes['markers']) as $marker) {
                $this->assertArrayHasKey(
                    $marker,
                    $cited,
                    $file.' 의 대응표에 루트 .htaccess 의 `'.$marker.'` 표식 행이 없다 — 그 표식이 왜 필요하고 '
                    .'이 서버에 무엇이 대응하는지(또는 왜 없어도 되는지)를 아무도 적지 않았다'
                );
            }

            $measured++;
        }

        $this->assertSame(3, $measured, '대응표 역방향 축의 모집단이 셋이 아니다');
    }

    /**
     * 디렉토리 목록 차단의 **응답 코드 패리티**를 네 파일 전부가 선언한다.
     *
     * 루트와 `public/` 에 같은 이름의 디렉토리(`storage`)가 있다. 그 주소에 Apache 는
     * `IndexIgnore *` 로 답한다 — 서버 `Options` 에 `Indexes` 가 없으면 403 이고(P0 5마운트
     * 실측 전부 — vhost 가 `Options -Indexes` 였다), 켜져 있으면 **빈 목록 200**(파일명
     * 노출 0)이다. nginx·Caddy·IIS 는 403·404 다. 파일명 노출은 어느 쪽도 0 이라 보호는
     * 동등한데 응답 코드가 갈리며, 루트 `.htaccess` 가 `Options -Indexes` 를 두지 못하는
     * 이유(`AllowOverride` 등급)가 그 차이의 원천이다. 적혀 있지 않으면 첫 실측에서
     * 200 을 본 사람이 「목록이 열렸다」로, 403 을 본 사람이 「Apache 와 갈렸다」로 읽는다
     * (G3 회차 14 W4-5).
     *
     * 판정은 형제 축과 같이 **선언 토큰**으로 한다.
     *
     * @scenario server_parity=autoindex, protection=directory_listing_response_code
     *
     * @effects autoindex_response_code_parity_is_declared_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_autoindex_response_parity_is_declared_on_every_server_config(): void
    {
        $measured = 0;

        foreach (['.htaccess', self::IIS_CONFIG, self::NGINX_FRAGMENT, self::CADDY_FRAGMENT] as $file) {
            $body = (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/G7-PARITY-NOTE:\s*AUTOINDEX_EMPTY_LISTING/',
                $body,
                $file.' 에 디렉토리 목록 응답 코드 패리티 선언 토큰(`G7-PARITY-NOTE: AUTOINDEX_EMPTY_LISTING`)이 없다 — '
                .'Apache(Indexes 켜진 서버)의 빈 목록 200 과 형제 셋의 403·404 가 갈린다는 사실을 아무도 적지 않았다'
            );

            $measured++;
        }

        $this->assertSame(4, $measured, '목록 응답 패리티 선언 축의 모집단이 넷이 아니다');
    }

    /**
     * nginx 조각의 **모든 응답 생산 `location`** 이 `autoindex off` 와 `nosniff` 를 갖는다.
     *
     * 형제 셋은 이 둘을 **무조건** 건다 — Apache 는 디렉토리 단위 `.htaccess`(`IndexIgnore *`
     * + `Header always set`)라 그 아래 전 경로에, IIS 는 `system.webServer` 전역에, Caddy 는
     * 사이트 블록 전역에. nginx 만 `location` 단위이고, 그 지시어들은 **상속되지 않는다** —
     * `add_header` 는 상위 것을 대체하고, `autoindex` 는 블록마다 따로 켜고 끈다.
     *
     * 종전 형제 검사들은 「그 토큰이 파일 어딘가에 있는가」만 재서, 한 `location` 에만 있고
     * 나머지가 운영자 블록을 상속하는 상태가 초록이었다. 그러면 같은 사이트가 어느 주소로
     * 들어오느냐에 따라 보호 강도가 갈리는데, 그 차이는 오류가 아니라 조용한 동작 차이로만
     * 나타난다.
     *
     * 모집단은 리터럴로 세지 않고 **조각에서 도출**한다 — location 이 하나 늘면 이 검사가
     * red 가 돼 사람이 그 자리에 같은 줄을 옮긴다.
     *
     * @scenario server_parity=nosniff, servers=apache+nginx+caddy+iis
     *
     * @effects nginx_every_response_location_declares_autoindex_and_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_nginx_every_response_producing_location_declares_autoindex_off_and_nosniff(): void
    {
        $blocks = $this->nginxLocationBlocks();

        $this->assertGreaterThanOrEqual(
            6,
            count($blocks),
            'nginx location 블록 도출이 깨졌다 — 모집단이 비면 이 검사는 아무것도 재지 않는다'
        );

        $measured = 0;

        foreach ($blocks as $selector => $body) {
            // 대상에서 빠지는 것은 **응답을 내지 않는** 블록뿐이다 — `rewrite … last` 는 그
            // 자리에서 내부적으로 다른 location 을 다시 타므로 이 블록의 이름으로 나가는 응답이
            // 없다.
            //
            // `deny all`(403)·`return 30x`(301/404)는 **응답이다.** 종전에는 그 둘도 빼고
            // 있었는데, 형제 셋은 그 응답에도 헤더를 싣는다(Apache 는 디렉토리 하위 전 응답 ·
            // IIS 는 사이트 전역 · Caddy 는 그 자리). nginx 만 `add_header` 가 상속되지 않고
            // **대체**되므로, 빼 두면 같은 사이트가 서버에 따라 다른 헤더를 내는 상태를 이
            // 검사가 정당화하게 된다.
            if (preg_match('/\brewrite\s+\^\s+\S+\s+last\s*;/', $body)) {
                continue;
            }

            $measured++;

            $this->assertMatchesRegularExpression(
                '/add_header\s+X-Content-Type-Options\s+"?nosniff"?\s+always\s*;/',
                $body,
                'nginx location `'.$selector.'` 에 nosniff 가 없다 — `add_header` 는 상속되지 않고 대체되므로 '
                .'이 경로로 들어온 요청만 형제 서버들과 보호 강도가 갈린다'
            );

            // 디렉토리 목록은 조회 경로가 **요청 경로 전체**(`$uri`)인 블록에서만 생긴다.
            // FastCGI 로 그대로 넘기는 블록(`try_files` 없음)과, 캡처로 **특정 파일**을
            // 지목하는 블록(`try_files /public/$1` — `$1` 이 `.php` 로 끝난다)은 디렉토리에
            // 닿을 수 없으므로 그 축이 없다.
            if (! preg_match('/\btry_files\s+\S*\$uri\b/', $body)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\bautoindex\s+off\s*;/',
                $body,
                'nginx location `'.$selector.'` 에 `autoindex off;` 가 없다 — 이 블록은 디렉토리를 조회하므로 '
                .'운영자가 상위에서 `autoindex on` 을 켜 두면 여기서만 목록이 열린다'
            );
        }

        $this->assertGreaterThanOrEqual(
            5,
            $measured,
            '응답 생산 location 이 다섯 미만으로 잡혔다 — 제외 조건이 너무 넓어 축이 비었다'
        );
    }

    /**
     * 형제 대응물이 없는 규칙(역방향)은 **세 조각 모두** 자기 파일에서 선언한다.
     *
     * 종전에는 역방향 절이 nginx 조각에만 있었다. 그러면 IIS 고유(`denyUrlSequences`)·
     * Caddy 고유(`encode gzip`)가 어느 대조에도 잡히지 않아, 늘리거나 지워도 아무것도
     * 울지 않는다 — 「형제와 같은 것을 막는가」는 재면서 「나만 갖는 것이 무엇인가」는
     * 아무도 재지 않는 상태였다.
     *
     * @scenario server_parity=reverse_direction, servers=nginx+caddy+iis
     *
     * @effects every_server_fragment_declares_its_reverse_only_rules
     *
     * @return void 반환값 없음
     */
    public function test_every_non_apache_config_declares_its_reverse_only_rules(): void
    {
        $measured = 0;

        foreach ([self::IIS_CONFIG, self::NGINX_FRAGMENT, self::CADDY_FRAGMENT] as $file) {
            $body = (string) file_get_contents(base_path($file));

            $this->assertMatchesRegularExpression(
                '/G7-REVERSE-ONLY:\s*\S+/',
                $body,
                $file.' 에 역방향 선언 토큰(`G7-REVERSE-ONLY:`)이 없다 — 이 파일만 갖는 규칙이 '
                .'어느 대조에도 잡히지 않아, 늘리거나 지워도 아무것도 울지 않는다'
            );

            $measured++;
        }

        $this->assertSame(3, $measured, '역방향 선언 축의 모집단이 셋이 아니다');
    }

    /**
     * IIS `denyUrlSequences` 의 항목 집합은 **역방향 선언이 SSoT** 다.
     *
     * 이 목록은 형제 셋에 대응물이 없다(경로 순회를 서버가 정규화 단계에서 처리한다).
     * 그래서 「형제 목록에서 도출」할 수 없고, 종전 역방향 대조는 `fileExtension` 과
     * `segment` 만 보고 이 축을 통째로 지나쳤다. 항목이 하나 늘면 그 문자열이 든 정상
     * 주소가 전부 `404.7` 이 되는데(확장 번들·사이트맵이 사정권) IIS 실측 환경이 없어
     * 응답으로 확인할 수단도 없다 — 그 상태에서 무측정이면 사각이다.
     *
     * @scenario server_parity=reverse_direction, server_kind=iis, surface=request_filtering
     *
     * @effects every_server_fragment_declares_its_reverse_only_rules
     *
     * @return void 반환값 없음
     */
    public function test_iis_deny_url_sequences_match_their_reverse_only_declaration(): void
    {
        $raw = (string) file_get_contents(base_path(self::IIS_CONFIG));

        $this->assertSame(
            1,
            preg_match('/G7-REVERSE-ONLY:\s*deny_url_sequences\s*=\s*(\S+)/', $raw, $declared),
            'web.config 에 `G7-REVERSE-ONLY: deny_url_sequences = …` 선언이 없다 — 이 목록의 SSoT 가 사라졌다'
        );

        $expected = array_values(array_filter(array_map('trim', explode(',', $declared[1]))));
        $this->assertNotSame([], $expected, '역방향 선언이 항목을 하나도 열거하지 않았다');

        preg_match_all('/<add\s+sequence="([^"]+)"/', $this->iisConfigWithoutComments(), $actual);

        $this->assertSame(
            $expected,
            $actual[1],
            'web.config 의 `<denyUrlSequences>` 항목이 역방향 선언과 다르다 — 이 목록에 항목이 늘면 '
            .'그 문자열이 든 정상 주소가 전부 404.7 이 된다. 늘리려면 선언부터 고치고 그 자리에 '
            .'어느 주소가 영향을 받는지 근거를 남긴다'
        );
    }

    /**
     * IIS `fileExtensions` 는 **전 구간**에 걸리므로 제외 목록을 되넣지 않는다.
     *
     * 형제 셋은 같은 목록을 최상위 한 칸에만 앵커해서 건다(`^/[^/]+\.…$`). 이 절만 URL
     * 어느 구간이든 걸리므로 목록이 좁을 수밖에 없고, 무엇을 왜 뺐는지가 web.config 머리말에
     * 적혀 있다. 그런데 종전 역방향 대조는 **멤버십**(IIS 목록 ⊆ 루트 목록)만 봐서, 뺀
     * 항목을 되넣어도 그것이 루트 목록에 있는 한 통과했다 — `.json` 하나로 확장 라우트
     * 전체가, `.js` 하나로 확장 번들이 404.7 이 된다.
     *
     * @scenario server_parity=reverse_direction, server_kind=iis, surface=request_filtering
     *
     * @effects every_server_fragment_declares_its_reverse_only_rules
     *
     * @return void 반환값 없음
     */
    public function test_iis_file_extensions_do_not_readd_the_globally_scoped_exclusions(): void
    {
        $raw = (string) file_get_contents(base_path(self::IIS_CONFIG));

        $this->assertSame(
            1,
            preg_match('/G7-REVERSE-ONLY:\s*excluded_extensions\s*=\s*(\S+)/', $raw, $declared),
            'web.config 에 `G7-REVERSE-ONLY: excluded_extensions = …` 선언이 없다 — 제외 목록의 SSoT 가 사라졌다'
        );

        $excluded = array_values(array_filter(array_map(
            static fn (string $name): string => strtolower(ltrim(trim($name), '.')),
            explode(',', $declared[1])
        )));

        $this->assertNotSame([], $excluded, '제외 선언이 항목을 하나도 열거하지 않았다');

        preg_match_all('/<add\s+fileExtension="\.([^"]+)"/', $this->iisConfigWithoutComments(), $actual);

        $this->assertNotEmpty($actual[1], 'web.config 의 fileExtensions 를 하나도 찾지 못했다 — 이 대조가 아무것도 재지 않는다');

        foreach ($actual[1] as $extension) {
            $this->assertNotContains(
                strtolower($extension),
                $excluded,
                'web.config 이 `.'.$extension.'` 를 다시 막는다 — 이 절은 URL **전 구간**에 걸리므로 '
                .'그 확장자가 든 G7 자신의 주소(확장 라우트·번들·사이트맵·robots)가 전부 404.7 이 된다. '
                .'되넣으려면 IIS 실측이 먼저이고, 제외 선언부터 고친다'
            );
        }
    }

    /**
     * nginx 조각의 `location` 블록을 `선택자 => 본문` 으로 잘라 돌려줍니다.
     *
     * 주석은 걷어내고 중괄호 균형으로 자릅니다 — 목록을 리터럴로 들고 있으면 조각에
     * location 이 하나 늘 때 그 자리가 조용히 모집단 밖에 남습니다.
     *
     * @return array<string, string> 선택자 => 블록 본문
     */
    private function nginxLocationBlocks(): array
    {
        $body = $this->directiveBody(self::NGINX_FRAGMENT);

        $blocks = [];
        $offset = 0;

        while (preg_match('/^location\s+([^{]+?)\s*\{/m', $body, $matched, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $selector = trim((string) $matched[1][0]);
            $open = (int) $matched[0][1] + strlen((string) $matched[0][0]);

            $depth = 1;
            $cursor = $open;
            $length = strlen($body);

            while ($cursor < $length && $depth > 0) {
                if ($body[$cursor] === '{') {
                    $depth++;
                } elseif ($body[$cursor] === '}') {
                    $depth--;
                }
                $cursor++;
            }

            $this->assertSame(0, $depth, 'nginx location `'.$selector.'` 의 중괄호가 닫히지 않았다');

            $blocks[$selector] = substr($body, $open, $cursor - $open - 1);
            $offset = $cursor;
        }

        return $blocks;
    }

    /**
     * **부분 미검증**도 선언 토큰으로 적는다.
     *
     * 토큰의 단위가 「파일」이면 「일부 축만 재지 않았다」를 표현할 수 없다. nginx 조각은
     * 문서 루트 배치는 실측했지만 하위 주소 배치는 재지 않았고, 그 사실이 한국어 산문
     * 한 줄로만 있었다 — D-6 이 없앤 바로 그 형태가 이 파일에는 그대로 남아 있었고,
     * 그 사각의 실물이 「그대로 따라 하면 정적 자산이 전부 미스가 되는」 조정 지시였다.
     *
     * 축 한정 토큰은 `UNVERIFIED(<축>)` 형태다. 형제 둘의 파일 단위 토큰과 같은 접두를
     * 쓰므로 위 검사는 그대로 통과한다.
     *
     * @scenario server_parity=verification_token, granularity=axis
     *
     * @effects unverified_axes_are_declared_not_narrated
     *
     * @return void 반환값 없음
     */
    public function test_partially_unverified_axes_carry_an_axis_scoped_token(): void
    {
        $body = (string) file_get_contents(base_path(self::NGINX_FRAGMENT));

        $this->assertMatchesRegularExpression(
            '/G7-VERIFICATION-STATUS:\s*UNVERIFIED\([a-z0-9-]+\)/',
            $body,
            self::NGINX_FRAGMENT.' 에 축 한정 검증 상태 토큰이 없다 — 이 조각은 문서 루트 배치만 '
            .'실측했고 하위 주소 배치는 재지 않았다. 그 사실을 산문으로만 두면 읽는 검사가 없어 '
            .'문장을 지워도 아무것도 울지 않는다'
        );

        // 그 축을 실제로 다루는 절이 함께 있어야 한다 — 토큰만 있고 안내가 없으면
        // 운영자는 무엇이 미검증인지 알 수 없다.
        $this->assertStringContainsString(
            '하위 폴더 마운트',
            $body,
            self::NGINX_FRAGMENT.' 에 하위 폴더 마운트 절이 없다 — 토큰이 가리키는 대상이 사라졌다'
        );

        // 그리고 그 절이 **틀린 지시**를 담고 있지 않아야 한다. `$uri` 는 그 배치에서
        // 이미 접두사를 포함하므로 `try_files /<접두사>/public$uri` 는 언제나 부재다.
        $this->assertDoesNotMatchRegularExpression(
            '~try_files\s+/g7/public\$uri~',
            $body,
            self::NGINX_FRAGMENT.' 의 하위 폴더 지시가 `try_files /g7/public$uri` 를 권한다 — '
            .'그 배치에서 `$uri` 는 이미 `/g7/…` 라 조회가 언제나 부재이고 정적 자산이 전부 미스가 된다'
        );
    }

    /**
     * 정적 서빙 응답에 `X-Content-Type-Options: nosniff` 가 **네 서버 전부**에서 붙는다 (G3 회차 16 W2 C-3).
     *
     * 파일 서빙 헬퍼(`fileResponse()`·`streamedFileResponse()`)는 그 헤더를 단일 지점에서 붙인다 —
     * `public/storage` 링크를 만들 수 없는 호스팅이 그 통로를 탄다. 링크가 **있는** 사이트는 같은
     * 파일을 웹서버가 정적으로 내주는데, 그 자리에 같은 헤더가 없으면 업로드된 SVG·HTML 이 링크
     * 유무에 따라 한쪽에서만 앱 오리진에서 실행된다 — 「링크 유무로 위험이 갈리지 않는다」는 계약의
     * 정적 서빙 쪽 절반이 비어 있었다.
     *
     * Apache 는 `Header`(`mod_headers` · `AllowOverride FileInfo` 등급)라 제한 호스팅에서도 평가된다.
     * IIS 는 `<httpProtocol><customHeaders>`, nginx 는 `add_header … always`, Caddy 는 `header` 다.
     *
     * @scenario server_parity=nosniff, servers=apache+nginx+caddy+iis
     *
     * @effects static_serving_sends_nosniff_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_every_server_config_sends_nosniff_for_static_files(): void
    {
        $measured = 0;

        // Apache — 위임된 요청은 `public/.htaccess` 가 평가하므로 그 파일이 소유한다.
        $apache = $this->directiveBody('public/.htaccess');
        $this->assertMatchesRegularExpression(
            '/^Header\s+always\s+set\s+X-Content-Type-Options\s+"?nosniff"?$/mi',
            $apache,
            'public/.htaccess 에 nosniff 헤더가 없다 — 링크가 있는 사이트의 정적 서빙만 그 헤더를 잃는다'
        );
        // `<IfModule mod_headers.c>` 안이어야 한다 — 모듈 부재 서버에서 미지 지시어는 500 이다.
        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>\s*\n\s*Header\s+always\s+set\s+X-Content-Type-Options/i',
            $apache,
            'Header 지시어가 <IfModule mod_headers.c> 밖에 있다 — mod_headers 없는 서버에서 전 요청 500'
        );
        $measured++;

        // IIS — `<customHeaders>` 는 `system.webServer` 코어 기능이라 재작성 모듈과 무관하게 붙는다.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file(base_path(self::IIS_CONFIG));
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($xml);
        $headers = $xml->xpath('//httpProtocol/customHeaders/add[@name="X-Content-Type-Options"]');
        $this->assertCount(1, $headers, 'web.config 에 X-Content-Type-Options customHeader 가 없다');
        $this->assertSame('nosniff', (string) $headers[0]['value']);
        $measured++;

        // nginx — `always` 가 없으면 4xx·5xx 응답에는 붙지 않는다(404 에도 본문이 실린다).
        $nginx = $this->directiveBody(self::NGINX_FRAGMENT);
        $this->assertMatchesRegularExpression(
            '/^add_header\s+X-Content-Type-Options\s+"?nosniff"?\s+always;$/m',
            $nginx,
            'nginx 조각에 `add_header X-Content-Type-Options nosniff always;` 가 없다'
        );
        $measured++;

        // Caddy — 네 handle 이 전부 import 하는 `(g7_delegate)` 스니펫이 그 자리다.
        $caddy = $this->directiveBody(self::CADDY_FRAGMENT);
        $delegate = $this->blocksContaining($caddy, '(g7_delegate)', false);
        $this->assertNotSame([], $delegate, 'Caddy (g7_delegate) 스니펫을 찾지 못했다');
        $this->assertMatchesRegularExpression(
            '/^header\s+X-Content-Type-Options\s+"?nosniff"?$/m',
            $delegate[0],
            'Caddy (g7_delegate) 에 `header X-Content-Type-Options nosniff` 가 없다'
        );
        $measured++;

        $this->assertSame(4, $measured, 'nosniff 축의 모집단이 넷이 아니다');
    }

    /**
     * ACME(`.well-known/`) **정적 서빙**에도 `nosniff` 가 붙는다.
     *
     * 형제 검사(`test_every_server_config_sends_nosniff_for_static_files`)는 「파일
     * 어딘가에 지시어가 있는가」만 재므로, 위임 경로에만 헤더가 있고 이 경로만 빠진
     * 상태를 통과시킨다. 실제로 세 서버가 그 상태였다 — 이 경로는 `public/` 아래가
     * 아니라 **프로젝트 루트**를 읽으므로 `public/.htaccess` 의 헤더를 지나지 않고,
     * nginx 의 `add_header` 는 상속되지 않고 대체되며, Caddy 의 `handle` 블록도
     * `(g7_delegate)` 를 import 하지 않는다. IIS 만 `customHeaders` 가 사이트 전역이라
     * 자동으로 붙는다.
     *
     * 운영자가 이 디렉토리에 두는 것은 `security.txt`·`assetlinks.json`·
     * `apple-app-site-association` 처럼 **외부가 가져가는 파일**이라 스니핑 승격의
     * 대상이 된다.
     *
     * @scenario server_parity=nosniff, servers=apache+nginx+caddy+iis
     *
     * @effects static_serving_sends_nosniff_on_every_server
     *
     * @return void 반환값 없음
     */
    public function test_acme_well_known_static_serving_also_sends_nosniff(): void
    {
        $measured = 0;

        // Apache — `.well-known/` 은 루트 `.htaccess` 가 위임에서 빼고 직접 서빙한다.
        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>\s*\R\s*Header\s+always\s+set\s+X-Content-Type-Options\s+"?nosniff"?\s*\R\s*<\/IfModule>/i',
            (string) file_get_contents(base_path('.htaccess')),
            '루트 .htaccess 에 nosniff 헤더가 없다 — 이 파일이 직접 서빙하는 `.well-known/` 만 그 헤더를 잃는다'
        );
        $measured++;

        // nginx — `^~ /.well-known/` 블록이 자기 `add_header` 를 가져야 한다.
        $nginxAcme = $this->blocksContaining($this->directiveBody(self::NGINX_FRAGMENT), '/.well-known/', false);
        $this->assertNotSame([], $nginxAcme, 'nginx 조각에서 `.well-known` location 을 찾지 못했다');
        $this->assertMatchesRegularExpression(
            '/add_header\s+X-Content-Type-Options\s+"?nosniff"?\s+always;/',
            $nginxAcme[0],
            'nginx `.well-known` location 에 nosniff 가 없다 — `add_header` 는 상속되지 않고 대체된다'
        );
        $measured++;

        // Caddy — `handle /.well-known/*` 블록이 자기 `header` 를 가져야 한다.
        $caddyAcme = $this->blocksContaining($this->directiveBody(self::CADDY_FRAGMENT), '/.well-known/*', false);
        $this->assertNotSame([], $caddyAcme, 'Caddy 조각에서 `.well-known` handle 을 찾지 못했다');
        $this->assertMatchesRegularExpression(
            '/header\s+X-Content-Type-Options\s+"?nosniff"?/',
            $caddyAcme[0],
            'Caddy `.well-known` handle 에 nosniff 가 없다 — 그 블록은 (g7_delegate) 를 import 하지 않는다'
        );
        $measured++;

        // IIS — `customHeaders` 가 사이트 전역이라 이 경로에도 적용된다. 형제 검사가
        // 그 존재를 이미 잠그므로 여기서는 그 사실만 계수한다(사본을 만들지 않는다).
        $measured++;

        $this->assertSame(4, $measured, 'ACME nosniff 축의 모집단이 넷이 아니다');
    }

    /**
     * nginx 의 `fastcgi_param` **재정의는 `include fastcgi_params` 뒤**에 온다 (G3 회차 16 W2 C-4).
     *
     * `fastcgi_params` 가 `SCRIPT_NAME`·`DOCUMENT_ROOT`·`QUERY_STRING` 을 자기 값으로 선언하므로,
     * include 가 재정의 **뒤**에 오면 같은 이름이 두 번 선언된다 — nginx 는 둘 다 FastCGI 에 실어
     * 보내고 어느 값이 이기는지는 PHP-FPM 의 처리 순서에 달린다. 조각 머리말이 「`fastcgi_pass` 만
     * 고치라」고 안내하는 블록에서 그 값이 배치에 따라 갈리면 오류 없이 접두사 복원이 어긋난다.
     *
     * @scenario server_kind=nginx_snippet, mount_shape=docroot_relative
     *
     * @effects nginx_fastcgi_overrides_follow_the_params_include
     *
     * @return void 반환값 없음
     */
    public function test_nginx_fastcgi_param_overrides_come_after_the_include(): void
    {
        $nginx = (string) preg_replace('~^[ \t]*#.*$~m', '', (string) file_get_contents(base_path(self::NGINX_FRAGMENT)));
        // FastCGI 로 넘기는 블록은 중첩 중괄호가 없다 — `{ … }` 한 겹으로 잘라 그 안을 본다.
        preg_match_all('/\{([^{}]*fastcgi_pass[^{}]*)\}/s', $nginx, $matches);
        $blocks = $matches[1];
        $this->assertGreaterThanOrEqual(2, count($blocks), 'fastcgi_pass 를 가진 nginx 블록이 둘 미만이다 — 이 검사가 아무것도 재지 않는다');

        foreach ($blocks as $block) {
            $include = strpos($block, 'include');
            $this->assertNotFalse($include, "fastcgi_param 을 가진 블록에 include fastcgi_params 가 없다:\n".$block);
            $this->assertMatchesRegularExpression('/include\s+fastcgi_params;/', $block);

            $firstParam = strpos($block, 'fastcgi_param');
            $this->assertLessThan(
                $firstParam,
                $include,
                "fastcgi_param 재정의가 include fastcgi_params 앞에 있다 — 같은 이름이 두 번 선언되어 어느 값이 이기는지 서버에 달린다:\n".$block
            );
        }
    }

    /**
     * nginx (9) `public/` 아래 `.php` 직접 실행 블록은 파일이 없으면 **프런트 컨트롤러로 폴백**한다 (G3 회차 16 W2 C-7).
     *
     * `=404` 로 끝내면 `/foo.php` 같은 주소의 응답 주체가 nginx 가 되는데, 형제 Apache 는 같은
     * 주소를 `public/.htaccess` 의 프런트 컨트롤러 위임으로 애플리케이션에 넘긴다(라우트가
     * 있으면 200, 없으면 앱의 404 화면). 응답 주체가 서버에 따라 갈리면 그 주소의 404 화면·
     * 헤더·로그가 서버마다 다르다.
     *
     * @scenario server_kind=nginx_snippet, protection=htaccess_ok
     *
     * @effects nginx_php_location_falls_back_to_the_front_controller
     *
     * @return void 반환값 없음
     */
    public function test_nginx_php_location_falls_back_to_the_front_controller(): void
    {
        $nginx = (string) preg_replace('~^[ \t]*#.*$~m', '', (string) file_get_contents(base_path(self::NGINX_FRAGMENT)));
        preg_match_all('/location\s+~\s+\^\/\(\.\+\\\\\.php\)\$\s*\{([^{}]*)\}/s', $nginx, $matches);
        $blocks = $matches[1];
        $this->assertCount(1, $blocks, 'public/ 아래 .php 실행 블록을 하나로 특정하지 못했다');

        $this->assertMatchesRegularExpression(
            '/try_files\s+\/public\/\$1\s+@g7_front;/',
            $blocks[0],
            '.php 실행 블록이 =404 로 끝난다 — 형제 Apache 는 같은 주소를 프런트 컨트롤러에 넘기므로 응답 주체가 갈린다'
        );
    }
}
