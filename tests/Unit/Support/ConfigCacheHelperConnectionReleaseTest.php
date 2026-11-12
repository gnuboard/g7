<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Mockery;
use Tests\TestCase;

/**
 * 캐시를 굽는 일회용 앱이 연 DB 연결을 닫는지 잠근다 (7.1.6).
 *
 * `config:cache`·`route:cache` 는 새 Application 을 부팅하고, 그 부팅이 DB 에 연결한다. 그 연결을 닫지 않아
 * 캐시를 한 번 구울 때마다 연결이 하나씩 남았다(로컬 실측: 한 프로세스에서 10번 구워 연결 +10). 확장 여러 개를
 * 한 프로세스에서 차례로 업데이트하면 확장마다 두 캐시를 다시 구우므로 DB 의 동시 연결 한도를 넘겼다.
 *
 * @scenario-manifest tests/scenarios/one-off-app-db-connection-release.yaml
 */
class ConfigCacheHelperConnectionReleaseTest extends TestCase
{
    /**
     * 일회용 앱의 DB 연결을 닫고, 실행 중인 앱의 컨테이너로 되돌린다.
     *
     * @scenario case=one_off_app_connected
     *
     * @effects one_off_app_connections_are_closed
     *
     * @return void 반환값 없음
     */
    public function test_connections_opened_by_the_one_off_app_are_closed(): void
    {
        $running = Container::getInstance();
        $idle = Mockery::mock();
        $idle->shouldReceive('transactionLevel')->andReturn(0);
        $manager = Mockery::mock();
        $manager->shouldReceive('getConnections')->once()->andReturn(['mysql' => $idle]);
        $manager->shouldReceive('disconnect')->once()->with('mysql');
        $oneOff = $this->oneOffApp($manager);

        ConfigCacheHelper::withPreservedContainer(static function () use ($oneOff): void {
            // config:cache 가 새 Application 을 만들면 전역 컨테이너가 그 앱으로 바뀐다
            Container::setInstance($oneOff);
        });

        $this->assertSame($running, Container::getInstance());
    }

    /**
     * 트랜잭션이 열려 있는 연결은 닫지 않는다.
     *
     * @scenario case=one_off_app_in_transaction
     *
     * @effects open_transaction_connection_is_left_alone
     *
     * @return void 반환값 없음
     */
    public function test_connection_with_open_transaction_is_not_closed(): void
    {
        $busy = Mockery::mock();
        $busy->shouldReceive('transactionLevel')->andReturn(1);
        $manager = Mockery::mock();
        $manager->shouldReceive('getConnections')->once()->andReturn(['mysql' => $busy]);
        $manager->shouldNotReceive('disconnect');
        $oneOff = $this->oneOffApp($manager);

        ConfigCacheHelper::withPreservedContainer(static function () use ($oneOff): void {
            Container::setInstance($oneOff);
        });

        $this->addToAssertionCount(1);
    }

    /**
     * 콜백이 앱을 바꾸지 않았으면(같은 컨테이너) 실행 중인 앱의 연결을 건드리지 않는다.
     *
     * @scenario case=no_one_off_app
     *
     * @effects running_app_connections_are_never_closed
     *
     * @return void 반환값 없음
     */
    public function test_running_app_connections_are_never_closed(): void
    {
        $before = array_keys($this->app['db']->getConnections());
        $pdoBefore = $before === [] ? null : $this->app['db']->connection()->getRawPdo();

        ConfigCacheHelper::withPreservedContainer(static function (): void {
            // 아무것도 바꾸지 않는다
        });

        $this->assertSame($before, array_keys($this->app['db']->getConnections()));
        if ($pdoBefore !== null) {
            $this->assertSame($pdoBefore, $this->app['db']->connection()->getRawPdo());
        }
    }

    /**
     * 연결을 닫다가 오류가 나도 캐시 굽기 결과에 영향을 주지 않는다.
     *
     * @scenario case=close_throws
     *
     * @effects close_failure_does_not_break_cache_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_failure_while_closing_is_swallowed(): void
    {
        $running = Container::getInstance();
        $manager = Mockery::mock();
        $manager->shouldReceive('getConnections')->andThrow(new \RuntimeException('gone away'));
        $oneOff = $this->oneOffApp($manager);

        ConfigCacheHelper::withPreservedContainer(static function () use ($oneOff): void {
            Container::setInstance($oneOff);
        });

        $this->assertSame($running, Container::getInstance());
    }

    /**
     * 일회용 앱이 부팅하며 바꿔 놓은 Eloquent 연결 해석기·모델 이벤트 디스패처를 실행 중인 앱의 것으로 되돌린다.
     *
     * 일회용 앱의 DatabaseServiceProvider 가 `Model::setConnectionResolver()`·`setEventDispatcher()` 를 자기 것으로 바꾼다.
     * 되돌리지 않으면 그 뒤 모델 조회가 (닫아 둔) 일회용 앱의 DB 관리자로 다시 연결해 연결이 쌓이고(번들 일괄 업데이트
     * 실측: 확장마다 +4, 18개에 최대 66개), 모델 이벤트도 실행 중인 앱의 리스너에 닿지 않는다.
     *
     * @scenario case=one_off_app_rebinds_eloquent
     *
     * @effects eloquent_resolver_and_dispatcher_point_back_to_running_app
     *
     * @return void 반환값 없음
     */
    public function test_eloquent_resolver_and_event_dispatcher_are_restored(): void
    {
        $runningResolver = Model::getConnectionResolver();
        $runningDispatcher = Model::getEventDispatcher();
        $manager = Mockery::mock();
        $manager->shouldReceive('getConnections')->andReturn([]);
        $oneOff = $this->oneOffApp($manager);
        $foreignResolver = Mockery::mock(ConnectionResolverInterface::class);
        $foreignDispatcher = new Dispatcher($oneOff);

        ConfigCacheHelper::withPreservedContainer(static function () use ($oneOff, $foreignResolver, $foreignDispatcher): void {
            Container::setInstance($oneOff);
            // 일회용 앱의 DatabaseServiceProvider::boot() 가 하는 일
            Model::setConnectionResolver($foreignResolver);
            Model::setEventDispatcher($foreignDispatcher);
        });

        $this->assertSame($runningResolver, Model::getConnectionResolver());
        $this->assertSame($runningDispatcher, Model::getEventDispatcher());
    }

    /**
     * 일회용 앱이 바꿔 놓은 페이지네이터 주소·쪽 번호 해석기를 실행 중인 앱의 요청 기준으로 되돌린다.
     *
     * @scenario case=one_off_app_rebinds_paginator
     *
     * @effects paginator_resolvers_point_back_to_running_app
     *
     * @return void 반환값 없음
     */
    public function test_paginator_resolvers_are_restored(): void
    {
        $runningPath = Paginator::resolveCurrentPath();
        $manager = Mockery::mock();
        $manager->shouldReceive('getConnections')->andReturn([]);
        $oneOff = $this->oneOffApp($manager);

        ConfigCacheHelper::withPreservedContainer(static function () use ($oneOff): void {
            Container::setInstance($oneOff);
            // 일회용 앱의 PaginationServiceProvider 가 하는 일
            Paginator::currentPathResolver(static fn () => 'http://one-off.invalid/console');
        });

        $this->assertSame($runningPath, Paginator::resolveCurrentPath());
    }

    /**
     * 「DB 관리자를 이미 꺼내 쓴」 일회용 앱 대역을 만듭니다.
     *
     * @param  object  $manager  DB 관리자 대역
     * @return Container 일회용 앱 대역
     */
    private function oneOffApp(object $manager): Container
    {
        $oneOff = new Container;
        $oneOff->singleton('db', static fn () => $manager);
        // 부팅하며 DB 를 쓴 상태를 만든다 (꺼내 쓴 적 없는 앱은 닫을 것이 없다)
        $oneOff->make('db');

        return $oneOff;
    }
}
