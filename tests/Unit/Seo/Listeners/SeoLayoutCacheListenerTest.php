<?php

namespace Tests\Unit\Seo\Listeners;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Listeners\SeoLayoutCacheListener;
use App\Models\Template;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * 레이아웃 변경 시 봇(SEO) 캐시 삭제 리스너 테스트
 *
 * 레이아웃 저장·버전 복원·파일 작업·오버레이 변경은 모두 레이아웃 캐시 정리 훅을 거친다.
 * 그 훅에서 활성 사용자 템플릿의 레이아웃이면 같은 이름의 봇 캐시 항목을 지운다 (공개#154).
 */
class SeoLayoutCacheListenerTest extends TestCase
{
    private SeoCacheManagerInterface|Mockery\MockInterface $cacheMock;

    private TemplateRepositoryInterface|Mockery\MockInterface $templateRepository;

    private SeoLayoutCacheListener $listener;

    /**
     * 캐시 매니저·템플릿 저장소를 목으로 바꾸고 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->templateRepository = Mockery::mock(TemplateRepositoryInterface::class);

        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);
        $this->app->instance(TemplateRepositoryInterface::class, $this->templateRepository);

        $this->listener = new SeoLayoutCacheListener;
    }

    /**
     * 목 기대값을 검증하고 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 호출 횟수 기대(once·never)도 단언이다 — 단언 0건(risky)으로 세지 않게 PHPUnit 에 넘긴다
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 템플릿 ID 에 대응하는 템플릿 모델을 저장소 목이 돌려주게 합니다.
     *
     * @param  int  $id  템플릿 ID
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $type  템플릿 유형 (user|admin)
     * @param  string  $status  템플릿 상태
     * @return void 반환값 없음
     */
    private function mockTemplate(int $id, string $identifier, string $type = 'user', string $status = 'active'): void
    {
        $template = new Template;
        $template->id = $id;
        $template->identifier = $identifier;
        $template->type = $type;
        $template->status = $status;

        $this->templateRepository->shouldReceive('findById')->with($id)->andReturn($template);
    }

    /**
     * 레이아웃 캐시 정리 훅 하나를 동기 실행으로 구독합니다.
     *
     * @scenario surface=listener_contract
     *
     * @effects layout_change_listener_subscribes_cache_clear_hook_sync
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_layout_after_cache_clear_hook_synchronously(): void
    {
        $hooks = SeoLayoutCacheListener::getSubscribedHooks();

        $this->assertSame(['core.layout.after_cache_clear'], array_keys($hooks));
        $this->assertSame('onLayoutCacheCleared', $hooks['core.layout.after_cache_clear']['method']);
        $this->assertTrue($hooks['core.layout.after_cache_clear']['sync']);
    }

    /**
     * 활성 사용자 템플릿의 레이아웃이면 그 이름(DB·편집기 전체 이름)의 봇 캐시를 한 번 지웁니다.
     *
     * @scenario surface=layout_active_user
     *
     * @effects layout_change_invalidates_bot_cache_of_that_layout
     *
     * @return void 반환값 없음
     */
    public function test_active_user_template_layout_invalidates_bot_cache_once(): void
    {
        $this->mockTemplate(2, 'sirsoft-basic', 'user', ExtensionStatus::Active->value);

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->with('gnuboard7-hello_module.user_memo_list')
            ->once()
            ->andReturn(3);

        $this->listener->onLayoutCacheCleared(2, 'gnuboard7-hello_module.user_memo_list', 'template.2.layout.x');
    }

    /**
     * 관리자 템플릿(활성 사용자 템플릿이 아님) 레이아웃은 봇 캐시와 무관하므로 건드리지 않습니다.
     *
     * @scenario surface=layout_admin
     *
     * @effects layout_change_skips_non_user_template
     *
     * @return void 반환값 없음
     */
    public function test_admin_template_layout_does_not_touch_bot_cache(): void
    {
        $this->mockTemplate(1, 'sirsoft-admin_basic', 'admin');

        $this->cacheMock->shouldReceive('invalidateByLayout')->never();

        $this->listener->onLayoutCacheCleared(1, 'admin_dashboard', 'template.1.layout.admin_dashboard');
    }

    /**
     * 캐시 매니저가 예외를 던져도 경고만 남기고 저장 흐름을 막지 않습니다.
     *
     * @scenario surface=layout_active_user
     *
     * @effects layout_change_cache_failure_does_not_break_save
     *
     * @return void 반환값 없음
     */
    public function test_cache_failure_logs_warning_and_passes(): void
    {
        Log::spy();
        $this->mockTemplate(2, 'sirsoft-basic', 'user', ExtensionStatus::Active->value);

        $this->cacheMock->shouldReceive('invalidateByLayout')
            ->once()
            ->andThrow(new \RuntimeException('cache down'));

        $this->listener->onLayoutCacheCleared(2, 'home', 'template.2.layout.home');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('[SEO] Layout cache invalidation failed', Mockery::on(fn (array $ctx) => ($ctx['layout'] ?? null) === 'home'
                && ($ctx['error'] ?? null) === 'cache down'));
    }

    /**
     * 꺼진(비활성) 사용자 템플릿의 레이아웃 편집은 봇이 받는 화면이 아니므로 건드리지 않습니다.
     *
     * @scenario surface=layout_inactive_user
     *
     * @effects layout_change_skips_non_user_template
     *
     * @return void 반환값 없음
     */
    public function test_inactive_user_template_layout_does_not_touch_bot_cache(): void
    {
        $this->mockTemplate(3, 'sirsoft-other', 'user', ExtensionStatus::Inactive->value);

        $this->cacheMock->shouldReceive('invalidateByLayout')->never();

        $this->listener->onLayoutCacheCleared(3, 'home', 'template.3.layout.home');
    }
}
