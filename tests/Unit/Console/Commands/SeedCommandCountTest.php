<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\SeedCommand;
use Tests\TestCase;

/**
 * SeedCommand --count 옵션 테스트
 *
 * db:seed 커맨드의 --count 옵션 등록 및 파싱 로직을 검증합니다.
 */
class SeedCommandCountTest extends TestCase
{
    /**
     * --count 옵션이 커맨드 정의에 등록되어 있습니다.
     */
    public function test_count_option_is_registered(): void
    {
        $command = new SeedCommand(app('db'));
        $command->setLaravel(app());
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('count'));
    }

    /**
     * --count 옵션이 배열 타입으로 등록되어 있습니다.
     */
    public function test_count_option_is_array_type(): void
    {
        $command = new SeedCommand(app('db'));
        $command->setLaravel(app());
        $option = $command->getDefinition()->getOption('count');

        $this->assertTrue($option->isArray());
    }

    /**
     * --count 옵션의 기본값이 빈 배열입니다.
     */
    public function test_count_option_default_is_empty_array(): void
    {
        $command = new SeedCommand(app('db'));
        $command->setLaravel(app());
        $option = $command->getDefinition()->getOption('count');

        $this->assertEquals([], $option->getDefault());
    }

    /**
     * parseCountOptions 메서드가 존재합니다.
     */
    public function test_parse_count_options_method_exists(): void
    {
        $command = new SeedCommand(app('db'));

        $this->assertTrue(method_exists($command, 'parseCountOptions'));
    }
}
