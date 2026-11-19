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
     * 모듈 조회 계약(게시판 `BoardDirectory`, 이커머스 `ProductDirectory`·`CategoryDirectory`)도 바인딩된 것으로 고정한다 — 시험 DB 에
     * 모듈이 설치돼 있지 않아도 위치가 생긴다. 계약이 없는 옛 모듈 판을 흉내 내려면 `$contractsBound` 를 false 로 준다(#916).
     *
     * @param  array<int, string>  $modules  켜진 모듈 식별자
     * @param  bool  $contractsBound  모듈 조회 계약이 바인딩됐는지
     * @return void 반환값 없음
     */
    protected function pinActiveModules(array $modules, bool $contractsBound = true): void
    {
        $this->app->instance(BuiltinPositionListener::class, new class($modules, $contractsBound) extends BuiltinPositionListener
        {
            /**
             * 시험용 켜진 모듈을 받습니다.
             *
             * @param  array<int, string>  $modules  켜진 모듈 식별자
             * @param  bool  $contractsBound  모듈 조회 계약이 바인딩됐는지
             */
            public function __construct(private array $modules, private bool $contractsBound) {}

            /**
             * 시험용 계약 바인딩 여부를 돌려줍니다.
             *
             * @param  string  $contract  계약 인터페이스 이름
             * @return bool 바인딩됐는지
             */
            protected function contractBound(string $contract): bool
            {
                return $this->contractsBound;
            }

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
