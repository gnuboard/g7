<?php

namespace Tests\Unit\Extension\Consent;

use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSelection;
use App\Extension\Consent\ConsentSubject;
use App\Extension\HookManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesConsentProviders;
use Tests\TestCase;

/**
 * 동의 선택 상태의 요청 메모 (#860 W10)
 *
 * 쿠키 동의 미들웨어·뷰 컴포저·동의 API 가 한 요청에서 `selection()` 을 여러 번 부르면 그때마다 제공자 등록
 * 필터를 돌려 제공자를 새로 만들었다. 같은 요청에서는 한 번만 계산하되, 운영자 선택값·제공자 등록·설정 저장이
 * 바뀌면 다시 계산한다.
 */
class ConsentManagerSelectionMemoTest extends TestCase
{
    use FakesConsentProviders;

    /**
     * 제공자 등록·선택값을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetConsentProviders();
    }

    /**
     * 제공자 등록 필터가 몇 번 돌았는지 세는 필터를 겁니다.
     *
     * @param  int  $calls  호출 수 (참조)
     * @return void 반환값 없음
     */
    private function countFilterCalls(int &$calls): void
    {
        HookManager::addFilter(ConsentManager::REGISTERED_PROVIDERS_FILTER, function (array $providers) use (&$calls): array {
            $calls++;

            return $providers;
        });
    }

    /**
     * 같은 요청에서 selection() 을 여러 번 불러도 제공자 등록 필터는 한 번만 돕니다.
     *
     * @effects consent_selection_is_computed_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 선택_상태는_요청당_한_번만_계산한다(): void
    {
        $this->fakeConsentProvider('memo-a');
        $calls = 0;
        $this->countFilterCalls($calls);
        $manager = app(ConsentManager::class);

        $first = $manager->selection();
        $manager->selection();
        $manager->snapshotFor(ConsentSubject::guest(null));

        $this->assertSame(ConsentSelection::STATE_AUTO, $first->state);
        $this->assertSame(1, $calls);
    }

    /**
     * 운영자 선택값이 바뀌거나 제공자 등록이 바뀌거나 명시적으로 비우면 다시 계산합니다.
     *
     * @effects consent_selection_is_computed_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 선택값_등록_변경과_비우기_뒤에는_다시_계산한다(): void
    {
        $this->fakeConsentProvider('memo-a');
        $calls = 0;
        $this->countFilterCalls($calls);
        $manager = app(ConsentManager::class);

        $this->assertSame(ConsentSelection::STATE_AUTO, $manager->selection()->state);

        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => 'memo-missing']);
        $this->assertSame(ConsentSelection::STATE_MISSING, $manager->selection()->state, '선택값 변경');
        config(['g7_settings.core.'.ConsentManager::SETTING_KEY => '']);

        $this->fakeConsentProvider('memo-b');
        $this->assertSame(ConsentSelection::STATE_AMBIGUOUS, $manager->selection()->state, '제공자 등록 변경');

        $before = $calls;
        $manager->forgetSelection();
        $manager->selection();
        $this->assertSame($before + 1, $calls, '명시적 비우기');
    }

    /**
     * 요청이 바뀌면(스코프 인스턴스 비움) 새로 계산합니다 — 큐 워커·상주 프로세스에서 옛 판정이 남지 않습니다.
     *
     * @effects consent_selection_is_computed_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 다음_요청에서는_새로_계산한다(): void
    {
        $this->fakeConsentProvider('memo-a');
        $calls = 0;
        $this->countFilterCalls($calls);

        app(ConsentManager::class)->selection();
        $this->app->forgetScopedInstances();
        app(ConsentManager::class)->selection();

        $this->assertSame(2, $calls);
    }
}
