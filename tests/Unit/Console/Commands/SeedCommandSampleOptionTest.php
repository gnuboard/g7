<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\Module\SeedModuleCommand;
use App\Console\Commands\Plugin\SeedPluginCommand;
use App\Console\Commands\SeedCommand;
use Tests\TestCase;

/**
 * db:seed, module:seed, plugin:seed 커맨드의 --sample 옵션 테스트
 *
 * 각 커맨드에 --sample 옵션이 등록되어 있고,
 * 옵션 지정 유무에 따라 정상 동작하는지 검증합니다.
 */
class SeedCommandSampleOptionTest extends TestCase
{
    /**
     * module:seed 커맨드에 --sample 옵션이 등록되어 있습니다.
     */
    public function test_module_seed_has_sample_option(): void
    {
        $command = app(SeedModuleCommand::class);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('sample'));
        $this->assertFalse($definition->getOption('sample')->acceptValue());
    }

    /**
     * plugin:seed 커맨드에 --sample 옵션이 등록되어 있습니다.
     */
    public function test_plugin_seed_has_sample_option(): void
    {
        $command = app(SeedPluginCommand::class);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('sample'));
        $this->assertFalse($definition->getOption('sample')->acceptValue());
    }

    /**
     * db:seed 커맨드에 --sample 옵션이 등록되어 있습니다.
     */
    public function test_db_seed_has_sample_option(): void
    {
        $command = app(SeedCommand::class);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('sample'));
        $this->assertFalse($definition->getOption('sample')->acceptValue());
    }

    /**
     * module:seed --sample 옵션 없이 실행해도 파싱 오류가 발생하지 않습니다.
     */
    public function test_module_seed_without_sample_option(): void
    {
        $this->artisan('module:seed', [
            'identifier' => 'nonexistent-module',
            '--no-interaction' => true,
        ])->assertFailed(); // 모듈이 없어서 실패하지만 옵션 관련 오류는 아님
    }

    /**
     * module:seed --sample 옵션과 함께 실행해도 파싱 오류가 발생하지 않습니다.
     */
    public function test_module_seed_with_sample_option(): void
    {
        $this->artisan('module:seed', [
            'identifier' => 'nonexistent-module',
            '--sample' => true,
            '--no-interaction' => true,
        ])->assertFailed(); // 모듈이 없어서 실패하지만 --sample 파싱 오류는 아님
    }
}
