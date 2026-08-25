<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 「이미 설치/활성화」 판정의 오탐 회귀 가드 (21차 #21-3)
 *
 * 확장 install/activate 태스크는 exit code ≠ 0 이어도 출력에 「이미 설치」 류 문구가 있으면
 * 성공으로 접는다(재시도 멱등성). 종전 판정은 stdout+stderr **전문에 대한 부분문자열**이라,
 * 의존성 충돌 메시지(`Dependency "x" is already installed at version 1.2, but … Aborting.`)
 * 처럼 문구가 **문장 중간**에 나타나는 실패도 성공으로 뒤집혔다 — 설치는 초록으로 끝나고
 * 그 확장은 어디에도 없으며, 로그에는 「건너뜀」만 남는다. 같은 판정이 확장 8종
 * (template/module/plugin/user_template × install/activate)을 전부 나른다.
 *
 * 실제 코어 커맨드의 문구(lang/{ko,en}/modules·plugins·templates.php)는 전부 **문장 끝**에
 * 그 구가 온다 — 판정을 줄 끝 앵커로 좁히면 실 메시지는 그대로 잡고 중간 삽입 오탐은 떨어진다.
 *
 * @effects installer_already_applied_judgment_rejects_mid_sentence_matches
 */
class InstallerAlreadyAppliedJudgmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    public function test_real_command_messages_are_recognized(): void
    {
        $this->assertTrue(installerOutputConfirmsAlreadyApplied('⚠️  모듈 "sirsoft-board"이(가) 이미 설치되어 있습니다.'));
        $this->assertTrue(installerOutputConfirmsAlreadyApplied('모듈 "sirsoft-board"은(는) 이미 활성화되어 있습니다.'));
        $this->assertTrue(installerOutputConfirmsAlreadyApplied('Module "sirsoft-board" is already installed.'));
        $this->assertTrue(installerOutputConfirmsAlreadyApplied('Plugin "sirsoft-gdpr" is already active.'));
        $this->assertTrue(installerOutputConfirmsAlreadyApplied("some noise\nTemplate is already installed.\n"));
    }

    public function test_mid_sentence_occurrence_is_not_success(): void
    {
        $this->assertFalse(
            installerOutputConfirmsAlreadyApplied('Dependency "sirsoft-board" is already installed at version 1.2, but this module requires ^2.0. Aborting.'),
            '의존성 충돌 실패가 「이미 설치」 성공으로 뒤집히면 확장이 없는 채 설치가 완료된다 (#21-3)'
        );
        $this->assertFalse(
            installerOutputConfirmsAlreadyApplied('확장 "x"가 이미 설치되어 있습니다만, 버전이 맞지 않아 중단합니다.'),
            '문장 중간의 구는 성공 확인이 아니다'
        );
        $this->assertFalse(installerOutputConfirmsAlreadyApplied(''));
    }

    /**
     * 22차 N-1: 코어의 「이미 …」 문구 **전수**(파생 모집단)가 판정을 통과해야 한다.
     *
     * 21차 앵커 수정이 `…되어 있습니다` 계열만 남기고 `이미 활성화된 템플릿입니다.`
     * (templates.errors — TemplateManager 예외 경로가 실제로 내보내는 문구) 계열을
     * 놓쳤다 — KO 로케일(이 제품의 기본값)에서 템플릿 재시도가 하드 실패로 바뀌는
     * 역방향 회귀였고, 수기 케이스 목록이 그것을 통과시켰다. 모집단을
     * lang/{ko,en}/{modules,plugins,templates}.php 의 `already_installed`/`already_active`
     * 키에서 **도출**한다 (§16.25.10 규약 ② — 새 문구가 추가되면 이 게이트가 자동 요구).
     */
    public function test_every_core_already_message_is_recognized(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $offenders = [];
        $checked = 0;

        foreach (['ko', 'en'] as $locale) {
            foreach (['modules', 'plugins', 'templates'] as $domain) {
                $file = $projectRoot.'/lang/'.$locale.'/'.$domain.'.php';
                if (! is_file($file)) {
                    continue;
                }
                $table = include $file;
                foreach ($this->collectAlreadyMessages((array) $table) as $key => $message) {
                    $checked++;
                    // 커맨드 출력 형태 재현: 자리표시자 치환 + 접두 기호(warn/error 출력).
                    $rendered = '❌ '.str_replace([':module', ':plugin', ':template'], 'sirsoft-x', $message);
                    if (! installerOutputConfirmsAlreadyApplied($rendered)) {
                        $offenders[] = $locale.'/'.$domain.'.'.$key.' → '.$message;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(8, $checked, '모집단 도출 실패 — already 키가 이보다 적을 수 없다');
        $this->assertSame([], $offenders, "판정이 놓치는 코어 「이미 …」 문구 (22차 N-1 — 그 경로의 재시도가 하드 실패가 된다):\n".implode("\n", $offenders));
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, string>
     */
    private function collectAlreadyMessages(array $table, string $path = ''): array
    {
        $found = [];

        foreach ($table as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value)) {
                $found += $this->collectAlreadyMessages($value, $keyPath);

                continue;
            }

            if (is_string($value) && preg_match('/(^|\.)already_(installed|active)$/', $keyPath) === 1) {
                $found[$keyPath] = $value;
            }
        }

        return $found;
    }

    /**
     * 배선: 태스크 러너가 인라인 패턴 루프가 아니라 이 판정을 경유한다.
     */
    public function test_task_runner_routes_through_judgment(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php');

        $this->assertStringContainsString('installerOutputConfirmsAlreadyApplied(', $source, '태스크 러너가 판정 SSoT 를 경유하지 않는다 (#21-3)');
        $this->assertStringNotContainsString('stripos($outputText, $pattern)', $source, '무경계 부분문자열 판정이 남아 있다 (#21-3)');
    }
}
