<?php

namespace Tests\Feature\Extension;

use App\Extension\Testing\ExtensionTestAllowlist;
use App\Jobs\DispatchHookListenerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 훅 리스너 격리 테스트
 *
 * 배경: `modules`/`plugins` 등록 행은 테스트 프로세스가 바뀌어도 DB 에 남는다. 그래서 어떤
 * 확장의 스위트를 돌린 뒤 다른 확장의 스위트를 돌리면, 앞 확장이 여전히 "활성" 으로 보인다.
 * 훅 리스너 등록이 그 활성 판정만 보고 있었던 탓에, ServiceProvider 는 등록되지 않았는데
 * 리스너만 등록되어 훅 발화 시 Repository 바인딩이 없어 컨테이너 해석이 실패했다
 * (게시판 게시글 삭제 18건이 이 경로로 깨졌다).
 *
 * `ExtensionTestAllowlist` 는 자기 문서에 "ServiceProvider / route / hook listener 등록 대상"
 * 을 허용한다고 선언하면서 실제로는 앞의 둘만 가드하고 있었다. 그 간극을 고정한다.
 */
class HookListenerAllowlistIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * allowlist 가 확장 유형 양쪽(module/plugin)을 판정하는지 확인합니다.
     */
    #[Test]
    public function allowlist_gates_both_extension_types(): void
    {
        ExtensionTestAllowlist::set(['modules/sirsoft-board']);

        $this->assertTrue(ExtensionTestAllowlist::isActive());
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-board'));
        $this->assertFalse(
            ExtensionTestAllowlist::isAllowed('module', 'sirsoft-ecommerce'),
            'allowlist 밖 모듈이 허용되면 그 모듈의 리스너가 프로바이더 없이 등록된다.'
        );
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * 해석할 수 없는 리스너는 호출자의 작업을 깨뜨리지 않고 건너뛰는지 확인합니다.
     *
     * 수정 전에는 BindingResolutionException 이 그대로 전파돼, 큐 드라이버가 sync 인 환경에서
     * 게시글 삭제 요청 자체가 500 이 됐다.
     */
    #[Test]
    public function unresolvable_listener_is_skipped_instead_of_breaking_the_caller(): void
    {
        Log::spy();

        $job = new DispatchHookListenerJob(
            UnwiredExtensionListenerStub::class,
            'handle',
            [],
            [],
        );

        $job->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, '훅 리스너를 해석할 수 없어'))
            ->once();
    }

    /**
     * 리스너 자신의 도메인 예외는 그대로 전파되는지 확인합니다.
     *
     * 해석 실패만 건너뛰어야 하며, 리스너가 실제로 던진 예외까지 삼키면 실패가 조용해진다.
     */
    #[Test]
    public function listener_domain_exception_still_propagates(): void
    {
        $job = new DispatchHookListenerJob(
            ThrowingListenerStub::class,
            'handle',
            [],
            [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('도메인 실패');

        $job->handle();
    }
}

/**
 * 배선되지 않은 확장의 리스너 대역 — 해석 불가능한 의존을 요구합니다.
 */
class UnwiredExtensionListenerStub
{
    /**
     * @param  UnboundExtensionContract  $dependency  바인딩되지 않은 의존
     */
    public function __construct(private UnboundExtensionContract $dependency) {}

    /**
     * 훅 처리 (도달하지 않아야 함)
     */
    public function handle(): void {}
}

/**
 * 어떤 구현체도 바인딩되지 않은 계약 (확장 ServiceProvider 미등록 상황 재현용)
 */
interface UnboundExtensionContract {}

/**
 * 도메인 예외를 던지는 리스너 대역
 */
class ThrowingListenerStub
{
    /**
     * 훅 처리 — 도메인 예외 발생
     */
    public function handle(): void
    {
        throw new \RuntimeException('도메인 실패');
    }
}
