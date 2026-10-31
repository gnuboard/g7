<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * `INSTALLER_BASE_URL` 파생과 완료 리다이렉트의 고정 경로 제거(G3 입력 ②) 계약 테스트.
 *
 * 서버측 완료 리다이렉트(task-runner)와 모니터 폴백 2곳이 고정 `/install/` 을 쓰면
 * 서브경로 배치(문서루트=프로젝트 루트의 `/public/install` 등)에서 존재하지 않는 경로로
 * 떨어진다. 상수 파생은 API 진입 스크립트(`install/api/*.php`)에서도 옳아야 한다 —
 * dirname 이 `.../install/api` 가 되는 컨텍스트가 서버측 리다이렉트의 실제 실행 지점이다.
 *
 * 효과 요약(마커 아님 — 평문): 인스톨러의 리다이렉트와 주소는 기준 URL 파생에서 나온다. 조합과 효과 표식은 각 테스트 메서드가 단다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerBaseUrlDerivationTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    /**
     * 임시 BASE_PATH 를 세우고 API 진입 스크립트 컨텍스트(SCRIPT_NAME)를 재현한 뒤 인스톨러 설정을 불러와 기준 URL 상수를 그 컨텍스트로 고정한다.
     *
     * 스킴 판정 헬퍼가 있는 실행 모드 파일도 함께 불러온다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-baseurl-'.bin2hex(random_bytes(4)));
        }

        // API 진입 스크립트 컨텍스트를 재현한 뒤 config.php 를 로드한다 — 상수는 최초
        // require 시점에 고정되므로 이 클래스는 API 컨텍스트 전용이다.
        $_SERVER['SCRIPT_NAME'] = '/public/install/api/run-task.php';

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';

        // 스킴 판정 SSoT (`installerRequestIsHttps`) 가 사는 자리. 접두사 축과 스킴 축은
        // `index.php` 의 같은 한 줄이 만드는 값이라 두 계약을 같은 클래스가 잰다.
        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    /**
     * API 진입 스크립트 컨텍스트에서 고정된 기준 URL 상수가 api 꼬리를 벗긴 인스톨러 루트를 가리키는지 확인한다.
     *
     * @scenario entry_context=api_script, consumer=server_sse, redirect_context=subpath
     *
     * @effects installer_redirects_derive_from_base_url
     *
     * @return void 반환값 없음
     */
    public function test_base_url_from_api_entry_script_points_to_install_root(): void
    {
        $this->assertSame(
            '/public/install',
            INSTALLER_BASE_URL,
            'API 진입 컨텍스트의 dirname(/…/install/api)에서 /api 꼬리를 벗겨야 한다 — 아니면 서버측 리다이렉트가 /…/install/api/install 로 깨진다'
        );
    }

    /**
     * 상수는 최초 require 시점에 고정되므로 여러 입력을 재는 축은 파생 함수를 직접 부른다.
     *
     * @return array<string, array{0: string, 1: string}> 경우 이름 → [SCRIPT_NAME, 기대 기준 URL]
     */
    public static function scriptNameProvider(): array
    {
        return [
            '표준 배치' => ['/install/index.php', '/install'],
            '표준 배치 API' => ['/install/api/run-task.php', '/install'],
            '레이아웃 ② (rewrite 후)' => ['/public/install/index.php', '/public/install'],
            '레이아웃 ② API' => ['/public/install/api/check-configuration.php', '/public/install'],
            '서브디렉토리' => ['/g7/public/install/api/run-task.php', '/g7/public/install'],
            'Windows 구분자' => ['\\install\\api\\run-task.php', '/install'],
            // 대소문자 무시 파일시스템(Windows·IIS·macOS)에서 운영자가 다른 표기로 접속하면
            // SCRIPT_NAME 이 그대로 들어온다. 꼬리 판정이 대소문자를 구분하면 `/Install` 이
            // 「install 이 없다」로 읽혀 `/Install/install` 로 이중 조립되고, 그 값을 쓰는
            // 서버측 리다이렉트와 노출 프로브 기준점이 함께 깨진다.
            '대소문자 다른 install' => ['/Install/index.php', '/Install'],
            '대소문자 다른 install + API' => ['/Public/Install/API/run-task.php', '/Public/Install'],
        ];
    }

    /**
     * 표준 배치, 레이아웃 ②, 서브디렉토리, Windows 구분자, 대소문자가 다른 표기의 SCRIPT_NAME 마다 기준 URL 파생이 기대값과 같은지 확인한다.
     *
     * @param  string  $scriptName  서버가 넘기는 SCRIPT_NAME
     * @param  string  $expected  기대 기준 URL
     *
     * @scenario entry_context=api_script, redirect_context=install_root, redirect_context=subpath, base_path=root, base_path=subpath
     *
     * @effects installer_redirects_derive_from_base_url
     *
     * @return void 반환값 없음
     */
    #[DataProvider('scriptNameProvider')]
    public function test_base_url_derivation_covers_entry_contexts(string $scriptName, string $expected): void
    {
        $this->assertSame(
            $expected,
            installerDeriveBaseUrl($scriptName),
            $scriptName.' 에서 인스톨러 기준 URL 파생이 어긋난다'
        );
    }

    /**
     * `REQUEST_URI` 우선 분기의 입력과 기대값.
     *
     * 이 분기는 브라우저가 **실제로 친 주소**에서 기준점을 잡는다. `SCRIPT_NAME` 은 서버가
     * rewrite 를 마친 뒤의 파일 경로라 레이아웃 ②에서 `/public/install` 로 오는데, 브라우저가
     * 친 주소는 `/install/...` 이다 — 노출 프로브는 **브라우저 기준**이어야 하므로 이 분기가
     * 우선한다.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> [scriptName, requestUri, 기대]
     */
    public static function requestUriProvider(): array
    {
        return [
            // 레이아웃 ②: 서버는 `/public/install/...` 을 실행하지만 브라우저가 친 것은
            // `/install/...` 이다. 프로브 기준점은 브라우저 쪽이어야 한다.
            '레이아웃 ② — 브라우저 주소가 우선' => [
                '/public/install/index.php', '/install/', '/install',
            ],
            '레이아웃 ② API — 브라우저 주소가 우선' => [
                '/public/install/api/check-configuration.php',
                '/install/api/check-configuration.php',
                '/install',
            ],
            '하위 폴더 마운트' => [
                '/g7/public/install/index.php', '/g7/install/', '/g7/install',
            ],
            '쿼리스트링은 경로에서 제외된다' => [
                '/public/install/api/run-task.php',
                '/install/api/run-task.php?action=requirements',
                '/install',
            ],
            '대소문자 다른 install' => [
                '/public/install/index.php', '/Install/', '/Install',
            ],
            '꼬리 슬래시 없는 형태' => [
                '/public/install/index.php', '/install', '/install',
            ],
        ];
    }

    /**
     * `REQUEST_URI` 분기가 실제로 평가되는지 잰다.
     *
     * 종전 데이터 제공자는 인자를 하나만 넘겨(`installerDeriveBaseUrl($scriptName)`) 이 분기가
     * **한 번도 실행되지 않았다.** 레이아웃 ② 노출 프로브의 기준점을 정하는 것이 바로 이
     * 분기인데, 그 근거가 무게이트였다 (14차 G3 라운드).
     *
     * @param  string  $scriptName  서버가 넘기는 SCRIPT_NAME
     * @param  string  $requestUri  브라우저가 친 REQUEST_URI
     * @param  string  $expected  기대 기준 URL
     *
     * @scenario entry_context=api_script, deployment_layout=docroot_project_root, base_path=root, base_path=subpath
     *
     * @effects installer_base_url_follows_the_browser_address
     *
     * @return void 반환값 없음
     */
    #[DataProvider('requestUriProvider')]
    public function test_request_uri_branch_takes_precedence_over_script_name(
        string $scriptName,
        string $requestUri,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            installerDeriveBaseUrl($scriptName, $requestUri),
            $requestUri.' 에서 브라우저 기준 URL 파생이 어긋난다'
        );
    }

    /**
     * `install` 세그먼트가 없는 `REQUEST_URI` 는 이 분기를 타지 않고 `SCRIPT_NAME` 으로 떨어진다.
     *
     * 대조군이 없으면 「언제나 REQUEST_URI 를 쓴다」는 구현으로도 위 케이스가 초록이 되고,
     * 프록시가 경로를 갈아 끼운 배치에서 기준점이 통째로 어긋난다.
     *
     * @scenario entry_context=api_script, deployment_layout=docroot_project_root
     *
     * @effects installer_base_url_follows_the_browser_address
     *
     * @return void 반환값 없음
     */
    public function test_request_uri_without_install_segment_falls_back_to_script_name(): void
    {
        $this->assertSame(
            '/public/install',
            installerDeriveBaseUrl('/public/install/index.php', '/some/other/path'),
            'install 세그먼트가 없으면 SCRIPT_NAME 파생으로 떨어져야 한다'
        );

        $this->assertSame(
            '/public/install',
            installerDeriveBaseUrl('/public/install/index.php', ''),
            '빈 REQUEST_URI 는 분기를 타지 않아야 한다'
        );
    }

    /**
     * 접두사를 벗기는 리버스 프록시(`X-Forwarded-Prefix`) 뒤의 입력과 기대값.
     *
     * 프록시가 `/g7/install/` 을 `/install/` 로 벗겨 넘기면 `REQUEST_URI` 도 `SCRIPT_NAME` 도
     * 접두사를 모른다 — 노출 프로브 기준점이 사이트 밖(`/install`)이 되어 판정이 상시
     * 「측정 불가」로 강등된다 (13차 G3 W1-11). 헤더가 있으면 그것을 앞에 붙이되, 값은
     * 경로 접두사로만 받는다(`/` 시작 · `//` 아님 · 제어문자 없음). 요청 경로가 이미 그
     * 접두사로 시작하면(프록시가 벗기지 않는 배치) 두 번 붙이지 않는다.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string}> 경우 이름 → [SCRIPT_NAME, REQUEST_URI, 전달 접두사, 기대 기준 URL]
     */
    public static function forwardedPrefixProvider(): array
    {
        return [
            '접두사를 벗긴 프록시' => ['/public/install/index.php', '/install/', '/g7', '/g7/install'],
            '끝 슬래시 있는 접두사' => ['/public/install/index.php', '/install/', '/g7/', '/g7/install'],
            '깊은 접두사' => ['/install/api/run-task.php', '/install/api/run-task.php', '/sites/a', '/sites/a/install'],
            '요청 경로가 이미 접두사를 품음(벗기지 않는 프록시)' => ['/public/install/index.php', '/g7/install/', '/g7', '/g7/install'],
            '빈 헤더는 무시' => ['/public/install/index.php', '/install/', '', '/install'],
            '슬래시로 시작하지 않는 값은 무시' => ['/public/install/index.php', '/install/', 'g7', '/install'],
            '프로토콜 상대(//) 는 무시' => ['/public/install/index.php', '/install/', '//evil.example', '/install'],
            '절대 URL 은 무시' => ['/public/install/index.php', '/install/', 'https://evil.example/x', '/install'],
            '제어문자 포함은 무시' => ['/public/install/index.php', '/install/', "/g7\r\nX-Injected: 1", '/install'],
            '공백 포함은 무시' => ['/public/install/index.php', '/install/', '/g 7', '/install'],
            '루트 접두사(/)는 무접두와 같다' => ['/public/install/index.php', '/install/', '/', '/install'],
            // 14차 G3 W2-1 — 값의 **형태**만 신뢰한다. 직결 배치에서는 이 헤더를 클라이언트가
            // 보낼 수 있고, 그 값은 `Location:` 과 `<script src>` 에 그대로 실린다.
            '역슬래시는 무시(WHATWG 가 /\evil 을 //evil 로 정규화한다)' => ['/public/install/index.php', '/install/', '/\evil.com', '/install'],
            '상위 디렉토리 세그먼트(/../)는 무시' => ['/public/install/index.php', '/install/', '/../', '/install'],
            '깊은 상위 디렉토리 세그먼트는 무시' => ['/public/install/index.php', '/install/', '/g7/../..', '/install'],
            '현재 디렉토리 세그먼트(/./)는 무시' => ['/public/install/index.php', '/install/', '/g7/./x', '/install'],
            '속성 탈출 문자(")·태그(<)는 무시' => ['/public/install/index.php', '/install/', '/g7"><script>', '/install'],
            '퍼센트 인코딩된 상위 디렉토리(%2e%2e)는 무시' => ['/public/install/index.php', '/install/', '/%2e%2e/x', '/install'],
            '점을 품은 세그먼트 이름(g7%2e%2e → g7..)은 디렉토리 이동이 아니다' => ['/public/install/index.php', '/install/', '/g7%2e%2e', '/g7../install'],
            '허용 문자로만 된 정상 접두사' => ['/public/install/index.php', '/install/', '/g7/sub-dir_1', '/g7/sub-dir_1/install'],
            '점·물결표를 품은 정상 접두사' => ['/public/install/index.php', '/install/', '/~user/site.v2', '/~user/site.v2/install'],
            // 15차 G3 W1-1 — 비ASCII 마운트(`/한글/`)와 그것을 퍼센트 인코딩한 형태는 **정상 배치**다.
            // 종전 화이트리스트는 둘 다 버려 `INSTALLER_BASE_URL` 이 마운트 밖(`/install`)이 됐고,
            // 화면이 조립하는 `${origin}${INSTALLER_BASE_URL}/api/*.php` 가 전부 404 라 인스톨러
            // 자체가 불능이었다. 출력은 브라우저·`Location:`·`<script src>` 에 실리므로 정규화된
            // 퍼센트 인코딩 한 형태로만 돌려준다.
            '퍼센트 인코딩된 비ASCII 접두사는 그대로(대문자 정규화)' => ['/public/install/index.php', '/install/', '/%ed%95%9c%ea%b8%80', '/%ED%95%9C%EA%B8%80/install'],
            '원시 UTF-8 접두사는 퍼센트 인코딩해 돌려준다' => ['/public/install/index.php', '/install/', '/한글/site', '/%ED%95%9C%EA%B8%80/site/install'],
            // 16차 G3 A6 — 계약 변경. 종전 「인코딩된 공백은 감춘 공백이니 버린다」는 원시 공백을
            // 거부하는 이유(헤더 값의 형태)를 인코딩 표기에까지 옮긴 것인데, 출력은 언제나
            // 정규형이라 감출 것이 없고 디렉토리 이름에 공백이 든 마운트는 정상 배치다.
            '인코딩된 공백(%20)은 정상 마운트' => ['/public/install/index.php', '/install/', '/g%207', '/g%207/install'],
            '인코딩된 구분자(%2F)는 무시 — 디코딩 뒤 세그먼트 경계가 바뀐다' => ['/public/install/index.php', '/install/', '/g7%2Fx', '/install'],
            '인코딩된 제어문자(%0A)는 무시' => ['/public/install/index.php', '/install/', '/g7%0AX', '/install'],
            '잘못된 퍼센트 시퀀스(%G1)는 무시' => ['/public/install/index.php', '/install/', '/g7%G1', '/install'],
            '인코딩된 역슬래시(%5C)는 무시' => ['/public/install/index.php', '/install/', '/g7%5Cevil', '/install'],
            '잘못된 UTF-8 바이트열은 무시' => ['/public/install/index.php', '/install/', "/g7\xff", '/install'],
            '인코딩된 %25 는 %25 로 보존된다' => ['/public/install/index.php', '/install/', '/g7%25x', '/g7%25x/install'],
        ];
    }

    /**
     * 접두사를 **버린 사실이 관측된다** (15차 G3 W1-2).
     *
     * 종전에는 값을 버리고 `''` 로 떨어질 뿐이라, 프록시 뒤에서 인스톨러가 통째로 404 가 되는데
     * 운영자에게 닿는 단서가 없었다. 사유·길이만 기록한다 — 값 자체는 신뢰 경계 밖에서 온
     * 것이라 로그에 싣지 않는다.
     *
     * @scenario base_path=subpath, forwarded_prefix_spelling=raw
     *
     * @effects installer_rejected_forwarded_prefix_is_observable
     *
     * @return void 반환값 없음
     */
    public function test_rejected_forwarded_prefix_is_recorded_with_reason_and_length_only(): void
    {
        $this->assertSame('/g7', installerSanitizeForwardedPrefix('/g7'));
        $this->assertNull(installerForwardedPrefixRejection(), '정상 값을 받았는데 거부 기록이 남아 있다');

        $this->assertSame('', installerSanitizeForwardedPrefix('/g7"><script>'));
        $rejection = installerForwardedPrefixRejection();
        $this->assertIsArray($rejection, '거부한 값의 기록이 없다 — 프록시 뒤 404 의 단서가 사라진다');
        $this->assertSame(['reason', 'length'], array_keys($rejection), '기록은 사유·길이만 싣는다 (값은 싣지 않는다)');
        $this->assertSame(strlen('/g7"><script>'), $rejection['length']);
        $this->assertNotEmpty($rejection['reason']);

        // 다음 정상 호출이 기록을 지운다 — 마지막 판정을 반영한다.
        installerSanitizeForwardedPrefix(null);
        $this->assertNull(installerForwardedPrefixRejection());
    }

    /**
     * 정제된 접두사(퍼센트 인코딩 정규형)와 요청 경로(브라우저가 친 표기 그대로)의 대조.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> [requestUri, forwardedPrefix, 기대]
     */
    public static function forwardedPrefixNormalizationProvider(): array
    {
        return [
            // 16차 G3 A1 — 정제기는 접두사를 정규형(`/%ED%95%9C%EA%B8%80`)으로 만드는데,
            // 「이미 그 접두사로 시작하는가」 대조는 요청 경로의 **원문 표기**와 했다. 비ASCII
            // 마운트에서 프록시가 접두사를 벗기지 않는 배치는 두 표기가 달라 대조가 언제나
            // 불일치 → 접두사가 두 번 붙어 `/%ED%95%9C%EA%B8%80/한글/install` 이 됐다. 그 값은
            // `Location:`·`<script src>`·`APP_URL` 에 실린다.
            '원시 UTF-8 요청 경로 + 원시 UTF-8 접두사' => ['/한글/install/', '/한글', '/%ED%95%9C%EA%B8%80/install'],
            '인코딩된 요청 경로 + 원시 UTF-8 접두사' => ['/%ED%95%9C%EA%B8%80/install/', '/한글', '/%ED%95%9C%EA%B8%80/install'],
            '소문자 인코딩 요청 경로 + 대문자 정규형 접두사' => ['/%ed%95%9c%ea%b8%80/install/', '/%ED%95%9C%EA%B8%80', '/%ED%95%9C%EA%B8%80/install'],
            // unreserved 를 굳이 인코딩한 표기(`%7E`)는 정규형에서 원문(`~`)으로 접힌다 —
            // 접두사와 요청 경로가 같은 마운트를 다른 표기로 적어도 한 번만 붙는다.
            '인코딩된 물결표 요청 경로 + 원시 접두사' => ['/%7Euser/install/', '/~user', '/~user/install'],
            '원시 물결표 요청 경로 + 인코딩된 접두사' => ['/~user/install/', '/%7Euser', '/~user/install'],
            // 대조군 — 접두사가 요청 경로에 없으면(벗기는 프록시) 종전대로 한 번 붙는다.
            '벗긴 프록시 뒤의 비ASCII 접두사' => ['/install/', '/한글', '/%ED%95%9C%EA%B8%80/install'],
            '벗긴 프록시 뒤의 ASCII 접두사' => ['/install/', '/g7', '/g7/install'],
            // 대조군 — 접두사와 **다른** 세그먼트로 시작하는 요청 경로에는 붙는다(부분 일치가 아니다).
            '접두사를 앞부분으로만 품은 세그먼트' => ['/g7x/install/', '/g7', '/g7/g7x/install'],
        ];
    }

    /**
     * 「이미 그 접두사로 시작한다」 대조는 **같은 정규형**끼리 한다 (16차 G3 A1).
     *
     * @param  string  $requestUri  브라우저가 친 요청 경로 (원문 표기)
     * @param  string  $forwardedPrefix  프록시가 넘긴 접두사
     * @param  string  $expected  기대 기준 URL
     *
     * @scenario forwarded_prefix_spelling=raw, forwarded_prefix_spelling=percent_encoded, base_path=subpath
     *
     * @effects installer_forwarded_prefix_comparison_is_encoding_normalized
     *
     * @return void 반환값 없음
     */
    #[DataProvider('forwardedPrefixNormalizationProvider')]
    public function test_forwarded_prefix_comparison_uses_one_percent_encoding_normal_form(
        string $requestUri,
        string $forwardedPrefix,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            installerDeriveBaseUrl('/public/install/index.php', $requestUri, $forwardedPrefix),
            $requestUri.' + '.$forwardedPrefix.' 에서 접두사가 이중으로 붙거나 빠진다'
        );
    }

    /**
     * 같은 접두사의 두 표기(원시 · 퍼센트 인코딩)가 **같은 판정**을 받는다.
     *
     * @return array<string, array{0: string, 1: string}> [forwardedPrefix, 기대 (빈 문자열 = 거부)]
     */
    public static function forwardedPrefixSpellingProvider(): array
    {
        return [
            // 16차 G3 A6 — 원시 허용 집합이 unreserved 뿐이라 `/g7,x` 는 버리고 `/g7%2Cx` 는
            // 통과했다. 출력은 언제나 정규형(세그먼트별 rawurlencode)이라 두 표기의 결과는
            // 같아야 하는데, 어느 표기로 헤더를 보내느냐에 따라 인스톨러가 404 가 되기도 했다.
            // 허용 폭은 RFC 3986 pchar(unreserved · sub-delims · `:` · `@`)까지다.
            '쉼표 — 원시' => ['/g7,x', '/g7%2Cx'],
            '쉼표 — 인코딩' => ['/g7%2Cx', '/g7%2Cx'],
            'sub-delims 전부 — 원시' => ["/a!$&'()*+;=b", '/a%21%24%26%27%28%29%2A%2B%3B%3Db'],
            'sub-delims 전부 — 인코딩' => ['/a%21%24%26%27%28%29%2A%2B%3B%3Db', '/a%21%24%26%27%28%29%2A%2B%3B%3Db'],
            '콜론·앳 — 원시' => ['/a:b@c', '/a%3Ab%40c'],
            '콜론·앳 — 인코딩' => ['/a%3Ab%40c', '/a%3Ab%40c'],
            // 공백은 원시로는 pchar 가 아니라 여전히 거부하지만(헤더 값의 형태 자체가 아니다),
            // 인코딩된 공백(`%20`)은 디렉토리 이름에 공백이 든 정상 마운트다 — 종전에는
            // 「인코딩으로 감춘 공백」으로 버렸는데, 출력이 정규형이라 감출 것이 없다.
            '인코딩된 공백은 정상 마운트' => ['/a%20b', '/a%20b'],
            '인코딩된 공백 (16차 이전 거부 케이스)' => ['/g%207', '/g%207'],
            '원시 공백은 여전히 거부' => ['/g 7', ''],
            // 작은따옴표는 pchar 이고 출력에서 `%27` 로 접힌다 — 속성 탈출 문자가 아니다.
            '작은따옴표 — 원시' => ["/it's", '/it%27s'],
            '작은따옴표 — 인코딩' => ['/it%27s', '/it%27s'],
            // 거부 집합은 그대로다 — 제어문자·구분자·역슬래시·`<>"` 는 두 표기 모두 버린다.
            '큰따옴표 — 원시' => ['/a"b', ''],
            '큰따옴표 — 인코딩' => ['/a%22b', ''],
            '꺾쇠 — 인코딩' => ['/a%3Cb', ''],
            '구분자 — 인코딩' => ['/a%2Fb', ''],
            '역슬래시 — 인코딩' => ['/a%5Cb', ''],
            '제어문자 — 인코딩' => ['/a%01b', ''],
            '삭제문자 — 인코딩' => ['/a%7Fb', ''],
            // 16차 G3 A14 — 중간 빈 세그먼트는 접는다. `//` 로 **시작**하는 값(프로토콜 상대)은
            // 그 전에 버려지므로 이 접기는 오리진을 바꾸지 못한다.
            '중간 빈 세그먼트는 접는다' => ['/a//b', '/a/b'],
            '연속 빈 세그먼트' => ['/a///b/', '/a/b'],
            '끝 빈 세그먼트' => ['/g7//', '/g7'],
        ];
    }

    /**
     * 원시 표기와 인코딩 표기의 판정이 같고, 빈 세그먼트는 접힌다 (16차 G3 A6 · A14).
     *
     * @param  string  $forwardedPrefix  프록시가 넘긴 접두사 (원시 또는 인코딩 표기)
     * @param  string  $expected  기대 정제 결과 (빈 문자열은 거부)
     *
     * @scenario forwarded_prefix_spelling=raw, forwarded_prefix_spelling=percent_encoded, base_path=subpath
     *
     * @effects installer_forwarded_prefix_pchar_is_accepted_in_both_spellings, installer_forwarded_prefix_collapses_empty_segments
     *
     * @return void 반환값 없음
     */
    #[DataProvider('forwardedPrefixSpellingProvider')]
    public function test_forwarded_prefix_spellings_receive_the_same_verdict(string $forwardedPrefix, string $expected): void
    {
        $this->assertSame(
            $expected,
            installerSanitizeForwardedPrefix($forwardedPrefix),
            var_export($forwardedPrefix, true).' 의 판정이 같은 값의 다른 표기와 갈린다'
        );
    }

    /**
     * `APP_URL` 의 호스트는 `Host` 헤더가 없으면 `SERVER_NAME` 으로 떨어진다 (16차 G3 D-8).
     *
     * HTTP/1.0 클라이언트·일부 프록시는 `Host` 를 싣지 않는다. 그때 `$_SERVER['HTTP_HOST']`
     * 직접 참조는 경고와 함께 빈 호스트를 만들고, 그 값은 `.env` 에 `APP_URL=http://` 로
     * **영구 저장**된다.
     *
     * @scenario base_path=root
     *
     * @effects installer_app_url_host_falls_back_to_server_name
     *
     * @return void 반환값 없음
     */
    public function test_app_url_host_falls_back_to_server_name_when_the_host_header_is_absent(): void
    {
        $index = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/index.php');

        $this->assertDoesNotMatchRegularExpression(
            '/\$_SERVER\[\'HTTP_HOST\'\](?!\s*\?\?)/',
            $index,
            'APP_URL 호스트가 Host 헤더를 가드 없이 읽는다 — 헤더가 없으면 빈 호스트가 .env 에 영구 저장된다'
        );
        $this->assertStringContainsString(
            "\$_SERVER['SERVER_NAME']",
            $index,
            'Host 헤더가 비어 있을 때의 폴백(SERVER_NAME)이 없다'
        );
    }

    /**
     * 정제기가 버린 접두사는 노출 점검의 `unmeasured` 사유로 화면에 도달한다.
     *
     * @scenario base_path=subpath, exposure_probe=unknown, exposure_unknown_cause=unmeasured_only, locale=ko, locale=en
     *
     * @effects installer_rejected_forwarded_prefix_is_observable
     *
     * @return void 반환값 없음
     */
    public function test_rejected_forwarded_prefix_reaches_the_exposure_plan_as_unmeasured(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/exposure-check.php';

        $root = dirname(__DIR__, 3);

        installerSanitizeForwardedPrefix('/g7 evil');
        $plan = installerExposureProbePlan($root);
        installerSanitizeForwardedPrefix(null);

        $reasons = array_column($plan['unmeasured'], 'reason');
        $this->assertContains(
            'forwarded_prefix_rejected',
            $reasons,
            '버린 접두사가 노출 점검 사유로 드러나지 않는다 — 운영자는 왜 기준 주소가 어긋났는지 알 수 없다'
        );

        foreach (['ko', 'en'] as $locale) {
            $lang = require $root.'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('web_exposure_unmeasured_reason_forwarded_prefix_rejected', $lang, $locale.' 사유 문구가 없다');
        }
    }

    /**
     * 정제기는 **허용 문자 화이트리스트**로 판정한다 — 차단 문자를 열거하는 형태는 새 우회
     * (역슬래시·퍼센트 인코딩)가 나올 때마다 한 글자씩 뒤따르게 된다.
     *
     * @scenario base_path=subpath, forwarded_prefix_spelling=raw, forwarded_prefix_spelling=percent_encoded
     *
     * @effects installer_base_url_never_leaves_the_mount_or_origin
     *
     * @return void 반환값 없음
     */
    public function test_forwarded_prefix_sanitizer_is_an_allow_list_not_a_deny_list(): void
    {
        $config = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/config.php');
        $body = $this->functionBody($config, 'installerSanitizeForwardedPrefix');

        // 원시 문자 축: ASCII 는 pchar(+`%` 시퀀스)만, 그 밖은 비ASCII 바이트만 통과한다.
        // 퍼센트 인코딩과 비ASCII 는 세그먼트를 디코딩해 다시 판정하고, 출력은 재인코딩한 형태다.
        $this->assertStringContainsString(
            "'#^/(?:[A-Za-z0-9._~!$&\\'()*+,;=:@/-]|%[0-9A-Fa-f]{2}|[\\x80-\\xFF])*$#'",
            $body,
            '정제기가 허용 문자 화이트리스트로 판정하지 않는다'
        );
        $this->assertStringContainsString('rawurldecode(', $body, '인코딩된 세그먼트를 디코딩해 다시 판정하지 않는다');
        $this->assertStringContainsString('rawurlencode(', $body, '출력을 정규화된 퍼센트 인코딩 한 형태로 돌려주지 않는다');
        $this->assertStringContainsString("'..'", $body, '정제기가 상위 디렉토리 세그먼트를 거부하지 않는다');
    }

    /**
     * `INSTALLER_BASE_URL` 이 HTML **속성**에 실리는 자리는 전부 이스케이프 헬퍼를 지난다.
     *
     * 인라인 JS 축(`window.INSTALLER_BASE_URL`)은 `JSON_HEX_*` 로 이미 닫혀 있었는데 속성 축
     * (`href`·`src`)은 원문 그대로였다 — 정제기가 한 글자라도 놓치면 그 자리가 스크립트
     * 주입 통로가 된다. 정제기와 출력 이스케이프는 서로 다른 방어선이라 둘 다 둔다.
     *
     * @scenario base_path=subpath
     *
     * @effects installer_base_url_attribute_outputs_are_escaped
     *
     * @return void 반환값 없음
     */
    public function test_base_url_html_attribute_outputs_are_escaped(): void
    {
        $root = dirname(__DIR__, 3);
        $index = $this->sourceWithoutComments($root.'/public/install/index.php');
        $functions = $this->sourceWithoutComments($root.'/public/install/includes/functions.php');

        $this->assertDoesNotMatchRegularExpression(
            '/<\?=\s*INSTALLER_BASE_URL\s*\?>/',
            $index,
            'index.php 가 INSTALLER_BASE_URL 을 HTML 속성에 원문 그대로 출력한다'
        );
        $this->assertGreaterThanOrEqual(
            3,
            preg_match_all('/<\?=\s*e\(INSTALLER_BASE_URL\)\s*\?>/', $index),
            'index.php 의 속성 출력 3곳(css·js×2)이 e() 를 지나지 않는다'
        );
        $this->assertStringNotContainsString(
            "href=\"'.INSTALLER_BASE_URL.'",
            $functions,
            'functions.php 의 <a href> 가 INSTALLER_BASE_URL 을 원문 그대로 이어 붙인다'
        );
        $this->assertStringContainsString(
            "href=\"'.e(INSTALLER_BASE_URL).'",
            $functions,
            'functions.php 의 <a href> 가 이스케이프 헬퍼를 지나지 않는다'
        );
    }

    /**
     * 프록시가 넘긴 접두사가 안전한 경로 접두사일 때만 기준 URL 앞에 한 번 붙고, 빈 값, 상대 경로, 프로토콜 상대, 절대 URL, 제어문자, 디렉토리 이동, 위험한 인코딩은 버려지는지 확인한다.
     *
     * @param  string  $scriptName  서버가 넘기는 SCRIPT_NAME
     * @param  string|null  $requestUri  브라우저가 친 REQUEST_URI
     * @param  string|null  $forwardedPrefix  프록시가 넘긴 접두사
     * @param  string  $expected  기대 기준 URL
     *
     * @scenario base_path=root, base_path=subpath, forwarded_prefix_spelling=raw, forwarded_prefix_spelling=percent_encoded
     *
     * @effects installer_base_url_never_leaves_the_mount_or_origin
     *
     * @return void 반환값 없음
     */
    #[DataProvider('forwardedPrefixProvider')]
    public function test_forwarded_prefix_is_prepended_only_when_it_is_a_safe_path_prefix(
        string $scriptName,
        ?string $requestUri,
        ?string $forwardedPrefix,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            installerDeriveBaseUrl($scriptName, $requestUri, $forwardedPrefix),
            var_export($forwardedPrefix, true).' 에서 프록시 접두사 흡수가 어긋난다'
        );
    }

    /**
     * 진입점이 그 헤더를 실제로 파생 함수에 넘긴다 — 함수만 받고 호출부가 안 넘기면 사문이다.
     *
     * @scenario base_path=subpath
     *
     * @effects installer_base_url_follows_the_browser_address
     *
     * @return void 반환값 없음
     */
    public function test_entry_point_passes_the_forwarded_prefix_header(): void
    {
        $config = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/config.php');

        $this->assertStringContainsString("\$_SERVER['HTTP_X_FORWARDED_PREFIX']", $config, '진입점이 X-Forwarded-Prefix 를 파생에 넘기지 않는다');
    }

    /**
     * 요청 경로에 dot-segment(`.`·`..`, 인코딩 포함)가 있으면 그 분기를 버린다 (17차 A-2).
     *
     * 접두사 정제기는 세그먼트를 디코딩해 `..`/`.` 를 거부하는데, 요청 경로 축에는 그 판정이
     * 없어 `installerNormalizePathEncoding()` 이 `%2E%2E` 를 실제 `..` 로 되살렸다 —
     * `/g7/%2E%2E/%2E%2E/install` 이 `/g7/../../install` 이 되어 마운트 밖을 가리켰다. 요청에
     * 없던 dot-segment 를 기준 URL 에 만들지 않고 `SCRIPT_NAME` 파생으로 떨어진다.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string}> [scriptName, requestUri, prefix, 기대] 경우 이름별 입력과 기대 기준 URL
     */
    public static function dotSegmentRequestPathProvider(): array
    {
        return [
            '인코딩된 상위 디렉토리 (무접두)' => ['/install/index.php', '/a/%2E%2E/install/', null, '/install'],
            '인코딩된 상위 디렉토리 (접두사)' => ['/g7/install/index.php', '/g7/%2E%2E/%2E%2E/install/', '/g7', '/g7/install'],
            '소문자 인코딩 상위 디렉토리' => ['/install/index.php', '/a/%2e%2e/install/', null, '/install'],
            '현재 디렉토리 세그먼트' => ['/install/index.php', '/a/%2E/install/', null, '/install'],
            '원시 상위 디렉토리' => ['/install/index.php', '/a/../install/', null, '/install'],
            // 대조군 — 이름에 점이 든 세그먼트(`g7..`)는 디렉토리 이동이 아니라 정상 마운트다.
            '점을 품은 세그먼트 이름은 통과' => ['/install/index.php', '/g7../install/', null, '/g7../install'],
        ];
    }

    /**
     * 요청 경로에 원시 또는 인코딩된 dot-segment 가 있으면 요청 경로 분기를 버리고 SCRIPT_NAME 파생으로 떨어지며, 이름에 점이 든 세그먼트는 정상 마운트로 통과하는지 확인한다.
     *
     * @param  string  $scriptName  서버가 넘기는 SCRIPT_NAME
     * @param  string  $requestUri  브라우저가 친 REQUEST_URI
     * @param  string|null  $prefix  프록시가 넘긴 접두사
     * @param  string  $expected  기대 기준 URL
     *
     * @scenario base_path=root, base_path=subpath
     *
     * @effects installer_base_url_never_leaves_the_mount_or_origin
     *
     * @return void 반환값 없음
     */
    #[DataProvider('dotSegmentRequestPathProvider')]
    public function test_request_path_with_dot_segment_falls_back_to_script_name(
        string $scriptName,
        string $requestUri,
        ?string $prefix,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            installerDeriveBaseUrl($scriptName, $requestUri, $prefix),
            $requestUri.' 의 dot-segment 가 기준 URL 을 마운트 밖으로 밀어낸다'
        );
    }

    /**
     * 역슬래시 치환이 만든 프로토콜 상대(`//evil.com`)는 그 분기를 버린다 (17차 A-4).
     *
     * `str_replace('\\','/')` 는 `parse_url` 뒤라 앞머리 역슬래시 두 개를 `//` 로 바꾼다 —
     * 그 값은 `<script src>`·`Location:` 에 실려 외부 오리진을 가리킨다. 접두사 정제기의
     * 프로토콜 상대 거부와 같은 판정을 요청 경로 축에도 적용한다.
     *
     * @scenario base_path=root
     *
     * @effects installer_base_url_never_leaves_the_mount_or_origin
     *
     * @return void 반환값 없음
     */
    public function test_backslash_derived_protocol_relative_request_path_is_rejected(): void
    {
        // PHP 리터럴에서 `\\\\` = 역슬래시 두 개 → parse_url 뒤 `//evil.com/install/`.
        $this->assertSame(
            '/install',
            installerDeriveBaseUrl('/install/index.php', '\\\\evil.com/install/'),
            '앞머리 역슬래시 두 개가 프로토콜 상대 기준 URL 을 만든다 — 외부 오리진이 <script src> 에 실린다'
        );

        // 대조군 — 앞머리 역슬래시 하나(`\evil`)는 슬래시 하나가 되어 같은 오리진 경로다(위험 없음).
        $this->assertSame(
            '/evil.com/install',
            installerDeriveBaseUrl('/install/index.php', '\\evil.com/install/'),
            '역슬래시 하나는 프로토콜 상대가 아니다 — 정상 요청 경로로 처리한다'
        );
    }

    /**
     * `showAlertAndRedirect()` 의 JS 문맥 값은 `installerJsString`(JSON_HEX_*)으로 인코딩한다 (17차 A-6).
     *
     * `$message`·`$redirectUrl` 은 인라인 `<script>` 의 JS 문자열 문맥에 실린다 — `htmlspecialchars`
     * 는 `</script>`·역슬래시·개행을 이스케이프하지 않아 스크립트 탈출 통로가 된다. index.php:232
     * 규약(인라인 script 값은 예외 없이 JSON_HEX 를 지난다)과 통일한다.
     *
     * @scenario redirect_context=install_root, redirect_context=subpath
     *
     * @effects install_redirect_destination_uses_a_javascript_encoder
     *
     * @return void 반환값 없음
     */
    public function test_alert_redirect_encodes_js_context_with_json_hex_not_htmlspecialchars(): void
    {
        $functions = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/functions.php');
        $body = $this->functionBody($functions, 'showAlertAndRedirect');

        $this->assertStringContainsString('installerJsString($message)', $body, 'alert 메시지가 JS 인코딩(installerJsString)을 지나지 않는다');
        $this->assertStringContainsString('installerJsString($redirectUrl)', $body, 'redirect URL 이 JS 인코딩(installerJsString)을 지나지 않는다');
        $this->assertStringNotContainsString(
            'htmlspecialchars($message',
            $body,
            'JS 문맥의 message 를 htmlspecialchars 로 이스케이프한다 — `</script>`·역슬래시·개행을 놓친다'
        );
        $this->assertStringNotContainsString(
            'htmlspecialchars($redirectUrl',
            $body,
            'JS 문맥의 redirectUrl 을 htmlspecialchars 로 이스케이프한다'
        );
    }

    /**
     * 태스크 러너의 서버측 완료 리다이렉트와 설치 모니터의 완료 폴백 세 곳이 고정 설치 경로 대신 기준 URL 에서 주소를 만드는지 소스(주석 제거)로 확인한다.
     *
     * @scenario redirect_context=subpath, consumer=server_sse, installation_mode=sse, installation_mode=polling, installation_mode=stepwise
     *
     * @effects installer_redirects_derive_from_base_url
     *
     * @return void 반환값 없음
     */
    public function test_completion_redirects_never_hardcode_install_path(): void
    {
        $installerRoot = dirname(__DIR__, 3).'/public/install';

        $taskRunner = $this->sourceWithoutComments($installerRoot.'/includes/task-runner.php');
        $this->assertStringNotContainsString(
            "'redirect' => '/install/'",
            $taskRunner,
            '서버측 완료 리다이렉트는 INSTALLER_BASE_URL 파생이어야 한다 (G3 입력 ②)'
        );
        $this->assertStringContainsString(
            "'redirect' => INSTALLER_BASE_URL.'/'",
            $taskRunner
        );

        $monitor = $this->sourceWithoutComments($installerRoot.'/assets/js/installation-monitor.js');
        $this->assertStringNotContainsString(
            "redirect: '/install/'",
            $monitor,
            '모니터 완료 폴백 2곳은 window.INSTALLER_BASE_URL 파생이어야 한다 (G3 입력 ②)'
        );
        // SSE 끊김 뒤 상태 감시(_finishWatch)·Polling·Stepwise 세 곳. 감시 경로는 7.2.0 이
        // 들여온 것이라 그쪽 원본의 '/install/' 고정값을 기준 URL 로 바꿔 합류했다.
        $this->assertSame(
            3,
            substr_count($monitor, "(window.INSTALLER_BASE_URL || '/install') + '/'"),
            'SSE 감시·Polling·Stepwise 완료 폴백 세 곳 모두 페이지가 심은 기준 URL 을 써야 한다'
        );
    }

    /**
     * `APP_URL` 기본값이 **마운트 접두사까지** 담는다.
     *
     * 그 값은 요청이 없는 문맥의 주소 SSoT 다 — `AssetUrl::configuredBasePath()` 가
     * `app.url` 의 경로를 접두사 폴백으로 읽고 큐·콘솔·예약 작업·**메일 본문**이 그것을
     * 탄다. 하위 폴더 설치에서 접두사를 빠뜨리면 그 문맥의 주소가 전부 사이트 밖을
     * 가리키는데 화면(요청 기반)은 정상이라 드러나지 않는다 — 그 폴백이 막으려고 만든
     * 실패를 인스톨러가 심고 있었다. P0 하위 폴더 실측에서 발견 (15차 G3 라운드).
     *
     * 파생은 형제 SSoT(`installerDeriveBaseUrl`)를 경유해야 한다. 여기서 다시 조립하면
     * Alias·API 컨텍스트 흡수가 사본으로 갈린다.
     *
     * @scenario base_path=root, base_path=subpath
     *
     * @effects installer_seeds_app_url_with_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    public function test_app_url_default_carries_the_mount_prefix(): void
    {
        $index = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/index.php');

        $this->assertStringNotContainsString(
            "'://'.\$_SERVER['HTTP_HOST'];",
            $index,
            'APP_URL 기본값이 host 로 끝난다 — 하위 폴더 설치에서 마운트 접두사를 잃는다'
        );
        $this->assertStringContainsString(
            'installerDeriveBaseUrl(',
            $index,
            'APP_URL 접두사는 인스톨러 기준 주소 SSoT 에서 얻어야 한다 (사본 금지)'
        );

        // 파생 자체의 계약 — `/…/install` 에서 `/install` 을 벗기면 마운트 접두사다.
        $cases = [
            ['/install/index.php', '/install/', ''],
            ['/g7/public/install/index.php', '/g7/install/', '/g7'],
            ['/sub/dir/install/index.php', '/sub/dir/install/', '/sub/dir'],
        ];

        foreach ($cases as [$scriptName, $requestUri, $expected]) {
            $base = installerDeriveBaseUrl($scriptName, $requestUri);
            $prefix = rtrim((string) preg_replace('#/install$#i', '', $base), '/');

            $this->assertSame($expected, $prefix, "요청 {$requestUri} 의 마운트 접두사");
        }
    }

    /**
     * `APP_URL` 파생도 프록시가 벗긴 접두사(`X-Forwarded-Prefix`)를 **같이** 받는다 (15차 G3 W1-3).
     *
     * 기준 URL(`config.php`)은 그 헤더를 넘기는데 `APP_URL` 파생(`index.php`)만 두 인자로
     * 불러, 접두사를 벗기는 프록시 뒤에서는 `.env` 에 접두사 없는 `APP_URL` 이 **영구 저장**됐다.
     * 두 호출은 같은 인자 집합이어야 한다 — 한쪽만 넘기면 같은 요청을 두 지점이 다르게 읽는다.
     *
     * @scenario base_path=subpath
     *
     * @effects installer_seeds_app_url_with_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    public function test_app_url_derivation_passes_the_same_arguments_as_the_base_url_derivation(): void
    {
        $root = dirname(__DIR__, 3);
        $index = $this->sourceWithoutComments($root.'/public/install/index.php');
        $config = $this->sourceWithoutComments($root.'/public/install/includes/config.php');

        $argumentsOf = function (string $source, string $file): string {
            $this->assertSame(
                1,
                preg_match('/\$\w+ = installerDeriveBaseUrl\(\s*(.*?)\);/s', $source, $m),
                $file.' 에서 파생 호출을 찾지 못했다'
            );

            return (string) preg_replace('/\s+/', '', $m[1]);
        };

        $configArgs = $argumentsOf($config, 'config.php');
        // `index.php` 만 SCRIPT_NAME 부재를 `?? ''` 로 흡수한다(CLI 로 열리는 테스트 하네스) — 그 차이만 접는다.
        $indexArgs = str_replace("(\$_SERVER['SCRIPT_NAME']??'')", "\$_SERVER['SCRIPT_NAME']", $argumentsOf($index, 'index.php'));

        $this->assertStringContainsString('HTTP_X_FORWARDED_PREFIX', $indexArgs, 'APP_URL 파생이 X-Forwarded-Prefix 를 넘기지 않는다 — 프록시 뒤에서 접두사 없는 APP_URL 이 .env 에 영구 저장된다');
        $this->assertSame(rtrim($configArgs, ','), rtrim($indexArgs, ','), '기준 URL 파생과 APP_URL 파생의 인자 집합이 갈렸다');

        // 동작 축 — 같은 요청에서 두 파생이 같은 접두사를 낸다.
        $base = installerDeriveBaseUrl('/public/install/index.php', '/install/', '/g7');
        $prefix = rtrim((string) preg_replace('#/install$#i', '', $base), '/');
        $this->assertSame('/g7', $prefix, '프록시가 벗긴 접두사가 APP_URL 마운트 접두사에 실리지 않는다');
    }

    /**
     * `APP_URL` 의 **스킴**도 같은 저장소의 공용 판정을 쓴다.
     *
     * 접두사 축(위 테스트)과 스킴 축은 같은 한 줄이 만드는 값인데, 종전에는 접두사만
     * SSoT 를 경유하고 스킴은 `$_SERVER['HTTPS'] === 'on'` 단독 판정이었다. 그 형태는
     * TLS 를 앞단에서 끝내는 프록시 뒤에서 거짓이 된다 — 그 배치의 `$_SERVER['HTTPS']`
     * 는 비어 있고 `HTTP_X_FORWARDED_PROTO: https` 만 온다. 그러면 인스톨러가 `.env` 에
     * `APP_URL=http://…` 를 **영구 저장**하고, `AssetUrl::absolute()` 는 오리진 축에서
     * 설정을 요청보다 우선하므로 https 페이지에 `http://` 이미지가 실려 브라우저가
     * 혼합 콘텐츠로 **조용히 차단**한다. 그 실패는 `AssetUrl::absolute()` 독블록이
     * 이름을 붙여 막으려던 바로 그것이다.
     *
     * 같은 저장소에 프록시를 아는 `installerRequestIsHttps()` 가 이미 있고 형제 둘
     * (`session.php` · `api/check-configuration.php`)이 쓴다. 값을 영구화하는 이 지점만
     * 안 쓰고 있었다 — 판정이 갈리면 같은 요청을 두 곳이 다르게 읽는다 (12차 G3 라운드
     * A12-3, 제품 결정 「같은 저장소의 공용 판정으로 통일」).
     *
     * @scenario base_path=root
     *
     * @effects installer_seeds_app_url_scheme_from_shared_https_judgment
     *
     * @return void 반환값 없음
     */
    public function test_app_url_default_takes_the_scheme_from_the_shared_judgment(): void
    {
        $index = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/index.php');

        $this->assertStringNotContainsString(
            "\$_SERVER['HTTPS'] === 'on'",
            $index,
            'APP_URL 스킴을 자기 방식으로 판정한다 — 프록시 뒤에서 http 가 .env 에 영구 저장된다'
        );
        $this->assertStringContainsString(
            'installerRequestIsHttps(',
            $index,
            'APP_URL 스킴은 프록시를 아는 공용 판정을 경유해야 한다 (형제 둘이 이미 쓴다)'
        );

        // 공용 판정 자체의 계약 — 두 축 모두, 그리고 「둘 다 없으면 http」.
        $cases = [
            [['HTTPS' => 'on'], true, '직접 HTTPS'],
            [['HTTPS' => '1'], true, "HTTPS 값이 '1' 인 서버"],
            [['HTTP_X_FORWARDED_PROTO' => 'https'], true, 'TLS 종단 프록시 뒤'],
            [['HTTP_X_FORWARDED_PROTO' => 'https, http'], true, '프록시 체인 (맨 앞이 클라이언트 쪽)'],
            [['HTTPS' => 'off'], false, "HTTPS 값이 'off'"],
            [[], false, '평문'],
        ];

        foreach ($cases as [$server, $expected, $label]) {
            $this->assertSame($expected, installerRequestIsHttps($server), $label);
        }
    }
}
