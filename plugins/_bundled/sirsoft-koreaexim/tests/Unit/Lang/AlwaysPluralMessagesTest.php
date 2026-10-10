<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit\Lang;

use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

/**
 * 괄호 없이 늘 복수로 적혀 있던 한국수출입은행 환율 플러그인 서버 영어 문구의 단·복수 테스트.
 *
 * 실행 이력 정리 명령 출력 「Pruned 1 runs.」 를 `단수|복수` + `trans_choice` 로 1 이면 단수로 고른다.
 * 한국어 출력은 그대로다.
 */
class AlwaysPluralMessagesTest extends PluginTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-koreaexim', base_path('plugins/_bundled/sirsoft-koreaexim/lang'));
    }

    /**
     * 실행 이력 정리 명령은 지운 실행이 1 건이면 「Pruned 1 run.」 이다.
     *
     * @scenario surface=console_output
     *
     * @effects console_output_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function prune_history_command_output_is_singular_for_one(): void
    {
        foreach ([1 => 'Pruned 1 run.', 2 => 'Pruned 2 runs.'] as $pruned => $expected) {
            $runs = \Mockery::mock(RateRunRepositoryInterface::class);
            $runs->shouldReceive('prune')->andReturn($pruned);
            $this->app->instance(RateRunRepositoryInterface::class, $runs);
            $this->app->setLocale('en');

            $this->artisan('sirsoft-koreaexim:prune-history')->expectsOutput($expected)->assertSuccessful();
        }

        $this->app->setLocale('ko');
        $this->assertSame(
            __('sirsoft-koreaexim::messages.pruned', ['count' => 1]),
            trans_choice('sirsoft-koreaexim::messages.pruned', 1)
        );
    }
}
