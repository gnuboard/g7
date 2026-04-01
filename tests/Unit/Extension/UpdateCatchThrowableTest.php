<?php

namespace Tests\Unit\Extension;

use Tests\TestCase;

/**
 * 확장 업데이트 catch 블록이 \Throwable을 잡는지 검증
 *
 * PHP의 \Error는 \Exception의 하위 클래스가 아니므로
 * catch (\Exception)으로는 잡을 수 없습니다.
 * 업데이트 프로세스에서 \Error 발생 시 상태 복원이 누락되는
 * 버그를 방지하기 위해 catch (\Throwable)로 변경되었습니다.
 *
 * @see https://www.php.net/manual/en/class.error.php
 */
class UpdateCatchThrowableTest extends TestCase
{
    /**
     * \Error는 \Throwable이지만 \Exception은 아님을 검증
     */
    public function test_php_error_is_throwable_but_not_exception(): void
    {
        $error = new \Error('Test error');

        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertNotInstanceOf(\Exception::class, $error);
    }

    /**
     * catch (\Throwable)가 \Error를 잡는지 검증
     */
    public function test_catch_throwable_catches_error(): void
    {
        $caught = false;

        try {
            throw new \Error('Non-static method called statically');
        } catch (\Throwable $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'catch (\\Throwable) should catch \\Error');
    }

    /**
     * catch (\Exception)가 \Error를 잡지 못하는지 검증 (이전 버그 재현)
     */
    public function test_catch_exception_does_not_catch_error(): void
    {
        $caught = false;
        $uncaught = false;

        try {
            try {
                throw new \Error('Non-static method called statically');
            } catch (\Exception $e) {
                $caught = true;
            }
        } catch (\Error $e) {
            $uncaught = true;
        }

        $this->assertFalse($caught, 'catch (\\Exception) should NOT catch \\Error');
        $this->assertTrue($uncaught, '\\Error should propagate past catch (\\Exception)');
    }

    /**
     * ModuleManager::updateModule의 외부 catch가 \Throwable을 사용하는지 검증
     */
    public function test_module_manager_update_uses_throwable_catch(): void
    {
        $reflection = new \ReflectionMethod(
            \App\Extension\ModuleManager::class,
            'updateModule'
        );

        $source = file_get_contents($reflection->getFileName());
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $methodLines = array_slice(
            explode("\n", $source),
            $startLine - 1,
            $endLine - $startLine + 1
        );
        $methodSource = implode("\n", $methodLines);

        // 외부 catch 블록이 \Throwable을 사용하는지 확인
        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $methodSource,
            'ModuleManager::updateModule의 외부 catch 블록은 \\Throwable을 사용해야 합니다'
        );
    }

    /**
     * PluginManager::updatePlugin의 외부 catch가 \Throwable을 사용하는지 검증
     */
    public function test_plugin_manager_update_uses_throwable_catch(): void
    {
        $reflection = new \ReflectionMethod(
            \App\Extension\PluginManager::class,
            'updatePlugin'
        );

        $source = file_get_contents($reflection->getFileName());
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $methodLines = array_slice(
            explode("\n", $source),
            $startLine - 1,
            $endLine - $startLine + 1
        );
        $methodSource = implode("\n", $methodLines);

        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $methodSource,
            'PluginManager::updatePlugin의 외부 catch 블록은 \\Throwable을 사용해야 합니다'
        );
    }

    /**
     * TemplateManager::updateTemplate의 외부 catch가 \Throwable을 사용하는지 검증
     */
    public function test_template_manager_update_uses_throwable_catch(): void
    {
        $reflection = new \ReflectionMethod(
            \App\Extension\TemplateManager::class,
            'updateTemplate'
        );

        $source = file_get_contents($reflection->getFileName());
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $methodLines = array_slice(
            explode("\n", $source),
            $startLine - 1,
            $endLine - $startLine + 1
        );
        $methodSource = implode("\n", $methodLines);

        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $methodSource,
            'TemplateManager::updateTemplate의 외부 catch 블록은 \\Throwable을 사용해야 합니다'
        );
    }
}
