<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Tests\Unit\Support\LayoutMarkup\GateMembershipGuardTest;

/**
 * 종료 게이트가 실제로 실행하는 테스트 집합을 **선언에서 도출**합니다.
 *
 * ## 왜 이 클래스가 있는가 (31차)
 *
 * S17 이후 서른 회차가 같은 형태로 반복됐고, 그 공통 골격은 검출력이 아니라 **판정의 세계**였다.
 * 가드마다 "검사 대상이 어디 있는가" 를 손으로 쓴 디렉토리 목록으로 답했고, 그 목록의 경계는
 * 저자가 정하므로 여집합이 늘 남았다. 남은 여집합이 다음 회차의 검출물이 되는 구조라 회차는
 * 끝나지 않는다(㊽·㊿·(53)·(54) 가 "모집단이 좁았다" 로 넉 번, 28차가 후행 슬래시 표기로,
 * 30차가 `GATE_TEST_DIRECTORIES` 2줄로, 31차가 그 2줄의 여집합에서 실물 위반 1건으로).
 *
 * 그래서 모집단을 닫힌 세계에서 도출한다. **무엇이 수집되는지는 `phpunit.xml` 이 정본이고,
 * 무엇이 선택되는지는 `--filter` 정규식이 정본이다.** 둘을 합치면 "게이트에서 도는 테스트" 의
 * 정의에 사람의 열거가 끼어들지 않는다.
 *
 * 사본이 사각을 만든다는 것은 이 트랙이 `app/` 에 대해 세 회차에 걸쳐 확인한 사실이므로
 * (27차 규약 3벌 · 29차 짝 판별 2벌 · 28차 디렉토리 사본 3벌), 같은 규율을 가드 자신에게도
 * 적용해 도출을 **한 곳**에 둔다. 소비자는 게이트 소속 가드·오라클 독립성 가드·단언 검출력
 * 메타가드 셋이다.
 */
class GateTestPopulation
{
    /**
     * PHPUnit 설정 파일 — 무엇이 수집되는지의 정본.
     */
    public const PHPUNIT_CONFIG = 'phpunit.xml';

    /**
     * 종료 검증 명령이 쓰는 `--filter` 정규식 대안들 — **이 상수가 값의 정본이다**.
     *
     * 34차 정정 — 종전 독블록은 "계획서의 PHPUnit 한 줄과 같은 값이어야 하며 그 동일성을
     * `GateMembershipGuardTest` 가 **양방향으로** 잠근다" 고 적었으나, 그 주장은 성립한 적이 없다.
     * 계획서는 저장소 밖(개발자 로컬 계획서 디렉토리)이라 어떤 테스트도 읽지 않는다(실측: `tests/` 에서
     * 계획서 경로를 참조하는 코드 0건). 가드가 잠그는 것은 **코드 쪽 끝 셋**뿐이다 —
     * 이 상수 ↔ 시나리오 매니페스트 `test_files` ↔ 디스크 상주.
     *
     * 그래서 계획서는 값을 복제하지 않는다. 종료 검증 블록은 이 상수를 **인용**하고, 필터
     * 문자열은 여기서 조립한다({@see gateFilterExpression()}). 28차가 "참조 축 485" 를 산문에서
     * 테스트로 옮긴 것과 같은 규율이다 — 값을 두 곳에 적으면 한 곳이 낡는다.
     *
     * @var array<int, string>
     */
    public const GATE_FILTERS = [
        'LayoutMarkup',
        'EnsureAdminOrder',
        'RestoreLayoutExtensions',
        'ValidLayoutExtensionStructure',
        // ── 39차: 마킹 모집단을 저장소 전량으로 반전하자 드러난 셋 ──
        //
        // 세 파일 다 이 트랙의 축(조각 경로 규약 · 컴포넌트 레지스트리)을 단언하면서
        // `@scenario`/`@effects` 까지 달아 놓았는데 어느 매니페스트에도 없었다 — 즉 "덮는다" 는
        // 선언만 있고 커버리지 집계에는 한 번도 들어간 적이 없었다. 매니페스트에 등재하면
        // {@see \Tests\Unit\Support\LayoutMarkup\GateMembershipGuardTest::test_every_declared_php_test_file_is_reachable_by_gate_filters()}
        // 가 게이트 도달까지 요구하므로 필터 대안을 함께 넓힌다(28·30차가 같은 형태로 두 번
        // 겪은, 소속이 네임스페이스의 우연에 걸리는 문제다).
        'PartialResolutionParity',
        'LayoutPartialBaseDirectory',
        'GeneratesComponentManifest',
        // ── S20 41차: 문서 엔드포인트 게이트 ──
        //
        // `Tests\Unit\Rules\DocLayoutEndpointWhitelistTest` 는 S20 이 신설한 문서 게이트인데
        // 네임스페이스가 위 어느 대안에도 걸리지 않아 **게이트에서 한 번도 돈 적이 없다**. 회차
        // 기록은 그 사이 이 테스트를 "통과" 로 세어 왔다 — 손으로 돌린 결과였고, 게이트 소속과
        // 실행 사실이 갈린 자리다(39차 셋과 같은 형태가 한 축 더 있었다). 짝인 마크업 컴파일
        // 게이트는 `LayoutMarkup` 에 우연히 걸려 돌고 있었으므로 이 결손이 절반만 보였다.
        'DocLayoutEndpointWhitelist',
        // ── S21.1 56차: 주입 단락의 안전 명제 ──
        //
        // `Tests\Feature\Api\Public\LayoutServingTest` 는 이 트랙이 만든 파일이 아니지만, 주입
        // 단락(S21.1~S21.8)이 프론트엔드 파일을 수백 건 건드리면서 E2E spec 을 동반하지 않는
        // 근거가 그 안의 comment strip 단언이다 — 주입은 산출에 `comment` 키만 더하고 공개 서빙은
        // 그 키를 재귀 제거하므로 브라우저가 보는 값이 주입 전후로 같다. 그 명제를 매니페스트에
        // 올린 이상 게이트에서도 돌아야 한다(위 41차 자리와 같은 이유 — 선언과 실행이 갈리면
        // 회차 기록이 「통과」로 세는 것은 손으로 돌린 결과다).
        'LayoutServing',
        // ── S21.3 67차: 같은 명제의 두 번째 소비자 ──
        //
        // 화면 렌더 경로는 공개 서빙 하나가 아니다 — 미리보기 서빙(무인증 토큰)도 확장 조각을
        // 얹고, 그쪽에는 주석 제거가 없어 같은 누출이 남아 있었다. 한 소비자에서 초록이 나면
        // 나머지가 보이지 않는다는 것을 이 자리가 실물로 보였다(위 `LayoutServing` 단언이
        // green 인 채로 미리보기 응답에는 주석이 실려 있었다). 그 표본을 매니페스트에 올린
        // 이상 게이트에서도 돌아야 한다.
        'LayoutPreviewController',
    ];

    /**
     * 종료 검증 명령이 쓰는 Artisan 커맨드 → 그 명령이 넘기는 옵션 목록.
     *
     * 계획서 §"S18 종료 검증 명령" 의 Artisan 줄들과 같은 값이어야 하며, **오늘 실행 가능한
     * 것만** 담는다. 32차 실측 — 그 블록의 세 번째 줄(`layout:decompile --all --check`)은 S18 P2
     * 산출물이라 옵션이 없어 `exit 1` 인데, 회차 기록 넷이 "종료 검증 명령 5종 전건 초록" 이라
     * 적어 왔다(실제로는 lang-pack 을 끼워 센 다른 조합이었다). 선언한 게이트와 실제 실행
     * 가능성이 갈리는 자리에 판정 장치가 없었던 것이므로, 목록을 코드로 옮겨 잠근다.
     *
     * **S18.5 — `layout:decompile --sync`·`--check` 가 아래 `PENDING_…` 에서 여기로 왔다.** 봉인이
     * 설계대로 착수 시점에 red 를 냈다("실행 가능해졌습니다 — 옮기라"). 계획서 종료 검증 블록의
     * "S18.5 이후" 표기도 같은 회차에 함께 지웠다 — 이 두 자리는 값을 복제하므로 한쪽만 고치면
     * 다음 회차가 갈린 채로 또 찾는다(38차가 그 형태를 정확히 겪었다).
     *
     * @var array<string, array<int, string>>
     */
    public const GATE_ARTISAN_COMMANDS = [
        'layout:compile' => ['all', 'roundtrip'],
        'layout:decompile' => ['all', 'sync', 'check'],
    ];

    /**
     * 종료 검증 명령에 그대로 넣을 `--filter` 값을 조립합니다.
     *
     * 계획서·문서가 필터 문자열을 **복제하지 않도록** 한다 — 필요한 쪽은 이 값을 인용한다.
     *
     * @return string `--filter` 인자값
     */
    public static function gateFilterExpression(): string
    {
        return implode('|', self::GATE_FILTERS);
    }

    /**
     * 아직 실행할 수 없는 게이트 명령 → 사유 (자기 정리형).
     *
     * 옵션이 생기는 순간 `GateMembershipGuardTest` 가 red 로 "실행 목록으로 옮기라" 를 알린다.
     *
     * **S18.5 에서 비었다.** 두 행(`layout:decompile --sync`·`--check`)이 이 단락의 산출물이었고,
     * 착수하자 봉인이 설계대로 red 를 내 위 {@see GATE_ARTISAN_COMMANDS} 로 옮겼다. 계획서 종료
     * 검증 블록의 "S18.5 이후" 표기도 같은 회차에 함께 지웠다 — 그 블록은 사람이 실행할 명령이라
     * 코드 인용으로 대체할 수 없어 값을 복제하며(38차가 명시), 한쪽만 고치면 다음 회차가 갈린 채로
     * 또 찾는다.
     *
     * 대장이 비어도 판정식은 살아 있어야 하므로 관측을 합성 항목으로 옮겼다
     * ({@see GateMembershipGuardTest::test_pending_ledger_predicate_still_detects_a_runnable_command()}).
     * 종전 판본의 `assertNotEmpty` 하한을 그대로 두면 오늘 red 이고, 그냥 지우면 `foreach` 가 빈
     * 배열을 돌아 공허하게 참이 된다(34차가 이관 제외 대장에서 잡은 형태).
     *
     * 새 미착수 명령이 생기면 여기에 `'커맨드 --옵션' => '사유'` 로 등재한다.
     *
     * @var array<string, string>
     */
    public const PENDING_GATE_ARTISAN_COMMANDS = [];

    /**
     * 자기시험이 모집단에 밀어 넣은 합성 표본 → 절대 경로 (32차 근본 조치 H).
     *
     * @var array<string, string>
     */
    private static array $injected = [];

    /**
     * 합성 표본을 **모집단에 주입한 채로** 콜백을 실행합니다 (32차 근본 조치 H).
     *
     * ## 왜 주입인가
     *
     * 32차까지 게이트의 자기시험 넷은 전부 표본을 **private 판정식에 직접** 넘겼다. 가드는
     * `모집단 도출 → 문지기 → 판정식 → 단언` 4단인데 표본이 3단에 들어가므로, 앞 두 단이 그
     * 표본을 걸러내도 자기시험은 초록이다. 회차마다 깨진 단이 달랐을 뿐(29차 단언 · 31차 모집단 ·
     * 32차 문지기) **"고친 단만 시험된다"** 는 구조는 서른두 회차 내내 같았다.
     *
     * 그래서 주입 지점을 파이프라인 **입구**로 내린다. 표본을 디스크에 써서 모집단에 넣고
     * 프로덕션 테스트 메서드를 그대로 돌리면, 문지기를 포함한 전 구간이 표본 경로에 들어온다.
     * 이 형태였다면 29·31·32차 결함은 전부 발견 즉시 red 였다.
     *
     * @param  array<string, string>  $samples  상대 경로(파일명만) → PHP 소스
     * @param  callable():void  $callback  주입 상태에서 실행할 검사
     * @return void 반환값 없음
     */
    public static function withInjectedFiles(array $samples, callable $callback): void
    {
        $directory = base_path(self::SAMPLE_DIRECTORY);

        File::ensureDirectoryExists($directory);

        $previous = self::$injected;

        try {
            foreach ($samples as $name => $source) {
                $relative = self::SAMPLE_DIRECTORY.'/'.$name;

                File::put(base_path($relative), $source);

                self::$injected[$relative] = base_path($relative);
            }

            $callback();
        } finally {
            foreach (array_keys(self::$injected) as $relative) {
                if (! isset($previous[$relative]) && File::exists(base_path($relative))) {
                    File::delete(base_path($relative));
                }
            }

            self::$injected = $previous;
        }
    }

    /**
     * 합성 표본이 놓이는 디렉토리 (저장소 루트 기준, git 미추적).
     */
    public const SAMPLE_DIRECTORY = 'storage/framework/testing/guard-samples';

    /**
     * 현재 주입돼 있는 합성 표본 — 게이트 모집단이 아닌 모집단을 쓰는 가드가 합류하는 지점.
     *
     * 주입 레지스트리를 여기 한 곳에 두는 이유는 이 트랙이 `app/` 에서 세 회차에 걸쳐 배운 것과
     * 같다 — 사본은 사각을 만든다. 확장 소스를 모집단으로 쓰는 가드도 자기 레지스트리를 만들지
     * 않고 이 목록을 자기 모집단에 더한다.
     *
     * @return array<string, string> 상대 경로 → 절대 경로
     */
    public static function injectedFiles(): array
    {
        return self::$injected;
    }

    /**
     * `phpunit.xml` 의 `<testsuite>` 선언을 디렉토리 패턴 → 제외 접두 목록으로 읽습니다.
     *
     * 제외는 선언한 스위트 안에서만 유효하므로 패턴별로 모아 돌려준다.
     *
     * @return array<string, array<int, string>> 디렉토리 패턴 → 제외 접두 경로 목록
     */
    public static function suiteDirectories(): array
    {
        $xml = simplexml_load_string(File::get(base_path(self::PHPUNIT_CONFIG)));

        if ($xml === false) {
            return [];
        }

        $patterns = [];

        foreach ($xml->testsuites->testsuite as $suite) {
            $excludes = [];

            foreach ($suite->exclude as $exclude) {
                $excludes[] = trim((string) $exclude);
            }

            foreach ($suite->directory as $directory) {
                $pattern = trim((string) $directory);
                $patterns[$pattern] = array_merge($patterns[$pattern] ?? [], $excludes);
            }
        }

        return $patterns;
    }

    /**
     * 한 디렉토리 패턴이 수집하는 PHP 파일을 돌려줍니다 (추상 클래스 제외).
     *
     * `<directory>` 는 글롭을 허용하므로 확장하고, 하위 디렉토리를 놓치지 않도록 재귀 순회한다.
     *
     * @param  string  $pattern  `phpunit.xml` 의 디렉토리 패턴
     * @param  array<int, string>  $excludes  제외 접두 경로 목록
     * @return array<string, string> 상대 경로 → 절대 경로
     */
    public static function filesForPattern(string $pattern, array $excludes): array
    {
        $collected = [];

        foreach (File::glob(base_path($pattern)) ?: [] as $base) {
            if (! File::isDirectory($base)) {
                continue;
            }

            foreach (File::allFiles($base) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = self::relativePath($file->getPathname());

                foreach ($excludes as $exclude) {
                    if (str_starts_with($relative, $exclude)) {
                        continue 2;
                    }
                }

                if (preg_match('/^abstract\s+class\s/m', File::get($file->getPathname())) === 1) {
                    continue;
                }

                $collected[$relative] = $file->getPathname();
            }
        }

        return $collected;
    }

    /**
     * `phpunit.xml` 이 수집하는 구체 테스트 파일 전량입니다.
     *
     * @return array<string, string> 상대 경로 → 절대 경로
     */
    public static function collectedFiles(): array
    {
        $collected = [];

        foreach (self::suiteDirectories() as $pattern => $excludes) {
            $collected += self::filesForPattern($pattern, $excludes);
        }

        return $collected;
    }

    /**
     * 게이트 필터가 실제로 선택하는 테스트 파일입니다 (`--filter` 의미론 그대로).
     *
     * @return array<string, string> 상대 경로 → 절대 경로 (경로 정렬됨)
     */
    public static function gateSelectedFiles(): array
    {
        $selected = [];

        foreach (self::collectedFiles() as $relative => $absolute) {
            $fqcn = self::fullyQualifiedClassName($absolute);

            if ($fqcn !== null && self::matchesAnyGateFilter($fqcn)) {
                $selected[$relative] = $absolute;
            }
        }

        // 자기시험이 주입한 합성 표본 — 프로덕션 검사가 그 표본을 실제로 보는지 확인하기 위한
        // 유일한 경로다 (근본 조치 H). 주입은 `withInjectedFiles()` 안에서만 살아 있다.
        $selected += self::$injected;

        ksort($selected);

        return $selected;
    }

    /**
     * 게이트 상주 테스트가 **끌어 쓰는 지원 파일**을 import 에서 도출합니다.
     *
     * 32차 ⚠️ — `tests/Support/` 는 어느 `phpunit.xml` 스위트에도 없어 가드 모집단 밖이었다.
     * 그런데 그 안의 트레이트는 게이트 테스트가 실제로 실행하는 단언을 갖고 있다(도달 시험
     * 하네스가 그 예다). 즉 **가드가 공유하는 코드가 가드의 판정 대상이 아닌** 상태였고, 이는
     * 22차가 "감시자가 자기 자신을 관측 밖에 두고 있었다" 로 지목한 형태와 같다.
     *
     * 목록을 손으로 쓰지 않는다 — 게이트 파일의 `use Tests\Support\…;` 를 읽어 도출한다.
     * 쓰이지 않게 되면 자동으로 빠지고, 새로 쓰면 자동으로 들어온다.
     *
     * @return array<string, string> 상대 경로 → 절대 경로
     */
    public static function gateSupportFiles(): array
    {
        $support = [];

        foreach (self::gateSelectedFiles() as $absolute) {
            preg_match_all('/^\s*use\s+(Tests\\\\Support\\\\[\w\\\\]+)\s*;/m', File::get($absolute), $matches);

            foreach ($matches[1] as $fqcn) {
                $relative = 'tests/'.str_replace('\\', '/', substr($fqcn, strlen('Tests\\'))).'.php';

                if (File::exists(base_path($relative))) {
                    $support[$relative] = base_path($relative);
                }
            }
        }

        ksort($support);

        return $support;
    }

    /**
     * PHP 테스트 파일에서 `namespace` + `class` 를 읽어 FQCN 을 만듭니다.
     *
     * @param  string  $absolutePath  테스트 파일 절대 경로
     * @return string|null 클래스 선언을 찾지 못하면 null
     */
    public static function fullyQualifiedClassName(string $absolutePath): ?string
    {
        $source = File::get($absolutePath);

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $classMatch) !== 1) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([^;]+);/m', $source, $nsMatch) === 1
            ? trim($nsMatch[1])
            : '';

        return $namespace === '' ? $classMatch[1] : $namespace.'\\'.$classMatch[1];
    }

    /**
     * FQCN 이 종료 게이트 필터 대안 중 하나에 걸리는지 판정합니다.
     *
     * @param  string  $fqcn  테스트 클래스 FQCN
     * @return bool 하나라도 걸리면 true
     */
    public static function matchesAnyGateFilter(string $fqcn): bool
    {
        foreach (self::GATE_FILTERS as $filter) {
            if (preg_match('/'.$filter.'/i', $fqcn) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP 테스트 소스를 `public function test_*` 메서드 이름 → 본문으로 분해합니다.
     *
     * 가드의 판정 단위를 파일에서 **메서드**로 내리는 데 쓴다. 32차 실측 — 하한 의무가 파일
     * 단위라 한 메서드의 `assertNotEmpty` 하나가 같은 파일의 다른 도출형 메서드를 전부
     * 면제시키고 있었다. 분해기를 여기 한 곳에 두어 가드마다 사본이 생기지 않게 한다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<string, string> 메서드명 → 본문
     */
    public static function testMethodBodies(string $code): array
    {
        // 합성 표본을 담은 가드는 nowdoc 안에 `public function test_…` 를 문자열로 들고 있다.
        // 그것을 실제 메서드로 세면 존재하지 않는 메서드에 하한을 요구하게 되므로 본문을 지운다
        // (`<<<'PHP'` 시작 표기는 남겨 자기시험 판별 신호를 보존한다).
        //
        // 경계는 **모든 함수 선언**이다. `test_` 만 경계로 삼으면 마지막 테스트 메서드의 본문이
        // 뒤따르는 private 헬퍼 전부를 삼켜, 그 헬퍼가 가진 신호가 테스트의 것으로 오귀속된다
        // (32차 실측 — 그렇게 오귀속된 `GateTestPopulation::` 하나가 없는 도출을 있다고 만들었다).
        return self::methodBodiesMatching($code, static fn (string $name): bool => str_starts_with($name, 'test_'));
    }

    /**
     * PHP 테스트 소스를 **비공개 헬퍼** 이름 → 본문으로 분해합니다 (34차 — 호출그래프 1단용).
     *
     * 가드의 의무(모집단 하한 등)는 `test_` 메서드에 걸리는데, 도출식을 private 헬퍼로 빼면 그
     * 의무가 사라진다. 헬퍼 본문을 함께 볼 수 있어야 "테스트가 헬퍼를 통해 도출한다" 를 판정할 수
     * 있다. 분해 규칙은 {@see testMethodBodies()} 와 같고 선택 조건만 반대다 — 사본을 만들지
     * 않도록 경계 계산을 공유한다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @return array<string, string> 메서드명 → 본문
     */
    public static function nonPublicMethodBodies(string $code): array
    {
        return self::methodBodiesMatching($code, static fn (string $name): bool => ! str_starts_with($name, 'test_'));
    }

    /**
     * 이름 조건을 만족하는 메서드의 본문을 분해합니다.
     *
     * @param  string  $code  주석이 제거된 PHP 코드
     * @param  callable(string):bool  $accepts  메서드명 선택 조건
     * @return array<string, string> 메서드명 → 본문
     */
    private static function methodBodiesMatching(string $code, callable $accepts): array
    {
        $code = self::codeWithoutHeredocBodies($code);

        preg_match_all(
            '/(?:public|protected|private)(?:\s+static)?\s+function\s+(\w+)\s*\(/',
            $code,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $bodies = [];
        $count = count($matches[1]);

        foreach ($matches[1] as $index => $match) {
            if (! $accepts($match[0])) {
                continue;
            }

            $start = $matches[0][$index][1];
            $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($code);

            $bodies[$match[0]] = substr($code, $start, $end - $start);
        }

        return $bodies;
    }

    /**
     * heredoc/nowdoc **본문**만 지웁니다 (시작·종료 표기는 남긴다).
     *
     * @param  string  $code  PHP 코드
     * @return string 표본 본문이 제거된 코드
     */
    public static function codeWithoutHeredocBodies(string $code): string
    {
        $result = '';
        $inHeredoc = false;

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                $result .= $token;

                continue;
            }

            if ($token[0] === T_START_HEREDOC) {
                $inHeredoc = true;
                $result .= $token[1];

                continue;
            }

            if ($token[0] === T_END_HEREDOC) {
                $inHeredoc = false;
                $result .= $token[1];

                continue;
            }

            $result .= $inHeredoc ? '' : $token[1];
        }

        return $result;
    }

    /**
     * **모든 문자열 리터럴의 본문**을 지웁니다 (구분자는 남긴다) — 35차 근본 조치 J.
     *
     * ## 왜 heredoc 만으로는 부족한가
     *
     * 가드가 자기 판정식에 걸리는 이유는 "합성 표본을 담고 있어서" 인데, 그 표본이 담기는 그릇은
     * nowdoc 만이 아니다 — 무효 형태를 설명하는 문구(`'assertTrue(true) — 방어를 흉내낸 …'`)와
     * 자기시험이 판정식에 직접 먹이는 인라인 문자열도 같은 그릇이다. 35차 실측에서 파일 단위
     * 면제를 걷어내자 이 형태가 정확히 12건 드러났다.
     *
     * 판정 단위는 **코드로서의 단언**이어야 한다. 문자열 안에 적힌 단언은 데이터이지 실행되는
     * 단언이 아니므로, 리터럴 본문을 걷어내면 표본은 사라지고 실제 코드만 남는다. 구분자(`''`)를
     * 남기는 이유는 "리터럴이 있었다" 는 사실 자체가 판정 신호인 자리가 있기 때문이다
     * (`assertNotNull('x')` 는 리터럴이라서 무효고, `assertSame(['a'], …)` 는 리터럴 목록이라서
     * 실질 하한이다).
     *
     * @param  string  $code  PHP 코드
     * @return string 리터럴 본문이 제거된 코드
     */
    public static function codeWithoutLiteralBodies(string $code): string
    {
        $result = '';
        $inHeredoc = false;

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                $result .= $token;

                continue;
            }

            if ($token[0] === T_START_HEREDOC) {
                $inHeredoc = true;
                $result .= $token[1];

                continue;
            }

            if ($token[0] === T_END_HEREDOC) {
                $inHeredoc = false;
                $result .= $token[1];

                continue;
            }

            if ($inHeredoc || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $quote = $token[1][0];
                $result .= $quote.$quote;

                continue;
            }

            $result .= $token[1];
        }

        return $result;
    }

    /**
     * 절대 경로를 저장소 루트 기준 슬래시 상대 경로로 정규화합니다.
     *
     * @param  string  $absolutePath  절대 경로
     * @return string 저장소 루트 기준 상대 경로
     */
    public static function relativePath(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $root = str_replace('\\', '/', base_path()).'/';

        return str_starts_with($normalized, $root)
            ? substr($normalized, strlen($root))
            : $normalized;
    }
}
