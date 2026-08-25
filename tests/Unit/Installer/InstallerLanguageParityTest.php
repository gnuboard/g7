<?php

namespace Tests\Unit\Installer;

use App\Execution\ExecutionMode;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 다국어 정합 회귀 테스트 (10차 #G7).
 *
 * 인스톨러의 `public/install/lang/{ko,en}.php` 는 그동안 **어떤 자동 검사도 보지 않는 축**이었다 —
 * audit 룰도, 스크립트도, PHPUnit 도 이 디렉토리를 스코프에 넣지 않았다. 라운드마다 사람이 손으로
 * 키 수를 세어 왔고, 그래서 다음 두 결함이 여러 라운드를 통과했다:
 *
 *  - `{count}` 중괄호 자리표시자 — `lang()` 은 `:name` 만 치환하므로 화면에 그대로 노출됐다.
 *  - `fields.db_prefix` 같은 **중첩 키**를 프론트가 평면 조회 — 번역 대신 키 문자열이 나갔다.
 *
 * 고정하는 계약:
 *
 *  1. 두 로케일의 키 집합이 같다 (중첩 그룹 평탄화 기준).
 *  2. 같은 키의 자리표시자 집합이 같다 — 한쪽에만 있으면 그 로케일에서 값이 빠진 문장이 나간다.
 *  3. 중괄호 자리표시자를 쓰지 않는다 — 치환기가 모르는 표기다.
 *  3-b. 줄바꿈 이스케이프가 값에 문자 그대로 남지 않는다 — 홑따옴표 문자열의 `
` 은
 *       PHP 가 해석하지 않아 화면에 역슬래시와 n 이 그대로 나간다. 중괄호 금지와 같은
 *       종류(치환기·파서가 모르는 표기)인데 한쪽만 잠겨 있었다 (11차 #B18).
 *  4. 코드가 **리터럴로** 부르는 키가 정의돼 있다 (PHP·JS 양쪽. 동적 조립은 대상 밖).
 *  5. 점 경로 키는 중첩 표에서 해석된다 — 프론트가 그 경로를 따라 내려갈 수 있어야 한다.
 *  6. 지원 언어 상수(`SUPPORTED_LANGUAGES`)와 배포되는 `lang/*.php` 목록이 일치한다 (12차 #C6).
 *  7. PHP 채널은 점 경로 키를 부르지 않는다 — 서버 `lang()` 은 평면 조회다 (12차 #C7).
 *  8. 자리표시자 치환은 `lang(key, replace)` 한 지점을 지난다 (12차 #C8, 잔존분은 상한 고정).
 *
 * 커버 축: locale ko·en / key_shape flat·nested / placeholder_style colon·brace·escape / reference_channel php·js
 *
 * @effects installer_lang_locales_have_identical_keys, installer_lang_placeholders_match_across_locales, installer_lang_rejects_brace_placeholders, installer_lang_literal_references_are_defined, installer_lang_rejects_literal_escape_sequences, installer_inline_script_strings_use_shared_escaping
 */
class InstallerLanguageParityTest extends TestCase
{
    /** 코드가 부르지만 값이 동적으로 조립되는 접두사 — 리터럴 대조 대상에서 제외한다. */
    private const DYNAMIC_PREFIXES = [
        'task_',
        'execution_mode_label_',
        'execution_mode_source_',
        'dependency_type_',
        'dir_',
    ];

    private static function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 중첩 표를 점 경로로 평탄화한다.
     *
     * @param  array<string, mixed>  $table
     * @return array<string, string>
     */
    private static function flatten(array $table, string $prefix = ''): array
    {
        $flat = [];
        foreach ($table as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }
            $flat[$path] = (string) $value;
        }

        return $flat;
    }

    /**
     * @return array<string, string>
     */
    /**
     * 검사할 로케일 목록 — 인스톨러가 실제로 배포하는 lang 파일에서 도출한다 (11차 #C7).
     *
     * `['ko', 'en']` 을 손으로 적어 두면 로케일이 늘어도 게이트가 그 사실을 모른다 —
     * 새 로케일의 키 누락·자리표시자 불일치가 통째로 검사 밖에 남는다.
     *
     * @return array<int, string> 로케일 코드
     */
    private function localeCodes(): array
    {
        $codes = [];
        foreach ($this->scanFiles(self::installRoot().'/lang', ['php']) as $path) {
            $codes[] = basename($path, '.php');
        }
        sort($codes);

        $this->assertNotEmpty($codes, '인스톨러 lang 파일을 하나도 찾지 못했다 — 경로가 바뀌었다');
        $this->assertContains('ko', $codes);
        $this->assertContains('en', $codes);

        return $codes;
    }

    private function locale(string $code): array
    {
        $path = self::installRoot().'/lang/'.$code.'.php';
        $this->assertFileExists($path);

        $table = require $path;
        $this->assertIsArray($table);

        return self::flatten($table);
    }

    /**
     * 문장에서 `:name` 자리표시자를 뽑는다.
     *
     * @return array<int, string>
     */
    private static function placeholders(string $message): array
    {
        // 앞 글자가 영숫자·`/` 면 자리표시자가 아니다 — `http://localhost:port` 의 `port`,
        // `8080:tcp` 같은 산문 표기를 자리표시자로 오인하면 로케일 간 비교가 거짓으로 갈린다
        // (ko 는 같은 자리에 `포트` 라고 적어 ASCII 매칭에 걸리지 않는다).
        preg_match_all('/(?<![A-Za-z0-9\/]):([a-z][a-z0-9_]*)/i', $message, $matches);
        $found = array_unique($matches[1]);
        sort($found);

        return $found;
    }

    public function test_locales_define_the_same_keys(): void
    {
        $ko = $this->locale('ko');
        $en = $this->locale('en');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($ko), array_keys($en))),
            'ko 에만 있는 키는 영어 화면에서 키 문자열이 그대로 노출된다'
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($ko))),
            'en 에만 있는 키는 한국어 화면에서 키 문자열이 그대로 노출된다'
        );
    }

    public function test_placeholders_match_across_locales(): void
    {
        $ko = $this->locale('ko');
        $en = $this->locale('en');

        $mismatched = [];
        foreach ($ko as $key => $message) {
            if (! isset($en[$key])) {
                continue;
            }
            // 산문 안의 `http://`·`localhost:8080` 같은 표기는 자리표시자가 아니다 —
            // 두 로케일에서 **같게** 나타나면 비교 결과가 같아지므로 별도 예외가 필요 없다.
            if (self::placeholders($message) !== self::placeholders($en[$key])) {
                $mismatched[] = $key;
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            '자리표시자가 한쪽에만 있으면 그 로케일의 문장에서 값이 빠지거나 토큰이 그대로 남는다'
        );
    }

    public function test_brace_placeholders_are_not_used(): void
    {
        $offenders = [];
        foreach ($this->localeCodes() as $code) {
            foreach ($this->locale($code) as $key => $message) {
                if (preg_match('/\{[a-z_][a-z0-9_]*\}/i', $message) === 1) {
                    $offenders[] = $code.':'.$key;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'lang() 은 `:name` 만 치환한다 — 중괄호 표기는 화면에 그대로 노출된다 (`기존 테이블 {count}개 …`)'
        );
    }

    /**
     * 이스케이프 시퀀스가 값에 문자 그대로 남아 있지 않은가 (11차 #B18).
     *
     * PHP 홑따옴표 문자열은 `
` 을 해석하지 않는다 — 그 값을 alert/confirm 에 넘기면
     * 줄바꿈 대신 역슬래시와 n 이 화면에 뜬다. 같은 파일 안에서 형제 키는 쌍따옴표라
     * 정상이었고, 표기가 갈렸다는 사실을 어떤 게이트도 보지 않았다. 중괄호 금지 축과
     * 같은 종류의 결함이라 같은 자리에서 잠근다.
     */
    public function test_escape_sequences_are_not_literal(): void
    {
        $offenders = [];
        foreach ($this->localeCodes() as $code) {
            foreach ($this->locale($code) as $key => $message) {
                // 정규식 대신 문자 대조를 쓴다 — 역슬래시 이스케이프가 PHP·PCRE 두 층을
                // 지나면 표기가 뒤엉켜, 규칙을 고정하려던 검사 자신이 조용히 무력해진다.
                foreach ([chr(92).'n', chr(92).'r', chr(92).'t'] as $literal) {
                    if (str_contains((string) $message, $literal)) {
                        $offenders[] = $code.':'.$key;
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '홑따옴표 안의 줄바꿈 이스케이프는 해석되지 않는다 — 줄바꿈이 필요하면 쌍따옴표 문자열을 쓴다'
        );
    }

    /**
     * 코드가 리터럴로 부르는 키가 정의돼 있는가.
     *
     * PHP 는 `lang('key')`, JS 는 `lang('key')` · `getLangMessage('key')` 를 본다. 동적 조립
     * (`lang('task_'.$id)`) 은 리터럴이 아니므로 정규식이 애초에 잡지 않고, 접두사만 남는
     * 형태는 DYNAMIC_PREFIXES 로 걸러 오탐을 막는다.
     */
    public function test_literal_key_references_are_defined(): void
    {
        $defined = $this->locale('ko');
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            // 주석을 지운 뒤에 찾는다 — 규정·예시 주석 안의 `lang('key')` 는 호출이 아니다.
            // 남겨 두면 문서에 적힌 예시가 「정의되지 않은 키」로 잡혀 이 게이트가 오탐으로 죽는다.
            $source = $this->stripComments((string) file_get_contents($file));
            preg_match_all('/(?:lang|getLangMessage)\(\s*\'([a-z0-9_.]+)\'\s*[,)]/i', $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (isset($defined[$key])) {
                    continue;
                }
                foreach (self::DYNAMIC_PREFIXES as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        continue 2;
                    }
                }
                $missing[] = basename($file).':'.$key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '정의되지 않은 키를 부르면 화면에 번역 대신 키 문자열이 나간다'
        );
    }

    /**
     * 치환값을 넘기는데 그 키에 자리표시자가 없는 호출이 없어야 한다 (10차).
     *
     * 무해해 보이지만(값은 무시된다) 그 자리는 **문구를 고치다 자리표시자만 지운 흔적**이다 —
     * 반대 방향(자리표시자는 있는데 값을 안 넘김)은 화면에 `:error` 토큰을 그대로 노출시키는
     * 알려진 결함군이고, 이 검사는 그 짝을 코드 쪽에서 잡는다.
     */
    public function test_substitution_arguments_have_a_matching_placeholder(): void
    {
        $defined = $this->locale('ko');
        $surplus = [];

        foreach ($this->sourceFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));

            // `lang('key', ['a' => …, 'b' => …])` 의 키와 인자 이름만 뽑는다. 중첩 배열·함수
            // 호출이 값 자리에 오면 첫 `]` 에서 잘릴 수 있으므로 **이름 추출에만** 쓴다.
            preg_match_all('/lang\(\s*\'([a-z0-9_.]+)\'\s*,\s*\[([^\]]*)\]/i', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as [$whole, $key, $args]) {
                if (! isset($defined[$key])) {
                    continue; // 미정의 키는 다른 검사가 본다
                }
                $placeholders = self::placeholders($defined[$key]);

                preg_match_all('/\'([a-z][a-z0-9_]*)\'\s*=>/i', $args, $argNames);
                foreach (array_unique($argNames[1]) as $name) {
                    if (! in_array($name, $placeholders, true)) {
                        $surplus[] = basename($file).':'.$key.' → :'.$name;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $surplus,
            '치환값을 넘기는데 문구에 그 자리표시자가 없다 — 문구를 고치며 자리표시자만 지운 흔적이다'
        );
    }

    /**
     * 점 경로 키는 중첩 표에서 실제로 해석돼야 한다.
     *
     * 프론트가 그 경로를 따라 내려갈 수 있어야 한다 — 평면 조회만 하면 `fields.db_prefix` 처럼
     * 그룹 안에 사는 키가 통째로 원문 노출된다 (10차).
     */
    public function test_dotted_keys_resolve_through_the_nested_table(): void
    {
        $dotted = array_values(array_filter(
            array_keys($this->locale('ko')),
            static fn (string $key): bool => str_contains($key, '.')
        ));

        $this->assertNotEmpty($dotted, '중첩 그룹이 사라졌으면 이 계약을 다시 확인할 것');

        $table = require self::installRoot().'/lang/ko.php';

        foreach ($dotted as $key) {
            $node = $table;
            foreach (explode('.', $key) as $segment) {
                $this->assertIsArray($node, $key.' 의 경로 중간이 배열이 아니다');
                $this->assertArrayHasKey($segment, $node, $key.' 를 점 경로로 따라갈 수 없다');
                $node = $node[$segment];
            }
            $this->assertIsString($node, $key.' 가 문자열로 끝나지 않는다');
        }
    }

    /**
     * 런타임 지원 언어 목록과 실제 배포되는 lang 파일이 일치하는가 (12차 #C6).
     *
     * 지원 언어는 두 곳에 선언돼 있는데 대조 장치가 없었다 — 런타임은
     * `includes/config.php` 의 `SUPPORTED_LANGUAGES` 상수를, 이 게이트는 `lang/*.php`
     * 글롭을 본다. 상수에만 로케일을 더하면 `loadTranslations()` 이 빈 배열을 돌려주고
     * 화면 전체가 키 문자열이 된다. 반대로 파일만 두면 아무도 그 로케일을 고를 수 없다.
     * 둘 다 예외도 로그도 남기지 않는다.
     *
     * `config.php` 를 **require 하지 않는다** — 그 파일은 `BASE_PATH` 를 실 저장소 루트로
     * 박으므로, 같은 프로세스에서 도는 다른 인스톨러 테스트가 temp 격리 안전가드에 걸려
     * 통째로 skip 된다(종료 코드는 0 이라 초록으로 보인다). 상수 선언만 소스에서 읽는다.
     */
    public function test_supported_languages_constant_matches_shipped_lang_files(): void
    {
        // 주석을 지운 뒤 대조한다 — 주석 처리된 상수 선언도 원문에는 글자로 남는다.
        $source = $this->stripComments((string) file_get_contents(self::installRoot().'/includes/config.php'));

        $this->assertSame(
            1,
            preg_match("/define\(\s*'SUPPORTED_LANGUAGES'\s*,\s*\[(.*?)\]\s*\)/s", $source, $block),
            'config.php 에서 SUPPORTED_LANGUAGES 선언을 찾지 못했다 — 상수 이름·표기가 바뀌었으면 이 게이트도 함께 옮길 것'
        );

        preg_match_all("/'([a-z][a-z0-9_-]*)'\s*=>/i", $block[1], $matches);
        $declared = $matches[1];
        sort($declared);

        $this->assertNotEmpty($declared, 'SUPPORTED_LANGUAGES 에서 로케일 코드를 하나도 읽지 못했다 — 배열 표기가 바뀌었다');

        $this->assertSame(
            $this->localeCodes(),
            $declared,
            'SUPPORTED_LANGUAGES 와 lang/*.php 가 어긋났다 — 상수에만 있으면 그 로케일 화면 전체가 키 문자열이 되고, 파일에만 있으면 아무도 고를 수 없다'
        );
    }

    /**
     * PHP 채널에서 점 경로 키를 부르지 않는가 (12차 #C7).
     *
     * 두 층의 조회 규칙이 다르다 — JS(`installer.js`)는 점 경로를 따라 내려가지만
     * 서버 `lang()` 은 `$translations[$key] ?? $key` 평면 조회다. 그래서 PHP 에서
     * `lang('fields.db_prefix')` 를 부르면 **키 문자열이 그대로 화면에 나간다**.
     *
     * 이 게이트는 평탄화한 키 집합으로 판정하므로 그 호출을 「정의된 키」로 보고
     * 초록을 준다 — 검사는 통과하는데 화면에는 키가 뜨는 조합이다. 그래서 PHP 채널만
     * 따로 점 없는 키로 좁힌다. (두 층의 조회 규칙 자체를 통일하는 안은 별도 판단 사항이다.)
     */
    public function test_php_lang_calls_do_not_use_dotted_keys(): void
    {
        $dotted = [];

        foreach ($this->sourceFiles() as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $source = $this->stripComments((string) file_get_contents($file));
            preg_match_all('/\blang\(\s*\'([a-z0-9_]+(?:\.[a-z0-9_]+)+)\'/i', $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                $dotted[] = basename($file).':'.$key;
            }
        }

        $this->assertSame(
            [],
            $dotted,
            '서버 lang() 은 평면 조회다 — 점 경로 키를 부르면 번역 대신 키 문자열이 나간다 (중첩 키는 JS 채널 전용)'
        );
    }

    /**
     * 동적으로 조립되는 키의 어휘가 lang 에 도달하는가 (12차 #C6 재귀).
     *
     * `test_literal_key_references_are_defined` 는 **리터럴** 호출만 본다. 그래서
     * `lang(getTaskName($id))` · `lang('execution_mode_label_'.$mode)` 처럼 접두사에
     * 어휘를 붙여 조립하는 자리는 `DYNAMIC_PREFIXES` 로 통째로 면제된다 — 그 어휘는
     * 다른 곳(키 표·Enum·판정기)에 선언돼 있는데 lang 과 대조하는 장치가 없었다.
     * 표에 항목을 하나 더 적고 문구를 빠뜨리면 그 화면에만 키 문자열이 나가고,
     * 예외도 경고도 남지 않는다.
     */
    public function test_dynamically_composed_keys_have_definitions(): void
    {
        $defined = $this->locale('ko');
        $missing = [];

        // (1) 키 표 — `getStepName()` · `getTaskName()` 이 돌려주는 값 전수.
        $prefixes = implode('|', array_map(
            static fn (string $p): string => preg_quote($p, '/'),
            array_merge(self::DYNAMIC_PREFIXES, ['step_'])
        ));
        $tables = $this->stripComments((string) file_get_contents(self::installRoot().'/includes/functions.php'));
        preg_match_all('/=>\s*\'(('.$prefixes.')[a-z0-9_]+)\'/', $tables, $matches);
        $tableKeys = array_values(array_unique($matches[1]));

        $this->assertGreaterThan(
            20,
            count($tableKeys),
            '키 표를 읽지 못했다 — 표가 다른 파일로 옮겨갔다면 이 게이트도 함께 옮길 것'
        );
        foreach ($tableKeys as $key) {
            if (! isset($defined[$key])) {
                $missing[] = 'functions.php 키 표 → '.$key;
            }
        }

        // (2) 실행 모드 라벨 — 접두사 + Enum 어휘. `auto` 는 해석 **입력**이라 라벨이 없다
        //     (판정 결과로는 subprocess|inprocess 만 나온다).
        foreach (ExecutionMode::values() as $mode) {
            if ($mode === 'auto') {
                continue;
            }
            $key = 'execution_mode_label_'.$mode;
            if (! isset($defined[$key])) {
                $missing[] = 'ExecutionMode → '.$key;
            }
        }

        // (3) 판정 근거 — `execution-mode.php` 가 실제로 내보내는 source 어휘.
        $resolver = $this->stripComments((string) file_get_contents(self::installRoot().'/includes/execution-mode.php'));
        preg_match_all('/\'source\'\s*=>\s*\'([a-z_]+)\'/', $resolver, $sourceMatches);
        $sources = array_values(array_unique($sourceMatches[1]));

        $this->assertNotEmpty($sources, 'execution-mode.php 에서 판정 근거 어휘를 읽지 못했다 — 표기가 바뀌었다');
        foreach ($sources as $source) {
            $key = 'execution_mode_source_'.$source;
            if (! isset($defined[$key])) {
                $missing[] = 'execution-mode.php → '.$key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '동적으로 조립되는 키의 어휘가 lang 에 없다 — 그 화면에만 번역 대신 키 문자열이 나간다'
        );
    }

    /**
     * 자리표시자 치환이 `lang(key, replace)` 한 지점을 지나는가 (12차 #C8).
     *
     * 7차가 치환을 `lang(key, replace)` 로 단일화했는데 `lang(key).replace(':x', …)`
     * 형태가 남아 두 계통이 공존한다. `String.prototype.replace` 는 첫 문자열 인자에
     * 대해 **첫 occurrence 만** 바꾸는 반면 `lang()` 내부는 전부 치환한다 — 같은
     * 자리표시자가 두 번 나오는 문구가 생기는 순간 한쪽만 채워진 문장이 나간다.
     *
     * 잔존 지점은 이 공정의 소유 밖이므로 **상한(ceiling)** 으로 고정한다: 새로 늘지
     * 않도록 막고, 고쳐서 줄어드는 것은 통과시킨다. 0 이 되면 이 표를 지운다.
     */
    public function test_placeholder_substitution_goes_through_the_lang_helper(): void
    {
        /** @var array<string, int> 파일별 잔존 허용 상한 (감소만 허용) */
        $ceiling = [
            'assets/js/installer.js' => 14,
        ];

        $root = self::installRoot();
        $counts = [];

        foreach ($this->sourceFiles() as $file) {
            $source = $this->stripComments((string) file_get_contents($file));
            $found = preg_match_all(
                '/\b(?:lang|getLangMessage)\(\s*\'[a-z0-9_.]+\'\s*\)\s*\.replace\(/i',
                $source
            );

            if ($found > 0) {
                $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
                $counts[$relative] = ($counts[$relative] ?? 0) + $found;
            }
        }

        $exceeded = [];
        foreach ($counts as $relative => $count) {
            $allowed = $ceiling[$relative] ?? 0;
            if ($count > $allowed) {
                $exceeded[] = $relative.': '.$count.' (상한 '.$allowed.')';
            }
        }

        $this->assertSame(
            [],
            $exceeded,
            '자리표시자 치환은 lang(key, replace) 한 지점을 지난다 — .replace(문자열, …) 은 첫 occurrence 만 바꾼다'
        );
    }

    /**
     * PHP·JS 주석을 지운다.
     *
     * PHP 는 토크나이저로 정확히 지우고, JS 는 토크나이저가 없으므로 줄 주석·블록 주석을
     * 보수적으로 지운다(문자열 안의 `//` 를 지우지 않도록 따옴표 상태를 따라간다).
     *
     * @param  string  $source  원본
     * @return string 주석이 지워진 소스
     */
    private function stripComments(string $source): string
    {
        if (str_starts_with(ltrim($source), '<?php')) {
            $out = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                        ? str_repeat("\n", substr_count($token[1], "\n"))
                        : $token[1];

                    continue;
                }
                $out .= $token;
            }

            return $out;
        }

        $out = '';
        $length = strlen($source);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $i + 1 < $length ? $source[$i + 1] : '';

            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\') {
                    $out .= $next;
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $quote = $char;
                $out .= $char;

                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $segment = $end === false ? substr($source, $i) : substr($source, $i, $end - $i + 2);
                $out .= str_repeat("\n", substr_count($segment, "\n"));
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * 대조 대상 소스 — 인스톨러 PHP 전체 + 인스톨러 JS.
     *
     * @return array<int, string>
     */
    /**
     * 재귀 스캔 — 한 겹 `glob('*.php')` 은 하위 디렉토리가 하나 생기는 순간 그 안을 통째로
     * 놓친다. 모집단 밖의 위반은 영원히 초록이므로 예외 없이 재귀로 훑는다.
     *
     * @param  string  $dir  뿌리 디렉토리
     * @param  array<int, string>  $extensions  대상 확장자
     * @return array<int, string> 파일 절대경로 (정렬됨)
     */
    private function scanFiles(string $dir, array $extensions): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && in_array(strtolower($entry->getExtension()), $extensions, true)) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $entry->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function sourceFiles(): array
    {
        $root = self::installRoot();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            if (str_contains(str_replace('\\', '/', $path), '/lang/')) {
                continue;
            }
            if (in_array(strtolower($entry->getExtension()), ['php', 'js'], true)) {
                $files[] = $path;
            }
        }

        sort($files);
        $this->assertNotEmpty($files);

        return $files;
    }

    /**
     * 인라인 `<script>` 안에 심는 문구는 이스케이프 헬퍼를 지나는가 (11차 #D4).
     *
     * 9차 #L36 이 `installerJsString()` 을 만들었지만 호출 31곳이 전부 한 파일
     * (`views/3-configuration.php`)이고, 그 함수에 테스트 참조가 **0** 이었다 — 본문을
     * `return "'".$value."'";` 로 바꿔도 전 스위트가 초록이다. 나머지 인라인 스크립트 두
     * 곳은 서로 다른 규칙(지역 상수·맨 json_encode)을 쓰고 있었고 그 사실도 무검사였다.
     */
    public function test_inline_script_string_helper_escapes_dangerous_characters(): void
    {
        require_once self::installRoot().'/includes/functions.php';

        $cases = [
            'quote' => "it's",
            'tag' => '</script><script>alert(1)</script>',
            'amp' => 'a & b',
            'newline' => 'line1
line2',
            'dquote' => 'say "hi"',
        ];

        foreach ($cases as $label => $value) {
            $encoded = installerJsString($value);

            $this->assertStringStartsWith('"', $encoded, $label.': JS 문자열 리터럴이어야 한다');
            $this->assertStringNotContainsString('</script', $encoded, $label.': 스크립트 종료 태그가 그대로 나가면 안 된다');
            $this->assertSame($value, json_decode($encoded, true), $label.': 값이 왕복해야 한다');
        }

        // 한글은 그대로 둔다 — 이스케이프하면 화면 문구가 읽을 수 없게 된다.
        $this->assertStringContainsString('설치', installerJsString('설치'));
    }

    /**
     * 인라인 스크립트가 문구를 심는 채널이 갈라지지 않았는가 (11차 #D4).
     *
     * 규칙이 파일마다 다르면 한 파일만 규칙에서 뒤처져도 아무도 모른다.
     */
    public function test_inline_scripts_use_the_shared_escaping_helper(): void
    {
        $offenders = [];

        foreach ($this->scanFiles(self::installRoot().'/views', ['php']) as $path) {
            $source = $this->stripComments((string) file_get_contents((string) $path));

            // `<script>` 구간만 본다 — HTML 본문의 lang() 은 htmlspecialchars 담당이다.
            if (preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $source, $blocks) !== 1 && ! isset($blocks[1])) {
                continue;
            }

            foreach ($blocks[1] ?? [] as $block) {
                if (preg_match('/(?<!installerJsString\()json_encode\s*\(\s*lang\s*\(/', $block) === 1) {
                    $offenders[] = basename((string) $path);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            '인라인 스크립트의 문구는 installerJsString() 한 지점을 지난다 — 파일마다 다른 규칙을 쓰면 한 곳만 뒤처져도 드러나지 않는다'
        );
    }
}
