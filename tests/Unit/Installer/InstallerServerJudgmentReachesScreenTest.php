<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 서버가 갈라 낸 판정을 화면이 접지 않는다 (19차 S 축).
 *
 * 14차(§16.20)가 S2 면에서 세운 유형을 나머지 면에 적용한 결과, 이 면들의 결함은 「목록·어휘가
 * 어긋난 것」이 아니라 **「서버가 낸 판정의 일부를 화면이 버린 것」**에 몰려 있었다.
 *
 *  - **S-2**: `check-env.php` 는 3상태(없음 / 있으나 쓰기 불가 / 있고 쓰기 가능)를 내는데
 *    사전 점검이 `env_exists` 만 읽어 가운데 상태를 통과시켰다. `.env` 쓰기는 설치 **중**이
 *    아니라 끝난 뒤 finalize 에서 한 번 일어나고 실패해도 조용히 return 하므로, 14단계가
 *    전부 초록으로 끝나고 「설치 완료」가 뜨는데 `.env` 는 설치 전 그대로다. `_guard.php` 는
 *    `.env` 의 `INSTALLER_COMPLETED` 로만 판정하므로 인스톨러가 영구히 열린 채 남는다.
 *  - **S-3**: finalize 는 405/409/403 으로 거부될 수 있는데 `fetch` 는 4xx 에 reject 하지
 *    않으므로 `.catch` 가 발화하지 않는다. 같은 파일의 중단 경로는 14차 #2 에서 이미
 *    `res.ok` 판정을 받았다 — 한 파일 안에서 규칙이 갈려 있던 자리다.
 *
 * 함수 본문은 **중괄호로 잘라** 본다(고정 길이 창이 아니다) — 고정 창은 그 앞에 코드를 넣는
 * 것만으로 조용히 사각이 된다.
 *
 * 커버 축: judgment env_writable·finalize_accepted / consumed yes·no
 *
 * @effects installer_precheck_reads_env_writable, installer_finalize_checks_acceptance
 */
class InstallerServerJudgmentReachesScreenTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private function clientSource(): string
    {
        return $this->stripJsComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/installer.js')
        );
    }

    /**
     * S-2 — 사전 점검이 서버의 쓰기 권한 판정을 읽는다.
     */
    public function test_pre_install_check_consumes_env_writable(): void
    {
        $body = $this->functionBody($this->clientSource(), 'checkFilesBeforeInstall');

        $this->assertNotSame('', $body, 'checkFilesBeforeInstall() 을 찾지 못했다');

        $this->assertStringContainsString(
            'env_writable',
            $body,
            '서버가 「있으나 쓰기 불가」를 갈라 냈는데 사전 점검이 읽지 않으면, 설치가 완주하고도 .env 가 그대로 남는다',
        );

        $this->assertStringContainsString(
            'return false',
            $body,
            '판정을 읽기만 하고 진행을 막지 않으면 아무것도 달라지지 않는다',
        );
    }

    /**
     * S-2 — 「없다」와 「쓸 수 없다」의 안내가 갈린다.
     *
     * 조치가 다르다(생성 vs 권한). 한 문구로 뭉개면 운영자가 `cp` 를 다시 실행하고 같은
     * 자리에 머문다.
     */
    public function test_pre_install_check_distinguishes_missing_from_unwritable(): void
    {
        $body = $this->functionBody($this->clientSource(), 'checkFilesBeforeInstall');

        $this->assertStringContainsString('file_setup_description', $body, '「없다」 안내가 없다');
        // 「쓸 수 없다」 안내는 공용 헬퍼로 추출됐다 (23차 F-2 — 사전 점검·재검사·서버 거부
        // 세 소비처가 공유). 사전 점검이 그 헬퍼를 경유하고, 헬퍼가 그 문구를 소유하는지 본다.
        $this->assertStringContainsString('renderEnvNotWritableSetup(', $body, '「쓸 수 없다」 안내 경로가 없다');
        $helperBody = $this->functionBody($this->clientSource(), 'renderEnvNotWritableSetup');
        $this->assertStringContainsString('file_setup_not_writable', $helperBody, '「쓸 수 없다」 안내가 없다');

        foreach (['ko', 'en'] as $locale) {
            $keys = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('file_setup_not_writable', $keys, $locale.' 문구가 없다');
        }
    }

    /**
     * S-3 — 마무리 요청의 수락 여부를 본다.
     */
    public function test_finalize_checks_acceptance(): void
    {
        $body = $this->functionBody($this->clientSource(), 'finalizeEnvironment');

        if ($body === '') {
            // 이름이 바뀌었을 수 있다 — 호출 지점을 소유한 함수에서 찾는다.
            $body = $this->functionBody($this->clientSource(), 'showCompletionSection');
        }

        $this->assertNotSame('', $body, 'finalize 요청을 담은 함수를 찾지 못했다');

        $this->assertStringContainsString(
            'finalize-env.php',
            $body,
            'finalize 요청이 이 함수에 없다 — 옮겼다면 이 검사도 함께 옮긴다',
        );

        $this->assertStringContainsString(
            'res.ok',
            $body,
            'fetch 는 4xx 에 reject 하지 않는다 — 수락 여부를 보지 않으면 거부된 마무리가 초록 「완료」로 끝난다',
        );

        foreach (['ko', 'en'] as $locale) {
            $keys = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('finalize_rejected_notice', $keys, $locale.' 문구가 없다');
        }
    }

    /**
     * S-4·S-11 — 확장 스캔 실패의 세 사유가 화면까지 도달한다.
     *
     * 서버는 사유를 갈라 냈는데(디렉토리 열거 불가 · 관리자 템플릿 없음 · 스캔 실패) 화면은
     * 본문을 읽기 **전에** `response.ok` 로 던져 「HTTP 500」 다섯 글자만 남겼다. 게다가
     * 서버가 싣던 `error_message` 는 lang 키가 아닌 **영어 원문**이라, 그 경로가 살아나면
     * 한국어 화면에 개발자 문구가 그대로 떴다.
     */
    public function test_extension_scan_failure_reasons_reach_the_screen(): void
    {
        $server = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/scan-extensions.php');

        $keys = [];
        if (preg_match_all("/'message_key'\s*=>\s*'([a-z0-9_]+)'/", $server, $m)) {
            $keys = array_values(array_unique($m[1]));
        }

        $this->assertGreaterThanOrEqual(
            3,
            count($keys),
            '서버가 사유를 다국어 키로 싣지 않으면 화면은 상태 코드만 그릴 수 있다',
        );

        $labels = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/views/4-extension-selection.php');
        foreach (['ko', 'en'] as $locale) {
            $messages = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $messages, $locale.'.php 에 '.$key.' 가 없다');
                $this->assertStringContainsString(
                    $key,
                    $labels,
                    $key.' 가 화면 라벨 표에 없다 — 서버가 실어 보내도 화면이 문구를 찾지 못한다',
                );
            }
        }

        $client = $this->clientSource();
        $body = $this->functionBody($client, 'loadExtensions');

        if ($body !== '') {
            $this->assertStringContainsString(
                'message_key',
                $body,
                '화면이 사유 키를 읽지 않으면 세 사유가 한 줄로 뭉개진다',
            );
        }
    }

    /**
     * S-6 — 화면의 「고를 수 있는가」와 서버의 저장 게이트가 같은 판정을 쓴다.
     *
     * 저장 게이트는 **필수 함수가 막혔을 때만** subprocess 를 거부하고 PHP probe 실패는
     * 일부러 막지 않는다(A-0 ③). 화면이 `mode !== inprocess` 로 따로 판정하면, 서버가
     * 받아들이는 선택을 화면이 잠근다 — A-4 가 없앤 비대칭의 역방향이다.
     */
    public function test_execution_mode_selectability_shares_the_save_gate(): void
    {
        foreach ([
            'public/install/api/check-configuration.php',
            'public/install/views/3-configuration.php',
        ] as $relative) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.$relative);

            $this->assertStringContainsString(
                'installerExecutionModeRejection(',
                $source,
                $relative.' 이 저장 게이트와 다른 규칙으로 선택 가능 여부를 정한다',
            );
        }
    }
}
