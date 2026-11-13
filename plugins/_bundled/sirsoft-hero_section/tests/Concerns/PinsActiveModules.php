<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Concerns;

use Plugins\Sirsoft\HeroSection\Listeners\BuiltinPositionListener;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 켜진 모듈을 시험에서 고정한다 — 내장 위치 리스너가 이 목록으로 동적 위치(게시판·상품·카테고리)를 등록한다.
 *
 * 시험 DB 의 실제 모듈 상태(설치·활성)에 기대지 않게 한다. 레지스트리는 요청 단위로 위치를 기억하므로 바꿀 때마다 비운다.
 */
trait PinsActiveModules
{
    /**
     * 켜진 모듈을 정합니다.
     *
     * @param  array<int, string>  $modules  켜진 모듈 식별자
     * @return void 반환값 없음
     */
    protected function pinActiveModules(array $modules): void
    {
        $this->app->instance(BuiltinPositionListener::class, new class($modules) extends BuiltinPositionListener
        {
            /**
             * 시험용 켜진 모듈을 받습니다.
             *
             * @param  array<int, string>  $modules  켜진 모듈 식별자
             */
            public function __construct(private array $modules) {}

            /**
             * 시험용 켜진 모듈을 돌려줍니다.
             *
             * @return array<int, string> 켜진 모듈 식별자
             */
            protected function activeModules(): array
            {
                return $this->modules;
            }
        });
        $this->app->forgetInstance(PositionRegistry::class);
    }
}
