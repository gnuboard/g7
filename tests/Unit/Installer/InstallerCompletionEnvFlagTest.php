<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 완료 판정(.env INSTALLER_COMPLETED) 회귀 테스트.
 *
 * 결함: 완료 판정이 parse_ini_file 로 .env 를 읽었는데, 배포 .env.example 에는 INI 문법으로
 * 해석되지 않는 주석(괄호 포함)이 있어 파싱이 통째로 실패했다. 그 결과 `.env` 플래그만 보는
 * finalize 계열 가드(finalize-env·state-management)가 설치 완료 후에도 열려 있었다
 * (브라우저 실측: 완료 후 finalize-env POST 200, state-management reclaim 이 410 대신 CSRF 판정까지 진행).
 */
class InstallerCompletionEnvFlagTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 루트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeInstallerTempBase();
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 임시 루트에 .env 를 쓰고 두 완료 판정 결과를 돌려준다.
     *
     * @param  string  $env  .env 본문
     * @return array{general: bool, finalize: bool, screen: bool} 일반 가드·finalize 가드·설치 화면 판정
     */
    private function judge(string $env): array
    {
        file_put_contents($this->installerTempBase.'/.env', $env);

        $result = $this->runInstallerScript(
            "require_once \$projectRoot.'/public/install/api/_guard.php';"
            ."echo json_encode(['general' => installer_is_completed(), 'finalize' => installer_finalize_is_completed(), 'screen' => isInstallationCompleted()]);"
        );

        return json_decode(strtok($result['raw'], "\n"), true);
    }

    /**
     * 배포 .env.example 그대로에 완료 플래그가 붙으면 두 가드 모두 완료로 판정한다.
     *
     * @effects completion_flag_read_despite_non_ini_comments
     *
     * @return void 반환값 없음
     */
    public function test_real_env_example_with_completed_flag_is_completed(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 3).'/.env.example');
        $this->assertMatchesRegularExpression('/^#[^\n]*\(/m', $example, '전제: 배포 템플릿에 INI 로 해석되지 않는 괄호 주석이 있다');

        $verdict = $this->judge(rtrim($example)."\n\n# Installation Status\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue($verdict['finalize'], 'finalize 계열 가드가 열려 있으면 완료 후 finalize·state-management 가 동작한다');
        $this->assertTrue($verdict['general']);
    }

    /**
     * 참 값 변형(따옴표·1·yes·대소문자)을 모두 완료로 본다.
     *
     * @effects completion_flag_read_despite_non_ini_comments
     *
     * @return void 반환값 없음
     */
    public function test_truthy_variants_are_completed(): void
    {
        foreach (['true', '"true"', "'TRUE'", '1', 'yes', ' true '] as $value) {
            $this->assertTrue($this->judge("# 주석 (괄호)\nAPP_ENV=local\nINSTALLER_COMPLETED={$value}\n")['finalize'], $value);
        }
    }

    /**
     * 거짓 값·주석 처리된 플래그·플래그 없음은 완료가 아니다 (설치 진행 중 오차단 금지).
     *
     * @effects completion_flag_read_despite_non_ini_comments
     *
     * @return void 반환값 없음
     */
    public function test_false_commented_or_missing_flag_is_not_completed(): void
    {
        foreach ([
            "INSTALLER_COMPLETED=false\n",
            "# INSTALLER_COMPLETED=true\n",
            "APP_ENV=local\n",
            "MY_INSTALLER_COMPLETED=true\n",
        ] as $env) {
            $this->assertFalse($this->judge("# 주석 (괄호)\n".$env)['finalize'], $env);
        }
    }

    /**
     * 인스톨러 가드 둘·설치 화면·앱 진입 파일(public/index.php)이 같은 `.env` 에 같은 답을 낸다.
     *
     * 판정이 INI 파서를 먼저 쓰면 `;` 뒤를 주석으로 잘라 `.env` 로더와 다른 값을 읽는다. 앱 진입
     * 파일은 `=true` 만 보던 탓에 `=1`·CRLF·등호 주변 공백을 미설치로 읽어, 인스톨러가 410 으로 닫힌
     * 사이트를 설치 화면으로 보냈다.
     *
     * @param  string  $env  .env 본문
     * @param  bool  $expected  완료로 읽혀야 하는가
     *
     * @effects completion_flag_read_despite_non_ini_comments
     *
     * @return void 반환값 없음
     */
    #[DataProvider('parityCases')]
    public function test_every_completion_reader_agrees(string $env, bool $expected): void
    {
        $verdict = $this->judge($env);

        $this->assertSame($expected, $verdict['general'], '일반 가드');
        $this->assertSame($expected, $verdict['finalize'], 'finalize 가드');
        $this->assertSame($expected, $verdict['screen'], '설치 화면(installer-state)');
        $this->assertSame($expected, $this->frontControllerVerdict($env), '앱 진입 파일(public/index.php)');
    }

    /**
     * 판정이 갈릴 수 있는 `.env` 형태.
     *
     * @return array<string, array{0: string, 1: bool}> 사례 이름별 .env 본문과 기대 판정
     */
    public static function parityCases(): array
    {
        return [
            '괄호 주석 + 1' => ["# 주석 (괄호)\nAPP_ENV=local\nINSTALLER_COMPLETED=1\n", true],
            'CRLF' => ["APP_ENV=local\r\nINSTALLER_COMPLETED=true\r\n", true],
            '등호 주변 공백 + yes' => ["INSTALLER_COMPLETED = yes\n", true],
            '세미콜론 꼬리는 값의 일부' => ["INSTALLER_COMPLETED=true ; memo\n", false],
            '같은 키는 마지막 줄' => ["INSTALLER_COMPLETED=false\nINSTALLER_COMPLETED=true\n", true],
            '마지막 줄이 거짓' => ["INSTALLER_COMPLETED=true\nINSTALLER_COMPLETED=false\n", false],
            '주석 처리' => ["# INSTALLER_COMPLETED=true\n", false],
        ];
    }

    /**
     * public/index.php 의 설치 판정 구간을 그대로 떼어 임시 `.env` 로 평가한다 (사본을 만들지 않는다).
     *
     * @param  string  $env  .env 본문
     * @return bool 그 구간이 낸 설치 완료 판정
     */
    private function frontControllerVerdict(string $env): bool
    {
        // meta:allow raw-source-read — 구간을 가르는 표식(`// region:install-flag`)이 주석이라 원문이 필요하다. 글자를 대조하지 않고 그 구간을 실행해 판정한다.
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/index.php');
        $start = strpos($source, '// region:install-flag');
        $end = strpos($source, '// endregion:install-flag');
        $this->assertNotFalse($start, 'public/index.php 에 설치 판정 구간 표식이 없다');
        $this->assertNotFalse($end, 'public/index.php 에 설치 판정 구간 끝 표식이 없다');
        $region = substr($source, (int) $start, (int) $end - (int) $start);

        $envPath = $this->installerTempBase.'/front-controller.env';
        file_put_contents($envPath, $env);

        $evaluate = static function (string $installedFlagPath, string $envPath, string $code): bool {
            $isInstalled = false;
            eval($code);

            return $isInstalled;
        };

        return $evaluate($this->installerTempBase.'/storage/app/g7_installed', $envPath, $region);
    }
}
