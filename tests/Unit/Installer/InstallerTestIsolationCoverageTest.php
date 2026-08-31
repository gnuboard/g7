<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 테스트의 프로세스 격리 전수 검사.
 *
 * 인스톨러 테스트는 실 `.env`/상태 파일을 파괴하지 않으려고 `BASE_PATH` 를 temp 로 고정하고,
 * 그렇지 못하면 스스로 skip 한다(R7 안전가드). 그런데 `BASE_PATH` 는 상수라 **한 프로세스에
 * 하나뿐**이다 — 격리 없이 그 상수를 정의하는 클래스가 여럿이면 먼저 도는 쪽이 값을 박고,
 * 나머지는 안전가드에 걸려 조용히 통째로 skip 된다. 종료 코드는 그대로 0 이므로 **초록으로
 * 보이는 채 커버리지만 사라진다.**
 *
 * 실제로 그 사각 안에 GitHub 토큰 유출 회귀 테스트가 들어 있었다(6차 라운드 #4) — 어떤 공정이
 * `--filter` 로 일부만 돌리면 그 보안 게이트가 평가되지 않은 채 초록을 본다.
 *
 * 그래서 초록이 클래스 이름의 알파벳 순서에 기대지 않도록, `BASE_PATH` 를 정의하는 클래스는
 * 반드시 프로세스 격리 속성을 갖는다. 파일을 열거하지 않고 디렉토리를 스캔하므로 새로 추가되는
 * 클래스도 모집단이다.
 *
 * 커버 축: isolation_declaration class_isolated·method_isolated·missing / base_path_definition defines·does_not_define / temp_base_cleanup shared_trait·own_teardown·missing
 */
class InstallerTestIsolationCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 클래스 단위 / 메서드 단위 격리 — 둘 다 프로세스 경계를 만들어 상수 충돌을 없앤다 */
    private const ISOLATION_ATTRIBUTES = [
        'RunClassInSeparateProcess',
        'RunTestsInSeparateProcesses',
    ];

    /**
     * 인스톨러 include 중 `BASE_PATH` 를 (직접이든 전이든) 정의하는 것 — **소스에서 도출한다**.
     *
     * 이것을 require 하는 테스트는 본문에 `define('BASE_PATH'` 를 쓰지 않고도 상수를
     * 박는다 — 그것도 **실 저장소 루트**로. 문자열만 보는 검사는 이 경로를 통째로 놓치므로
     * 모집단 판정에 함께 넣는다.
     *
     * 19차 G#11 — 종전에는 이 목록이 **수기 4개**였다. 이 클래스의 docblock 은 「파일을
     * 열거하지 않고 디렉토리를 스캔하므로 새로 추가되는 클래스도 모집단이다」라고 클래스
     * 모집단의 도출성을 말하는데, 판정의 **다른 축**인 전이 정의자는 손으로 적혀 있었다.
     * 실측 결과 `api/save-extensions.php`·`api/scan-extensions.php` 두 개가 직접
     * `define('BASE_PATH'` 를 하면서 목록에 없었고, 그 둘을 require 하는 새 테스트는
     * 상수를 실 저장소 루트로 박고도 이 게이트를 통과한다 — 6차가 닫은 「조용한 skip」이
     * 그 자리에서 다시 열린다.
     *
     * 그래서 ① 직접 정의자를 인스톨러 트리 전체에서 스캔하고 ② 그것을 require 하는 파일을
     * 고정점에 도달할 때까지 접어 전이 정의자를 만든다. 새 파일이 생겨도 자동으로 든다.
     *
     * @return array<int, string> 인스톨러 루트 기준 상대 경로
     */
    private function transitiveDefiners(): array
    {
        $root = dirname(__DIR__, 3).'/public/install';
        $sources = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            // 읽기와 주석 제거를 공용 진입점에 묶는다 (G3 회차 14 W4-8) — 아래 직접 정의자 판정은
            // 주석을 지운 소스를 요구하고, 전이 판정(`loadsFile()`)은 토큰이라 주석에 무관하다.
            $sources[$relative] = $this->sourceWithoutComments($file->getPathname());
        }

        // ① 직접 정의자 — 따옴표 종류와 `define (` 공백을 모두 받는다. 주석을 지운 뒤 본다 —
        //    주석 처리된 `define('BASE_PATH'` 도 원문에는 글자로 남는다.
        $definers = [];
        foreach ($sources as $relative => $source) {
            if (preg_match('/\bdefine\s*\(\s*([\x27"])BASE_PATH\1/', $this->stripComments($source)) === 1) {
                $definers[$relative] = true;
            }
        }

        // ② 전이 — 정의자를 require 하는 파일도 정의자다. 고정점까지 접는다.
        //
        //    판정은 `loadsFile()` 의 토큰 경로다 (G3 회차 13 W5-5). 종전에는 원문 정규식
        //    `(?:require|include)(?:_once)?[^;]*<basename>` 이었다 — 주석 속 경로가 매치되고,
        //    `include` 가 **`includes/`** 의 앞 일곱 글자에 매치되며, `config.php` 가
        //    `some-config.php` 에 매치됐다. 같은 파일 안에서 테스트 쪽(`definesBasePath`)만
        //    토큰이고 이 축은 원문이라 한 판정이 두 규칙을 갖고 있었다.
        //
        //    판정 불가(`null` — 파일명이 변수인 require)는 **정의자로 친다**. 무엇을 로드하는지
        //    모르는 파일을 「로드하지 않는다」로 접으면 그 파일을 require 하는 테스트가
        //    상수를 실 저장소 루트로 박고도 이 게이트를 통과한다(fail-closed 를 택한다).
        //
        //    needle 은 **디렉토리를 포함한 상대 경로**로 넘긴다 (G3 회차 14 W4-7). 종전에는
        //    `basename($definer)` 였는데, 파일명뿐인 needle 로는 `loadsFile()` 이 동적
        //    require 의 디렉토리 리터럴(`__DIR__."/../lang/{$lang}.php"`)을 배제 근거로 쓸
        //    수 없다 — 같은 라운드가 그 자리의 `false` 접힘(fail-open)을 `null` 로 고치자
        //    `functions.php`·`validation.php`(번역 파일만 동적 require)가 `api/save-extensions.php`
        //    의 전이 정의자로 실측됐고, 그것을 require 하는 테스트 셋에 거짓 격리 의무가
        //    생겼다. 이 소비처는 정의자의 디렉토리를 **알고 있으므로** 버리지 않는다 — 같은
        //    디렉토리 형제 형태(`__DIR__.'/config.php'`)는 `loadsFile()` 이 그대로 받는다.
        //    루트의 `index.php` 는 상대 경로에 디렉토리가 없으므로 `./` 를 붙여 「트리 루트
        //    기준」임을 선언한다(그 트레이트 독블록).
        do {
            $grew = false;
            foreach ($sources as $relative => $source) {
                if (isset($definers[$relative])) {
                    continue;
                }
                foreach (array_keys($definers) as $definer) {
                    if ($this->loadsFile($source, './'.$definer) !== false) {
                        $definers[$relative] = true;
                        $grew = true;
                        break;
                    }
                }
            }
        } while ($grew);

        return array_keys($definers);
    }

    private function scanDir(): string
    {
        return dirname(__DIR__, 2);   // tests/ 전체 — 인스톨러 테스트가 다른 디렉토리에 생겨도 잡는다
    }

    /**
     * 이 테스트 소스가 `BASE_PATH` 를 (직접이든 전이든) 정의하는가.
     *
     * 직접 정의는 주석을 지운 소스에서 본다. 전이 정의는 **로드**만 해당한다 — 경로를
     * 문자열로 언급만 하는 소스 대조 테스트(그 파일을 읽어 검사할 뿐 실행하지 않는다)까지
     * 잡으면 오탐이 된다. 판정은 트레이트의 `loadsFile()` 이 토큰으로 한다 (그 독블록에
     * 두 오탐 형태를 적어 두었다).
     *
     * `loadsFile()` 의 판정 불가(`null`)는 **정의자로 친다** — 파일명이 변수인 require 가
     * 정의자의 디렉토리를 가리키면 그 파일을 로드할 수 있고, 로드하면 상수가 박힌다.
     * 「모른다」를 「하지 않는다」로 접으면 6차가 닫은 조용한 skip 이 그 자리에서 다시 열린다.
     *
     * @param  string  $src  테스트 소스
     * @return bool 정의하면(또는 정의할 수 있으면) true
     */
    private function definesBasePath(string $src): bool
    {
        if (preg_match('/\bdefine\s*\(\s*([\'"])BASE_PATH\1/', $this->stripComments($src)) === 1) {
            return true;
        }

        foreach ($this->transitiveDefiners() as $include) {
            // 정의자 목록은 인스톨러 트리 루트 기준이다 — `./` 로 그 사실을 선언한다(위 전이 판정과
            // 같은 이유. 없으면 `require $path`(`…/lang/'.$code.'.php`) 같은 번역 파일 로드가 루트
            // 정의자 `index.php` 에 대해 판정 불가로 떨어져 그 테스트에 거짓 격리 의무가 생긴다).
            if ($this->loadsFile($src, './'.$include) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 프로세스 격리를 선언했는가.
     *
     * 묶음 표기(`#[RunClassInSeparateProcess, PreserveGlobalState(false)]`)도 유효한
     * 선언이다 — 닫는 대괄호를 요구하면 정상 선언을 위반으로 잡는다.
     */
    private function declaresIsolation(string $src): bool
    {
        foreach (self::ISOLATION_ATTRIBUTES as $attribute) {
            if (preg_match('/^\s*#\[\s*(?:[A-Za-z0-9_\\\\]+\s*(?:\([^)]*\))?\s*,\s*)*'.$attribute.'\b/m', $src) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string> 인스톨러 테스트 파일 경로
     */
    private function scannedFiles(): array
    {
        // 재귀 스캔 (11차 #D10). 종전에는 `tests/Unit/Installer` **한 디렉토리·비재귀**라
        // `tests/Feature/Installer/**` 나 하위 디렉토리에 인스톨러 테스트를 만들면 BASE_PATH 를
        // 실 저장소 루트로 박아도 게이트 밖이었다 — 6차 #4 가 닫은 「조용한 skip」이 그 위치에서
        // 다시 열린다. 모집단 조건은 위치가 아니라 **인스톨러 include 를 로드하는가**로 잡는다.
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->scanDir()));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            // 모집단 조건도 주석을 지운 소스로 본다 — 독블록에서만 인스톨러 경로를 언급하는 테스트는
            // 인스톨러 include 를 로드하지 않는다 (G3 회차 14 W4-8).
            $source = $this->sourceWithoutComments($file->getPathname());
            if (! str_contains($source, 'public/install/')) {
                continue;   // 인스톨러 include 를 건드리지 않는 테스트는 이 게이트의 대상이 아니다
            }

            $files[] = $file->getPathname();
        }
        sort($files);

        // 스캐너 자신은 모집단이 아니다 — 검사 대상 문자열을 본문에 들고 있을 뿐이다
        return array_values(array_filter($files, fn (string $f) => basename($f) !== basename(__FILE__)));
    }

    public function test_population_is_not_empty(): void
    {
        $this->assertGreaterThanOrEqual(20, count($this->scannedFiles()), '인스톨러 테스트 모집단이 비면 이 게이트는 무의미하다');
    }

    /**
     * @effects installer_test_defining_base_path_declares_process_isolation
     */
    public function test_every_class_defining_base_path_declares_process_isolation(): void
    {
        $offenders = [];
        $definers = 0;

        foreach ($this->scannedFiles() as $path) {
            $src = $this->sourceWithoutComments($path);

            if (! $this->definesBasePath($src)) {
                continue;
            }

            $definers++;

            if (! $this->declaresIsolation($src)) {
                $offenders[] = basename($path);
            }
        }

        // 검출기가 죽으면(정규식 훼손·전이 목록 공백) 루프가 한 번도 판정에 도달하지 못한 채
        // 「위반 0」이 된다 — 아무것도 검사하지 않은 초록이다. 형제 테스트와 같은 하한을 둔다.
        $this->assertGreaterThanOrEqual(
            10,
            $definers,
            'BASE_PATH 를 정의하는 클래스를 하나도 찾지 못했다 — 검출기가 죽었을 때의 vacuous green 을 막는 하한이다'
        );

        $this->assertSame(
            [],
            $offenders,
            "BASE_PATH 를 정의하는데 프로세스 격리가 없는 클래스: \n  ".implode("\n  ", $offenders)
                ."\n격리가 없으면 다른 클래스와 같은 프로세스에서 돌 때 안전가드에 걸려 통째로 skip 된다 "
                .'(종료 코드는 0 이라 초록으로 보인다). #[RunClassInSeparateProcess] + #[PreserveGlobalState(false)] 를 붙일 것.'
        );
    }

    /**
     * 격리를 선언한 클래스는 전역 상태 보존도 함께 꺼야 한다 — 켜져 있으면 부모 프로세스의
     * 상수·전역이 자식으로 직렬화돼 격리가 반쪽이 된다.
     */
    public function test_isolated_classes_disable_global_state_preservation(): void
    {
        $offenders = [];
        $isolatedCount = 0;

        foreach ($this->scannedFiles() as $path) {
            $src = $this->sourceWithoutComments($path);

            if (! $this->declaresIsolation($src)) {
                continue;
            }

            $isolatedCount++;

            if (preg_match('/#\[\s*(?:[A-Za-z0-9_\\\\]+\s*(?:\([^)]*\))?\s*,\s*)*PreserveGlobalState\(false\)/', $src) !== 1) {
                $offenders[] = basename($path);
            }
        }

        // 모집단 하한 — 격리 선언 클래스가 0 이면 위 루프가 한 번도 돌지 않아 이 단언이
        // 아무것도 검사하지 않은 채 초록이 된다 (vacuous).
        $this->assertGreaterThanOrEqual(10, $isolatedCount, '격리 선언 클래스가 없으면 이 게이트는 무의미하다');

        $this->assertSame([], $offenders, '격리 선언 클래스는 #[PreserveGlobalState(false)] 를 함께 선언해야 한다: '.implode(', ', $offenders));
    }

    /**
     * temp `BASE_PATH` 를 **새로 만드는** 클래스는 그것을 정리한다 (12차 L-4).
     *
     * 이 클래스들은 대부분 `#[RunTestsInSeparateProcesses]` 라 테스트 메서드마다 새 임의
     * 디렉토리가 생긴다. 접미사가 임의값이라 교차 오염은 없지만 아무도 치우지 않아, 한 번
     * 돌 때마다 개발자 임시 디렉토리에 트리가 쌓인다 — 실측 시점에 42개 중 정리 절차를 가진
     * 클래스는 14개뿐이었다. 정리는 공용 트레이트(`CleansTemporaryBasePath`)나 클래스 자신의
     * `tearDownAfterClass()` 둘 중 하나로 선언한다.
     *
     * @effects installer_test_creating_temp_base_path_cleans_it_up
     */
    public function test_every_class_creating_a_temp_base_path_cleans_it_up(): void
    {
        $offenders = [];
        $creators = 0;

        foreach ($this->scannedFiles() as $path) {
            $src = $this->sourceWithoutComments($path);

            if (! $this->createsTemporaryBasePath($src)) {
                continue;
            }

            $creators++;

            if (! $this->declaresCleanup($src)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertGreaterThanOrEqual(
            10,
            $creators,
            'temp BASE_PATH 를 만드는 클래스를 하나도 찾지 못했다 — 검출기가 죽었을 때의 vacuous green 을 막는 하한이다'
        );

        $this->assertSame(
            [],
            $offenders,
            "temp BASE_PATH 를 만들지만 정리하지 않는 클래스: \n  ".implode("\n  ", $offenders)
                ."\n`use Tests\\Concerns\\CleansTemporaryBasePath;` 를 붙이거나 자기 tearDownAfterClass() 에서 지울 것."
        );
    }

    /**
     * 이 소스가 시스템 temp 하위에 `BASE_PATH` 트리를 **새로 만드는가**.
     *
     * 프로젝트 루트를 `BASE_PATH` 로 박는 구형 테스트는 대상이 아니다 — 만든 것이 없으므로
     * 지울 것도 없고, 그런 클래스에 정리 선언을 붙이면 그 선언 자체가 거짓이 된다.
     *
     * @param  string  $src  테스트 소스
     * @return bool 임시 트리를 만들면 true
     */
    private function createsTemporaryBasePath(string $src): bool
    {
        // `define('BASE_PATH', <식>)` 의 **그 식**을 본다. 파일 어딘가에 sys_get_temp_dir() 이
        // 있는지로 판정하면, STATE_PATH 만 temp 로 격리하고 BASE_PATH 는 프로젝트 루트로 박는
        // 구형 테스트가 함께 걸린다(실측 4건) — 지울 것이 없는 클래스에 정리 선언을 붙이면
        // 그 선언 자체가 거짓이 된다.
        if (preg_match('/\bdefine\s*\(\s*([\'"])BASE_PATH\1\s*,\s*([^;]*?)\)\s*;/s', $src, $m) !== 1) {
            return false;
        }

        $expression = $m[2];

        if (str_contains($expression, 'sys_get_temp_dir')) {
            return true;
        }

        // 변수·정적 속성을 거쳐 오는 표기(`self::$base` · `$this->tempBase` · `$base`)는
        // 그 대입식까지 한 단계 따라간다.
        if (preg_match('/(self::\$\w+|\$this->\w+|\$\w+)/', $expression, $ref) !== 1) {
            return false;
        }

        $assignment = '/'.preg_quote($ref[1], '/').'\s*=\s*([^;]*);/s';
        if (preg_match_all($assignment, $src, $assignments) === 0) {
            return false;
        }

        foreach ($assignments[1] as $rhs) {
            if (str_contains($rhs, 'sys_get_temp_dir')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 정리 절차를 선언했는가 (공용 트레이트 또는 자기 tearDownAfterClass).
     *
     * @param  string  $src  테스트 소스
     * @return bool 선언했으면 true
     */
    private function declaresCleanup(string $src): bool
    {
        return str_contains($src, 'CleansTemporaryBasePath')
            || preg_match('/function\s+tearDownAfterClass\s*\(/', $src) === 1;
    }

    /**
     * 판정 헬퍼의 **부정 분기**를 실제로 평가한다 (9차 #G14).
     *
     * 실 소스가 전부 준수 상태라 위 두 테스트의 `$offenders` 는 늘 빈 배열이다 — 판정이
     * 뒤집혀도(항상 true / 항상 false) red 가 나지 않는다. 합성 입력으로 양쪽 분기를 태운다.
     */
    public function test_detectors_actually_discriminate(): void
    {
        $direct = "<?php
define('BASE_PATH', '/tmp/x');
";
        $transitive = "<?php
require_once \$root.'/public/install/includes/installer-state.php';
";
        $mentionOnly = "<?php
\$path = 'includes/installer-state.php';
";
        $neither = '<?php
class Foo {}
';

        $this->assertTrue($this->definesBasePath($direct), '직접 define 을 놓치면 게이트가 통째로 무의미해진다');
        $this->assertTrue($this->definesBasePath($transitive), '전이 정의(로드)도 상수를 박는다');
        $this->assertFalse($this->definesBasePath($mentionOnly), '경로를 문자열로 언급만 하는 소스 대조 테스트는 대상이 아니다');
        $this->assertFalse($this->definesBasePath($neither));

        // G3 회차 13 W5-4·5 — 토큰 경로의 다섯 형태.
        $commentedDefine = "<?php\n// define('BASE_PATH', '/tmp/x');\n";
        $commentedRequire = "<?php\nrequire __DIR__.'/other.php'; // public/install/includes/installer-state.php\n";
        $dynamicRequire = "<?php\nforeach (\$files as \$file) {\n    require \$root.'/public/install/api/'.\$file;\n}\n";
        $langRequire = "<?php\n\$root = dirname(__DIR__, 3).'/public/install/lang';\n\$t = require \$root.'/'.\$locale.'.php';\n";
        $siblingName = "<?php\nrequire_once __DIR__.'/public/install/includes/some-installer-state.php';\n";

        $this->assertFalse($this->definesBasePath($commentedDefine), '주석 처리된 define 은 상수를 박지 않는다');
        $this->assertFalse($this->definesBasePath($commentedRequire), '주석 속 경로는 로드가 아니다');
        $this->assertTrue($this->definesBasePath($dynamicRequire), '파일명이 변수인 require 가 정의자의 디렉토리를 가리키면 보수적으로 정의자다 (fail-closed)');
        $this->assertFalse($this->definesBasePath($langRequire), '변수를 풀면 lang/ 임이 드러난다 — 판정 불가로 남기면 거짓 red 다');
        $this->assertFalse($this->definesBasePath($siblingName), '파일명 대조는 세그먼트 경계다');

        $isolatedClass = '<?php
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class A {}
';
        $isolatedGrouped = '<?php
#[RunTestsInSeparateProcesses, PreserveGlobalState(false)]
class B {}
';
        $notIsolated = "<?php
#[Group('x')]
class C {}
";

        $tempCreator = "<?php\ndefine('BASE_PATH', sys_get_temp_dir().'/g7-x-'.bin2hex(random_bytes(4)));\n";
        $rootDefiner = "<?php\ndefine('BASE_PATH', dirname(__DIR__, 3));\n";

        $viaProperty = "<?php\nself::\$base = sys_get_temp_dir().'/g7-y-'.bin2hex(random_bytes(4));\ndefine('BASE_PATH', self::\$base);\n";
        $stateOnly = "<?php\ndefine('BASE_PATH', \$projectRoot);\ndefine('STATE_PATH', sys_get_temp_dir().'/g7-state.json');\n";

        $this->assertTrue($this->createsTemporaryBasePath($tempCreator), 'temp 트리를 만드는 클래스를 놓치면 정리 게이트가 무의미해진다');
        $this->assertTrue($this->createsTemporaryBasePath($viaProperty), '정적 속성을 거쳐 오는 표기가 가장 흔하다');
        $this->assertFalse($this->createsTemporaryBasePath($rootDefiner), '프로젝트 루트를 박는 구형 테스트는 지울 것이 없다');
        $this->assertFalse($this->createsTemporaryBasePath($stateOnly), 'STATE_PATH 만 temp 로 격리한 클래스는 BASE_PATH 트리를 만들지 않는다');
        $this->assertFalse($this->createsTemporaryBasePath($neither));

        $this->assertTrue($this->declaresCleanup('use Tests\\Concerns\\CleansTemporaryBasePath;'));
        $this->assertTrue($this->declaresCleanup('public static function tearDownAfterClass(): void {}'));
        $this->assertFalse($this->declaresCleanup('protected function tearDown(): void {}'), '메서드 단위 tearDown 은 클래스 트리를 지우지 않는다');

        $this->assertTrue($this->declaresIsolation($isolatedClass));
        $this->assertTrue($this->declaresIsolation($isolatedGrouped), '묶음 표기도 유효한 선언이다');
        $this->assertFalse($this->declaresIsolation($notIsolated), '격리가 아닌 속성을 격리로 세면 사각이 그대로 열린다');
    }
}
