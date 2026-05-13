<?php

namespace Tests\Unit\Console;

use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * language-pack:check-updates 콘솔 커맨드 회귀 테스트.
 *
 * 본 커맨드는 LanguagePackService::checkUpdates() 에 위임하므로 위임 동작과
 * 출력 포맷이 회귀 없이 유지되는지 검증합니다.
 */
class CheckLanguagePackUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 정리.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_outputs_no_packs_message_when_empty(): void
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('checkUpdates')
            ->once()
            ->with(null)
            ->andReturn(['checked' => 0, 'updates' => 0, 'details' => []]);
        $this->app->instance(LanguagePackService::class, $service);

        $this->artisan('language-pack:check-updates')
            ->expectsOutputToContain('GitHub 기반 언어팩이 없습니다')
            ->assertSuccessful();
    }

    public function test_renders_each_detail_line(): void
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('checkUpdates')
            ->once()
            ->with(null)
            ->andReturn([
                'checked' => 2,
                'updates' => 1,
                'details' => [
                    ['identifier' => 'foo-core-ja', 'current' => '1.0.0', 'latest' => '1.1.0', 'has_update' => true, 'error' => null],
                    ['identifier' => 'foo-core-de', 'current' => '1.0.0', 'latest' => '1.0.0', 'has_update' => false, 'error' => null],
                ],
            ]);
        $this->app->instance(LanguagePackService::class, $service);

        $this->artisan('language-pack:check-updates')
            ->expectsOutputToContain('foo-core-ja')
            ->expectsOutputToContain('foo-core-de')
            ->assertSuccessful();
    }

    public function test_passes_identifier_option(): void
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('checkUpdates')
            ->once()
            ->with('foo-core-ja')
            ->andReturn(['checked' => 0, 'updates' => 0, 'details' => []]);
        $this->app->instance(LanguagePackService::class, $service);

        $this->artisan('language-pack:check-updates', ['--identifier' => 'foo-core-ja'])
            ->assertSuccessful();
    }
}
