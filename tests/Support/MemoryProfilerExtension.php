<?php

namespace Tests\Support;

use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber as TestFinishedSubscriber;
use PHPUnit\Event\TestSuite\Finished as SuiteFinished;
use PHPUnit\Event\TestSuite\FinishedSubscriber as SuiteFinishedSubscriber;
use PHPUnit\Event\TestSuite\Started as SuiteStarted;
use PHPUnit\Event\TestSuite\StartedSubscriber as SuiteStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * 테스트 클래스별 메모리 증가분을 기록하는 진단 확장.
 *
 * 전량 스위트가 `memory_limit` 을 소진하고 중간에 죽으면 **그 뒤 결과 전부를 신호로 쓸 수 없다**.
 * 어느 테스트가 얼마나 남기는지 모르는 채 "의심 가는 곳 하나 고치고 재실행" 을 반복하면 매번
 * 사망 지점만 뒤로 밀린다(실제로 6,511 → 7,117 로 밀렸을 뿐 해소되지 않았다).
 *
 * 그래서 추측 대신 **클래스별 잔존 증가분**(스위트 종료 후 `gc_collect_cycles()` 를 돌리고도
 * 회수되지 않은 양)을 계측해 상위 누수원을 특정한다. 진단 전용이며 `PROFILE_MEMORY=1` 일 때만
 * 켜진다 — 평상시 실행에는 아무 영향이 없다.
 *
 * 사용:
 *   PROFILE_MEMORY=1 php -d memory_limit=-1 artisan test
 *   → storage/logs/memory-profile.csv (클래스, 테스트수, 잔존증가 bytes, 누적 bytes)
 */
final class MemoryProfilerExtension implements Extension
{
    /**
     * 확장을 부트스트랩합니다.
     *
     * @param  Configuration  $configuration  PHPUnit 설정
     * @param  Facade  $facade  이벤트 구독 파사드
     * @param  ParameterCollection  $parameters  확장 파라미터
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('PROFILE_MEMORY') !== '1') {
            return;
        }

        // 진단 실행은 **완주해야** 의미가 있다 — 중간에 죽으면 그 뒤 프로파일도 실패 목록도 못 얻는다.
        // `phpunit.xml` 의 `<ini name="memory_limit">` 는 부팅 시 적용되므로 CLI `-d` 로는 못 넘긴다.
        // 확장이 그 뒤에 부트스트랩되므로 여기서 푼다(평상시 실행은 이 분기에 오지 않아 1G 유지).
        ini_set('memory_limit', getenv('PROFILE_MEMORY_LIMIT') ?: '-1');

        $state = new MemoryProfilerState;

        $facade->registerSubscribers(
            new class($state) implements SuiteStartedSubscriber
            {
                public function __construct(private MemoryProfilerState $state) {}

                public function notify(SuiteStarted $event): void
                {
                    $this->state->begin($event->testSuite()->name());
                }
            },
            new class($state) implements TestFinishedSubscriber
            {
                public function __construct(private MemoryProfilerState $state) {}

                public function notify(TestFinished $event): void
                {
                    $this->state->countTest();
                }
            },
            new class($state) implements SuiteFinishedSubscriber
            {
                public function __construct(private MemoryProfilerState $state) {}

                public function notify(SuiteFinished $event): void
                {
                    $this->state->end($event->testSuite()->name());
                }
            },
        );
    }
}
