<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * CoreUpdateCommand 의 업그레이드 스텝 재실행 권한 안내 (renderResumeGuidance) 계약 테스트.
 *
 * 배경: sudo(root) 로 `core:update` 를 실행하면 업그레이드 스텝 spawn 자식이 root 로
 * 돌아가고, 그 자식이 실패하면 스텝이 미실행 상태로 남아 운영자에게 재실행을 안내한다.
 * 이때 실행 사용자 권한에 따라 4가지 경우로 분기해 안내해야 한다:
 *
 *  1. non_root           — 일반 SSH 사용자 / 공유 호스팅(웹서버=PHP=실행 유저) / posix 미지원
 *                          → 명령만 그대로. sudo -u 접두사·경고 없음.
 *  2. root_web_known     — root 실행 + 웹서버 계정 식별 가능
 *                          → `sudo -u {webUser}` + 계정명 명시 경고.
 *  3. root_web_symmetric — root 실행 + 웹서버 계정이 root 로 추정 (root 서비스 구성)
 *                          → 명령만 그대로.
 *  4. root_web_unknown   — root 실행 + 웹서버 계정 식별 불가
 *                          → `sudo -u <웹서버계정>` placeholder + 계정명 미상 경고.
 *
 * `classifyResumeExecutionContext()` 는 posix/파일시스템 상태에 의존하므로, 판정 결과를
 * 입력으로 받아 출력만 담당하는 `renderResumeGuidance()` 를 리플렉션으로 직접 검증한다.
 */
class CoreUpdateResumeGuidanceTest extends TestCase
{
    private const RESUME = 'php artisan core:execute-upgrade-steps --from=7.0.0 --to=7.0.1 --force';

    /**
     * 모드/유저를 입력해 renderResumeGuidance 를 실행하고 콘솔 출력 문자열을 반환한다.
     */
    private function render(string $mode, ?string $webUser): string
    {
        $command = app(CoreUpdateCommand::class);

        $input = new ArrayInput([]);
        $output = new BufferedOutput;
        $style = new OutputStyle($input, $output);

        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);
        $property->setValue($command, $style);

        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'renderResumeGuidance');
        $method->setAccessible(true);
        $method->invoke($command, self::RESUME, $mode, $webUser);

        return $output->fetch();
    }

    /**
     * 케이스 1: non_root — 명령만 그대로. sudo -u 접두사·경고 없음.
     */
    public function test_non_root_prints_command_verbatim_without_permission_warning(): void
    {
        $out = $this->render('non_root', null);

        $this->assertStringContainsString(self::RESUME, $out);
        $this->assertStringNotContainsString('sudo -u', $out);
        $this->assertStringNotContainsString('root', $out);
    }

    /**
     * 케이스 2: root_web_known — sudo -u {webUser} 접두사 + 계정명 명시 경고.
     */
    public function test_root_web_known_prefixes_sudo_and_warns_with_account_name(): void
    {
        $out = $this->render('root_web_known', 'www-data');

        $this->assertStringContainsString('sudo -u www-data '.self::RESUME, $out);
        // 계정명이 경고 문구에 노출되어야 한다
        $this->assertStringContainsString('www-data', $out);
        $this->assertStringContainsString('root', $out);
        // placeholder 는 나오면 안 된다 (계정 식별 성공 경로)
        $this->assertStringNotContainsString('<웹서버계정>', $out);
    }

    /**
     * 케이스 3: root_web_symmetric — root 서비스 구성 → 명령만 그대로.
     */
    public function test_root_web_symmetric_prints_command_verbatim(): void
    {
        $out = $this->render('root_web_symmetric', null);

        $this->assertStringContainsString(self::RESUME, $out);
        $this->assertStringNotContainsString('sudo -u', $out);
    }

    /**
     * 케이스 4: root_web_unknown — placeholder + 계정명 미상 경고.
     */
    public function test_root_web_unknown_uses_placeholder_and_generic_warning(): void
    {
        $out = $this->render('root_web_unknown', null);

        $this->assertStringContainsString('sudo -u <웹서버계정> '.self::RESUME, $out);
        $this->assertStringContainsString('식별하지 못했습니다', $out);
        // 대표 웹서버 계정 예시를 안내에 포함해 운영자가 직접 확인하도록 유도
        $this->assertStringContainsString('www-data', $out);
    }
}
