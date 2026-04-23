<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\UpgradeHandoffException;
use Tests\TestCase;

/**
 * UpgradeHandoffException 단위 테스트
 *
 * 업그레이드 스텝이 새 PHP 프로세스 재진입을 요청할 때 던지는 sentinel exception.
 * 본 예외는 7.0.0-beta.3 에서 신설되었으며, 상위 계층(CoreUpdateCommand,
 * ExecuteUpgradeStepsCommand, spawnUpgradeStepsProcess) 이 이 예외의 필드를
 * 계약대로 읽어 동작하므로 필드/상수/메시지 계약을 고정한다.
 */
class UpgradeHandoffExceptionTest extends TestCase
{
    /**
     * EXIT_CODE 는 spawn 체인의 핸드오프 판정에 쓰이는 고정 값(sysexits.h EX_TEMPFAIL=75).
     * 이 값이 변경되면 spawnUpgradeStepsProcess 의 판정 로직과 어긋나므로 고정.
     */
    public function test_exit_code_is_75(): void
    {
        $this->assertSame(75, UpgradeHandoffException::EXIT_CODE);
    }

    /**
     * 필수 필드(afterVersion, reason) 는 readonly 로 전달받아 그대로 노출.
     */
    public function test_stores_required_fields_as_readonly(): void
    {
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '신설 메서드 미로드',
        );

        $this->assertSame('7.0.0-beta.2', $exception->afterVersion);
        $this->assertSame('신설 메서드 미로드', $exception->reason);
    }

    /**
     * resumeCommand 는 nullable — 생략 시 null.
     * CoreUpdateCommand catch 시점에 from/to 버전 기반으로 자동 생성된다.
     */
    public function test_resume_command_defaults_to_null(): void
    {
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '테스트',
        );

        $this->assertNull($exception->resumeCommand);
    }

    /**
     * resumeCommand 를 명시 전달하면 그대로 저장.
     */
    public function test_resume_command_can_be_overridden(): void
    {
        $custom = 'php artisan custom:command --flag';
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '테스트',
            resumeCommand: $custom,
        );

        $this->assertSame($custom, $exception->resumeCommand);
    }

    /**
     * 예외 메시지는 다국어(`exceptions.core_update.handoff`) 로부터 빌드되며
     * 필드 값이 치환된다. 현재 locale 에 맞는 언어로 빌드된다.
     */
    public function test_message_contains_field_values(): void
    {
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '고유한_사유_마커',
            resumeCommand: 'php artisan some:cmd',
        );

        $message = $exception->getMessage();

        // 필드 값 3가지가 메시지에 모두 포함되어야 한다 (한/영 메시지 공통)
        $this->assertStringContainsString('7.0.0-beta.2', $message);
        $this->assertStringContainsString('고유한_사유_마커', $message);
        $this->assertStringContainsString('php artisan some:cmd', $message);
    }

    /**
     * resumeCommand 가 null 인 경우 메시지에는 placeholder 문자열이 치환된다.
     * 예외 메시지는 주로 로그/디버깅용이며, 사용자에게 노출되는 실제 재실행 명령은
     * CoreUpdateCommand 가 별도로 생성해 출력한다.
     */
    public function test_message_uses_placeholder_when_resume_command_is_null(): void
    {
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '테스트',
        );

        $message = $exception->getMessage();
        $this->assertStringContainsString('(auto)', $message);
    }

    /**
     * RuntimeException 상속 — catch 계층이 \Throwable / \RuntimeException 양쪽에서
     * 포착 가능해야 한다. 본 계약이 깨지면 상위 fallback catch 경로가 누락된다.
     */
    public function test_extends_runtime_exception(): void
    {
        $exception = new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '테스트',
        );

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }
}
