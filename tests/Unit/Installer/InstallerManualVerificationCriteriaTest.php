<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 운영자 **수동 확인 기준**이 응답 코드로 적히는 것을 구조로 막는다 (D1 현장 제보).
 *
 * 배경:
 *   문서 루트를 바꿀 수 없는 호스팅(레이아웃 ②)에서는 루트 `.htaccess` 가 `public/` 로
 *   시작하지 않는 **모든** 주소를 앱으로 위임한다. 그래서 파일이 서빙되지 않은 주소도
 *   200 + 설치 안내 화면으로 돌아온다 — 그것이 그 배치의 **정상 응답**이다.
 *
 *   그런데 판정 불능 안내는 정상을 「오류 화면·404·403」으로만 적고 있었다. 운영자는
 *   `/composer.json` 이 200 설치 안내 팝업으로 뜨는 것을 보고 노출이라 읽었고, 실제로
 *   그 오독이 현장에서 제보로 올라왔다. 안내가 정상을 이상으로 읽게 만든 것이다.
 *
 *   그래서 기준 문장을 하나로 세우고(`web_exposure_manual_criteria`), 나머지 수동 확인
 *   문구가 **응답 코드를 판정 기준으로** 다시 쓰지 못하게 한다. 문구는 사람이 고치는
 *   자리라 규율만으로는 되돌아온다 — 형태로 잠근다.
 *
 * 잠그는 계약:
 *   1. 수동 확인 문구 집합에 판정 기준으로서의 `403`·`404` 표기가 없다
 *      (기준 문장 자신은 「그 숫자도 정상이다」를 말하려고 쓰므로 명시 허용).
 *   2. 기준 문장은 **내용 기준**을 말한다 (ko `내용` · en `contents`).
 *   3. 화면이 고르는 불능 사유 키 6종이 두 표에 전부 있고, `installer.js` 가 그 6종을
 *      **리터럴로** 참조한다 (조립하면 정적 대조가 통째로 사각이 된다).
 *   4. 공개 문서(`INSTALL.md`)의 「판정 불능」 절이 화면과 같은 기준을 적는다.
 *
 * 이 검사는 소스를 **텍스트로** 읽는다 — `config.php` 를 require 하면 그것만으로
 * `BASE_PATH` 가 실 저장소 루트로 박히기 때문이다 (InstallerRequiredExtensionDocParityTest 선례).
 *
 * 커버 축: locale ko·en / reference_channel js·doc / exposure_probe unknown
 */
class InstallerManualVerificationCriteriaTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 화면이 사유로 고르는 선두 문장 키 — `unknownDetailKey()` 가 돌려주는 전체 집합. */
    private const UNKNOWN_DETAIL_KEYS = [
        'web_exposure_unknown_detail_control',
        'web_exposure_unknown_detail_unmeasured',
        'web_exposure_unknown_detail_probe',
        'web_exposure_unknown_detail_shape',
        'web_exposure_unknown_detail_no_targets',
        'web_exposure_unknown_detail_mixed',
    ];

    /**
     * 응답 코드 표기가 **허용되는** 키.
     *
     * 기준 문장 하나뿐이다 — 그 문장의 목적이 「404·403 도 정상이고, 설치 안내 화면도
     * 정상이다」를 말하는 것이라 그 숫자를 쓸 수밖에 없다. 목록을 늘릴 때는 그 문구가
     * 왜 응답 코드를 판정 기준으로 말해도 되는지 여기에 사유를 함께 적는다.
     */
    private const STATUS_CODE_ALLOWED_KEYS = [
        'web_exposure_manual_criteria',
    ];

    /**
     * 이 테스트 파일에서 세 단계 위인 저장소 루트 경로를 돌려준다.
     *
     * @return string 저장소 루트 절대 경로
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 인스톨러 언어 파일 하나를 읽어 문자열 값만 남긴 번역 표를 만든다.
     *
     * @param  string  $code  읽을 로케일 코드 (ko, en)
     * @return array<string, string> 키별 번역 문자열 표
     */
    private function locale(string $code): array
    {
        $path = $this->repoRoot().'/public/install/lang/'.$code.'.php';
        $this->assertFileExists($path);

        $table = require $path;
        $this->assertIsArray($table);

        return array_filter($table, 'is_string');
    }

    /**
     * 운영자 수동 확인 문구 집합 — 「직접 열어 보고 무엇을 보면 정상/실패인가」를 말하는 키.
     *
     * @param  string  $code  문구를 고를 로케일 코드
     * @return array<string, string> 수동 확인 문구 키와 문장 표
     */
    private function manualVerificationMessages(string $code): array
    {
        $table = $this->locale($code);
        $selected = [];

        foreach ($table as $key => $value) {
            $isManual = str_starts_with($key, 'web_exposure_unknown_detail_')
                || str_starts_with($key, 'web_exposure_manual_')
                || str_starts_with($key, 'web_exposure_absent_control_')
                || $key === 'web_exposure_control_failed_hint';

            if ($isManual) {
                $selected[$key] = $value;
            }
        }

        // 모집단이 비면 `foreach` 가 0건을 돌려 초록이 된다 — 하한을 잠근다.
        $this->assertGreaterThanOrEqual(
            10,
            count($selected),
            $code.' 수동 확인 문구 모집단이 예상보다 작다 — 키 이름 규칙이 바뀌었으면 이 선택기도 함께 고친다'
        );

        return $selected;
    }

    /**
     * 판정 기준을 응답 코드로 적지 않는다 (D1 — 계약 1).
     *
     * @scenario exposure_probe=unknown, deployment_layout=docroot_project_root
     *
     * @effects exposure_manual_criteria_judges_by_content_not_status
     *
     * @return void 반환값 없음
     */
    public function test_manual_verification_messages_do_not_judge_by_status_code(): void
    {
        $offenders = [];

        foreach (['ko', 'en'] as $code) {
            foreach ($this->manualVerificationMessages($code) as $key => $message) {
                if (in_array($key, self::STATUS_CODE_ALLOWED_KEYS, true)) {
                    continue;
                }

                if (preg_match('/40[34]/', $message) === 1) {
                    $offenders[] = $code.'.'.$key;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '수동 확인 문구가 응답 코드를 판정 기준으로 말한다 — 문서 루트를 바꿀 수 없는 배치의 정상 응답(200 설치 안내 화면)이 그 목록에 없어, 운영자가 정상을 이상으로 읽는다'
        );
    }

    /**
     * 기준 문장은 **보이는 내용**을 기준으로 말한다 (D1 — 계약 2).
     *
     * @scenario exposure_probe=unknown
     *
     * @effects exposure_manual_criteria_judges_by_content_not_status
     *
     * @return void 반환값 없음
     */
    public function test_criteria_message_states_the_content_rule(): void
    {
        $expectations = [
            'ko' => ['내용', '설치 안내'],
            'en' => ['contents', 'installation notice'],
        ];

        foreach ($expectations as $code => $needles) {
            $message = $this->locale($code)['web_exposure_manual_criteria'] ?? '';

            $this->assertNotSame('', $message, $code.' 표에 판정 기준 문장이 없다');

            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $message,
                    $code.' 기준 문장이 「'.$needle.'」을 말하지 않는다 — 그 말이 빠지면 응답 코드 기준으로 되돌아간 것과 같다'
                );
            }
        }
    }

    /**
     * 화면이 고르는 사유 키 6종이 두 표에 있고 소스가 그것을 **리터럴로** 부른다 (D1 — 계약 3).
     *
     * @scenario exposure_probe=unknown, exposure_control=unreachable
     *
     * @effects exposure_unknown_detail_names_its_actual_cause
     *
     * @return void 반환값 없음
     */
    public function test_every_unknown_cause_key_is_defined_and_referenced(): void
    {
        $source = $this->sourceWithoutComments($this->repoRoot().'/public/install/assets/js/installer.js');

        foreach (['ko', 'en'] as $code) {
            $table = $this->locale($code);

            foreach (self::UNKNOWN_DETAIL_KEYS as $key) {
                $this->assertArrayHasKey($key, $table, $code.' 표에 '.$key.' 가 없다 — 그 사유에서 화면에 키 이름이 그대로 뜬다');
            }
        }

        foreach (self::UNKNOWN_DETAIL_KEYS as $key) {
            $this->assertStringContainsString(
                "'".$key."'",
                $source,
                $key.' 를 installer.js 가 리터럴로 부르지 않는다 — 접두사 조립으로 부르면 「표에는 있는데 아무도 부르지 않는 키」를 정적으로 대조할 수 없다'
            );
        }

        $this->assertStringContainsString(
            "'web_exposure_manual_criteria'",
            $source,
            '판정 기준 문장이 카드 조립에서 빠졌다 — 사유만 말하고 기준을 말하지 않으면 이번 오독이 그대로 남는다'
        );
        $this->assertStringContainsString(
            "'web_exposure_absent_control_label'",
            $source,
            '비교용 주소 블록이 카드 조립에서 빠졌다 — 운영자가 「정상이 어떻게 보이는가」를 대조할 길이 사라진다'
        );
    }

    /**
     * 공개 문서가 화면과 같은 기준을 적는다 (D1 — 계약 4).
     *
     * 문서는 릴리즈에 실려 나간다. 화면만 고치고 문서를 두면, 운영자가 「모두 접근할 수
     * 없음을 확인하라」는 문장을 먼저 읽고 그 기준으로 화면을 해석한다.
     *
     * @scenario exposure_probe=unknown, deployment_layout=docroot_project_root
     *
     * @effects exposure_manual_criteria_judges_by_content_not_status
     *
     * @return void 반환값 없음
     */
    public function test_install_doc_undetermined_section_matches_the_screen_criteria(): void
    {
        $path = $this->repoRoot().'/INSTALL.md';
        $this->assertFileExists($path);

        // `\R` 로 자르지 않는다 — 유니코드 모드가 아닌 `\R` 은 **바이트** `\x85`(NEL)도 줄바꿈으로
        // 읽어, 한글 UTF-8 시퀀스 한가운데에서 줄을 자른다(그 조각은 더 이상 올바른 문자열이
        // 아니라 대조가 조용히 어긋난다). 실제 줄바꿈 세 형태만 명시한다.
        // meta:allow raw-source-read — 소스가 아니라 공개 설치 문서(INSTALL.md)의 문장을 대조한다.
        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];
        $matched = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, '**판정 불능**')
        ));

        $this->assertCount(
            1,
            $matched,
            'INSTALL.md 의 「판정 불능」 줄을 하나로 특정하지 못했다 — 절이 바뀌었으면 이 대조도 함께 고친다'
        );

        $line = $matched[0];

        $this->assertStringContainsString(
            '내용',
            $line,
            '문서가 판정 기준을 내용으로 말하지 않는다'
        );
        $this->assertStringContainsString(
            '설치 안내',
            $line,
            '문서가 「설치 안내 화면도 정상」을 말하지 않는다 — 문서 루트를 바꿀 수 없는 배치에서는 그것이 정상 응답이다'
        );
        $this->assertStringNotContainsString(
            '접근할 수 없음',
            $line,
            '문서가 접근 차단을 판정 기준으로 요구한다 — 레이아웃 ②에서는 그 기준을 영영 만족할 수 없다'
        );
    }
}
