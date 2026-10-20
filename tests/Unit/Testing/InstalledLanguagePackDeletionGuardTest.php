<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;

/**
 * 시험 코드가 실제 설치 언어팩 폴더(`lang-packs/<공식 팩 식별자>`)를 지우거나 덮어쓰지 않는지 정적으로 잠근다.
 *
 * 2026-10-18 11:26 실측: `ManagerSyncFilterTest`·`LanguagePackSeedInjectorTest` 가 시험 파일을
 * `base_path("lang-packs/g7-{$scope}-{$target}-ja")` — 실제 설치 팩과 같은 이름 — 에 쓰고 `finally` 에서
 * `File::deleteDirectory` 로 그 폴더를 통째로 지웠다. 필터 실행 한 번 뒤 개발 서버의 설치 ja 팩 4개가 사라져
 * DB 는 active 인데 「파일 없음」이 됐고 일본어 화면 일부가 영어로 떨어졌다. RefreshDatabase 는 DB 만 되돌리고
 * 파일 시스템은 개발 클론을 그대로 쓰므로, 시험은 공식 팩 이름과 겹치지 않는 가짜 식별자(`g7test-…` 등)를 쓴다.
 *
 * 판정(시험 PHP 전 파일, 주석 제외):
 *  - 지우기·옮기기 호출(`deleteDirectory`·`cleanDirectory`·`moveDirectory`·`File::delete`·`unlink`·`rmdir`)의 첫 인자를
 *    같은 파일의 대입·상수로 풀었을 때 `lang-packs/[_bundled/]<공식 팩 식별자>` 이거나 공식 벤더 접두 `g7-` 뒤에
 *    변수를 이어 붙인 `lang-packs/g7-{$…}` 꼴이면 위반 — 변수 값에 따라 실제 설치 팩이 된다.
 *  - 실제 서비스로 공식 팩을 번들 설치(`installFromBundled('<공식 팩>')`)하면 설치본을 교체하므로 위반.
 *  - 동적 모양: `foreach (<lang-packs 아래 나열 — directories·glob·scandir·files> as $v)` 의 `$v` 를 지우면 위반 — 이름을
 *    확인하지 않아 설치본·다른 실행의 사본까지 지운다(`LanguagePackSecurityTest` tearDown 이 `_pending` 하위를 통째로 지움).
 *    가짜 접두로 좁힌 glob(`…/g7test-*`)·시험이 만든 항목만 고른 목록·임시 루트 나열은 통과.
 *
 * 2026-10-18 사고의 네 폴더 가운데 `g7-plugin-sirsoft-pay_nicepayments-ja` 는 시험이 아니라 운영 경로에서 사라졌다 —
 * 2026-10-14 20:03:28 번들 재설치의 `finalizeInstall` 이 옛 폴더를 지운 뒤 rename 실패를 보지 않았다(사본이
 * `_pending/5d807708-…` 에 남음). 그 수정과 회귀 시험은 `LanguagePackInstallDirectoryReplaceTest`.
 *  - 면제는 (파일, 식별자) 단위로만 두고, 면제된 파일은 실행 전 설치본을 옮겨 두고 끝나면 되돌리는 표지를 지녀야 한다.
 *
 * @scenario-manifest tests/scenarios/testing-installed-lang-pack-isolation.yaml
 */
class InstalledLanguagePackDeletionGuardTest extends TestCase
{
    /**
     * 공식 팩 이름을 쓰되 설치본을 백업·복원하는 파일 → 허용 식별자·백업 표지·사유.
     *
     * 표지 정규식은 그 파일에 모두 있어야 한다 — 백업 로직이 사라지면 면제도 함께 무효가 된다.
     *
     * @var array<string, array{identifiers: list<string>, markers: list<string>, reason: string}>
     */
    private const EXEMPTIONS = [
        'tests/Unit/Services/LanguagePack/LanguagePackServiceTest.php' => [
            'identifiers' => ['g7-core-ja'],
            'markers' => [
                '/protectedRealInstallDir\s*=\s*\$backup/',
                '/function\s+tearDown\b[\s\S]*moveDirectoryReliably\(\s*\$this->protectedRealInstallDir/',
            ],
            'reason' => '실번들 설치 경로(installFromBundled(g7-core-ja))가 시험 대상이라 실제 이름이 필요하다 — setUp 이 설치본을 '
                .'lang-packs/.test-backup-g7-core-ja 로 옮기고 tearDown 이 되돌리며, 옮기지 못하면 시험을 건너뛴다.',
        ],
    ];

    /** 공식 번들 팩 수 하한 — 디렉터리 해석이 어긋나 모집단이 비면 가드가 조용히 통과한다. */
    private const MIN_OFFICIAL_PACKS = 10;

    /** 검사 대상 시험 파일 수 하한. */
    private const MIN_TEST_FILES = 1000;

    /**
     * 시험 코드 어디에도 공식 설치 팩 폴더를 지우는 자리가 없다(면제 파일의 허용 식별자 제외).
     *
     * @scenario target=repository_tests
     *
     * @effects tests_never_delete_installed_official_lang_pack_dirs
     *
     * @return void 반환값 없음
     */
    public function test_no_test_deletes_an_installed_official_lang_pack(): void
    {
        $packs = $this->officialPackIdentifiers();
        $this->assertGreaterThanOrEqual(self::MIN_OFFICIAL_PACKS, count($packs), '공식 번들 팩 목록(lang-packs/_bundled)을 읽지 못했다');

        $files = $this->scanTestFiles();
        $this->assertGreaterThanOrEqual(self::MIN_TEST_FILES, count($files), '검사 대상 시험 파일 모집단이 너무 작다 — 경로 해석을 확인할 것');

        $violations = [];
        foreach ($files as $relative) {
            $content = (string) file_get_contents($this->repoRoot().'/'.$relative);
            $allowed = self::EXEMPTIONS[$relative]['identifiers'] ?? [];
            foreach ($this->findViolations($content, $packs) as $violation) {
                if ($violation['identifier'] !== null && in_array($violation['identifier'], $allowed, true)) {
                    continue;
                }
                $violations[] = "{$relative}:{$violation['line']} {$violation['message']}";
            }
        }

        $this->assertSame([], $violations, "시험이 실제 설치 언어팩 폴더를 지운다 — 가짜 식별자(g7test-…)를 쓰거나 설치본을 백업·복원할 것:\n"
            .implode("\n", $violations));
    }

    /**
     * 면제 파일은 실재하고, 백업·복원 표지를 지니며, 면제 식별자는 실제로 쓰인다(죽은 면제 금지).
     *
     * @scenario target=exemption_registry
     *
     * @effects lang_pack_deletion_exemptions_require_backup_restore
     *
     * @return void 반환값 없음
     */
    public function test_exemptions_keep_backup_and_restore(): void
    {
        $packs = $this->officialPackIdentifiers();

        foreach (self::EXEMPTIONS as $relative => $exemption) {
            $path = $this->repoRoot().'/'.$relative;
            $this->assertFileExists($path, "면제 파일이 없다: {$relative}");
            $content = (string) file_get_contents($path);

            foreach ($exemption['markers'] as $marker) {
                $this->assertMatchesRegularExpression($marker, $content, "{$relative}: 백업·복원 표지가 사라졌다({$marker}) — 면제 근거가 없다");
            }

            $used = array_values(array_unique(array_filter(array_column($this->findViolations($content, $packs), 'identifier'))));
            foreach ($exemption['identifiers'] as $identifier) {
                $this->assertContains($identifier, $packs, "{$relative}: 면제 식별자 {$identifier} 는 공식 팩이 아니다");
                $this->assertContains($identifier, $used, "{$relative}: 면제 식별자 {$identifier} 를 더는 지우지 않는다 — 면제를 걷을 것");
            }
        }
    }

    /**
     * 판정식은 사고의 세 모양(보간 이름·변수에 담은 실제 이름·실번들 설치)과 lang-packs 아래 나열 항목을 통째로 지우는
     * 동적 모양을 잡고, 가짜 이름·번들 읽기·임시 루트·시험이 만든 항목만 고른 정리는 통과시킨다.
     *
     * @scenario target=synthetic_samples
     *
     * @effects detector_flags_f59_incident_shapes, detector_flags_lang_pack_enumeration_deletes
     *
     * @return void 반환값 없음
     */
    public function test_detector_flags_incident_shapes_and_passes_safe_shapes(): void
    {
        $packs = ['g7-plugin-sirsoft-gdpr-ja', 'g7-module-sirsoft-ecommerce-ja', 'g7-core-ja'];

        // 원형 — ManagerSyncFilterTest(수정 전)
        $interpolated = <<<'PHP'
<?php
function t($scope, $target) {
    $packRoot = base_path("lang-packs/g7-{$scope}-{$target}-ja");
    File::ensureDirectoryExists($packRoot.'/seed');
    try {} finally { File::deleteDirectory($packRoot); }
}
PHP;
        $hits = $this->findViolations($interpolated, $packs);
        $this->assertCount(1, $hits, '보간으로 공식 이름이 되는 경로의 삭제를 놓쳤다');
        $this->assertSame(5, $hits[0]['line']);

        // 실제 이름을 상수·프로퍼티에 담아 지우기 — LanguagePackSeedInjectorTest(수정 전)
        $literal = <<<'PHP'
<?php
class X {
    private const PACK = 'g7-module-sirsoft-ecommerce-ja';
    private const TARGET = 'sirsoft-gdpr';
    private string $root;
    function a() { $this->root = base_path('lang-packs/'.self::PACK); }
    function b() { File::deleteDirectory($this->root); }
    function c() { File::deleteDirectory(base_path('lang-packs/_bundled/g7-core-ja')); }
    function d() { File::deleteDirectory(base_path('lang-packs/g7-plugin-'.self::TARGET.'-ja')); }
}
PHP;
        $hits = $this->findViolations($literal, $packs);
        $this->assertSame(['g7-module-sirsoft-ecommerce-ja', 'g7-core-ja', 'g7-plugin-sirsoft-gdpr-ja'], array_column($hits, 'identifier'));

        $install = <<<'PHP'
<?php
$pack = $this->service->installFromBundled('g7-plugin-sirsoft-gdpr-ja', autoActivate: true);
$mock->shouldReceive('installFromBundled')->with('g7-core-ja');
PHP;
        $hits = $this->findViolations($install, $packs);
        $this->assertSame(['g7-plugin-sirsoft-gdpr-ja'], array_column($hits, 'identifier'), '실번들 설치는 잡고 목 기대값은 통과해야 한다');

        // 동적 모양 — lang-packs 아래를 나열해 이름 확인 없이 지운다. LanguagePackSecurityTest tearDown(수정 전)이
        // lang-packs/_pending 하위를 통째로 지워, 2026-10-14 nicepayments-ja 재설치 실패의 증거 사본(_pending/5d807708-…)까지 지웠다.
        $enumerated = <<<'PHP'
<?php
class Y {
    function tearDown(): void {
        $pendingRoot = base_path('lang-packs/_pending');
        foreach (File::directories($pendingRoot) as $sub) {
            File::deleteDirectory($sub);
        }
        foreach (glob(base_path('lang-packs/*'), GLOB_ONLYDIR) as $dir) { File::deleteDirectory($dir); }
    }
}
PHP;
        $hits = $this->findViolations($enumerated, $packs);
        $this->assertSame([6, 8], array_column($hits, 'line'), 'lang-packs 아래를 나열해 통째로 지우는 동적 모양을 놓쳤다');

        // 통과해야 하는 동적 모양 — 시험이 만든 항목만 고른 목록, 가짜 접두로 좁힌 glob, 임시 루트 나열
        $enumeratedSafe = <<<'PHP'
<?php
class Z {
    function tearDown(): void {
        foreach ($this->leftoverPendingEntries() as $entry) { File::deleteDirectory(base_path('lang-packs/_pending/'.$entry)); }
        foreach (glob(base_path('lang-packs/_pending/'.self::ID.'_old_*')) as $old) { File::deleteDirectory($old); }
        foreach (glob(base_path('lang-packs/g7test-*')) as $fake) { File::deleteDirectory($fake); }
        foreach (File::directories(storage_path('t/lang-packs')) as $tmp) { File::deleteDirectory($tmp); }
        foreach (glob(base_path('lang-packs/_bundled/*-ja/seed/notifications.json')) as $seed) { $x = file_get_contents($seed); }
    }
}
PHP;
        $this->assertSame([], $this->findViolations($enumeratedSafe, $packs), '안전한 동적 모양을 위반으로 잡았다');

        // 통과해야 하는 모양
        $safe = <<<'PHP'
<?php
function t($scope, $target) {
    $identifier = "g7test-{$scope}-{$target}-ja";
    $packRoot = base_path('lang-packs/'.$identifier);
    File::deleteDirectory($packRoot);
    // File::deleteDirectory(base_path('lang-packs/g7-core-ja'));  주석은 판정하지 않는다
    $read = file_get_contents(base_path('lang-packs/g7-core-ja/backend/ja/common.php'));
    File::copyDirectory(base_path('lang-packs/_bundled/g7-core-ja'), $packRoot);
    $fake = storage_path('test_base_'.uniqid());
    File::ensureDirectoryExists($fake.'/lang-packs/g7-core-ja');
    File::deleteDirectory($fake);
    File::deleteDirectory(base_path('lang-packs/test-core-ja'));
    $plugin = 'acme-menu-plugin';
    File::deleteDirectory(base_path('lang-packs/g7-plugin-'.$plugin.'-ja'));
}
PHP;
        $this->assertSame([], $this->findViolations($safe, $packs), '안전한 모양을 위반으로 잡았다');
    }

    /**
     * 한 파일에서 공식 설치 팩을 지우거나 교체하는 자리를 찾는다.
     *
     * @param  string  $content  PHP 소스
     * @param  list<string>  $packs  공식 팩 식별자 목록
     * @return list<array{line: int, identifier: ?string, message: string}> 위반 목록
     */
    private function findViolations(string $content, array $packs): array
    {
        $code = $this->stripComments($content);
        $bindings = $this->collectBindings($code);
        $alternation = implode('|', array_map(fn (string $p) => preg_quote($p, '#'), $packs));
        // `lang-packs/` 와 식별자 사이에 올 수 있는 이음매: 따옴표·문자열 잇기·DIRECTORY_SEPARATOR·풀어 쓴 표지(«»)
        $joint = '(?:[\s\'".«»]|DIRECTORY_SEPARATOR)*';
        $officialPath = '#lang-packs'.$joint.'(?:/|DIRECTORY_SEPARATOR)?'.$joint.'(?:_bundled'.$joint.'(?:/|DIRECTORY_SEPARATOR)'.$joint.')?('.$alternation.')(?![a-z0-9_-])#';
        // 풀지 못한 변수(`$x`·`{$x}`)가 공식 벤더 접두 `g7-` 뒤에 붙은 꼴 — 값에 따라 공식 이름이 된다.
        $interpolatedOfficial = '#lang-packs'.$joint.'/?'.$joint.'g7-[a-z0-9_-]*(?:\{\$|\$|[\'"]\s*\.\s*\$)#';
        $tempRoot = '#storage_path\(|sys_get_temp_dir\(|tempnam\(#';

        $enumerated = $this->collectLangPackEnumerationVariables($code, $bindings, $tempRoot);

        $violations = [];
        if (preg_match_all('#\b(deleteDirectory|deleteDirectories|cleanDirectory|moveDirectory|unlink|rmdir)\s*\(|File::delete\s*\(#', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$text, $offset]) {
                $argument = $this->firstArgument($code, $offset + strlen($text));
                $resolved = $this->resolve($argument, $bindings);
                if (preg_match($tempRoot, $resolved)) {
                    continue;
                }
                $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                // 동적 모양 — lang-packs 아래를 나열한 항목을 이름 확인 없이 지운다(LanguagePackSecurityTest tearDown 이
                // lang-packs/_pending 하위를 통째로 지워, 다른 실행·실서버 설치가 쓰던 사본까지 함께 지웠다).
                foreach ($enumerated as $variable => $listing) {
                    if (preg_match('#'.preg_quote($variable, '#').'(?![A-Za-z0-9_])#', $argument)) {
                        $violations[] = ['line' => $line, 'identifier' => null, 'message' => "lang-packs 아래를 나열한 항목({$variable} ← {$listing})을 이름 확인 없이 지운다 — 시험이 만든 항목만 골라 지우거나 가짜 접두로 좁힌 glob 을 쓸 것"];

                        continue 2;
                    }
                }
                if (preg_match_all($officialPath, $resolved.' '.$this->joinLiterals($resolved), $ids)) {
                    foreach (array_unique($ids[1]) as $identifier) {
                        $violations[] = ['line' => $line, 'identifier' => $identifier, 'message' => "공식 설치 팩 폴더 lang-packs/{$identifier} 를 지운다: ".$this->compact($argument)];
                    }

                    continue;
                }
                if (preg_match($interpolatedOfficial, $resolved)) {
                    $violations[] = ['line' => $line, 'identifier' => null, 'message' => '공식 벤더 접두 g7- 에 변수를 이은 lang-packs 경로를 지운다(변수 값에 따라 실제 설치 팩이 된다): '.$this->compact($argument)];
                }
            }
        }

        // 목 기대값(`shouldReceive('installFromBundled')`)은 이름 바로 뒤에 여는 괄호가 오지 않으므로 잡히지 않는다.
        if (preg_match_all('#\binstallFromBundled\s*\(\s*([^,)]+)#', $code, $installs, PREG_OFFSET_CAPTURE)) {
            foreach ($installs[1] as [$argument, $offset]) {
                $resolved = $this->resolve($argument, $bindings);
                if (preg_match('#[\'"«]\s*('.$alternation.')\s*[\'"»]#', $resolved, $id)) {
                    $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                    $violations[] = ['line' => $line, 'identifier' => $id[1], 'message' => "실제 서비스로 공식 팩 {$id[1]} 를 번들 설치해 설치본을 교체한다"];
                }
            }
        }

        usort($violations, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return $violations;
    }

    /**
     * `foreach (<lang-packs 아래 나열> as $v)` 의 루프 변수를 모은다 — 나열 함수(`directories`·`glob`·`scandir`·`files` 등)의
     * 인자를 풀어 `lang-packs` 경로이면 대상. 가짜 접두로 좁힌 glob(`…/g7test-*`·`…/{$id}_old_*` 처럼 `*` 바로 앞에 글자가 있음)과
     * 임시 루트는 제외한다.
     *
     * @param  string  $code  주석을 지운 소스
     * @param  array<string, list<string>>  $bindings  이름 → 대입식 목록
     * @param  string  $tempRoot  임시 루트 판정 정규식
     * @return array<string, string> 루프 변수 → 나열 식(메시지용)
     */
    private function collectLangPackEnumerationVariables(string $code, array $bindings, string $tempRoot): array
    {
        $variables = [];
        if (! preg_match_all('#\bforeach\s*\((.+?)\s+as\s+(?:\$\w+\s*=>\s*)?(\$\w+)\s*\)#s', $code, $loops, PREG_SET_ORDER)) {
            return $variables;
        }

        foreach ($loops as [, $listing, $variable]) {
            if (! preg_match('#\b(?:directories|allDirectories|files|allFiles|glob|scandir)\s*\(#', $listing)) {
                continue;
            }
            $resolved = $this->resolve($listing, $bindings);
            $joined = $this->joinLiterals($resolved);
            if (! str_contains($joined, 'lang-packs') || preg_match($tempRoot, $resolved) || preg_match('#[A-Za-z0-9_-]\*#', $joined)) {
                continue;
            }
            $variables[$variable] = $this->compact($listing);
        }

        return $variables;
    }

    /**
     * 주석과 히어독·나우독 본문을 같은 줄 수의 빈 줄로 바꾼다 (줄 번호 보존).
     *
     * 히어독 본문을 비우는 것은 이 가드 자신의 합성 표본(나우독)이 판정 대상이 되지 않게 하려는 것이다 — 시험 코드의
     * 실제 경로는 일반 문자열로 쓰인다.
     *
     * @param  string  $content  PHP 소스
     * @return string 주석·히어독 본문을 지운 소스
     */
    private function stripComments(string $content): string
    {
        $out = '';
        $inHeredoc = false;
        foreach (token_get_all($content) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if ($id === T_START_HEREDOC) {
                $inHeredoc = true;
            } elseif ($id === T_END_HEREDOC) {
                $inHeredoc = false;
            } elseif ($inHeredoc || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($text, "\n"));

                continue;
            }
            $out .= $text;
        }

        return $out;
    }

    /**
     * 파일 안의 대입·프로퍼티 기본값·상수를 이름 → 식 목록으로 모은다 (스코프는 보지 않는다 — 넓게 잡는 쪽이 안전하다).
     *
     * @param  string  $code  주석을 지운 소스
     * @return array<string, list<string>> 이름 → 대입식 목록
     */
    private function collectBindings(string $code): array
    {
        $bindings = [];
        if (preg_match_all('#(\$this->\w+|\$\w+)\s*(?:\.=|=)(?![=>])\s*([^;]+);#', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $name, $expr]) {
                $bindings[$name][] = $expr;
            }
        }
        if (preg_match_all('#(?:private|protected|public)\s+(?:static\s+)?(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)\s*=\s*([^;]+);#', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $name, $expr]) {
                $bindings['$this->'.$name][] = $expr;
                $bindings['self::$'.$name][] = $expr;
                $bindings['static::$'.$name][] = $expr;
            }
        }
        if (preg_match_all('#\bconst\s+(?:\w+\s+)?(\w+)\s*=\s*([^;]+);#', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $name, $expr]) {
                $bindings['self::'.$name][] = $expr;
                $bindings['static::'.$name][] = $expr;
            }
        }

        return $bindings;
    }

    /**
     * 식 안의 변수·상수를 같은 파일의 대입식으로 몇 단계 풀어 쓴다 (풀린 자리는 «…» 로 감싼다).
     *
     * @param  string  $expr  식
     * @param  array<string, list<string>>  $bindings  이름 → 대입식 목록
     * @param  int  $depth  남은 풀이 깊이
     * @return string 풀어 쓴 식
     */
    private function resolve(string $expr, array $bindings, int $depth = 4): string
    {
        if ($depth === 0) {
            return $expr;
        }

        return (string) preg_replace_callback('#(?:self|static)::\$?\w+|\$this->\w+(?!\s*\()|\$\w+#', function (array $m) use ($bindings, $depth) {
            $name = $m[0];
            if (! isset($bindings[$name])) {
                return $name;
            }

            return '«'.implode(' | ', array_map(fn (string $e) => $this->resolve($e, $bindings, $depth - 1), $bindings[$name])).'»';
        }, $expr);
    }

    /**
     * 풀어 쓴 식에서 문자열 조각 사이의 잇기(`'a'.«'b'».'c'`)를 없애 한 문자열로 붙인다 — 상수로 이어 만든 공식 이름도 잡기 위해.
     *
     * @param  string  $resolved  풀어 쓴 식
     * @return string 조각을 붙인 식
     */
    private function joinLiterals(string $resolved): string
    {
        do {
            $before = $resolved;
            $resolved = (string) preg_replace('#[\'"]\s*»*\s*\.\s*«*\s*[\'"]#u', '', $resolved);
        } while ($resolved !== $before);

        return $resolved;
    }

    /**
     * 여는 괄호 바로 뒤부터 첫 인자(최상위 쉼표 또는 닫는 괄호까지)를 잘라낸다.
     *
     * @param  string  $code  소스
     * @param  int  $start  여는 괄호 다음 위치
     * @return string 첫 인자 식
     */
    private function firstArgument(string $code, int $start): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($code);
        for ($i = $start; $i < $length; $i++) {
            $ch = $code[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
            } elseif ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                break;
            }
        }

        return substr($code, $start, $i - $start);
    }

    /**
     * 메시지용으로 식의 공백을 줄이고 길이를 자른다.
     *
     * @param  string  $expr  식
     * @return string 줄인 식
     */
    private function compact(string $expr): string
    {
        return mb_substr((string) preg_replace('#\s+#', ' ', trim($expr)), 0, 120);
    }

    /**
     * 공식 번들 팩 식별자 목록(`lang-packs/_bundled/*`)을 돌려준다.
     *
     * @return list<string> 식별자 목록
     */
    private function officialPackIdentifiers(): array
    {
        $dirs = glob($this->repoRoot().'/lang-packs/_bundled/*', GLOB_ONLYDIR) ?: [];
        $ids = array_values(array_filter(array_map('basename', $dirs), fn (string $id) => ! str_starts_with($id, '_') && ! str_starts_with($id, '.')));
        sort($ids);

        return $ids;
    }

    /**
     * PHPUnit 스위트가 도는 시험 PHP 파일 전부(코어 tests/ 와 번들 확장 tests/)를 저장소 기준 상대 경로로 돌려준다.
     *
     * @return list<string> 상대 경로 목록
     */
    private function scanTestFiles(): array
    {
        $root = $this->repoRoot();
        $roots = [$root.'/tests'];
        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach (glob($root.'/'.$type.'/_bundled/*/tests', GLOB_ONLYDIR) ?: [] as $dir) {
                $roots[] = $dir;
            }
        }

        $files = [];
        foreach ($roots as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! str_ends_with($path, '.php') || str_contains($path, '/node_modules/')) {
                    continue;
                }
                $files[] = substr($path, strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * 저장소 루트 절대 경로(슬래시 구분)를 돌려준다.
     *
     * @return string 저장소 루트
     */
    private function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 3));
    }
}
