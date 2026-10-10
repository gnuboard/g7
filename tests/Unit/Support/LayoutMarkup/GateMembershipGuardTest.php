<?php

namespace Tests\Unit\Support\LayoutMarkup;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\GateTestPopulation;

/**
 * 종료 게이트의 **소속**을 이름의 우연이 아니라 선언에서 도출한다 (30차 근본 조치 B).
 *
 * S17·S18 의 종료 판정은 `php vendor/bin/phpunit --filter=…` 한 줄이다. PHPUnit 의 `--filter` 는
 * `클래스FQCN::메서드` 문자열에 정규식을 걸므로, **테스트가 어느 네임스페이스에 놓였는가**가
 * 게이트 포함 여부를 정한다. 즉 소속이 파일 위치의 우연에 걸려 있다.
 *
 * 그 우연이 두 번 사고를 냈다:
 *
 * - 28차 — `FragmentSourceCopyGuardTest` 가 `Tests\Unit\Extension\Helpers` 에 있어 게이트 밖이었다.
 *   가드를 옮기고 그 사유를 독블록에 적었다.
 * - 30차 — **같은 회차에 만든** `LayoutFragmentSourceHelperTest`(P1 코어 진입점의 유일한 계약
 *   테스트, 8건)는 옮기지 않아 그대로 게이트 밖이었다. 시나리오 매니페스트는 그 파일을 S18 P1
 *   산출물로 **명시 등재**했는데, 종료 명령은 그중 한 건도 실행하지 않았다.
 *
 * 산문으로 "게이트 안에 두라" 고 적는 것으로는 반복이 멈추지 않는다는 것이 두 회차의 결론이라,
 * 판정을 기계로 옮긴다. 이 가드는 **양방향**이다:
 *
 * 1. 매니페스트가 선언한 PHP 테스트 파일은 전부 게이트 필터에 걸린다 (선언 → 실행)
 * 2. 게이트 네임스페이스 안의 테스트 클래스는 전부 매니페스트에 선언돼 있다 (실행 → 선언)
 *
 * 한쪽만 두면 ⓐ 선언했는데 안 도는 파일(30차) 또는 ⓑ 도는데 아무 데도 등재되지 않아 커버리지
 * 산정에서 빠지는 파일이 남는다.
 *
 * ## 31차 — 모집단을 손으로 쓴 디렉토리 목록에서 `phpunit.xml` 도출로 반전
 *
 * 30차 판본의 방향2 는 `GATE_TEST_DIRECTORIES` 두 줄을 모집단으로 삼았다. 그런데 판정 기준인
 * `--filter` 는 **FQCN 정규식**이라 실제 실행 집합은 그 두 디렉토리와 무관하게 정해진다. 둘이
 * 갈리는 만큼이 사각이고, 실측하면 그 사각에 위반이 살아 있었다 —
 * `EnsureAdminOrderListTestBadgeLayoutListenerTest` 는 필터 `EnsureAdminOrder` 에 걸려 게이트
 * 안에서 도는데 매니페스트에 없었고, 자매 4파일이 30차에 `PluginTestCase` 로 전환된 뒤에도 홀로
 * 순수 `PHPUnit\Framework\TestCase` 로 남아 있었다. 같은 사각에 `tests/Unit/Enums` ·
 * `tests/Unit/Rules` 의 게이트 상주 테스트도 함께 들어 있었다(그쪽은 마침 등재돼 있었다).
 *
 * 손으로 쓴 열거는 그 경계를 저자가 정하므로 여집합이 늘 남는다. 그래서 모집단을 **`phpunit.xml`
 * 의 `<testsuite><directory>`(+`<exclude>`)에서 도출**한다 — PHPUnit 이 무엇을 수집하는지는
 * 그 파일이 정본이고, 무엇을 선택하는지는 필터 정규식이 정본이다. 둘을 합치면 "게이트에서 도는
 * 테스트" 의 정의가 사람의 열거를 거치지 않는다.
 *
 * 도출이 조용히 죽는 것을 막기 위해 {@see test_every_declared_suite_directory_contributes_tests()}
 * 가 선언 디렉토리마다 최소 1건 기여를 요구하고, {@see test_every_gate_filter_has_resident_tests()}
 * 가 필터 축마다 상주 테스트 실재를 요구한다.
 */
class GateMembershipGuardTest extends LayoutMarkupTestCase
{
    /**
     * 시나리오 매니페스트 경로 (레이아웃 마크업 트랙의 test_files SSoT).
     */
    private const MANIFEST = 'tests/scenarios/layout-markup.yaml';

    /**
     * 지금은 0건이어도 정상인 선언 슬롯 → 사유.
     *
     * 글롭이 0개 디렉토리로 풀리는 것과 낡아 죽은 것은 기계로 구별되지 않으므로(둘 다 0건),
     * 판단이 필요한 자리는 사유와 함께 여기 등재한다. 목록이 거짓말로 굳는 것을 막기 위해
     * {@see test_forward_declared_slots_are_still_barren()} 가 **등재분이 실제로 0건인지**를
     * 되짚어, 기여가 생긴 슬롯은 목록에서 빠지도록 강제한다(자기 정리형 허용 목록).
     *
     * @var array<string, string>
     */
    private const FORWARD_DECLARED_PATTERNS = [
        'modules/_bundled/*/tests/Feature/Installation' => '번들 모듈이 자기 설치 테스트를 두는 슬롯. '
            .'현재 실물 0개이고 git 이력에도 존재한 적이 없다. 인스톨러 테스트는 개발 `.env` 를 '
            .'파괴할 수 있어 평상시 스위트에서 격리해야 하므로, 모듈이 이 디렉토리를 만들면 '
            .'`Module` 스위트의 exclude 가 그것을 막고 `Installation` 스위트만 수집한다.',
        'templates/_bundled/*/tests' => '번들 템플릿의 테스트 슬롯 (32차 신설). 디스크에 두 '
            .'템플릿의 `tests/` 가 실재하는데 `phpunit.xml` 선언이 없어, 모집단을 선언에서 '
            .'도출하는 장치들이 이 축을 구조적으로 보지 못했다 — **선언에 빠진 축은 도출로도 '
            .'복구되지 않는다.** 현재 `.php` 0건이라 슬롯으로 등재하고, 템플릿이 게이트 대상 '
            .'테스트를 만들면 이 등재가 red 로 목록에서 빼라고 알린다.',
    ];

    /**
     * 마킹은 있는데 어느 매니페스트에도 없는 **타 트랙 소유** 테스트 → 사유·소유 (39차 신설).
     *
     * 이 트랙이 고칠 수 있는 자리가 아니다 — 각 확장의 시나리오 매니페스트는 그 확장의 축
     * (`axes`)으로 짜여 있어, 여기서 파일만 밀어 넣으면 그 매니페스트의 `cross_product` 와
     * 어긋난다. 그래서 **늘지 않게만 잠그고** 해소는 소유 트랙 몫으로 남긴다.
     *
     * 목록은 {@see test_every_marked_test_file_is_declared_in_some_manifest()} 가 **정확히
     * 일치**로 대조하므로, 소유 트랙이 등재하면 그 즉시 "여기서도 빼라" 가 red 다.
     *
     * @var array<string, string>
     */
    private const FOREIGN_MARKED_TEST_DEBT = [
        // ── develop 병합 유입분 (3·4·5차). 아래 22행은 이 트랙이 만든 것이 아니라 develop 라인이
        //    자기 트랙 테스트에 마킹을 달고 시나리오 매니페스트는 두지 않은 채 넘어온 것이다.
        //    귀속 실측: 전부 develop 계보 커밋(`2b38e15aa`·`03b48f76a`·`6217f4d78` 등)이 도입했고
        //    5차 병합이 새로 들인 마킹 테스트는 0건이고 6·7차도 0건, 8차가 4건을 들였다(아래 별도 묶음). 소유 트랙이 매니페스트를 세우면 그 즉시
        //    "여기서도 빼라" 가 red 이므로, 이 목록은 늘지 않게만 잠그는 자리다.
        //    #745 가 게시판 매니페스트(`board-comment-on-post-state`·`board-upgrade-steps`)에 등재해
        //    `CommentPostStateTest`·`PlaintextPostPasswordRehashTest`·`PostListViewCountIndexUpgradeTest` 3행을 해소했다.
        'modules/_bundled/sirsoft-board/tests/Feature/NoticeResultCapTest.php' => '게시판 트랙 소유 (공지 조회의 결과 상한 준수).',
        'modules/_bundled/sirsoft-board/tests/Feature/PostNumberBoundedTotalRegressionTest.php' => '게시판 트랙 소유 (총 건수 절사 시 글번호 표기).',
        'modules/_bundled/sirsoft-board/tests/Feature/PostSearchKeywordSanitizationTest.php' => '게시판 트랙 소유 (전문검색 연산자 입력의 500 방지).',
        // ── develop 8차 병합 유입분(7.0.10 · KVE-2026 보안 6묶음). 같은 형태 — develop 라인이 마킹은 달고
        //    매니페스트는 두지 않았다. 귀속 실측: 커밋 `07fd45e6b`(#645) 도입, 이 트랙 변경 0.
        'modules/_bundled/sirsoft-board/tests/Feature/SecretPostChildWriteGateTest.php' => '게시판 트랙 소유 (비밀글 하위 쓰기 게이트 — 댓글·답글·신고의 부모 열람 권한 재적용, KVE-2026-2044).',
        'modules/_bundled/sirsoft-board/tests/Feature/User/PostSecretVerifyPasswordTest.php' => '게시판 트랙 소유 (비밀글 비밀번호 검증 응답 형상과 열람 확인 토큰 발급).',
        'modules/_bundled/sirsoft-board/tests/Unit/Benchmark/ListIndexCoverageTest.php' => '성능 계측 트랙 소유 (목록 프로파일 ↔ 인덱스 선언 대조).',
        'modules/_bundled/sirsoft-board/tests/Unit/Repositories/UserCommentedActivityDeferredJoinTest.php' => '목록 지연 조인 트랙 소유 (그룹 쿼리 총 건수·컬럼 프루닝).',
        'modules/_bundled/sirsoft-ecommerce/tests/Feature/Http/Controllers/Admin/ProductListPayloadSizeBenchmarkTest.php' => '목록 응답 축소 트랙 소유 (페이로드 바이트·쿼리 수).',
        'modules/_bundled/sirsoft-ecommerce/tests/Feature/Identity/CheckoutVerificationGuardTest.php' => '이커머스 IDV 트랙 소유 (체크아웃 본인인증 게이트).',
        'modules/_bundled/sirsoft-ecommerce/tests/Feature/Performance/OrderOptionBulkQueryCountRegressionTest.php' => '이커머스 성능 트랙 소유 (일괄 상태변경의 배치 조회).',
        'modules/_bundled/sirsoft-ecommerce/tests/Unit/Benchmark/ListIndexCoverageTest.php' => '성능 계측 트랙 소유 (목록 프로파일 ↔ 인덱스 선언 대조).',
        'modules/_bundled/sirsoft-ecommerce/tests/Unit/Services/PgProviderSupportedMethodsVocabularyTest.php' => 'PG 트랙 소유 (결제수단 어휘 일치).',
        'modules/_bundled/sirsoft-page/tests/Unit/Benchmark/ListIndexCoverageTest.php' => '성능 계측 트랙 소유 (목록 프로파일 ↔ 인덱스 선언 대조).',
        'plugins/_bundled/sirsoft-gdpr/tests/Feature/GdprConsentAtomicityTest.php' => 'GDPR 트랙 소유 (동의 철회·삭제의 원자성).',
        'plugins/_bundled/sirsoft-pay_kginicis/tests/Feature/Controllers/VbankNotifyReplayTest.php' => 'PG 트랙 소유 (가상계좌 통보 재생 방어).',
        'plugins/_bundled/sirsoft-pay_kginicis/tests/Unit/Support/BackendMessageKeyResolutionTest.php' => 'PG 트랙 소유 (백엔드 메시지 키의 로케일 해석).',
        'plugins/_bundled/sirsoft-pay_nhnkcp/tests/Feature/Concerns/PreventsReplayCallbackTest.php' => 'PG 트랙 소유 (콜백 재생 방어).',
        'plugins/_bundled/sirsoft-pay_nhnkcp/tests/Unit/Services/CliInjectionDefenseTest.php' => 'PG 트랙 소유 (CLI 인자 주입 방어).',
        'plugins/_bundled/sirsoft-pay_nicepayments/tests/Feature/Concerns/PreventsReplayCallbackTest.php' => 'PG 트랙 소유 (콜백 재생 방어).',
        'plugins/_bundled/sirsoft-pay_nicepayments/tests/Unit/Controllers/AuthTokenDedupeKeyTest.php' => 'PG 트랙 소유 (인증 토큰 중복 제거 키).',
        'plugins/_bundled/sirsoft-verification_kginicis/tests/Feature/Listeners/CleanInicisRecordAtomicityTest.php' => '본인인증 트랙 소유 (인증 기록 정리의 원자성).',
        'plugins/_bundled/sirsoft-verification_kginicis/tests/Feature/PluginCacheContextualBindingTest.php' => '확장 캐시 바인딩 트랙 소유 (플러그인 컨텍스추얼 바인딩).',
        'plugins/_bundled/sirsoft-verification_nhnkcp/tests/Feature/Listeners/CleanKcpRecordAtomicityTest.php' => '본인인증 트랙 소유 (인증 기록 정리의 원자성).',
        'tests/Unit/Benchmark/ListIndexCoverageTest.php' => '성능 계측 트랙 소유 (목록 프로파일 ↔ 인덱스 선언 대조).',
        'tests/Unit/Installer/InstallerStep3EnvValueGuardTest.php' => '설치 마법사 트랙 소유 (3단계 입력의 줄바꿈 주입으로 .env 항목이 끼워지는 것 방지, KVE-2026-2042 — develop 8차 유입).',
        'tests/Unit/Http/Resources/HasRowNumberBoundedTotalTest.php' => '대용량 목록 상한 트랙 소유 (총 건수 절사 시 행번호 표기).',
        'tests/Unit/Search/KeywordSearchTest.php' => '검색 트랙 소유 (키워드 술어의 활성 엔진 위임).',
        'tests/Unit/Services/LanguagePackServiceUpdateCheckScopeTest.php' => '언어팩 트랙 소유 (업데이트 확인 범위).',
        'tests/Unit/Services/UserServiceWithdrawTest.php' => '회원 트랙 소유 (탈퇴 처리의 멱등성·컬럼 한계).',
    ];

    /**
     * 종료 검증 명령의 Artisan 줄이 **오늘 실행 가능**하다 (32차 ⚠️).
     *
     * 게이트 목록과 실제 실행 가능성이 갈리는 자리에 판정 장치가 없었다 — 계획서 종료 검증
     * 블록의 한 줄이 미착수 단계(S18 P2)의 옵션을 쓰고 있어 `exit 1` 인데, 네 회차가 "종료 검증
     * 명령 5종 전건 초록" 으로 기록했다(실제로는 다른 조합을 셌다). 선언을 코드로 옮기고 옵션
     * 실재를 리플렉션이 아니라 커맨드 정의에서 확인한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gate_artisan_commands_are_runnable_today
     *
     * @return void 반환값 없음
     */
    public function test_gate_artisan_commands_are_runnable_today(): void
    {
        $declared = GateTestPopulation::GATE_ARTISAN_COMMANDS;

        $this->assertNotEmpty($declared,
            '게이트 Artisan 명령 목록이 비었다 — 목록이 사라지면 이 검사는 아무것도 잠그지 않는다');

        $registry = $this->app->make(Kernel::class)->all();

        foreach ($declared as $command => $options) {
            $this->assertArrayHasKey($command, $registry,
                "게이트가 쓰는 Artisan 커맨드가 없습니다: {$command}");

            $definition = $registry[$command]->getDefinition();

            foreach ($options as $option) {
                $this->assertTrue($definition->hasOption($option),
                    "게이트 명령 `{$command}` 에 `--{$option}` 옵션이 없습니다 — 종료 검증 블록의 "
                    .'그 줄은 오늘 exit 1 이다. 아직 만들지 않은 단계의 옵션이라면 '
                    .'PENDING_GATE_ARTISAN_COMMANDS 로 옮기고 계획서에도 "S18.5 이후" 로 표기해야 한다.');
            }
        }
    }

    /**
     * 미착수 게이트 명령이 여전히 실행 불가인지 되짚는다 (자기 정리).
     *
     * 옵션이 생겼는데 목록에 남아 있으면 그 명령은 영원히 게이트 밖이다.
     *
     * **S18.5 — 대장이 비었다.** 두 행(`layout:decompile --sync`·`--check`)이 설계대로 착수 시점에
     * red 를 냈고, 옮겨진 뒤 이 목록은 `[]` 가 됐다. 종전 판본은 `assertNotEmpty($pending)` 하한을
     * 두고 *"전부 실행 가능해졌다면 이 테스트를 제거해야 한다"* 고 적었는데, 그대로 따르면 다음
     * 미착수 명령이 생겼을 때 **알려 줄 장치가 사라진다**. 반대로 하한만 지우면 `foreach` 가 빈
     * 배열을 돌아 공허하게 참이 된다 — 34차가 이관 제외 대장에서 잡은 `assertSame([], [])` 형태다.
     *
     * 그래서 하한을 지우되 관측력을 **판정식 자기시험**으로 옮긴다
     * ({@see test_pending_ledger_predicate_still_detects_a_runnable_command()}). 대장이 비어 있어도
     * "실행 가능해진 항목을 이 술어가 잡는다" 는 명제는 계속 시험된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects pending_gate_artisan_commands_are_pruned_when_they_become_runnable
     *
     * @return void 반환값 없음
     */
    public function test_pending_gate_artisan_commands_are_still_unrunnable(): void
    {
        $runnable = [];

        foreach (array_keys(GateTestPopulation::PENDING_GATE_ARTISAN_COMMANDS) as $entry) {
            if ($this->pendingEntryIsRunnable($entry)) {
                $runnable[] = $entry;
            }
        }

        $this->assertSame([], $runnable,
            '미착수로 등재된 게이트 명령이 실행 가능해졌습니다 — PENDING_GATE_ARTISAN_COMMANDS 에서 '
            .'빼고 GATE_ARTISAN_COMMANDS 로 옮긴 뒤 계획서 종료 검증 블록의 유예 표기도 함께 '
            .'지워야 한다.');
    }

    /**
     * 미착수 대장이 비어도 그 판정식이 살아 있음을 합성 항목으로 시험합니다 (S18.5).
     *
     * 실물 대장이 관측을 제공하지 못하게 된 자리에서 관측을 합성 표본으로 옮기는 것은 이 트랙의
     * 확립된 처방이다(34차 이관 제외 대장 · S18.5 조각 짝 수렴). 표본은 실재 커맨드에서 고른다 —
     * 가짜 커맨드 이름을 쓰면 "대장이 실재 커맨드를 가리키는가" 축까지 함께 죽는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects pending_gate_ledger_predicate_is_self_tested_when_the_ledger_is_empty
     *
     * @return void 반환값 없음
     */
    public function test_pending_ledger_predicate_still_detects_a_runnable_command(): void
    {
        $this->assertTrue($this->pendingEntryIsRunnable('layout:decompile --sync'),
            '실행 가능한 옵션을 미착수 판정식이 잡지 못합니다 — 대장이 빈 지금 이 축이 죽으면 '
            .'다음 미착수 명령은 영원히 게이트 밖에 남는다');

        $this->assertFalse($this->pendingEntryIsRunnable('layout:decompile --not-a-real-option'),
            '존재하지 않는 옵션을 실행 가능으로 판정합니다 (과탐 — 그러면 대장이 영원히 red 다)');
    }

    /**
     * 미착수 대장 항목(`커맨드 --옵션`)이 오늘 실행 가능한지 판정합니다.
     *
     * @param  string  $entry  대장 키
     * @return bool 옵션이 실재하면 true
     */
    private function pendingEntryIsRunnable(string $entry): bool
    {
        [$command, $flag] = explode(' --', $entry, 2);

        $registry = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey($command, $registry,
            "미착수 목록이 존재하지 않는 커맨드를 담고 있습니다: {$command}");

        return $registry[$command]->getDefinition()->hasOption($flag);
    }

    /**
     * 매니페스트가 선언한 PHP 테스트가 전부 종료 게이트 필터에 걸린다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects declared_test_files_are_reachable_by_gate_filters
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_php_test_file_is_reachable_by_gate_filters(): void
    {
        $declared = $this->declaredPhpTestFiles();

        $this->assertNotEmpty($declared,
            '매니페스트에서 도출한 PHP 테스트 파일이 0건이다 — 파싱이 죽었는지 먼저 확인해야 한다 '
            .'("위반 0" 과 "검사 0" 은 다른 명제다)');

        $unreachable = [];

        foreach ($declared as $relative) {
            $this->assertFileExists(base_path($relative),
                "매니페스트가 실재하지 않는 테스트 파일을 선언합니다: {$relative}");

            $fqcn = GateTestPopulation::fullyQualifiedClassName(base_path($relative));

            if ($fqcn === null) {
                continue;
            }

            if (! GateTestPopulation::matchesAnyGateFilter($fqcn)) {
                $unreachable[$relative] = $fqcn;
            }
        }

        $this->assertSame([], $unreachable,
            '매니페스트가 S18 산출물로 선언했는데 종료 게이트가 실행하지 않는 테스트가 있습니다 — '
            .'게이트 필터에 걸리는 네임스페이스로 옮기거나 필터를 넓혀야 한다 '
            .'(선언만 하고 안 도는 테스트는 커버리지 착시를 만든다).');
    }

    /**
     * 게이트 안에서 도는 테스트 클래스가 전부 매니페스트에 선언돼 있다.
     *
     * 반대 방향이 없으면 "돌기는 하는데 어디에도 등재되지 않은" 테스트가 남아, 시나리오 커버리지
     * 산정과 실제 실행 집합이 조용히 갈린다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gate_resident_tests_are_declared_in_manifest
     *
     * @return void 반환값 없음
     */
    public function test_every_gate_resident_test_is_declared_in_manifest(): void
    {
        $declared = array_flip($this->declaredPhpTestFiles());
        $resident = $this->gateResidentTestFiles();

        $this->assertNotEmpty($resident,
            '게이트 네임스페이스에서 테스트 파일이 0건 도출됐다 — 디렉토리 규약이 바뀌었는지 확인');

        $undeclared = array_values(array_filter(
            $resident,
            static fn (string $relative): bool => ! isset($declared[$relative])
        ));

        $this->assertSame([], $undeclared,
            '게이트에서 도는데 시나리오 매니페스트에 등재되지 않은 테스트가 있습니다 — '
            .'test_files 에 사유 주석과 함께 추가해야 한다.');
    }

    /**
     * 게이트 필터 대안이 전부 살아 있다 (죽은 필터 = 죽은 축).
     *
     * 필터 문자열이 낡으면 그 축은 조용히 0건이 된다 — 19차가 "가드가 죽어 후보 0건" 으로 겪은
     * 형태다. 각 대안이 선언 집합에서 최소 1건을 잡는지 확인한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gate_filters_each_match_at_least_one_declared_test
     *
     * @return void 반환값 없음
     */
    public function test_every_gate_filter_matches_something(): void
    {
        $fqcns = [];

        foreach ($this->declaredPhpTestFiles() as $relative) {
            $fqcn = GateTestPopulation::fullyQualifiedClassName(base_path($relative));

            if ($fqcn !== null) {
                $fqcns[] = $fqcn;
            }
        }

        foreach (GateTestPopulation::GATE_FILTERS as $filter) {
            $matched = array_filter(
                $fqcns,
                static fn (string $fqcn): bool => preg_match('/'.$filter.'/i', $fqcn) === 1
            );

            $this->assertNotEmpty($matched,
                "게이트 필터가 아무것도 잡지 못합니다: {$filter} — 이름이 바뀌었거나 대상이 사라졌다면 "
                .'그 축은 이미 게이트에서 빠진 상태다');
        }
    }

    /**
     * `phpunit.xml` 이 선언한 디렉토리 패턴마다 최소 1건이 도출된다.
     *
     * 모집단을 설정 파일에서 도출하는 대가는 **도출이 조용히 죽을 수 있다**는 것이다. 글롭이
     * 낡거나(`_bundled` 구조 변경) 파싱이 어긋나면 그 스위트가 통째로 0건이 되고, 그때 방향2 는
     * "위반 없음" 을 돌려준다. 패턴별 하한을 두어 그 상태를 red 로 만든다 — 19차가 "가드가 죽어
     * 후보 0건" 으로 겪은 형태를 이 축에서 되풀이하지 않기 위한 것이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects phpunit_suite_directories_each_contribute_collected_tests
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_suite_directory_contributes_tests(): void
    {
        $declared = GateTestPopulation::suiteDirectories();

        // 32차 ⚠️ — 선언 파싱이 죽어 0건이 되면 `$barren` 도 0건이라 이 단언은 조용히 통과한다.
        $this->assertNotEmpty($declared,
            'phpunit.xml 이 선언한 디렉토리가 0건이다 — 설정 파싱이 죽었는지 먼저 확인해야 한다');

        $barren = [];

        foreach ($declared as $pattern => $excludes) {
            $roots = File::glob(base_path($pattern)) ?: [];
            $found = 0;

            foreach ($roots as $base) {
                if (! File::isDirectory($base)) {
                    continue;
                }

                foreach (File::allFiles($base) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $relative = GateTestPopulation::relativePath($file->getPathname());

                    foreach ($excludes as $exclude) {
                        if (str_starts_with($relative, $exclude)) {
                            continue 2;
                        }
                    }

                    $found++;
                }
            }

            if ($found === 0 && ! isset(self::FORWARD_DECLARED_PATTERNS[$pattern])) {
                $barren[] = $pattern;
            }
        }

        $this->assertSame([], $barren,
            'phpunit.xml 이 선언한 디렉토리인데 테스트가 0건 도출됐습니다 — 글롭이 낡았거나 구조가 '
            .'바뀌었다는 뜻이고, 그 스위트는 이미 게이트 소속 판정에서 빠진 상태다. 의도된 미래 '
            .'슬롯이라면 FORWARD_DECLARED_PATTERNS 에 사유와 함께 등재해야 한다.');
    }

    /**
     * 미래 슬롯 허용 목록이 여전히 0건인지 되짚는다 (자기 정리).
     *
     * 허용 목록은 그대로 두면 거짓말로 굳는다 — 슬롯에 테스트가 생겼는데 목록에 남아 있으면 그
     * 디렉토리는 하한 검사를 영구히 면제받는다. 등재분이 실제로 기여 0건임을 요구해, 기여가
     * 생기는 순간 red 로 "목록에서 빼라" 를 알린다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects forward_declared_suite_slots_are_pruned_when_they_gain_tests
     *
     * @return void 반환값 없음
     */
    public function test_forward_declared_slots_are_still_barren(): void
    {
        $declared = GateTestPopulation::suiteDirectories();

        // 32차 ⚠️ — 허용 목록이 비면 루프가 0회라 이 테스트는 아무것도 단언하지 않는다.
        // 선언 파싱이 살아 있는지를 하한으로 못박아 "검사 0" 과 "위반 0" 을 가른다.
        $this->assertNotEmpty($declared,
            'phpunit.xml 이 선언한 디렉토리가 0건이다 — 설정 파싱이 죽었는지 먼저 확인해야 한다');

        $stale = [];

        foreach (self::FORWARD_DECLARED_PATTERNS as $pattern => $reason) {
            $this->assertArrayHasKey($pattern, $declared,
                "허용 목록이 phpunit.xml 에 없는 패턴을 담고 있습니다: {$pattern} — 선언이 사라졌다면 "
                .'목록에서도 지워야 한다');

            $found = 0;

            foreach (File::glob(base_path($pattern)) ?: [] as $base) {
                if (! File::isDirectory($base)) {
                    continue;
                }

                foreach (File::allFiles($base) as $file) {
                    if ($file->getExtension() === 'php') {
                        $found++;
                    }
                }
            }

            if ($found > 0) {
                // 등재 사유를 실패 메시지에 실어 나른다 — 38차까지 `$reason` 이 순회에서만 꺼내지고
                // 어디에도 쓰이지 않아, 면제가 낡았을 때 **왜 면제했는지**가 화면에 안 나왔다.
                // 등록부의 사유 칸은 읽히지 않으면 주석과 다를 바 없다.
                $stale[$pattern] = "{$found}건 발견 (등재 사유: {$reason})";
            }
        }

        $this->assertSame([], $stale,
            '미래 슬롯으로 면제해 둔 디렉토리에 테스트가 생겼습니다 — FORWARD_DECLARED_PATTERNS 에서 '
            .'제거해 하한 검사를 받게 해야 한다(면제가 남아 있으면 그 축은 계속 검사 밖이다).');
    }

    /**
     * 게이트 필터 축마다 디스크에 실제로 상주하는 테스트가 있다.
     *
     * {@see test_every_gate_filter_matches_something()} 는 **선언 집합**에서만 확인하므로, 매니페스트
     * 항목과 실물이 함께 사라지면 둘 다 초록이 된다. 상주 축의 하한을 따로 두어 그 동시 소멸을
     * red 로 만든다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects gate_filters_each_have_resident_tests_on_disk
     *
     * @return void 반환값 없음
     */
    public function test_every_gate_filter_has_resident_tests(): void
    {
        $suiteFiles = GateTestPopulation::collectedFiles();

        $this->assertNotEmpty($suiteFiles,
            'phpunit.xml 스위트에서 PHP 테스트가 0건 도출됐다 — 모집단 도출이 죽었는지 먼저 확인');

        foreach (GateTestPopulation::GATE_FILTERS as $filter) {
            $matched = [];

            foreach ($suiteFiles as $relative => $absolute) {
                $fqcn = GateTestPopulation::fullyQualifiedClassName($absolute);

                if ($fqcn !== null && preg_match('/'.$filter.'/i', $fqcn) === 1) {
                    $matched[] = $relative;
                }
            }

            $this->assertNotEmpty($matched,
                "게이트 필터에 상주하는 테스트가 디스크에 없습니다: {$filter} — 대상이 삭제됐거나 "
                .'이름이 바뀌었다면 그 축은 종료 명령에서 아무것도 검사하지 않는다');
        }
    }

    /**
     * 매니페스트가 선언한 `effects` 집합이 **테스트가 마킹한 effect 전량을 덮는다.**
     *
     * 38차 ❌① — 이 방향이 없어서 회차 산출물이 등재 없이 종료됐다.
     *
     * `test-scenario-coverage` audit 룰은 **매니페스트 → 테스트** 한 방향만 본다(매니페스트가
     * 선언한 effect 를 아무 테스트도 덮지 않으면 red). 그 반대 — 테스트가 `@effects` 로 선언한
     * 토큰이 매니페스트에 없는 것 — 은 어떤 검사에도 걸리지 않았다. 이 클래스의 형제 명제도
     * *파일* 등재만 잠근다(`test_every_gate_resident_test_is_declared_in_manifest`). 그래서
     * **파일은 등재됐는데 그 파일이 새로 선언한 effect 는 미등재** 인 상태가 통과했다.
     *
     * 38차 실측 14건 중 하나가 37차 산출물이다(`fragment_helper_read_cache_is_keyed_by_path_mtime_size`
     * — 37차 ⓕ 가 "조각 캐시 무관측" 을 고치며 신설). 즉 **직전 회차가 자기 산출물을 등재하지
     * 못한 채 종료**했고 아무도 알리지 않았다. 30차가 게이트 소속을 양방향으로 잠근 것과 같은
     * 처치를 effect 축에 적용한다.
     *
     * 마킹 추출은 **재구현하지 않는다** — `@effects` 는 연속행(` *   tok,`)으로 이어질 수 있고
     * 구분자는 쉼표뿐이라, 자체 정규식은 조용히 절반을 놓친다(38차 검증 중 실제로 그 오류가
     * 났다). 하네스의 정본 추출기와 같은 의미론을 쓴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects declared_effects_cover_every_marked_effect
     *
     * @return void 반환값 없음
     */
    public function test_declared_effects_cover_every_marked_effect(): void
    {
        $manifest = Yaml::parseFile(base_path(self::MANIFEST));
        $declared = array_flip($manifest['effects'] ?? []);

        $this->assertNotEmpty($declared, '매니페스트 effects 가 비어 있다 — 파싱이 죽었는지 확인');

        $marked = [];
        foreach ($manifest['test_files'] ?? [] as $relative) {
            $absolute = base_path($relative);
            if (! is_file($absolute)) {
                continue;
            }
            foreach ($this->markedEffects((string) file_get_contents($absolute)) as $effect) {
                $marked[$effect] ??= $relative;
            }
        }

        // 하한 — 「위반 0」과 「검사 대상 0」을 가른다. 추출식이 죽으면 이 단언이 먼저 red 다.
        $this->assertNotEmpty($marked,
            '테스트에서 @effects 마킹이 0건 도출됐다 — 추출식이 죽었거나 test_files 가 비었다');

        $undeclared = [];
        foreach ($marked as $effect => $source) {
            if (! isset($declared[$effect])) {
                $undeclared[] = "{$effect}  <- {$source}";
            }
        }

        $this->assertSame([], $undeclared,
            '테스트가 선언한 @effects 가 시나리오 매니페스트에 등재되지 않았습니다 — '
            .'그 축은 커버리지 산정에서 보이지 않으므로, 회차 산출물이 등재 없이 종료된다. '
            .'매니페스트 effects 목록에 사유 주석과 함께 추가할 것.');
    }

    /**
     * 마킹을 가진 테스트 파일은 **어느 매니페스트엔가** 등재돼 있다 — 모집단 반전 (39차 신설).
     *
     * ## 왜 이 방향이 또 필요한가
     *
     * 38차가 세운 형제 축({@see test_declared_effects_cover_every_marked_effect()})은 방향은
     * 맞았지만 모집단이 **`test_files` 안**이다. 그래서 *등재된 파일이 새로 선언한 effect* 는
     * 잡아도, **파일 자체가 어느 매니페스트에도 없는** 경우는 구조적으로 보지 못한다 —
     * 그 파일의 마킹은 수집조차 되지 않으므로 차집합에 나타날 수 없다.
     *
     * 39차 실측이 그 여집합에 실물 3건을 찾았다(`PluginPartialResolutionParityTest` ·
     * `LayoutPartialBaseDirectoryTest` · `GeneratesComponentManifestTest`). 셋 다 이 트랙의
     * 축(조각 경로 규약 · 컴포넌트 레지스트리)을 단언하면서 `@scenario`/`@effects` 까지 달아
     * 놓았는데, **커버리지 산정에는 한 번도 들어간 적이 없다.** 마킹은 "이 테스트가 어떤 축을
     * 덮는다" 는 선언이므로, 그 선언이 어디에도 수렴하지 않으면 초록은 아무것도 보증하지 않는다.
     *
     * 이 축은 회차마다 반복된 골격 — *열거의 여집합이 다음 회차의 검출물이 된다* — 의 모집단
     * 판본이다. 그래서 모집단을 손으로 쓰지 않는다: 매니페스트는 코어 + 번들 확장의 `scenarios`
     * 디렉토리 전량을 glob 으로, 테스트는 `phpunit.xml` 수집 대상 + 번들 확장 `tests` 트리를
     * 재귀로 도출한다. 추출은 {@see markedEffects()} 정본을 그대로 쓴다(39차 검증 중 자체
     * 추출기를 세 번 다시 만들어 세 번 다른 수를 얻었다 — 재구현이 그 오류의 원인이다).
     *
     * 타 트랙 소유 파일은 {@see FOREIGN_MARKED_TEST_DEBT} 에 사유와 함께 등재하고 **정확히
     * 일치**를 요구한다 — 부분집합으로 두면 해소된 항목이 목록에 남아 그 자리가 영구히 검사
     * 밖이 된다(래칫의 기존 규율과 같다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects marked_test_files_are_declared_in_some_manifest
     *
     * @return void 반환값 없음
     */
    public function test_every_marked_test_file_is_declared_in_some_manifest(): void
    {
        $declared = $this->allDeclaredTestFiles();

        $this->assertNotEmpty($declared,
            '어느 매니페스트에서도 test_files 가 도출되지 않았다 — glob 또는 파싱이 죽었는지 먼저 '
            .'확인해야 한다 (그 상태면 이 폐쇄는 전부를 위반으로 만들거나 공허하게 참이 된다)');

        $marked = $this->markedTestFiles();

        $this->assertNotEmpty($marked,
            '마킹을 가진 테스트 파일이 0건 도출됐다 — 모집단 도출이 죽으면 여집합은 언제나 공집합이다');

        $orphans = array_values(array_filter(
            $marked,
            static fn (string $relative): bool => ! isset($declared[$relative])
        ));

        $registered = array_keys(self::FOREIGN_MARKED_TEST_DEBT);
        sort($registered);

        $this->assertSame($registered, $orphans,
            '`@scenario`/`@effects` 를 선언했는데 어느 시나리오 매니페스트의 test_files 에도 없는 '
            .'테스트가 있습니다 — 그 파일의 마킹은 커버리지 산정에 한 번도 들어가지 않으므로 '
            .'"덮는다" 는 선언만 남고 실제 집계는 0 이다. 소유 트랙의 매니페스트에 등재하거나, '
            .'타 트랙 소유라면 FOREIGN_MARKED_TEST_DEBT 에 사유와 함께 등재할 것 '
            .'(해소되면 목록에서도 빼야 한다 — 남겨 두면 그 자리는 영구히 검사 밖이다).');
    }

    /**
     * 매니페스트 전량(코어 + 번들 확장)이 선언한 테스트 파일입니다.
     *
     * ## 왜 여기서만 `Yaml::parseFile()` 을 쓰지 않는가
     *
     * 저장소의 시나리오 매니페스트 169개 중 **16개가 Symfony YAML 의 엄격 파싱에서 예외**를 낸다
     * (한국어 설명 블록 안의 콜론·들여쓰기). 그 16개는 커버리지 도구가 실제로 쓰는 파서
     * (하네스의 `lib/test-scenario` 파서)로는 정상 파싱되므로 **결함이 아니라 파서 엄격성
     * 차이**다 — 세 표본을 그 파서로 직접 태워 확인했다(`test_files` 19/6/4 정상 도출).
     *
     * 그런데 이 축이 엄격 파서에 의존하면 그 16개가 **선언 집합에서 통째로 빠지고**, 그 매니페스트가
     * 소유한 테스트들이 일제히 "고아" 로 오검출된다 — 남의 트랙 파일을 이 트랙의 부채 목록에
     * 밀어 넣게 된다. `test_files` 는 스칼라 평면 목록이라 줄 단위 판독으로 충분하고 관용적이므로,
     * 여기서는 그쪽을 쓴다. 이 트랙 자신의 매니페스트는 형제 축들이 `Yaml::parseFile()` 로 읽으므로
     * 구조가 깨지면 그쪽이 먼저 red 다.
     *
     * @return array<string, string> 상대 경로 → 선언한 매니페스트 경로
     */
    private function allDeclaredTestFiles(): array
    {
        $declared = [];

        foreach ($this->scenarioManifests() as $manifest) {
            $inFiles = false;

            // 개행을 `\R` 로 자르지 않는다 — `/u` 없는 `\R` 은 **바이트** `\x85` 를 개행으로 보고,
            // 그 바이트는 한국어 UTF-8 시퀀스 안에 흔히 들어 있다. 39차에 실제로 한글 주석 한 줄이
            // 반토막 나면서 그 조각이 최상위 키로 오인돼 목록이 15건에서 끊겼다(선언 50여 건 중).
            foreach (preg_split('/\r\n|\r|\n/', (string) File::get(base_path($manifest))) as $line) {
                if (preg_match('/^test_files:/', $line) === 1) {
                    $inFiles = true;

                    continue;
                }

                if (! $inFiles) {
                    continue;
                }

                if (preg_match('/^\s*-\s*(\S+)/', $line, $matches) === 1) {
                    $relative = str_replace('\\', '/', trim($matches[1], "'\""));
                    $declared[$relative] = $manifest;

                    continue;
                }

                // 들여쓰기가 끝나면(주석·빈 줄이 아닌 최상위 키) 목록도 끝난다.
                if (preg_match('/^\S/', $line) === 1) {
                    $inFiles = false;
                }
            }
        }

        return $declared;
    }

    /**
     * 시나리오 매니페스트 전량입니다 — 코어 + 번들 확장 3종.
     *
     * @return array<int, string> 저장소 상대 경로
     */
    private function scenarioManifests(): array
    {
        $patterns = ['tests/scenarios/*.yaml'];

        foreach (['modules', 'plugins', 'templates'] as $type) {
            $patterns[] = $type.'/_bundled/*/tests/scenarios/*.yaml';
        }

        $manifests = [];

        foreach ($patterns as $pattern) {
            foreach (File::glob(base_path($pattern)) as $absolute) {
                $manifests[] = str_replace('\\', '/', substr($absolute, strlen(base_path()) + 1));
            }
        }

        sort($manifests);

        return $manifests;
    }

    /**
     * `@effects` 마킹을 가진 테스트 파일 전량입니다 (코어 + 번들 확장).
     *
     * @return array<int, string> 저장소 상대 경로 (정렬됨)
     */
    private function markedTestFiles(): array
    {
        $roots = ['tests'];

        foreach (['modules', 'plugins', 'templates'] as $type) {
            foreach (File::glob(base_path($type.'/_bundled/*/tests')) as $absolute) {
                $roots[] = str_replace('\\', '/', substr($absolute, strlen(base_path()) + 1));
            }
        }

        $marked = [];

        foreach ($roots as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if ($this->markedEffects((string) File::get($file->getPathname())) !== []) {
                    $marked[] = $relative;
                }
            }
        }

        sort($marked);

        return $marked;
    }

    /**
     * 테스트 파일 본문에서 `@effects` 토큰을 추출합니다.
     *
     * 연속행 처리가 요점이다 — 마킹 태그 뒤에 토큰을 쉼표로 잇고 다음 줄을 ` *   ` 로 이어
     * 적는 형태가 실제로 쓰이며, 줄 단위로만 자르는 추출식은 첫 줄만 보고 나머지를 조용히
     * 버린다(38차 검증 중 실제로 그 오류를 냈다). 구분자는 **쉼표뿐**이며 ` + `·`×`·`&` 는
     * 구분자가 아니다(계획서 §"생성기·하네스 주의" 가 등재한 함정).
     *
     * 이 독블록에 태그 예시를 **리터럴로 적지 않는다** — 적으면 이 추출기가 자기 설명을 실제
     * 마킹으로 읽어 자기 자신을 위반으로 만든다(신설 직후 실제로 그렇게 red 가 났다). 19차가
     * "규약을 설명하는 독블록이 위반으로 잡히면 작성자가 설명을 지운다" 로 정리한 형태이므로,
     * 설명을 지우는 대신 표기를 바꾼다.
     *
     * @param  string  $source  테스트 파일 전문
     * @return array<int, string> effect 토큰
     */
    private function markedEffects(string $source): array
    {
        $effects = [];

        if (preg_match_all('/\/\*\*([\s\S]*?)\*\//', $source, $blocks) === false) {
            return [];
        }

        foreach ($blocks[1] ?? [] as $body) {
            if (preg_match('/@effects\s+([^\n*]+(?:\n\s*\*\s*[^\n@*]+)*)/', $body, $m) !== 1) {
                continue;
            }
            $flat = (string) preg_replace('/\n\s*\*\s*/', ' ', $m[1]);
            foreach (explode(',', $flat) as $token) {
                $token = trim($token);
                if ($token !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $token) === 1) {
                    $effects[] = $token;
                }
            }
        }

        return $effects;
    }

    /**
     * 매니페스트의 `test_files` 중 PHP 파일만 상대 경로로 돌려줍니다.
     *
     * `.ts` 항목은 Vitest 게이트(⑥) 소관이라 PHPUnit 필터 판정 대상이 아니다.
     *
     * @return array<int, string> 저장소 루트 기준 상대 경로
     */
    private function declaredPhpTestFiles(): array
    {
        $manifest = Yaml::parseFile(base_path(self::MANIFEST));

        return array_values(array_filter(
            $manifest['test_files'] ?? [],
            static fn (string $path): bool => str_ends_with($path, '.php')
        ));
    }

    /**
     * 게이트 필터가 실제로 선택하는 구체 테스트 클래스 파일을 도출합니다.
     *
     * 모집단은 `phpunit.xml` 이 수집하는 전량이고, 그중 FQCN 이 게이트 필터 대안에 걸리는 것만
     * 남긴다 — `--filter` 의 의미론 그대로다. 추상 기반 클래스는 실행 단위가 아니라 제외한다.
     *
     * @return array<int, string> 저장소 루트 기준 상대 경로 (정렬됨)
     */
    private function gateResidentTestFiles(): array
    {
        return array_keys(GateTestPopulation::gateSelectedFiles());
    }
}
