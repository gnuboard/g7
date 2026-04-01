<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Helpers\ExtensionStatusGuard;
use Tests\TestCase;

class ExtensionStatusGuardTest extends TestCase
{
    /**
     * inactive 상태에서 예외가 발생하지 않는지 확인합니다.
     */
    public function test_allows_operation_when_status_is_inactive(): void
    {
        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Inactive, 'test-module');

        $this->assertTrue(true); // 예외 미발생 확인
    }

    /**
     * active 상태에서 예외가 발생하지 않는지 확인합니다.
     */
    public function test_allows_operation_when_status_is_active(): void
    {
        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Active, 'test-module');

        $this->assertTrue(true);
    }

    /**
     * uninstalled 상태에서 예외가 발생하지 않는지 확인합니다.
     */
    public function test_allows_operation_when_status_is_uninstalled(): void
    {
        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Uninstalled, 'test-module');

        $this->assertTrue(true);
    }

    /**
     * installing 상태에서 예외가 발생하는지 확인합니다.
     */
    public function test_blocks_operation_when_status_is_installing(): void
    {
        $this->expectException(\RuntimeException::class);

        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Installing, 'test-module');
    }

    /**
     * uninstalling 상태에서 예외가 발생하는지 확인합니다.
     */
    public function test_blocks_operation_when_status_is_uninstalling(): void
    {
        $this->expectException(\RuntimeException::class);

        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Uninstalling, 'test-module');
    }

    /**
     * updating 상태에서 예외가 발생하는지 확인합니다.
     */
    public function test_blocks_operation_when_status_is_updating(): void
    {
        $this->expectException(\RuntimeException::class);

        ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Updating, 'test-module');
    }

    /**
     * 예외 메시지에 식별자와 상태가 포함되는지 확인합니다.
     */
    public function test_exception_message_contains_status_and_name(): void
    {
        try {
            ExtensionStatusGuard::assertNotInProgress(ExtensionStatus::Installing, 'sirsoft-board');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sirsoft-board', $e->getMessage());
            $this->assertStringContainsString('installing', $e->getMessage());
        }
    }
}
