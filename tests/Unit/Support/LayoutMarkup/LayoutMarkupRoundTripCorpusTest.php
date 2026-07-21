<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;

/**
 * 전량 왕복 하네스(S9 DoD) — 번들 확장·템플릿의 모든 레이아웃 `.json` 이 왕복함을 기계 확인한다.
 *
 * 계획서 DoD "왕복 불일치 0" 의 회귀 봉인. `layout:compile --all --roundtrip` 과 같은 검사를
 * PHPUnit 으로 상시 돌려, 역변환기·직렬화기·컴파일러 어느 층의 회귀도 이 한 테스트가 잡는다.
 *
 * 표기 탈출구(`<json>`)로도 왕복하지 못하는 건이 하나라도 있으면 실패한다 — deep-equal 이 유일한 게이트다.
 */
class LayoutMarkupRoundTripCorpusTest extends LayoutMarkupTestCase
{
    /**
     * 왕복 코퍼스 모집단의 **하한** — 본 레이아웃 + fragment 전량 (㉓ 실측 639).
     *
     * 스캔 글롭이 좁아지면 게이트가 조용히 작아진 모집단 위에서 green 을 내므로(㉓ 이 겪은
     * "fragment 42건이 계량 밖" 사고) 하한으로 못박는다. 레이아웃 추가는 정상이라 상한은 없다.
     */
    private const CORPUS_MINIMUM = 639;

    /**
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects every_bundled_layout_round_trips_through_decompile_and_compile
     */
    public function test_every_bundled_layout_round_trips(): void
    {
        $resolver = new MarkupCompileContextResolver;
        $harness = new LayoutMarkupRoundTrip;
        $files = $this->scanLayoutFiles();

        // 모집단을 하한으로 못박는다. `assertNotEmpty` 만으로는 글롭이 좁아져 639→1 이 되어도 green 이다
        // — ㉓ 이 겪은 "반쪽 코퍼스 위의 escape-0"(fragment 42건이 통째로 계량 밖이던 것)이 바로 그
        // 형태였고, 그때 이 게이트는 아무 말도 하지 않았다. 레이아웃이 늘어나는 것은 정상이므로
        // 상한은 두지 않고, **줄어드는 것만** 회귀로 잡는다.
        $this->assertGreaterThanOrEqual(
            self::CORPUS_MINIMUM,
            count($files),
            '왕복 코퍼스 모집단이 줄었습니다 — 스캔 글롭이 좁아졌거나 레이아웃이 사라졌습니다 (기대 최소 '.self::CORPUS_MINIMUM.', 실측 '.count($files).').',
        );

        $failures = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            // 아래 둘은 종전 조용한 `continue` 였다. 조용히 넘기면 레지스트리 해석이 깨지거나 JSON 이
            // 손상돼도 "왕복 불일치 0" 이 유지된다 — 같은 파일의 이관 게이트(:250 이하)는 이미 이 둘을
            // 실패로 다루고 있어 엄격도가 비대칭이었다. 여기서도 실패로 맞춘다.
            $registry = $resolver->registryFor($file);

            if ($registry === null) {
                $failures[] = "{$relative} (대상 템플릿 레지스트리를 해석하지 못했습니다)";

                continue;
            }

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                $failures[] = "{$relative} (JSON 파싱 실패 — 손상되었거나 배열이 아닙니다)";

                continue;
            }

            $result = $harness->verify($json, $registry, $file);

            if (! $result['ok']) {
                $failures[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).($result['error'] !== null ? " ({$result['error']})" : '');
            }
        }

        $this->assertSame([], $failures, '왕복 불일치 레이아웃:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 이관 완료된 `.g7.html` 의 **명시 목록**이다 (계획서 §201·§207 "확장 목록형").
     *
     * 디렉토리 스캔만으로 게이트를 걸면 이관 파일이 사라져도 green 이 된다 — 회귀 감지력이 없다.
     * 그래서 W1 산출 16건(15 clean + S7 데모)을 목록으로 못박고, 스캔 결과가 이 목록과 정확히
     * 일치하는지까지 단언한다. 이후 웨이브는 이 목록을 확장한다.
     *
     * @var array<int, string>
     */
    private const MIGRATED_MARKUP_FILES = [
        // S7 데모 (W1 이전 완료본)
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_markup_demo.g7.html',
        // W1 — hello admin 템플릿 (7)
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/admin_dashboard.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/maintenance.g7.html',
        // W1 — hello user 템플릿 (8)
        'templates/_bundled/gnuboard7-hello_user_template/layouts/_user_base.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/home.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/maintenance.g7.html',
        // W2 — sirsoft-page (3)
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_detail.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_form.g7.html',
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_list.g7.html',
        // W2 — sirsoft-gdpr (5) — `partials/_shared/_policy_version_snapshot_modal` 은 F12 로 이관 제외
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/cookie_banner.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/extensions/mypage_privacy_tab.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/gdpr_consent_log.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/partials/plugin_settings/_policy_version_publish_modal.g7.html',
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-ckeditor5 (3)
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-content.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/extensions/html-editor.g7.html',
        'plugins/_bundled/sirsoft-ckeditor5/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-daum_postcode (2)
        'plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html',
        'plugins/_bundled/sirsoft-daum_postcode/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-marketing (6)
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-detail.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-form.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile-view.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-profile.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-register.g7.html',
        'plugins/_bundled/sirsoft-marketing/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_kginicis (4) — `extensions/admin_order_payment_query` 는 F9 로 이관 제외
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/checkout_payment_error.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_kginicis/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_nhnkcp (6)
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_payment_query.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/checkout_easy_pay.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_show.g7.html',
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-pay_nicepayments (5) — `extensions/admin_order_payment_query` 는 F10·F11 로 이관 제외
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_list_test_badge.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/checkout_easy_pay.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_mypage_order_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/user_order_complete_receipt.g7.html',
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/layouts/admin/plugin_settings.g7.html',
        // W2 — sirsoft-verification_kginicis (3) — 아래 RAW_JSON_PENDING_GRAMMAR 참조
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/identity_provider_inicis.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/extensions/mypage_identity_card.g7.html',
        'plugins/_bundled/sirsoft-verification_kginicis/resources/layouts/admin/plugin_settings.g7.html',
    ];

    /**
     * 아직 1급 문법이 없어 원시 JSON 으로 남는 자리 (U-RAWJSON — D41 → **D42 전면 재집계**).
     *
     * D41 은 태그 본문(`<params>`/`<fallback>`/`<prop>`/`<props>`)을 "설계된 정본" 으로 보아 계량에서
     * 뺐으나, PO 지적(3회차)으로 **그 제외를 폐기**했다. 이제 태그 예외 없이 세므로 대장이 17→35 로
     * 늘었다. 늘어난 것은 새 결함이 아니라 **원래 있었으나 안 세던 것**이다.
     *
     * 형태별로 필요한 문법이 다르다:
     *  - 속성값 17 — 임의 이름 객체 파라미터. field-map 이 `<body>`/`<query>` 두 이름에만 배선돼
     *    있어(`SUB_STRUCTURAL_TAGS`) `params.form` 같은 임의 키를 되돌릴 수단이 없다.
     *  - `<params>` 3 — **전부 평면 스칼라 맵**. `compileFieldMap` 을 데이터소스 자식에 확장하면 해소.
     *  - `<fallback>` 6 — 평면 2(`{"data":null}`) + 중첩 4. 중첩은 `<field>` 트리가 필요하다.
     *  - `<prop>` 4 — 평면맵 2 + 객체 리스트 2(`rowActions`·`tabs`). 항목 태그가 필요하다.
     *  - `<props>` 5 — 병합 지시자 2(`{"tabs":{"_append":[…]}}`) + **DataGrid 컬럼 트리 3**.
     *    컬럼 트리는 `<column>`/`<cell>` 문법이 이미 있으나 소유자 게이트(DataGrid 한정)에 걸려
     *    확장 주입 `<props>` 문맥에서 blob 으로 떨어진다 — 태그로 저작하라고 만든 포맷 안에
     *    컴포넌트 트리가 통째 JSON 인 가장 나쁜 형태다.
     *
     * 전부 S8 동결 문법의 확장이라 **문법 신설은 PO 승인 사항**이다(D36 선례). 이 목록은 그 판단이
     * 날 때까지의 **명시 대장**이며, 등재되지 않은 신규 발생은 그대로 실패한다.
     *
     * 묵인이 아니라 **열거**다 — 목록에 없는 새 원시 JSON 은 그대로 실패한다. 문법이 생기면 해당
     * 행을 지우고 재생성하면 된다.
     *
     * 값은 라벨→**정확 건수** 맵이다. 게이트가 다중집합을 정확 대조하므로 등재 라벨이라도 건수가
     * 늘면(예: `:value` 3→5) 실패한다 — "묵인이 아니라 열거" 를 라벨뿐 아니라 건수까지 봉인한다.
     *
     * @var array<string, array<string, int>> 파일(상대경로) → (허용 방출 라벨 → 정확 건수)
     */
    private const RAW_JSON_PENDING_GRAMMAR = [
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_form.g7.html' => [':errors' => 1, ':form' => 1, '<prop>' => 2],
        'modules/_bundled/sirsoft-page/resources/layouts/admin/admin_page_list.g7.html' => [':_local' => 1, '<prop>' => 1],
        'plugins/_bundled/sirsoft-daum_postcode/resources/extensions/ecommerce-address-search.g7.html' => [':theme' => 1],
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/gdpr_consent_log.g7.html' => [':filter' => 2],
        'plugins/_bundled/sirsoft-gdpr/resources/layouts/admin/plugin_settings.g7.html' => ['<prop>' => 1],
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-detail.g7.html' => ['<props>' => 1],
        'plugins/_bundled/sirsoft-marketing/resources/extensions/user-marketing-form.g7.html' => ['<props>' => 1],
        'plugins/_bundled/sirsoft-marketing/resources/layouts/admin/plugin_settings.g7.html' => [':editingChannel' => 1],
        'plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/admin_order_list_test_badge.g7.html' => ['<props>' => 1],
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/admin_order_list_test_badge.g7.html' => ['<props>' => 1],
        'plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_show.g7.html' => [':args' => 1],
        'plugins/_bundled/sirsoft-pay_nicepayments/resources/extensions/admin_order_list_test_badge.g7.html' => ['<props>' => 1],
        'plugins/_bundled/sirsoft-verification_kginicis/resources/layouts/admin/plugin_settings.g7.html' => [':form' => 2],
        
    ];

    /**
     * 이관된 `.g7.html` 이 원본과 100% 동등하고 escape-0 임을 봉인합니다 (S10 W1 DoD).
     *
     * 각 `.g7.html` 을 설치 시점과 같은 파사드로 컴파일해 짝 `.json` 과 정규화 후 deep-equal 을 보고,
     * 저작 파일에 `<json>` 탈출구가 하나도 없음을 확인한다. 기계 이관이 원본 기능을 100% 보존한다는
     * (D38) 계약의 회귀 게이트다. 짝 `.json` 부재·레지스트리 미해석은 **skip 이 아니라 실패**다 —
     * 조용히 건너뛰면 이관이 깨져도 green 이 된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects every_migrated_markup_file_matches_its_json_and_is_escape_free
     */
    public function test_every_migrated_markup_file_matches_its_json_and_is_escape_free(): void
    {
        $resolver = new MarkupCompileContextResolver;

        // 스캔 결과가 명시 목록과 정확히 일치해야 한다 (누락도 잉여도 회귀).
        $scanned = array_map(
            fn (string $path): string => str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)),
            $this->scanMarkupFiles(),
        );
        sort($scanned);

        $expected = self::MIGRATED_MARKUP_FILES;
        sort($expected);

        $this->assertSame($expected, $scanned, '이관된 .g7.html 집합이 명시 목록과 다릅니다 (누락 또는 미등록 신규 이관).');

        $failures = [];

        foreach (self::MIGRATED_MARKUP_FILES as $relative) {
            $markupFile = base_path($relative);

            if (! File::exists($markupFile)) {
                $failures[] = "{$relative} — 이관 저작 파일이 없습니다";

                continue;
            }

            $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
            $source = File::get($markupFile);

            if (str_contains($source, '<json')) {
                $failures[] = "{$relative} — <json> 탈출구가 남아 있음 (escape≠0)";

                continue;
            }

            // D41 — `<json>` 태그만 세면 속성값·`<prop>` 본문의 통짜 JSON 을 놓친다.
            // 1급 문법이 있는데 blob 으로 떨어진 자리를 여기서 잡는다.
            // 아직 문법이 없는 자리(U-RAWJSON ⓒ)는 아래 대장에 **명시 등재**된 것만 통과한다 —
            // 라벨뿐 아니라 **건수까지** 정확 다중집합으로 대조하므로, 등재 라벨이라도 건수가
            // 늘거나(예: `:value` 3→5) 미등재 라벨이 새로 뜨면 그대로 실패한다.
            $emitted = array_count_values(LayoutMarkupRoundTrip::rawJsonEmissions($source));
            ksort($emitted);

            $allowed = self::RAW_JSON_PENDING_GRAMMAR[$relative] ?? [];
            ksort($allowed);

            if ($emitted !== $allowed) {
                $format = static function (array $counts): string {
                    if ($counts === []) {
                        return '(없음)';
                    }

                    return implode(', ', array_map(
                        static fn (string $k, int $n): string => "{$k}×{$n}",
                        array_keys($counts),
                        array_values($counts),
                    ));
                };

                $emittedStr = $format($emitted);
                $allowedStr = $format($allowed);

                $failures[] = "{$relative} — 원시 JSON 방출이 대장과 불일치: 실측 [{$emittedStr}] · 대장 [{$allowedStr}]";

                continue;
            }

            if (! File::exists($jsonFile)) {
                $failures[] = "{$relative} — 짝 원본 .json 이 없습니다 (병존 규약 위반)";

                continue;
            }

            $registry = $resolver->registryFor($markupFile);

            if ($registry === null) {
                $failures[] = "{$relative} — 대상 템플릿 레지스트리를 해석하지 못했습니다";

                continue;
            }

            $original = json_decode(File::get($jsonFile), true);

            try {
                $compiled = (new LayoutMarkupCompiler($registry))->compile(
                    $source,
                    $markupFile,
                    $resolver->contextFor($markupFile),
                )->json;
            } catch (\Throwable $e) {
                $failures[] = "{$relative} — 컴파일 오류: {$e->getMessage()}";

                continue;
            }

            // 왕복 오라클(`LayoutMarkupRoundTrip::verify`)과 **동일한 기준**으로 비교한다 —
            // `normalizeDocument` 를 빼면 문서층 별칭(`state`→`initLocal`)과 "옮기지 않고 버리는"
            // 사어 키(`schema`·`pageConfig`·`_comment_*`)가 차이로 잡혀, 정상 이관이 실패로 보고된다.
            $a = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($original))));
            $b = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($compiled))));

            if ($a !== $b) {
                $failures[] = "{$relative} — 컴파일 산출이 원본 .json 과 다름";
            }
        }

        $this->assertSame([], $failures, '이관 동등성·escape-0 위반:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 번들 확장·템플릿에서 모든 레이아웃·fragment `.json` 을 스캔합니다.
     *
     * 모집단은 {@see LayoutCorpus} 가 SSoT — 커맨드(`layout:compile --all`)와 같은 코퍼스를 본다.
     * 스캔 글롭을 이 테스트가 따로 들고 있으면 한쪽만 넓힐 때 게이트가 반쪽 코퍼스에 남는다.
     *
     * @return array<int, string> `.json` 절대 경로 목록
     */
    private function scanLayoutFiles(): array
    {
        return LayoutCorpus::scan('.json');
    }

    /**
     * 번들 확장·템플릿에서 모든 이관 저작 파일 `.g7.html` 을 스캔합니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function scanMarkupFiles(): array
    {
        return LayoutCorpus::scan('.g7.html');
    }
}
