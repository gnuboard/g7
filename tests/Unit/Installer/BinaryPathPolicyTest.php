<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 실행 바이너리 경로 정책 테스트 (KVE-2026-1677).
 *
 * 정책 파일은 의존성이 없어야 한다 — 인스톨러 API(라이브러리 모드)와 설치 워커 양쪽에서
 * 단독으로 `require_once` 되므로, 이 테스트도 BASE_PATH·lang 스텁 없이 파일만 로드한다.
 * 로드에 다른 것이 필요해지면 이 테스트가 먼저 깨진다.
 *
 * 판정 축:
 *  - 인자 자리 차단 (하이픈 선두 · 인터프리터+임의 스크립트)
 *  - 자리별 비대칭 (PHP 자리는 이름 무제한 / Composer 자리는 이름 형태 제한)
 *  - stat 미사용 (존재하지 않는 절대경로도 형태만 맞으면 통과 — open_basedir 회귀 가드)
 */
class BinaryPathPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/binary-path-policy.php';
    }

    /**
     * 형태 규칙을 통과해야 하는 토큰.
     *
     * @param  string  $token  경로 토큰
     * @param  string  $reason  통과 사유 (실패 메시지용)
     *
     * @scenario vector=validatePhpPath, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects legitimate_paths_still_reach_exec
     */
    #[Test]
    #[DataProvider('shapeAllowedProvider')]
    public function it_accepts_tokens_with_a_valid_shape(string $token, string $reason): void
    {
        $this->assertTrue(
            installer_binary_path_shape_ok($token),
            "통과해야 하는 경로가 거부됨 ({$reason}): {$token}"
        );
    }

    /**
     * 형태 규칙에 걸려야 하는 토큰.
     *
     * @param  string  $token  경로 토큰
     * @param  string  $reason  거부 사유 (실패 메시지용)
     *
     * @scenario vector=validatePhpPath, payload_class=argument_injection_dash_r, expected_outcome=return_invalid_message
     *
     * @effects leading_hyphen_token_is_rejected
     * @effects php_slot_rejects_leading_hyphen
     * @effects relative_and_traversal_paths_are_rejected
     */
    #[Test]
    #[DataProvider('shapeRejectedProvider')]
    public function it_rejects_tokens_with_an_invalid_shape(string $token, string $reason): void
    {
        $this->assertFalse(
            installer_binary_path_shape_ok($token),
            "거부해야 하는 경로가 통과함 ({$reason}): {$token}"
        );
    }

    /**
     * Composer 자리는 이름 형태까지 제한한다.
     *
     * @param  string  $token  경로 토큰
     * @param  bool  $expected  기대 판정
     * @param  string  $reason  사유 (실패 메시지용)
     *
     * @scenario vector=validateComposerPath, payload_class=interpreter_plus_script, expected_outcome=return_invalid_message
     *
     * @effects getComposerCommand_rejects_interpreter_plus_arbitrary_script
     */
    #[Test]
    #[DataProvider('composerSlotProvider')]
    public function it_restricts_the_composer_slot_by_name(string $token, bool $expected, string $reason): void
    {
        $this->assertSame(
            $expected,
            installer_is_composer_binary_path($token),
            "Composer 자리 판정이 기대와 다름 ({$reason}): {$token}"
        );
    }

    /**
     * PHP 자리는 이름을 제한하지 않는다.
     *
     * 래퍼 스크립트·버전이 붙은 이름·커스텀 이름을 모두 허용해야 한다. 이 자리는
     * 인자가 `--version` 으로 고정된 실행 파일 자리라 이름 제한이 이번 공격과 무관하고,
     * 제한하면 설치 환경 다양성을 해친다.
     *
     * @param  string  $token  PHP 실행 경로
     *
     * @scenario vector=validatePhpPath, payload_class=wrapper_script_name, expected_outcome=accept_normal
     *
     * @effects legitimate_paths_still_reach_exec
     */
    #[Test]
    #[DataProvider('phpSlotNameProvider')]
    public function it_does_not_restrict_the_php_slot_by_name(string $token): void
    {
        $this->assertTrue(
            installer_binary_path_shape_ok($token),
            "PHP 자리 이름 제한이 생겨 정상 환경이 거부됨: {$token}"
        );
    }

    /**
     * 2토큰 입력은 자리별 규칙을 모두 만족할 때만 해석된다.
     *
     * @param  string  $raw  원본 입력
     * @param  array{php: string, composer: string}|null  $expected  기대 해석 결과
     * @param  string  $reason  사유 (실패 메시지용)
     *
     * @scenario vector=validateComposerPath, payload_class=argument_injection_dash_r, expected_outcome=return_invalid_message
     *
     * @effects argument_injection_never_reaches_exec
     * @effects getComposerCommand_keeps_multi_php_pair_support
     */
    #[Test]
    #[DataProvider('pairProvider')]
    public function it_resolves_only_well_formed_php_composer_pairs(string $raw, ?array $expected, string $reason): void
    {
        $this->assertSame(
            $expected,
            installer_resolve_php_composer_pair($raw),
            "2토큰 해석이 기대와 다름 ({$reason}): {$raw}"
        );
    }

    /**
     * 파일 시스템 stat 에 의존하지 않는다 (open_basedir 회귀 가드).
     *
     * `#361` 은 `is_file()`/`is_executable()` 가드가 시놀로지 DSM 등에서 정상 절대경로를
     * false negative 로 거부하는 회귀를 되돌린 이력이다. 존재하지 않는 절대경로가
     * 형태 검사만으로 통과해야 그 회귀가 구조적으로 재발하지 않는다.
     *
     * @scenario vector=getPhpBinary, payload_class=normal, expected_outcome=accept_normal
     *
     * @effects getPhpBinary_passes_through_legitimate_paths
     */
    #[Test]
    public function it_does_not_depend_on_filesystem_stat(): void
    {
        $missing = '/usr/local/php99/bin/php';

        $this->assertFileDoesNotExist($missing);
        $this->assertTrue(installer_binary_path_shape_ok($missing));
        $this->assertTrue(installer_is_composer_binary_path('/usr/local/php99/bin/composer'));
    }

    /**
     * 통과해야 하는 토큰 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function shapeAllowedProvider(): array
    {
        return [
            'bare php' => ['php', '시스템 기본'],
            'bare composer' => ['composer', '시스템 기본'],
            'POSIX 절대경로' => ['/usr/bin/php', '일반 설치'],
            '버전 붙은 이름' => ['/usr/bin/php8.3', '멀티 PHP'],
            'Plesk 경로' => ['/opt/plesk/php/8.2/bin/php', '패널 환경'],
            'Windows 드라이브' => ['C:\\php\\php.exe', 'Windows'],
            'Windows 슬래시' => ['C:/php/php.exe', 'Windows 정방향 슬래시'],
            'UNC 경로' => ['\\\\server\\share\\php.exe', '네트워크 공유'],
            '점 포함 디렉토리' => ['/usr/local/php.d/bin/php', '단일 점은 상위 참조가 아님'],
        ];
    }

    /**
     * 거부해야 하는 토큰 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function shapeRejectedProvider(): array
    {
        return [
            '빈 문자열' => ['', '입력 없음'],
            '하이픈 선두 (-r)' => ['-rphpinfo();', '코드 실행 플래그 — 이번 취약점'],
            '하이픈 선두 (-d)' => ['-dauto_prepend_file=/tmp/x', 'ini 주입'],
            '롱옵션' => ['--define=x', '옵션'],
            '상대경로' => ['bin/composer', 'CWD 의존'],
            '단순 이름' => ['evilbinary', 'bare 허용은 php/composer 뿐'],
            '부모 참조' => ['/usr/local/../../tmp/composer', '.. 세그먼트'],
            '부모 참조 (윈도우 구분자)' => ['C:\\php\\..\\..\\tmp\\evil.exe', '.. 세그먼트'],
            '해시 주석' => ['/usr/bin/php#.phar', '접미사 위장'],
            '공백 포함' => ['/usr/bin/my php', '토큰 분리 불가'],
            '세미콜론' => ['/usr/bin/php;id', '셸 메타문자'],
            '백틱' => ['/usr/bin/`id`', '셸 메타문자'],
            '달러 괄호' => ['/usr/bin/$(id)', '셸 메타문자'],
            '따옴표' => ['/usr/bin/"php"', '셸 메타문자'],
            '개행' => ["/usr/bin/php\nid", '제어문자'],
            'NUL 바이트' => ["/usr/bin/php\x00", '제어문자'],
        ];
    }

    /**
     * Composer 자리 판정 목록.
     *
     * @return array<string, array{string, bool, string}>
     */
    public static function composerSlotProvider(): array
    {
        return [
            'composer' => ['composer', true, 'bare 기본'],
            '절대경로 composer' => ['/usr/local/bin/composer', true, '일반 설치'],
            'composer2' => ['/usr/local/bin/composer2', true, '버전 붙은 이름'],
            'composer.phar' => ['/opt/composer.phar', true, 'phar 아카이브'],
            '임의 이름 phar' => ['/opt/tools/mytool.phar', true, 'phar 는 이름 무관 허용'],
            'composer.bat' => ['C:\\laragon\\bin\\composer\\composer.bat', true, 'Windows 배치'],
            'composer.exe' => ['C:\\tools\\composer.exe', true, 'Windows 실행 파일'],
            '임의 스크립트' => ['/tmp/evil.py', false, '인터프리터가 실행할 임의 스크립트 — 이번 취약점'],
            '임의 PHP 스크립트' => ['/tmp/evil.php', false, '임의 PHP 스크립트'],
            '셸 스크립트' => ['/tmp/evil.sh', false, '임의 스크립트'],
            'composer 접두 위장' => ['/tmp/composer-evil.sh', false, 'basename 완전 일치가 아님'],
            '하이픈 선두' => ['-rphpinfo();', false, '옵션'],
        ];
    }

    /**
     * PHP 자리에 허용되어야 하는 이름 목록.
     *
     * @return array<string, array{string}>
     */
    public static function phpSlotNameProvider(): array
    {
        return [
            '래퍼 스크립트' => ['/usr/local/bin/php-8.2-wrapper.sh'],
            '커스텀 이름' => ['/opt/mycompany/run-php'],
            '버전 접미사' => ['/usr/bin/php8.3'],
            'DSM 경로' => ['/usr/local/php84/bin/php'],
            'Windows exe' => ['C:\\php\\php.exe'],
        ];
    }

    /**
     * 2토큰 해석 목록.
     *
     * @return array<string, array{string, array{php: string, composer: string}|null, string}>
     */
    public static function pairProvider(): array
    {
        return [
            '멀티 PHP 정상' => [
                '/usr/local/php84/bin/php /usr/local/bin/composer.phar',
                ['php' => '/usr/local/php84/bin/php', 'composer' => '/usr/local/bin/composer.phar'],
                '운영 패턴',
            ],
            '래퍼 + composer' => [
                '/usr/local/bin/php-8.2-wrapper.sh /usr/local/bin/composer',
                ['php' => '/usr/local/bin/php-8.2-wrapper.sh', 'composer' => '/usr/local/bin/composer'],
                'PHP 자리 이름 무제한',
            ],
            'Windows 2토큰' => [
                'C:\\php\\php.exe D:\\composer\\composer.phar',
                ['php' => 'C:\\php\\php.exe', 'composer' => 'D:\\composer\\composer.phar'],
                'Windows',
            ],
            '인자 주입 (-r)' => ['/usr/bin/php -rphpinfo();', null, '둘째 토큰이 코드 실행 플래그'],
            '인터프리터 + 스크립트' => ['/usr/bin/python3 /tmp/evil.py', null, '둘째 토큰이 임의 스크립트'],
            'PHP 자리 옵션' => ['-rphpinfo(); /usr/bin/composer', null, '첫 토큰이 옵션'],
            '공백 없음' => ['/usr/bin/composer', null, '2토큰 입력이 아님'],
            '공백 포함 디렉토리' => [
                'C:\\Program Files\\PHP\\php.exe C:\\composer\\composer.phar',
                null,
                '#361 이 명시한 기존 known_limitation — 악화가 아님',
            ],
        ];
    }
}
