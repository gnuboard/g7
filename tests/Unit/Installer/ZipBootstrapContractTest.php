<?php

namespace Tests\Unit\Installer;

use App\Extension\Helpers\CoreBackupHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * ZIP 부트스트랩(`g7-setup.php`)의 소스 계약 (G4 · B-7 · §7 R27 · §11.13).
 *
 * 흐름 테스트(`ZipBootstrapFlowTest`)가 재지 못하는 것을 여기서 잰다 — **다른 파일과의
 * 관계**다. 부트스트랩은 압축이 풀리기 전에 도는 자기 완결 파일이라 코어의 어떤 판정도
 * require 할 수 없고, 그래서 사본을 갖는다. 사본은 조용히 갈린다.
 *
 * 커버 축: contract guard_parity·deferred_root_files·archive_strategy·release_payload /
 * outcome matches·drifted
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class ZipBootstrapContractTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private string $bootstrapPath = '';

    private string $source = '';

    /**
     * 저장소 루트의 압축 풀기 부트스트랩 파일이 있는지 확인하고 원문을 읽어 둔 뒤, 요청 처리 없이 상수와 순수 함수만 쓰도록 라이브러리 모드로 불러온다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapPath = dirname(__DIR__, 3).'/g7-setup.php';
        $this->assertFileExists($this->bootstrapPath, 'ZIP 부트스트랩 진입 파일이 저장소 루트에 있어야 한다');
        // meta:allow raw-source-read — 원문은 이 클래스의 주석 제거기(아래 sourceWithoutComments)와
        // 인라인 스타일시트 추출의 입력이다. 코드 문자 대조는 그 제거기를 지난 결과로만 한다.
        $this->source = (string) file_get_contents($this->bootstrapPath);

        // 상수·순수 함수만 쓰기 위해 요청 처리 없이 읽어 들인다 (파일 안의 경계 상수).
        if (! defined('G7_SETUP_LIBRARY_ONLY')) {
            define('G7_SETUP_LIBRARY_ONLY', true);
        }
        require_once $this->bootstrapPath;
    }

    /**
     * 미룰 루트 파일 목록이 코어의 루트 보호 파일 목록과 같아야 한다.
     *
     * 그 파일들은 모든 요청을 `public/` 으로 위임한다. 해제 도중에 놓이면 부트스트랩 자신이
     * 브라우저에서 사라져 재개 호출이 통째로 끊긴다 — 오류가 아니라 **화면이 멎는 것**이
     * 증상이라 목록이 하나 빠져도 알아채기 어렵다. 그래서 손으로 유지하지 않고 코어
     * 상수에서 도출한 목록과 대조한다.
     *
     * @scenario archive=g7_payload
     *
     * @effects zip_bootstrap_deferred_root_files_match_core_protection_list
     *
     * @return void 반환값 없음
     */
    public function test_미루는_루트_파일_목록이_코어_보호_파일_목록과_같다(): void
    {
        $expected = CoreBackupHelper::ROOT_PROTECTION_FILES;
        $actual = G7_SETUP_DEFERRED_ENTRIES;

        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            '루트 보호 파일이 늘었는데 부트스트랩의 지연 목록이 따라오지 않았다 — 그 파일이 해제 도중에 놓이면 재개가 끊긴다'
        );
    }

    /**
     * 해제 전략은 `ZipArchive` 단독이다 (§7 R27).
     *
     * `PharData` 는 경로 100바이트 제한으로 파일 324개를 조용히 누락시킨 선례가 있어 코어
     * 아카이브 추출에서 제거됐다. 외부 명령(`unzip`·`tar`)은 이 파일의 존재 이유(명령을 쓸 수
     * 없는 환경)와 정면으로 어긋난다.
     *
     * @scenario archive=g7_payload
     *
     * @effects zip_bootstrap_uses_ziparchive_only
     *
     * @return void 반환값 없음
     */
    public function test_아카이브_전략에_PharData_와_외부_명령이_없다(): void
    {
        // 주석을 걷어낸 **코드**만 본다 — 이 파일의 머리말은 왜 그 전략을 쓰지 않는지 설명하고
        // 있어, 원문 그대로 재면 그 설명 자체가 위반으로 잡힌다.
        $code = $this->sourceWithoutComments();

        foreach (['PharData', 'proc_open', 'shell_exec', 'passthru', 'popen'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "ZIP 부트스트랩이 금지된 해제·실행 수단을 쓴다: {$forbidden}"
            );
        }

        foreach (['exec', 'system'] as $forbidden) {
            $this->assertSame(
                0,
                preg_match('/(?<![A-Za-z_])'.$forbidden.'\s*\(/', $code),
                "ZIP 부트스트랩이 외부 명령을 실행한다: {$forbidden}()"
            );
        }

        $this->assertStringContainsString('ZipArchive', $code);
    }

    /**
     * 주석·docblock 을 걷어낸 소스.
     *
     * @return string 코드 토큰만 이어 붙인 문자열
     */
    private function sourceWithoutComments(): string
    {
        $code = '';

        foreach (token_get_all($this->source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * 확인 번호는 6자리 숫자다 (승인 시안 W4).
     *
     * FTP 로 파일을 열어 눈으로 옮겨 적는 값이라 길이가 곧 사용성이다. 늘리려면 시안 승인이
     * 필요하고, 줄이면 원격 대입 표면이 커진다.
     *
     * @scenario token=exhausted
     *
     * @effects zip_bootstrap_confirmation_number_length_is_approved
     *
     * @return void 반환값 없음
     */
    public function test_확인_번호_길이와_시도_제한이_승인된_값이다(): void
    {
        $this->assertSame(6, G7_SETUP_TOKEN_DIGITS);
        $this->assertGreaterThan(0, G7_SETUP_MAX_ATTEMPTS);
        $this->assertGreaterThan(0, G7_SETUP_LOCK_SECONDS);
    }

    /**
     * 정식 파일 이름 상수가 실제 파일 이름과 같아야 한다.
     *
     * 배포본에 든 사본을 건너뛰고 지우는 판정이 이 상수에 걸려 있다 — 어긋나면 완료 후에도
     * 진입 파일이 남아 임의 파일 쓰기 입구가 된다.
     *
     * @scenario archive=g7_payload
     *
     * @effects zip_bootstrap_self_name_constant_matches_file
     *
     * @return void 반환값 없음
     */
    public function test_정식_파일_이름_상수가_실제_파일과_일치한다(): void
    {
        $this->assertSame(basename($this->bootstrapPath), G7_SETUP_SELF_NAME);
    }

    /**
     * 부트스트랩 사전의 두 언어가 같은 키와 같은 자리표시자를 갖는다.
     *
     * 인스톨러 사전을 재는 `InstallerLanguageParityTest` 의 모집단은 `public/install/lang/**`
     * 뿐이다 — 부트스트랩은 압축이 풀리기 전에 도느라 그 사전을 쓸 수 없어 문구를 자기 안에
     * 갖고, 그래서 그 게이트 밖에 있다. 한쪽에만 키를 늘리면 `g7setup_lang()` 이 en 으로,
     * 그것도 없으면 **키 문자열 그대로** 떨어뜨린다 — 오류가 아니라 화면에 내부 이름이
     * 그대로 보이는 것이 증상이다.
     *
     * 언어 집합은 인스톨러의 `SUPPORTED_LANGUAGES` 에서 도출한다. 부트스트랩이 그 둘과 같은
     * 언어를 든다는 것이 사전 주석이 선언한 계약이고, 손으로 적으면 언어가 하나 늘 때 같이
     * 늘지 않는다.
     *
     * @scenario entry=fresh
     *
     * @effects zip_bootstrap_dictionary_locales_are_symmetric
     *
     * @return void 반환값 없음
     */
    public function test_부트스트랩_사전의_두_언어가_대칭이다(): void
    {
        $configSource = $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/config.php'));
        $block = (string) preg_replace('/^.*define\(\x27SUPPORTED_LANGUAGES\x27, \[/s', '', $configSource);
        $block = (string) preg_replace('/\]\);.*$/s', '', $block);
        preg_match_all("/^\s*'([a-z-]+)'\s*=>/m", $block, $localeMatch);

        $expectedLocales = $localeMatch[1];
        sort($expectedLocales);
        $this->assertGreaterThanOrEqual(
            2,
            count($expectedLocales),
            '인스톨러 지원 언어를 읽지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다'
        );

        $dictionary = g7setup_dictionary();
        $actualLocales = array_keys($dictionary);
        sort($actualLocales);

        $this->assertSame(
            $expectedLocales,
            $actualLocales,
            '부트스트랩 사전의 언어가 인스톨러 지원 언어와 다르다 — 없는 언어로 들어오면 그 화면 전체가 다른 언어로 뜬다'
        );

        $reference = $expectedLocales[0];
        $referenceKeys = array_keys($dictionary[$reference]);
        sort($referenceKeys);
        $this->assertNotEmpty($referenceKeys, '사전이 비었다');

        foreach ($expectedLocales as $locale) {
            $keys = array_keys($dictionary[$locale]);
            sort($keys);
            $this->assertSame(
                $referenceKeys,
                $keys,
                "부트스트랩 사전의 `{$locale}` 키 집합이 `{$reference}` 와 다르다 — 빠진 키는 화면에 키 문자열로 나간다"
            );

            foreach ($dictionary[$locale] as $key => $text) {
                $this->assertSame(
                    $this->placeholdersIn($dictionary[$reference][$key]),
                    $this->placeholdersIn($text),
                    "`{$locale}.{$key}` 의 자리표시자가 `{$reference}` 와 다르다 — 치환되지 않은 `:이름` 이 화면에 남는다"
                );
            }
        }
    }

    /**
     * 문구 안의 `:이름` 자리표시자 집합.
     *
     * @param  string  $text  문구
     * @return list<string> 정렬된 자리표시자 이름
     */
    private function placeholdersIn(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * 부트스트랩이 release 페이로드에 실려야 한다 (§11.13).
     *
     * git 이 추적하지 않으면 배포 ZIP 에 들어가지 않고, 그러면 운영자가 이 파일을 손에 넣을
     * 방법이 없다. 내부 자산 제외 목록(`.claude` 등)에도 걸리지 않아야 한다.
     *
     * @scenario archive=g7_payload
     *
     * @effects zip_bootstrap_ships_in_release_payload
     *
     * @return void 반환값 없음
     */
    public function test_부트스트랩이_배포_대상에_포함된다(): void
    {
        $repoRoot = dirname(__DIR__, 3);

        // 판정은 「이미 커밋됐는가」가 아니라 「무시 대상인가」로 한다 — 아직 커밋되지 않은
        // 신규 파일도 배포 대상이며, 배포에서 실제로 빠지는 사유는 무시 규칙에 걸리는 것이다.
        $output = [];
        $code = 0;
        exec(
            'git -C '.escapeshellarg($repoRoot).' check-ignore -q '.escapeshellarg('g7-setup.php').' 2>&1',
            $output,
            $code
        );

        $this->assertNotSame(
            0,
            $code,
            'g7-setup.php 가 git 무시 대상이다 — 배포 ZIP 에 실리지 않아 운영자가 이 파일을 손에 넣을 수 없다'
        );
    }

    /**
     * W4 화면이 라이트/다크 양쪽에서 성립한다 (D16).
     *
     * 인스톨러 CSS 를 지키는 게이트들은 `public/install/**` 만 본다 — 부트스트랩은 압축이
     * 풀리기 전에 도느라 그 스타일시트를 쓸 수 없어 색을 자기 안에 갖고, 그래서 그 게이트
     * 밖에 있다. 같은 규칙(색은 토큰 선언에만 리터럴로 두고 규칙 본문은 `var()` 로만 칠한다 ·
     * 다크 판이 라이트 토큰을 **전부** 재정의한다)을 여기서 다시 잰다.
     *
     * @scenario entry=fresh
     *
     * @effects zip_bootstrap_screen_tokenizes_colors_for_dark_mode
     *
     * @return void 반환값 없음
     */
    public function test_W4_화면의_색이_토큰화되고_다크_판이_전부_재정의한다(): void
    {
        $css = $this->inlineStylesheet();

        preg_match('/:root\s*\{(.*?)\}/s', $css, $light);
        preg_match('/\[data-theme="dark"\]\s*\{(.*?)\}/s', $css, $dark);

        $this->assertNotEmpty($light[1] ?? '', 'W4 화면에 라이트 토큰 선언 블록이 없다');
        $this->assertNotEmpty($dark[1] ?? '', 'W4 화면에 다크 토큰 재정의 블록이 없다 (D16)');

        $lightTokens = $this->declaredTokens($light[1]);
        $darkTokens = $this->declaredTokens($dark[1]);

        // 색 토큰만 대상이다 — 여백·글꼴처럼 테마와 무관한 토큰까지 재정의를 요구하지 않는다.
        $colorTokens = array_values(array_filter(
            $lightTokens,
            static fn (string $name): bool => (bool) preg_match('/color|text|bg|border|surface|primary/', $name)
        ));

        $this->assertNotEmpty($colorTokens);
        $this->assertSame(
            [],
            array_values(array_diff($colorTokens, $darkTokens)),
            '다크 판이 재정의하지 않은 색 토큰이 있다 — 그 값은 두 모드에서 같은 색으로 남는다'
        );

        // 토큰 선언 밖의 색 리터럴 (흑백 알파 오버레이는 토큰이 원리상 담지 못해 제외).
        $body = (string) preg_replace('/(?<=[{;\n])\s*--[\w-]+\s*:[^;{}]*;/', '', $css);
        $body = str_replace(['rgba(0, 0, 0,', 'rgba(0,0,0,'], '', $body);

        preg_match_all('/:[^;{}]*?(#[0-9a-fA-F]{3,8})\b/', $body, $literals);
        $this->assertSame(
            [],
            $literals[1],
            'W4 화면 규칙 본문에 색 리터럴이 있다 — 다크 재정의가 그 자리에 닿지 않는다'
        );
    }

    /**
     * W4 화면의 색이 인스톨러 색 체계를 **그대로 승계**한다 (§13 P4 다크 모드 항).
     *
     * 이름만 같고 값이 다르면 승계로 **보이는데** 실제로는 다른 색이다 — 압축 풀기 화면과
     * 바로 이어지는 인스톨러 화면의 상태색이 어긋나는데, 오류가 아니라 색 차이라 두 화면을
     * 나란히 놓고 보지 않으면 드러나지 않는다. 실제로 세 토큰이 라이트·다크 양쪽에서
     * 갈려 있었다.
     *
     * 모집단은 **공유하는 토큰 이름 전량**이다(손으로 고른 목록이 아니다) — 부트스트랩이
     * 새 토큰을 들이면 그 이름이 인스톨러에도 있는 한 자동으로 대조 대상이 된다.
     *
     * @scenario entry=fresh
     *
     * @effects zip_bootstrap_screen_inherits_installer_color_tokens
     *
     * @return void 반환값 없음
     */
    public function test_W4_화면의_색이_인스톨러_토큰_값을_그대로_승계한다(): void
    {
        $installerCss = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/css/installer.css');

        $bootLight = $this->declarationsIn($this->inlineStylesheet(), ':root');
        $bootDark = $this->declarationsIn($this->inlineStylesheet(), '[data-theme="dark"]');
        $installerLight = $this->declarationsIn($installerCss, ':root');
        $installerDark = $this->declarationsIn($installerCss, '[data-theme="dark"]');

        $this->assertNotEmpty($installerLight, '인스톨러 토큰 선언을 읽지 못했다 — 대조 모집단이 비면 이 검사는 무의미하다');

        $shared = array_values(array_intersect(array_keys($bootLight), array_keys($installerLight)));
        $this->assertGreaterThanOrEqual(
            10,
            count($shared),
            '공유 토큰이 거의 없다 — 승계했다고 볼 수 없거나 파서가 죽었다'
        );

        $mismatched = [];

        foreach ($shared as $name) {
            if ($bootLight[$name] !== $installerLight[$name]) {
                $mismatched[] = "{$name} (라이트) 부트스트랩={$bootLight[$name]} 인스톨러={$installerLight[$name]}";
            }

            if (isset($bootDark[$name], $installerDark[$name]) && $bootDark[$name] !== $installerDark[$name]) {
                $mismatched[] = "{$name} (다크) 부트스트랩={$bootDark[$name]} 인스톨러={$installerDark[$name]}";
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "W4 화면이 같은 이름의 토큰을 다른 값으로 쓴다 — 승계로 보이지만 색이 어긋난다:\n  ".implode("\n  ", $mismatched)
        );
    }

    /**
     * 선택자 블록 안의 커스텀 프로퍼티 선언 (이름 => 값).
     *
     * @param  string  $css  스타일시트 본문
     * @param  string  $selector  선택자 (`:root` 등)
     * @return array<string, string> 커스텀 프로퍼티 이름별 선언 값
     */
    private function declarationsIn(string $css, string $selector): array
    {
        $start = strpos($css, $selector.' {');
        if ($start === false) {
            return [];
        }

        $open = strpos($css, '{', $start);
        $depth = 1;
        $cursor = $open + 1;
        $length = strlen($css);

        while ($depth > 0 && $cursor < $length) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }
            $cursor++;
        }

        $body = substr($css, $open + 1, $cursor - $open - 2);

        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $body, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] = trim($match[2]);
        }

        return $declarations;
    }

    /**
     * W4 화면의 인라인 스타일시트 본문.
     *
     * @return string 첫 style 요소 안의 본문, 없으면 빈 문자열
     */
    private function inlineStylesheet(): string
    {
        preg_match('/<style>(.*?)<\/style>/s', $this->source, $matches);

        return $matches[1] ?? '';
    }

    /**
     * 선언 블록 안의 커스텀 프로퍼티 이름 목록.
     *
     * @param  string  $block  `{}` 안의 본문
     * @return array<int, string> 블록에 선언된 커스텀 프로퍼티 이름 목록
     */
    private function declaredTokens(string $block): array
    {
        preg_match_all('/(--[\w-]+)\s*:/', $block, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * 완료 판정이 인스톨러 진입 가드와 같은 답을 낸다.
     *
     * 부트스트랩은 `public/install/api/_guard.php` 를 require 할 수 없어(그 파일이 아직
     * 없다) 판정을 사본으로 갖는다. 사본이 갈리면 **완료된 사이트에서 압축 풀기가 다시
     * 열린다** — 인증 없는 파일 쓰기 표면이 되살아나는 것이다.
     *
     * @param  array<string, string>  $files  픽스처가 만들 파일 (상대 경로 => 내용)
     * @param  bool  $expected  완료로 판정되어야 하는가
     *
     * @scenario entry=completed_site
     *
     * @effects zip_bootstrap_completion_predicate_matches_installer_guard
     *
     * @return void 반환값 없음
     */
    #[DataProvider('completionFixtures')]
    public function test_완료_판정이_인스톨러_가드와_일치한다(array $files, bool $expected): void
    {
        if (! defined('PHP_BINARY') || PHP_BINARY === '') {
            $this->markTestSkipped('PHP_BINARY 를 알 수 없어 하위 프로세스를 띄울 수 없다');
        }

        $repoRoot = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir().'/g7-zip-parity-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            copy($this->bootstrapPath, $dir.'/g7-setup.php');

            foreach ($files as $relative => $content) {
                $full = $dir.'/'.$relative;
                if (! is_dir(dirname($full))) {
                    mkdir(dirname($full), 0777, true);
                }
                file_put_contents($full, $content);
            }

            $command = escapeshellarg(PHP_BINARY)
                .' '.escapeshellarg(__DIR__.'/stubs/zip_bootstrap_guard_parity_probe.php')
                .' '.escapeshellarg($dir)
                .' '.escapeshellarg($repoRoot)
                .' 2>&1';

            $output = [];
            exec($command, $output);
            $raw = implode("\n", $output);
            $decoded = json_decode($raw, true);

            $this->assertIsArray($decoded, '판정 하네스 응답이 JSON 이 아니다: '.$raw);
            $this->assertSame($expected, $decoded['bootstrap'], '부트스트랩 판정이 기대와 다르다');
            $this->assertSame(
                $decoded['guard'],
                $decoded['bootstrap'],
                '부트스트랩과 인스톨러 진입 가드의 완료 판정이 갈렸다'
            );
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * 완료 판정 픽스처 — 판정이 갈릴 수 있는 형태를 전부 담는다.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}> 사례 이름별 픽스처 파일 묶음과 기대 완료 판정
     */
    public static function completionFixtures(): array
    {
        return [
            '아무것도 없음' => [[], false],
            '락 파일만' => [['storage/app/g7_installed' => '1'], true],
            'env 표식 true' => [['.env' => "APP_NAME=G7\nINSTALLER_COMPLETED=true\n"], true],
            'env 표식 1' => [['.env' => "INSTALLER_COMPLETED=1\n"], true],
            'env 표식 따옴표' => [['.env' => "INSTALLER_COMPLETED=\"yes\"\n"], true],
            'env 표식 false' => [['.env' => "INSTALLER_COMPLETED=false\n"], false],
            'env 주석 처리' => [['.env' => "# INSTALLER_COMPLETED=true\n"], false],
            'env 표식 없음' => [['.env' => "APP_NAME=G7\n"], false],
            'env 파서 파손 + 표식' => [['.env' => "APP_NAME=\"broken\nINSTALLER_COMPLETED=true\n"], true],
            'env 파서 파손 + 표식 없음' => [['.env' => "APP_NAME=\"broken\n"], false],
        ];
    }

    /**
     * 픽스처가 만든 임시 폴더를 링크를 따라가지 않고 재귀로 지운다. 없는 폴더면 아무것도 하지 않는다.
     *
     * @param  string  $dir  지울 폴더 절대 경로
     * @return void 반환값 없음
     */
    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
