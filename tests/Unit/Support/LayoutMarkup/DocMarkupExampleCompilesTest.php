<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use Tests\Support\ScansDocRoots;
use Throwable;

/**
 * 문서에 실린 `.g7.html` 마크업 예시가 전건 컴파일되는지 본다 (S20 6차 감사 신설).
 *
 * ## 왜 저장소 안에 있어야 하는가
 *
 * S20 은 "마크업 예시 684/684 통과" 를 DoD 게이트로 보고했는데, 그 판정을 낸 도구가
 * **세션 스크래치패드의 node 스크립트**였다. 저장소에 없으니 다른 사람은 재현할 수 없고,
 * 다음 회차의 문서 편집이 예시를 깨뜨려도 아무것도 red 가 되지 않는다.
 *
 * 4차 감사에서 엔드포인트 판정을 두고 똑같은 지적을 받아 `DocLayoutEndpointWhitelistTest` 로
 * 옮겨 놓고도, 바로 옆 축을 스크래치패드에 남겨 뒀다. 같은 결함을 두 번 남기지 않기 위해
 * **프로덕션 컴파일러**(`LayoutMarkupCompiler`)를 판정 주체로 삼아 여기로 옮긴다.
 *
 * ## 모집단과 조각 처리
 *
 * `docs`(개발 하네스는 `G7_DOC_SCAN_EXTRA_ROOTS` 로 내부 문서 루트를 더한다)의 ```html 펜스 중 마크업 신호 보유분. 문서는 한 펜스에
 * 여러 예시를 나란히 싣는 관례가 있어(평면/조건형 `<permissions>`, `target_layout`/`extension_point`
 * 두 형태 …) **최상위 형제 단위로 쪼개** 각각 검사한다 — 합쳐 태우면 `duplicate_node_key` 같은
 * 하네스 인공 실패가 난다.
 *
 * 조각은 그대로 문서가 되지 않으므로 첫 태그로 봉투를 고르고, 실패하면 나머지 봉투도 시도한다
 * (문법이 갱신돼 분류가 어긋나도 게이트가 오탐하지 않게).
 *
 * 부정 예시(❌ 로 표시된 금지 패턴)는 대상에서 뺀다 — 일부러 틀린 것을 통과시키라고 요구하면
 * 문서가 거짓이 된다. 대신 건수를 세어 모집단 단언에 쓴다.
 *
 * **그 표지는 펜스 앞 산문에서만 읽는다** (S21 감사 정정). 종전 판본은 펜스 **본문**도 함께 훑어서,
 * 마크업 안에 `<!-- ❌ … -->` 라벨을 단 예시가 **스스로를 게이트에서 면제**시켰다. 작성 내용이 자기
 * 판정 대상 여부를 정하면 그 예시는 무엇을 실어도 초록이다 — 실측하면 코퍼스 전체에서 딱 한 자리가
 * 그 상태였고, 그 자리는 §10.5 가 *"라벨은 주석이 아니라 펜스 밖 산문으로"* 라고 적은 바로 그 규칙을
 * 스스로 어기고 있었다.
 */
class DocMarkupExampleCompilesTest extends LayoutMarkupTestCase
{
    use ScansDocRoots;

    /** 마크업 예시로 볼 펜스 언어. */
    private const MARKUP_LANGS = ['html', 'g7', 'g7html'];

    /** 부정 예시 표지 — 이 표지가 붙은 펜스는 일부러 위반을 싣는다. */
    private const NEGATIVE_MARKS = [
        '❌', '금지', '잘못된', '잘못', 'Before:', '오류', '거부', '에러', '안 됨', '안된다', '컴파일 에러',
    ];

    /** 자기 닫힘으로 취급하는 HTML void 요소. */
    private const VOID_TAGS = ['br', 'hr', 'area', 'base', 'col', 'embed', 'link', 'param', 'source', 'track', 'wbr'];

    /** 문서 봉투 태그 — 그 자체로 완결 문서다. */
    private const ROOT_TAGS = ['layout', 'partial', 'extension', 'override'];

    /** `<layout>` 직속 최상위 섹션 태그. */
    private const SECTION_TAGS = [
        'data-source', 'computed', 'meta', 'modal', 'slot', 'init-local', 'init-global',
        'init-isolated', 'init', 'action', 'defines', 'permissions', 'error-handling',
        'global-headers', 'transition-overlay', 'script', 'inject',
    ];

    /** `<on>` 안에 와야 하는 액션(핸들러) 태그. */
    private const ACTION_TAGS = [
        'navigate', 'api-call', 'set-state', 'open-modal', 'close-modal', 'toast',
        'refetch-data-source', 'set-locale', 'action-ref', 'sequence', 'parallel',
        'conditions', 'switch', 'suppress', 'replace-url', 'start-interval',
        'save-to-local-storage', 'load-from-local-storage', 'scroll-to', 'copy-to-clipboard',
    ];

    /** 마크업 예시임을 알리는 신호 — 하나라도 있으면 마크업 예시로 본다. */
    private const MARKUP_SIGNALS = [
        '<layout', '<partial', '<extension', '<override',
        'data-if=', 'data-each=', 'data-ref=', 'data-key=', 'data-slot',
        'data-track-changes', 'data-debounce', 'data-blur-until-loaded',
        '<data-source', '<computed', '<init-local', '<init-global', '<init-isolated',
        '<named-action', '<action ', '<action-ref', '<permissions', '<permission>',
        '<error-handling', '<on-code', '<global-headers', '<transition-overlay',
        '<defines', '<define ', '<modal', '<slot ', '<include', '<inject',
        '<on event=', '<on type=', '<on listen=', '<class-map', '<isolated-state',
        '<responsive', '<sortable', '<item-template', '<blur-until-loaded',
        '<column ', '<cell>', '<expand>', '<sub-row', '<footer-cell', '<footer-card',
        '<card>', '<title-prefix', '<title-suffix', '<switch-value', '<options>',
        '<set-state', '<api-call', '<navigate', '<open-modal', '<close-modal',
        '<toast', '<refetch-data-source', '<set-locale', '<action-ref',
        '<field name=', '<from-source', '<prop name=', '<query ', '<body ',
        '<success>', '<fail>', '<sequence>', '<parallel>', '<conditions>',
        '<when if=', '<all-of>', '<any-of>', '<start-interval',
        '<seo ', '<og ', '<vars ', '<structured-data',
    ];

    /**
     * 판정 레지스트리 — 번들 템플릿 전량에서 도출한다.
     *
     * 41차 정정: 종전 판본은 `sirsoft-admin_basic` **하나**로 판정했다. 그런데 문서는 두 템플릿을
     * 함께 다루므로, 사용자 템플릿 소유 컴포넌트(`Header` 등)를 쓴 예시가 작성 가능한데도 조용히
     * "컴파일 불가" 로 접혔다(실측 `troubleshooting-state-global.md`). 판정기를 한 템플릿으로 좁힌
     * 것은 모집단을 좁힌 것과 같은 종류의 결함이고, 이 트랙이 이미 세 번 겪은 형태다.
     *
     * 템플릿 목록도 손으로 적지 않는다 — 번들 템플릿의 `components.json` 글롭으로 도출한다
     * (아래 `setUp()` 참조). 어느 하나가 해석하면 그 예시는 작성 가능하다.
     *
     * @var array<string, LayoutMarkupCompiler>
     */
    private array $compilers = [];

    /**
     * 문서 예시가 쓸 수 있는 외부 스크립트 호스트 — 번들 확장·템플릿이 매니페스트로 선언한 호스트 전부.
     *
     * 코어 설정은 확장 호스트를 담지 않는다(A11, 2026-09-28). 문서는 번들 확장(예: 우편번호 플러그인)의
     * 사용 예를 보여 주므로, 그 확장이 선언한 호스트가 허용된 상태로 컴파일한다 — 테스트 DB 의 활성 상태와 무관하게.
     *
     * @var array<int, string>
     */
    private array $declaredHosts = [];

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(base_path('templates/_bundled/*/components.json')) as $manifest) {
            $this->compilers[basename(dirname($manifest))] = new LayoutMarkupCompiler(
                ComponentRegistry::fromFile($manifest),
            );
        }

        foreach (glob(base_path('{modules,plugins,templates}/_bundled/*/{module,plugin,template}.json'), GLOB_BRACE) as $manifest) {
            $declared = json_decode((string) file_get_contents($manifest), true)['trusted_script_hosts'] ?? [];
            if (is_array($declared)) {
                $this->declaredHosts = array_merge($this->declaredHosts, array_filter($declared, 'is_string'));
            }
        }
        $this->declaredHosts = array_values(array_unique($this->declaredHosts));
    }

    /**
     * 문서의 마크업 예시가 전건 컴파일됩니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects doc_markup_examples_compile_with_production_compiler
     *
     * @return void 반환값 없음
     */
    public function test_문서_마크업_예시가_전건_컴파일된다(): void
    {
        $failures = [];
        $inspected = 0;
        $negatives = 0;

        foreach ($this->docFiles() as $file) {
            $text = file_get_contents($file);
            $rel = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));

            foreach ($this->markupFences($text) as $fence) {
                if ($this->isNegative($text, $fence)) {
                    $negatives++;

                    continue;
                }

                foreach ($this->splitTopLevel($fence['body']) as $raw) {
                    if (! $this->hasSignal($raw)) {
                        continue;
                    }

                    $inspected++;
                    $error = $this->compileWithWrappers($this->normalizeElisions($raw));

                    if ($error !== null) {
                        $failures[] = "{$rel}:{$fence['startLine']} — {$error}";
                    }
                }
            }
        }

        // 판정기 하한 — 레지스트리 글롭이 죽으면 "컴파일 불가" 가 아니라 "판정 불가" 가 된다.
        $this->assertGreaterThan(
            1,
            count($this->compilers),
            '판정 레지스트리가 '.count($this->compilers).'개뿐 — 한 템플릿으로 판정하면 다른 템플릿 소유 '
            .'컴포넌트를 쓴 예시가 조용히 컴파일 불가로 접힌다 (41차가 그 형태로 1자리를 놓쳤다)'
        );

        // "실패 0" 과 "검사 대상 0" 은 다른 명제다 — 펜스 추출식이 깨지면 이 단언이 먼저 red 다.
        // 공개 `docs` 만으로 실측 487(2026-09-06) — 내부 문서 루트를 더하면 더 많아지지만 하한은
        // 공개 배포본에서도 성립해야 하므로 공개 실측에 맞춘다.
        $this->assertGreaterThan(
            450,
            $inspected,
            "검사한 마크업 조각이 {$inspected}건뿐 — 펜스/신호 추출식이 어긋났는지 먼저 확인할 것"
        );

        // 부정 예시가 0 이면 제외 판정식이 죽은 것이다 (문서는 ❌ 예시를 싣는다).
        $this->assertGreaterThan(
            5,
            $negatives,
            "부정 예시 제외가 {$negatives}건뿐 — NEGATIVE_MARKS 판정식을 확인할 것"
        );

        $this->assertSame(
            [],
            $failures,
            "컴파일되지 않는 마크업 예시:\n".implode("\n", $failures)
        );
    }

    /**
     * 판정식 자기시험 — 존재하지 않는 문법을 실으면 이 게이트가 red 가 됩니다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects doc_example_gate_rejects_nonexistent_syntax
     *
     * @return void 반환값 없음
     */
    public function test_자기시험_없는_문법은_실패로_잡힌다(): void
    {
        // `@click` 은 외래 관용구라 컴파일러가 foreign_idiom 으로 거절한다.
        $this->assertNotNull(
            $this->compileWithWrappers('<div @click="save()"/>'),
            '외래 관용구가 통과했다 — 봉투 시도 순서가 오류를 삼키고 있는지 확인할 것'
        );

        // 정상 예시는 통과해야 한다 (오탐이 아님을 같은 자리에서 증명).
        $this->assertNull(
            $this->compileWithWrappers('<div class="x"/>'),
            '정상 마크업이 실패로 잡혔다 — 봉투 목록을 확인할 것'
        );
    }

    /**
     * 펜스 **본문**은 스스로를 부정 예시로 선언할 수 없습니다 — 표지는 앞 산문에서만 읽는다.
     *
     * 이 명제가 깨지면 마크업 예시가 마크업 안에 `❌` 한 글자를 넣는 것만으로 컴파일 게이트를
     * 빠져나간다(자기충족 술어). 실물로 한 자리가 그 상태였다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fence_body_cannot_self_exempt_as_negative_example
     *
     * @return void 반환값 없음
     */
    public function test_펜스_본문은_스스로를_부정예시로_면제하지_못한다(): void
    {
        $fence = ['startLine' => 10, 'body' => '<div><!-- ❌ 금지된 형태 --><span/></div>'];
        $prose = implode("\n", array_fill(0, 20, '평범한 설명 문장이다.'));

        $this->assertFalse(
            $this->isNegative($prose, $fence),
            '펜스 본문의 표지가 그 펜스를 게이트에서 빼고 있다 — 작성 내용이 자기 판정 여부를 정한다',
        );

        // 앞 산문의 표지는 여전히 읽는다 (반대쪽으로 죽지 않았음을 같은 자리에서 증명).
        $lines = explode("\n", $prose);
        $lines[8] = '❌ 아래는 금지된 형태다.';

        $this->assertTrue(
            $this->isNegative(implode("\n", $lines), $fence),
            '펜스 앞 산문의 부정 표지를 못 읽는다 — 부정 예시가 컴파일 대상으로 들어온다',
        );
    }

    /**
     * 봉투를 차례로 씌워 컴파일을 시도하고, 전부 실패하면 마지막 오류를 돌려줍니다.
     *
     * @param  string  $body  마크업 조각
     * @return string|null 전건 실패 시 오류 메시지, 하나라도 통과하면 null
     */
    private function compileWithWrappers(string $body): ?string
    {
        $last = '알 수 없는 오류';

        foreach ($this->wrapperOrder($body) as $wrapper) {
            foreach ($this->compilers as $template => $compiler) {
                try {
                    $compiler->compile($this->wrap($wrapper, $body), null, new CompileContext(declaredScriptHosts: $this->declaredHosts));

                    return null;
                } catch (Throwable $e) {
                    $last = "[{$template}] ".$e->getMessage();
                }
            }
        }

        return $last;
    }

    /**
     * 조각에 봉투를 씌웁니다.
     *
     * @param  string  $wrapper  봉투 이름
     * @param  string  $body  마크업 조각
     * @return string 컴파일 대상 소스
     */
    private function wrap(string $wrapper, string $body): string
    {
        return match ($wrapper) {
            'asIs' => $body,
            // 완결 문서인데 본문이 `…` 로 생략된 예시 — 생략 자리에 채움 노드를 넣는다.
            'asIsFilled' => preg_replace('~</layout>\s*$~', "<div text=\"본문\"/>\n</layout>", $body),
            'layout' => "<layout name=\"doc_example\" version=\"1.0.0\">\n{$body}\n</layout>",
            // 최상위 섹션만 담은 조각은 components 가 비어 구조 검증에 걸린다 — 채움 노드를 얹는다.
            'layoutFilled' => "<layout name=\"doc_example\" version=\"1.0.0\">\n{$body}\n<div text=\"본문\"/>\n</layout>",
            // 모달 조각의 실제 작성 자리 — partial 루트 노드 하나 (코퍼스 관례).
            'partial' => "<partial name=\"doc_example\">\n{$body}\n</partial>",
            'node' => "<layout name=\"doc_example\" version=\"1.0.0\">\n<div>\n{$body}\n</div>\n</layout>",
            'action' => "<layout name=\"doc_example\" version=\"1.0.0\">\n<div>\n<on event=\"click\">\n{$body}\n</on>\n</div>\n</layout>",
            default => $body,
        };
    }

    /**
     * 첫 태그로 봉투 시도 순서를 정합니다.
     *
     * @param  string  $body  마크업 조각
     * @return array<int, string> 봉투 이름 목록
     */
    private function wrapperOrder(string $body): array
    {
        $tag = $this->firstTag($body);

        if ($tag !== null && in_array($tag, self::ROOT_TAGS, true)) {
            return ['asIs', 'asIsFilled', 'layout', 'node', 'action'];
        }
        if ($tag === 'on') {
            return ['node', 'layoutFilled', 'asIs', 'action'];
        }
        if ($tag !== null && in_array($tag, self::ACTION_TAGS, true)) {
            return ['action', 'node', 'layoutFilled', 'asIs'];
        }
        if ($tag === 'modal') {
            return ['partial', 'layoutFilled', 'node', 'asIs'];
        }
        if ($tag !== null && in_array($tag, self::SECTION_TAGS, true)) {
            return ['layoutFilled', 'layout', 'partial', 'node', 'asIs', 'action'];
        }

        return ['node', 'layoutFilled', 'partial', 'action', 'asIs'];
    }

    /**
     * 주석을 걷어낸 뒤 첫 여는 태그 이름을 돌려줍니다.
     *
     * @param  string  $body  마크업 조각
     * @return string|null 소문자 태그명
     */
    private function firstTag(string $body): ?string
    {
        $stripped = preg_replace('~<!--.*?-->~s', '', $body);

        return preg_match('~<([a-zA-Z][\w-]*)~', $stripped, $m) ? strtolower($m[1]) : null;
    }

    /**
     * 문서의 생략 관례를 컴파일 가능한 형태로 정규화합니다 (문서 자체는 건드리지 않습니다).
     *
     * `…`/`...` 는 "그 밖의 내용" 이라는 서술이지 문법이 아니므로, 그대로 태우면 문법 오류가
     * 아닌 자리에서 게이트가 red 를 낸다.
     *
     * @param  string  $body  마크업 조각
     * @return string 정규화된 조각
     */
    private function normalizeElisions(string $body): string
    {
        $s = preg_replace('~^[ \t]*(?:…|\.\.\.)[ \t]*$\R?~mu', '', $body);
        $s = preg_replace('~>[ \t]*(?:…|\.\.\.)[ \t]*<~u', '><', $s);

        foreach (['on', 'success', 'fail', 'init', 'when', 'else', 'case', 'default', 'actions', 'sequence', 'parallel'] as $tag) {
            $s = preg_replace("~(<{$tag}(?:\\s[^>]*)?>)\\s*(</{$tag}>)~", '$1<suppress/>$2', $s);
        }

        return $s;
    }

    /**
     * 마크업 본문을 최상위 형제 단위로 쪼갭니다. 앞선 주석은 뒤따르는 요소에 붙입니다.
     *
     * @param  string  $body  펜스 본문
     * @return array<int, string> 조각 목록
     */
    private function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $pending = '';
        $last = 0;
        $re = '~<!--.*?-->|</([a-zA-Z][\w-]*)\s*>|<([a-zA-Z][\w-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>~s';

        preg_match_all($re, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $m) {
            $whole = $m[0][0];
            $offset = $m[0][1];
            $chunk = substr($body, $last, $offset - $last);
            $last = $offset + strlen($whole);

            if ($depth > 0) {
                $buf .= $chunk.$whole;
            } elseif (str_starts_with($whole, '<!--')) {
                $pending .= $chunk.$whole;
            } elseif (($m[1][0] ?? '') !== '') {
                $buf .= $chunk.$whole;   // 미매칭 종료 태그 — 그대로 둔다
            } else {
                $tag = strtolower($m[2][0]);
                $selfClosing = ($m[4][0] ?? '') === '/' || in_array($tag, self::VOID_TAGS, true);
                $buf .= $chunk.$whole;

                if (! $selfClosing) {
                    $depth = 1;

                    continue;
                }

                $parts[] = trim($pending.$buf);
                $buf = '';
                $pending = '';
            }

            if ($depth > 0) {
                $depth = $this->balance($buf);
                if ($depth === 0) {
                    $parts[] = trim($pending.$buf);
                    $buf = '';
                    $pending = '';
                }
            }
        }

        $tail = trim($pending.$buf.substr($body, $last));
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return array_values(array_filter($parts, fn ($p) => preg_match('~<[a-zA-Z]~', $p) === 1));
    }

    /**
     * 열림/닫힘 태그 균형을 셉니다.
     *
     * @param  string  $s  마크업 조각
     * @return int 남은 깊이
     */
    private function balance(string $s): int
    {
        $d = 0;
        $re = '~<!--.*?-->|</([a-zA-Z][\w-]*)\s*>|<([a-zA-Z][\w-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>~s';
        preg_match_all($re, $s, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            if (str_starts_with($m[0], '<!--')) {
                continue;
            }
            if (($m[1] ?? '') !== '') {
                $d--;

                continue;
            }
            if (($m[4] ?? '') !== '/' && ! in_array(strtolower($m[2]), self::VOID_TAGS, true)) {
                $d++;
            }
        }

        return $d;
    }

    /**
     * 마크다운에서 마크업 코드 펜스를 뽑습니다.
     *
     * @param  string  $text  문서 전문
     * @return array<int, 반환값
     */
    private function markupFences(string $text): array
    {
        $lines = explode("\n", $text);
        $out = [];
        $open = null;

        foreach ($lines as $i => $line) {
            if (! preg_match('#^(\s{0,3})(`{3,}|~{3,})(.*)$#', $line, $m)) {
                continue;
            }

            $marker = $m[2];

            if ($open === null) {
                $info = trim($m[3]);
                if ($marker[0] === '`' && str_contains($info, '`')) {
                    continue;
                }
                $lang = strtolower(preg_split('~\s+~', $info)[0] ?? '');
                $open = ['lang' => $lang, 'char' => $marker[0], 'len' => strlen($marker), 'start' => $i];
            } elseif ($marker[0] === $open['char'] && strlen($marker) >= $open['len'] && trim($m[3]) === '') {
                $body = implode("\n", array_slice($lines, $open['start'] + 1, $i - $open['start'] - 1));
                if (in_array($open['lang'], self::MARKUP_LANGS, true) && $this->hasSignal($body)) {
                    $out[] = ['startLine' => $open['start'] + 1, 'body' => $body];
                }
                $open = null;
            }
        }

        return $out;
    }

    /**
     * 마크업 신호 보유 여부를 판정합니다.
     *
     * @param  string  $body  마크업 조각
     * @return bool 신호가 하나라도 있으면 true
     */
    private function hasSignal(string $body): bool
    {
        foreach (self::MARKUP_SIGNALS as $signal) {
            if (str_contains($body, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 부정 예시 펜스인지 판정합니다 (펜스 앞 3줄 + 본문).
     *
     * @param  string  $text  문서 전문
     * @param  array{startLine: int, body: string}  $fence  펜스
     * @return bool 부정 표지가 있으면 true
     */
    private function isNegative(string $text, array $fence): bool
    {
        $lines = explode("\n", $text);
        $before = implode("\n", array_slice($lines, max(0, $fence['startLine'] - 4), 3));

        foreach (self::NEGATIVE_MARKS as $mark) {
            if (str_contains($before, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 검사 대상 문서 파일 목록을 돌려줍니다.
     *
     * @return array<int, string> 절대 경로
     */
    private function docFiles(): array
    {
        $files = [];

        foreach ($this->docRoots() as $dir) {
            $abs = base_path($dir);
            if (! is_dir($abs)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs));
            foreach ($it as $entry) {
                if (! $entry->isFile() || ! str_ends_with($entry->getFilename(), '.md')) {
                    continue;
                }
                if (str_contains(str_replace('\\', '/', $entry->getPathname()), '/node_modules/')) {
                    continue;
                }
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
