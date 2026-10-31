<?php

namespace Tests\Unit\Installer;

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
     * @return array{general: bool, finalize: bool} 일반 가드·finalize 가드 판정
     */
    private function judge(string $env): array
    {
        file_put_contents($this->installerTempBase.'/.env', $env);

        $result = $this->runInstallerScript(
            "require_once \$projectRoot.'/public/install/api/_guard.php';"
            ."echo json_encode(['general' => installer_is_completed(), 'finalize' => installer_finalize_is_completed()]);"
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
}
