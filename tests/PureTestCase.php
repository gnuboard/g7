<?php

namespace Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * 앱·DB 를 쓰지 않는 순수 테스트 베이스
 *
 * `Tests\TestCase`(와 확장의 ModuleTestCase·PluginTestCase)는 테스트마다 앱을 부팅하고(약 0.2초) DB 를 준비한다.
 * 배열·문자열·값 객체만 다루는 테스트는 그 비용이 전부 낭비라 이 클래스를 상속한다 — 부팅하지 않는다.
 *
 * 대신 앱을 쓰기 시작하면 조용히 다른 환경에서 도는 테스트가 된다(설정·번역·컨테이너가 없다). 그래서 이 클래스를
 * 상속한 파일에 앱 사용 흔적(`app(`·`config(`·파사드·DB·HTTP 테스트 호출 등)이 생기면 정적 검사가 오류로 잡는다
 * (`test-pure-base-class-no-app`). 그때는 원래 베이스로 되돌린다.
 */
abstract class PureTestCase extends BaseTestCase
{
    /**
     * Mockery 기대값을 확인하고 테스트 시각 고정을 풉니다(Laravel TestCase 의 정리와 같은 부분만).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (class_exists(Mockery::class)) {
            $container = Mockery::getContainer();
            if ($container !== null) {
                $this->addToAssertionCount($container->mockery_getExpectationCount());
            }
            Mockery::close();
        }

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
