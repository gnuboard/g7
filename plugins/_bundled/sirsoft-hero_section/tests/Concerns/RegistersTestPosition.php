<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Concerns;

use App\Extension\HookManager;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;

/**
 * 시험용 동적 위치(`fake_hero`, 대상 = 가짜 해석기)를 위치 필터로 등록한다 — 제3 확장이 위치를 더하는 길과 같다.
 *
 * 모듈은 끈 상태로 고정한다(내장 동적 위치가 시험 DB 의 모듈 상태에 따라 바뀌지 않게). 훅은 시험마다 되돌려진다.
 */
trait RegistersTestPosition
{
    use PinsActiveModules;

    /**
     * 등록한 위치 필터 콜백 (거둘 때 그것만 뺀다)
     *
     * @var callable|null
     */
    private $testPositionFilter = null;

    /**
     * 시험용 동적 위치를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerTestPosition(): void
    {
        FakeTargetResolver::reset();
        $this->pinActiveModules([]);
        $this->testPositionFilter = fn (array $positions) => [
            ...$positions,
            ['key' => 'fake_hero', 'label' => ['ko' => '가짜 목록', 'en' => 'Fake list'], 'order' => 100,
                'target' => ['type' => 'fake', 'label' => ['ko' => '가짜', 'en' => 'Fake'], 'resolver' => FakeTargetResolver::class]],
        ];
        HookManager::addFilter(PositionRegistry::FILTER, $this->testPositionFilter);
    }

    /**
     * 시험용 동적 위치 등록을 거둡니다(확장을 끈 상황) — 레지스트리를 다시 모으게 비운다.
     *
     * @return void 반환값 없음
     */
    protected function unregisterTestPosition(): void
    {
        HookManager::removeFilter(PositionRegistry::FILTER, $this->testPositionFilter);
        $this->app->forgetInstance(PositionRegistry::class);
    }
}
