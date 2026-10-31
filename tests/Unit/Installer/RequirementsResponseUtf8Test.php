<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 설치 2단계(설치 환경 확인) 응답 인코딩 회귀 테스트.
 *
 * 배경 (sir.kr 566085 / gnuboard/g7#62 reopen):
 *   `whoami` = `it-manager\티아모라` (한글 계정명) 인 Windows 에서
 *   `check-configuration.php?action=requirements` 가 HTTP 200 + 빈 본문을 반환하고
 *   화면에 "요구사항 검증 실패: ... Unexpected end of JSON input" 만 표시된다.
 *
 *   원인: whoami 의 CP949 출력이 `directories.web_server_user` /
 *   `required_files.*.owner` 에 정규화 없이 실려 json_encode() 가 false 를 반환한다.
 *
 * getWebServerUser() 는 전역 함수라 mock 할 수 없으므로,
 * checkRequirements() 가 만드는 것과 같은 배열 형태에 CP949 값을 주입하고
 * **같은 직렬화 경로**(installer_json_encode)로 인코딩해 계약을 검사한다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
/**
 * 소유 매니페스트(마커 아님 — 평문): installer-process-output-utf8. 조합 표식은 각 테스트 메서드가 단다.
 */
class RequirementsResponseUtf8Test extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 시스템 temp 아래 임시 BASE_PATH 를 정하고 로그와 캐시 디렉토리를 만든 뒤, 요청을 처리하지 않는 라이브러리 모드로 인스톨러 설정과 함수와 요구사항 검사 파일을 불러온다.
     *
     * BASE_PATH 가 이미 temp 밖으로 정의돼 있으면 건너뛸 사유만 기록한다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=RequirementsResponseUtf8Test';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-req-utf8-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        foreach (['/storage/logs', '/storage/app', '/bootstrap/cache'] as $dir) {
            if (! is_dir(self::$sharedBase.$dir)) {
                mkdir(self::$sharedBase.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        // 라이브러리 모드 — 파일 로드만으로 요청을 처리하지 않도록 한다.
        if (! defined('CHECK_CONFIGURATION_LIBRARY')) {
            define('CHECK_CONFIGURATION_LIBRARY', true);
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
        require_once dirname(__DIR__, 3).'/public/install/api/check-configuration.php';
    }

    /**
     * 클래스 준비 단계가 기록한 건너뛸 사유가 있으면 테스트를 건너뛴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * UTF-8 문자열을 한국어 Windows 콘솔이 내는 CP949 바이트열로 바꿔 결함 재현 표본을 만든다.
     *
     * @param  string  $utf8  변환할 UTF-8 원문
     * @return string CP949 로 인코딩된 바이트열
     */
    private static function cp949(string $utf8): string
    {
        return (string) mb_convert_encoding($utf8, 'CP949', 'UTF-8');
    }

    /**
     * 2단계 응답이 한글 계정명 환경에서도 본문을 실어 보내고 계정명을 복원해야 한다.
     *
     * @scenario output_encoding=cp949_korean, sink=requirements_response, value_position=nested_array_value, locale=ko
     *
     * @effects response_body_never_empty, requirements_response_parses_after_cp949_account_name, korean_account_name_restored_exactly, non_string_scalars_preserve_type_and_value
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function requirements_response_survives_korean_account_name(): void
    {
        $account = 'it-manager\\티아모라';
        $raw = self::cp949($account);

        // checkDirectoryPermissions() / checkRequiredFiles() 가 만드는 형태를 그대로 모사
        $requirements = [
            'directories' => [
                'required' => true,
                'web_server_group' => $raw,
                'web_server_user' => $raw,
                'all_passed' => false,
                'message' => '디렉토리 권한을 확인하세요.',
            ],
            'required_files' => [
                'required' => true,
                'files' => [
                    '.env' => ['exists' => true, 'writable' => false, 'owner' => $raw],
                ],
                'all_passed' => false,
            ],
            'all_required_passed' => false,
            'is_windows' => true,
        ];

        // 사전 조건: 이 배열이 실제로 결함을 재현해야 한다.
        $this->assertFalse(
            json_encode($requirements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            '표본 배열은 기존 직렬화 방식에서 false 가 되어야 한다 (결함 재현).'
        );

        // checkRequirements() 가 쓰는 것과 같은 경로
        $json = installer_json_encode($requirements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);
        $this->assertNotSame('', $json, 'HTTP 200 + 빈 본문이 다시 나가면 안 된다.');

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, '프론트의 response.json() 이 파싱할 수 있어야 한다.');

        $this->assertSame($account, $decoded['directories']['web_server_user']);
        $this->assertSame($account, $decoded['directories']['web_server_group']);
        $this->assertSame($account, $decoded['required_files']['files']['.env']['owner']);

        // 나머지 필드가 정규화 과정에서 사라지거나 변형되지 않아야 한다.
        $this->assertFalse($decoded['all_required_passed']);
        $this->assertTrue($decoded['is_windows']);
        $this->assertSame('디렉토리 권한을 확인하세요.', $decoded['directories']['message']);
    }

    /**
     * 회귀 가드 (머신 무관): 출처가 posix / whoami / 환경변수 어느 쪽이든
     * 응답에 실릴 값은 항상 유효 UTF-8 이어야 한다.
     *
     * @scenario output_encoding=utf8, sink=requirements_response, value_position=scalar, locale=ko
     *
     * @effects web_server_user_always_valid_utf8
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function web_server_identity_is_always_encodable(): void
    {
        $payload = [
            'web_server_user' => getWebServerUser(),
            'web_server_group' => getWebServerGroup(),
        ];

        $this->assertNotFalse(
            json_encode($payload),
            '현재 머신의 웹서버 사용자/그룹 값이 json_encode 를 무력화하면 안 된다.'
        );
    }
}
