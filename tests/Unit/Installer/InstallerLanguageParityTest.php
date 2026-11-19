<?php

namespace Tests\Unit\Installer;

use App\Execution\ExecutionMode;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 다국어 정합 회귀 테스트.
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
 *  3-b. 줄바꿈 이스케이프가 값에 문자 그대로 남지 않는다 — 홑따옴표 문자열의 `\n` 은
 *       PHP 가 해석하지 않아 화면에 역슬래시와 n 이 그대로 나간다. 중괄호 금지와 같은
 *       종류(치환기·파서가 모르는 표기)인데 한쪽만 잠겨 있었다.
 *  4. 코드가 **리터럴로** 부르는 키가 정의돼 있다 (PHP·JS 양쪽. 동적 조립은 대상 밖).
 *  5. 점 경로 키는 중첩 표에서 해석된다 — 프론트가 그 경로를 따라 내려갈 수 있어야 한다.
 *  6. 지원 언어 상수(`SUPPORTED_LANGUAGES`)와 배포되는 `lang/*.php` 목록이 일치한다.
 *  7. PHP 채널은 점 경로 키를 부르지 않는다 — 서버 `lang()` 은 평면 조회다.
 *  8. 자리표시자 치환은 `lang(key, replace)` 한 지점을 지난다 (잔존분은 상한 고정).
 *
 * 커버 축: locale ko·en / key_shape flat·nested / placeholder_style colon·brace·escape / reference_channel php·js
 */
class InstallerLanguageParityTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /*
     * 인스톨러에는 문구 채널이 **둘** 있고 규칙이 다르다 — 이 테스트가 보는 것은 앞의 하나다.
     *
     *  - **진행 로그**(`lang()` 경유, SSE·폴링으로 화면에 뜬다): 운영자가 읽는 문구이므로
     *    번역 대상이고, 아래 검사들이 키 정의·자리표시자·양방향 parity 를 잠근다.
     *  - **진단 로그**(`addLog()` 로 상태 파일에 쌓이는 기록): 사후 분석용이라 원문 한국어
     *    리터럴을 그대로 쓴다. 이 채널을 번역 대상으로 끌어오면 키가 폭증하는 데 비해
     *    읽는 사람은 개발자뿐이다.
     *
     * 새 문구를 넣을 때 어느 채널인지 먼저 정한다 — 한 공정 안에서 두 규칙이 섞이면
     * 나중에 어느 쪽이 의도였는지 알 수 없게 된다.
     */

    /** 코드가 부르지만 값이 동적으로 조립되는 접두사 — 리터럴 대조 대상에서 제외한다. */
    private const DYNAMIC_PREFIXES = [
        'task_',
        'execution_mode_label_',
        'execution_mode_source_',
        'dependency_type_',
        'dir_',
    ];

    /**
     * 인스톨러 공개 디렉토리의 절대 경로를 돌려준다.
     *
     * @return string 인스톨러 루트 절대 경로
     */
    private static function installRoot(): string
    {
        return dirname(__DIR__, 3).'/public/install';
    }

    /**
     * 중첩 표를 점 경로로 평탄화한다.
     *
     * @param  array<string, mixed>  $table  평탄화할 다국어 표
     * @param  string  $prefix  상위 그룹의 점 경로 (최상위면 빈 문자열)
     * @return array<string, string> 점 경로 키 → 문구
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
     * 검사할 로케일 목록 — 인스톨러가 실제로 배포하는 lang 파일에서 도출한다.
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

    /**
     * 로케일의 다국어 파일을 읽어 점 경로로 평탄화한 표를 돌려준다.
     *
     * @param  string  $code  로케일 코드
     * @return array<string, string> 점 경로 키 → 문구
     */
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
     * @param  string  $message  자리표시자를 찾을 문구
     * @return array<int, string> 정렬된 자리표시자 이름 목록
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

    /**
     * ko 와 en 의 평탄화한 키 집합이 서로 남김없이 같은지 확인한다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_locales_have_identical_keys
     *
     * @return void 반환값 없음
     */
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

    /**
     * 두 로케일에 모두 있는 키마다 자리표시자 집합이 같은지 확인한다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_placeholders_match_across_locales
     *
     * @return void 반환값 없음
     */
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

    /**
     * 배포되는 모든 로케일의 문구에 치환기가 모르는 중괄호 자리표시자가 없는지 확인한다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_rejects_brace_placeholders
     *
     * @return void 반환값 없음
     */
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
     * 안내가 부르는 버튼 이름이 화면에 **실재하는 라벨**과 같다.
     *
     * ko 의 노출 조치 안내는 「[다시 검사]를 눌러 주세요」였는데 실제 버튼은 `recheck`
     * (`[다시 검증]`)다 — 운영자는 화면에 없는 버튼을 찾는다. 오탈자가 아니라 **다른 이름**
     * 이라 키 정합·자리표시자·parity 어느 검사에도 걸리지 않았고(문구 자체는 정상 번역이다),
     * en 은 처음부터 `Recheck` 로 일치해 두 로케일을 나란히 보지 않으면 드러나지 않았다.
     *
     * 대괄호는 이 사전에서 로그 접두(`[롤백]`)·구획 제목으로도 쓰이므로 전수 규칙으로
     * 넓히지 않는다 — **버튼을 지목하는 안내**만 그 버튼의 라벨과 대조한다.
     *
     * @effects installer_lang_guidance_names_existing_buttons
     *
     * @return void 반환값 없음
     */
    public function test_guidance_names_the_button_that_exists(): void
    {
        /** @var array<string, string> 안내 키 => 그 안내가 지목하는 버튼 라벨 키 */
        $guidance = [
            'web_exposure_exposed_remedy' => 'recheck',
            // 서버측 차단 문구의 en 이 `[Re-verify]` 를 불렀다(실재 라벨은 `Recheck`).
            'error_web_exposure_blocked' => 'recheck',
        ];

        $offenders = [];
        foreach ($this->localeCodes() as $code) {
            $table = $this->locale($code);
            foreach ($guidance as $messageKey => $labelKey) {
                if (! isset($table[$messageKey], $table[$labelKey])) {
                    $offenders[] = $code.':'.$messageKey.' (키 부재)';

                    continue;
                }
                if (! str_contains($table[$messageKey], '['.$table[$labelKey].']')) {
                    $offenders[] = $code.':'.$messageKey.' → ['.$table[$labelKey].']';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '안내가 실재하지 않는 버튼 이름을 부른다 — 운영자는 화면에 없는 버튼을 찾게 된다'
        );
    }

    /**
     * 원인을 열거하는 안내는 자기가 **몇 개를 열거하는지** 바르게 말한다.
     *
     * 양성 대조 실패 안내는 ③(리버스 프록시)을 덧붙이면서 머리말 「원인은 둘 중 하나」를 그대로
     * 두었다 — 운영자는 두 개만 읽고 세 번째를 지나친다. 열거 표식 수와 머리말의 수를 대조한다.
     *
     * @scenario exposure_control=unreachable
     *
     * @effects installer_control_failed_hint_counts_its_causes
     *
     * @return void 반환값 없음
     */
    public function test_control_failed_hint_states_the_number_of_causes_it_lists(): void
    {
        $expectedCount = [
            'ko' => ['/[①②③④⑤]/u', '셋 중 하나'],
            'en' => ['/\(\d\)/', 'three possible causes'],
        ];

        foreach ($expectedCount as $code => [$markerPattern, $heading]) {
            $message = $this->locale($code)['web_exposure_control_failed_hint'] ?? '';
            $listed = preg_match_all($markerPattern, $message);

            $this->assertSame(3, $listed, $code.' 안내가 열거하는 원인 수가 바뀌었다 — 머리말도 함께 고쳐야 한다');
            $this->assertStringContainsString($heading, $message, $code.' 안내의 머리말이 열거한 원인 수와 다르다');
        }
    }

    /**
     * 사유별 선두 문장으로 갈라진 뒤, **공용 문장이 어느 표에도 남지 않았다** (D1).
     *
     * 이름이 접두사로 겹치므로(`web_exposure_unknown_detail` ⊂ `…_detail_probe`) 한쪽 표에
     * 옛 키가 남으면 parity 검사는 「양쪽에 다 있다」로 초록이 된다 — 남은 키는 아무도 부르지
     * 않는 문장이라 다음 편집자가 그것을 고쳐도 화면은 바뀌지 않는다. 정확 일치로 잠근다.
     *
     * @scenario exposure_probe=unknown
     *
     * @effects exposure_unknown_detail_names_its_actual_cause
     *
     * @return void 반환값 없음
     */
    public function test_shared_unknown_detail_key_is_gone_from_both_tables(): void
    {
        $causeKeys = [
            'web_exposure_unknown_detail_control',
            'web_exposure_unknown_detail_unmeasured',
            'web_exposure_unknown_detail_probe',
            'web_exposure_unknown_detail_shape',
            'web_exposure_unknown_detail_no_targets',
            'web_exposure_unknown_detail_mixed',
        ];

        foreach ($this->localeCodes() as $code) {
            $table = $this->locale($code);

            $this->assertArrayNotHasKey(
                'web_exposure_unknown_detail',
                $table,
                $code.' 표에 사유를 단정하던 옛 공용 문장이 남아 있다 — 부르는 곳이 없어 고쳐도 화면이 바뀌지 않는다'
            );

            foreach ($causeKeys as $key) {
                $this->assertArrayHasKey($key, $table, $code.' 표에 '.$key.' 가 없다 — 그 사유에서 화면에 키 이름이 뜬다');
            }
        }
    }

    /**
     * 진행 기록 상한·회전·장시간 안내 문구가 두 표에 **자리표시자까지 같게** 있다 (D2).
     *
     * 이 셋은 화면이 값을 갈아 끼워 쓰는 문장이다(`:count`·`:name`·`:elapsed`). 한쪽 표에서
     * 자리표시자가 빠지면 그 로케일에서는 숫자 없는 문장이 나가는데, 그 숫자가 **그 문장의
     * 유일한 정보**다 — 「오래된 기록을 접었습니다」만으로는 몇 줄이 어디로 갔는지 알 수 없다.
     * (형제 검사 `test_placeholders_match_across_locales` 가 전 키를 이미 대조하지만, 이
     * 셋은 값이 빠져도 문장이 자연스러워 눈으로는 드러나지 않으므로 명시적으로 못 박는다.)
     *
     * @scenario progress_log_volume=large, task_duration=long_no_feedback
     *
     * @effects progress_log_has_a_screen_cap_and_says_so
     *
     * @return void 반환값 없음
     */
    public function test_progress_log_notices_exist_in_both_locales(): void
    {
        $expected = [
            'log_truncated_notice' => ['count'],
            'log_rotated_notice' => [],
            'stepwise_long_task_notice' => ['elapsed', 'name'],
            'stepwise_task_elapsed' => ['elapsed', 'name'],
            'stepwise_task_unnamed' => [],
        ];

        foreach ($this->localeCodes() as $code) {
            $table = $this->locale($code);

            foreach ($expected as $key => $placeholders) {
                $this->assertArrayHasKey($key, $table, $code.' 표에 '.$key.' 가 없다 — 그 자리에 키 문자열이 그대로 뜬다');
                $this->assertSame(
                    $placeholders,
                    self::placeholders($table[$key]),
                    $code.'.'.$key.' 의 자리표시자가 다르다 — 값이 빠진 문장이 나간다'
                );
            }
        }

        // 회전 안내는 **어디로 옮겼는지**가 정보다. 그 이름이 빠지면 기록이 사라진 것과 같다.
        foreach ($this->localeCodes() as $code) {
            $this->assertStringContainsString(
                'installation.log.1',
                $this->locale($code)['log_rotated_notice'],
                $code.' 회전 안내가 옮긴 파일 이름을 말하지 않는다'
            );
        }
    }

    /**
     * 이스케이프 시퀀스가 값에 문자 그대로 남아 있지 않은가.
     *
     * PHP 홑따옴표 문자열은 `\n` 을 해석하지 않는다 — 그 값을 alert/confirm 에 넘기면
     * 줄바꿈 대신 역슬래시와 n 이 화면에 뜬다. 같은 파일 안에서 형제 키는 쌍따옴표라
     * 정상이었고, 표기가 갈렸다는 사실을 어떤 게이트도 보지 않았다. 중괄호 금지 축과
     * 같은 종류의 결함이라 같은 자리에서 잠근다.
     *
     * @effects installer_lang_rejects_literal_escape_sequences
     *
     * @return void 반환값 없음
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
     * 동적으로 조립되는 서버별 안내 키가 **전부 정의돼 있다**.
     *
     * `installer.js` 는 `lang('web_exposure_server_guide_' + kind)` 로 키를 만든다.
     * 리터럴이 아니므로 형제 게이트(`test_literal_key_references_are_defined`)가 보지
     * 못하고, vitest 쪽은 `lang` 을 스텁으로 주입하므로 실제 키 존재를 재지 않는다 —
     * 두 검사 사이의 틈이다. 분기 목록을 **소스에서 도출해** 그 틈을 닫는다.
     *
     * @scenario server_kind=apache
     *
     * @effects server_guide_keys_exist_for_every_branch
     *
     * @return void 반환값 없음
     */
    public function test_dynamic_server_guide_keys_are_defined_for_every_branch(): void
    {
        $source = $this->sourceWithoutComments(
            dirname(__DIR__, 3).'/public/install/assets/js/installer.js'
        );

        $this->assertSame(
            1,
            preg_match("/const kinds = \[([^\]]*)\];/", $source, $m),
            '서버 종류 분기 목록을 소스에서 도출하지 못했다 — 이 게이트가 통째로 무력해진다'
        );

        preg_match_all("/'([a-z]+)'/", $m[1], $found);
        $kinds = $found[1];
        $kinds[] = 'other'; // 목록에 없는 값의 폴백 — 소스가 그렇게 접는다

        $this->assertGreaterThanOrEqual(5, count($kinds), '분기 도출이 비었다');

        foreach (['ko', 'en'] as $locale) {
            $defined = $this->locale($locale);

            $this->assertArrayHasKey(
                'web_exposure_server_guide_label',
                $defined,
                $locale.': 서버별 안내 제목 키가 없다'
            );

            foreach ($kinds as $kind) {
                $this->assertArrayHasKey(
                    'web_exposure_server_guide_'.$kind,
                    $defined,
                    $locale.': 서버 종류 `'.$kind.'` 의 안내 키가 없다 — 그 서버 운영자에게 키 문자열이 그대로 나간다'
                );
            }
        }
    }

    /**
     * 코드가 리터럴로 부르는 키가 정의돼 있는가.
     *
     * PHP 는 `lang('key')`, JS 는 `lang('key')` · `getLangMessage('key')` 를 본다. 동적 조립
     * (`lang('task_'.$id)`) 은 리터럴이 아니므로 정규식이 애초에 잡지 않고, 접두사만 남는
     * 형태는 DYNAMIC_PREFIXES 로 걸러 오탐을 막는다.
     *
     * @scenario locale=ko
     *
     * @effects installer_lang_literal_references_are_defined
     *
     * @return void 반환값 없음
     */
    public function test_literal_key_references_are_defined(): void
    {
        $defined = $this->locale('ko');
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            // 주석을 지운 뒤에 찾는다 — 규정·예시 주석 안의 `lang('key')` 는 호출이 아니다.
            // 남겨 두면 문서에 적힌 예시가 「정의되지 않은 키」로 잡혀 이 게이트가 오탐으로 죽는다.
            $source = $this->sourceWithoutComments($file);
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
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_placeholders_match_across_locales
     *
     * @return void 반환값 없음
     */
    public function test_substitution_arguments_have_a_matching_placeholder(): void
    {
        $defined = $this->locale('ko');
        $surplus = [];

        foreach ($this->sourceFiles() as $file) {
            $source = $this->sourceWithoutComments($file);

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
     *
     * @scenario locale=ko
     *
     * @effects installer_lang_literal_references_are_defined
     *
     * @return void 반환값 없음
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
     * 런타임 지원 언어 목록과 실제 배포되는 lang 파일이 일치하는가.
     *
     * 지원 언어는 두 곳에 선언돼 있는데 대조 장치가 없었다 — 런타임은
     * `public/install/includes/config.php` 의 `SUPPORTED_LANGUAGES` 상수를, 이 게이트는 `lang/*.php`
     * 글롭을 본다. 상수에만 로케일을 더하면 `loadTranslations()` 이 빈 배열을 돌려주고
     * 화면 전체가 키 문자열이 된다. 반대로 파일만 두면 아무도 그 로케일을 고를 수 없다.
     * 둘 다 예외도 로그도 남기지 않는다.
     *
     * `config.php` 를 **require 하지 않는다** — 그 파일은 `BASE_PATH` 를 실 저장소 루트로
     * 박으므로, 같은 프로세스에서 도는 다른 인스톨러 테스트가 temp 격리 안전가드에 걸려
     * 통째로 skip 된다(종료 코드는 0 이라 초록으로 보인다). 상수 선언만 소스에서 읽는다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_locales_have_identical_keys
     *
     * @return void 반환값 없음
     */
    public function test_supported_languages_constant_matches_shipped_lang_files(): void
    {
        // 주석을 지운 뒤 대조한다 — 주석 처리된 상수 선언도 원문에는 글자로 남는다.
        $source = $this->sourceWithoutComments(self::installRoot().'/includes/config.php');

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
     * PHP 채널에서 점 경로 키를 부르지 않는가.
     *
     * 두 층의 조회 규칙이 다르다 — JS(`installer.js`)는 점 경로를 따라 내려가지만
     * 서버 `lang()` 은 `$translations[$key] ?? $key` 평면 조회다. 그래서 PHP 에서
     * `lang('fields.db_prefix')` 를 부르면 **키 문자열이 그대로 화면에 나간다**.
     *
     * 이 게이트는 평탄화한 키 집합으로 판정하므로 그 호출을 「정의된 키」로 보고
     * 초록을 준다 — 검사는 통과하는데 화면에는 키가 뜨는 조합이다. 그래서 PHP 채널만
     * 따로 점 없는 키로 좁힌다. (두 층의 조회 규칙 자체를 통일하는 안은 별도 판단 사항이다.)
     *
     * @scenario locale=ko
     *
     * @effects installer_lang_literal_references_are_defined
     *
     * @return void 반환값 없음
     */
    public function test_php_lang_calls_do_not_use_dotted_keys(): void
    {
        $dotted = [];

        foreach ($this->sourceFiles() as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $source = $this->sourceWithoutComments($file);
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
     * 동적으로 조립되는 키의 어휘가 lang 에 도달하는가 (재귀).
     *
     * `test_literal_key_references_are_defined` 는 **리터럴** 호출만 본다. 그래서
     * `lang(getTaskName($id))` · `lang('execution_mode_label_'.$mode)` 처럼 접두사에
     * 어휘를 붙여 조립하는 자리는 `DYNAMIC_PREFIXES` 로 통째로 면제된다 — 그 어휘는
     * 다른 곳(키 표·Enum·판정기)에 선언돼 있는데 lang 과 대조하는 장치가 없었다.
     * 표에 항목을 하나 더 적고 문구를 빠뜨리면 그 화면에만 키 문자열이 나가고,
     * 예외도 경고도 남지 않는다.
     *
     * @scenario locale=ko
     *
     * @effects installer_lang_literal_references_are_defined
     *
     * @return void 반환값 없음
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
        $tables = $this->sourceWithoutComments(self::installRoot().'/includes/functions.php');
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
        $resolver = $this->sourceWithoutComments(self::installRoot().'/includes/execution-mode.php');
        preg_match_all('/\'source\'\s*=>\s*\'([a-z_]+)\'/', $resolver, $sourceMatches);
        $sources = array_values(array_unique($sourceMatches[1]));

        $this->assertNotEmpty($sources, 'execution-mode.php 에서 판정 근거 어휘를 읽지 못했다 — 표기가 바뀌었다');
        foreach ($sources as $source) {
            $key = 'execution_mode_source_'.$source;
            if (! isset($defined[$key])) {
                $missing[] = 'execution-mode.php → '.$key;
            }
        }

        // (4) 「재지 못한 사유」 어휘 — `exposure-check.php` 가 실제로 내보내는 reason 값.
        //     화면(`installer.js`)이 `'web_exposure_unmeasured_reason_' + u.reason` 으로
        //     조립하므로 (1)~(3) 과 같은 계열이다. 이 축이 없던 동안 `no_marker` 하나가
        //     정의 없이 남아, 그 계열은 「점검하지 못한 항목」에 **사유 없이 라벨만** 떴다 —
        //     D21 이 요구한 「무엇을 왜 재지 못했는지」의 절반이 빠진 상태였다.
        $exposure = $this->sourceWithoutComments(self::installRoot().'/includes/exposure-check.php');
        // `unmeasured[]` 에 실리는 사유만 본다. 같은 파일의 정리 스윕도 `reason` 키를 쓰지만
        // 그쪽은 진단 로그 채널이라 이 키 계열이 아니다 — 모집단을 넓히면 거짓 빨강이 된다.
        preg_match_all(
            // `$unmeasured[] = [...]` 뿐 아니라 **반환문 안에 인라인으로 실린** 형태도 본다
            // (7차: 대조 계열의 준비 실패가 그 형태다). 접두를 선택적으로 두지 않으면 그
            // 어휘가 모집단 밖에 남아 문구 없이 라벨만 뜨는 상태가 다시 생긴다.
            '/(?:\$unmeasured\[\]\s*=\s*)?\[\s*\'label\'\s*=>\s*\'[^\']*\'\s*,\s*\'reason\'\s*=>\s*\'([a-z_]+)\'/',
            $exposure,
            $reasonMatches
        );
        $reasons = array_values(array_unique($reasonMatches[1]));

        $this->assertNotEmpty($reasons, 'exposure-check.php 에서 사유 어휘를 읽지 못했다 — 표기가 바뀌었다');

        // 반대 방향도 잠근다. 이 축은 「서버가 어휘를 늘렸는데 문구가 없다」만 보고 있었다 —
        // 화면이 조립하는 **접두사 리터럴**이 바뀌면 사유가 조용히 사라지는데(`hasReason`
        // 판정이 그것을 삼켜 라벨만 뜬다) 그 회귀는 어느 게이트에도 걸리지 않았다.
        //
        // 접두사는 화면에서 두 번 쓰인다 — 키를 **조립**할 때와, 번역이 없어 키가 그대로
        // 돌아왔는지 **판정**할 때. 개수를 박아 두면 정당한 구조 변경에서 거짓 빨강이 되므로
        // 존재와 어휘 일관성을 잰다.
        $installerJs = $this->sourceWithoutComments(self::installRoot().'/assets/js/installer.js');

        $this->assertGreaterThan(
            0,
            substr_count($installerJs, "'web_exposure_unmeasured_reason_'"),
            '화면의 사유 키 접두사가 사라지면 「무엇을 왜 재지 못했는지」가 조용히 빠진다 (라벨만 뜬다)'
        );

        $definedWithPrefix = array_filter(
            array_keys($defined),
            static fn (string $key): bool => str_starts_with($key, 'web_exposure_unmeasured_reason_')
        );

        $this->assertNotEmpty(
            $definedWithPrefix,
            '화면이 조립하는 접두사로 시작하는 문구가 하나도 없다 — 두 쪽의 어휘가 갈렸다'
        );
        foreach ($reasons as $reason) {
            $key = 'web_exposure_unmeasured_reason_'.$reason;
            if (! isset($defined[$key])) {
                $missing[] = 'exposure-check.php → '.$key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '동적으로 조립되는 키의 어휘가 lang 에 없다 — 그 화면에만 번역 대신 키 문자열이 나간다'
        );
    }

    /**
     * 자리표시자 치환이 `lang(key, replace)` 한 지점을 지나는가.
     *
     * 앞서 치환을 `lang(key, replace)` 로 단일화했는데 `lang(key).replace(':x', …)`
     * 형태가 남아 두 계통이 공존한다. `String.prototype.replace` 는 첫 문자열 인자에
     * 대해 **첫 occurrence 만** 바꾸는 반면 `lang()` 내부는 전부 치환한다 — 같은
     * 자리표시자가 두 번 나오는 문구가 생기는 순간 한쪽만 채워진 문장이 나간다.
     *
     * 잔존 지점은 이 공정의 소유 밖이므로 **상한(ceiling)** 으로 고정한다: 새로 늘지
     * 않도록 막고, 고쳐서 줄어드는 것은 통과시킨다. 0 이 되면 이 표를 지운다.
     *
     * @scenario locale=ko, locale=en
     *
     * @effects installer_lang_placeholders_match_across_locales
     *
     * @return void 반환값 없음
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
            $source = $this->sourceWithoutComments($file);
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

    /**
     * 대조 대상 소스 — 인스톨러 PHP 전체 + 인스톨러 JS.
     *
     * 다국어 디렉토리 아래 파일은 정의 쪽이므로 대조 대상에서 뺀다.
     *
     * @return array<int, string> 파일 절대 경로 목록 (정렬됨)
     */
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
     * 인라인 `<script>` 안에 심는 문구는 이스케이프 헬퍼를 지나는가.
     *
     * 앞서 `installerJsString()` 을 만들었지만 호출 31곳이 전부 한 파일
     * (`public/install/views/3-configuration.php`)이고, 그 함수에 테스트 참조가 **0** 이었다 — 본문을
     * `return "'".$value."'";` 로 바꿔도 전 스위트가 초록이다. 나머지 인라인 스크립트 두
     * 곳은 서로 다른 규칙(지역 상수·맨 json_encode)을 쓰고 있었고 그 사실도 무검사였다.
     *
     * @effects installer_inline_script_strings_use_shared_escaping
     *
     * @return void 반환값 없음
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
     * 인라인 스크립트가 문구를 심는 채널이 갈라지지 않았는가.
     *
     * 규칙이 파일마다 다르면 한 파일만 규칙에서 뒤처져도 아무도 모른다.
     *
     * @effects installer_inline_script_strings_use_shared_escaping
     *
     * @return void 반환값 없음
     */
    public function test_inline_scripts_use_the_shared_escaping_helper(): void
    {
        $offenders = [];

        foreach ($this->scanFiles(self::installRoot().'/views', ['php']) as $path) {
            $source = $this->sourceWithoutComments((string) $path);

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
