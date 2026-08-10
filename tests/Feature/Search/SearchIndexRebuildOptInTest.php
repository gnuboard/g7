<?php

namespace Tests\Feature\Search;

use App\Enums\SearchIndexStatus;
use App\Extension\HookManager;
use App\Http\Controllers\Concerns\RebuildsSearchIndexOnDemand;
use App\Http\Requests\Module\PerformModuleUpdateRequest;
use App\Search\Contracts\SearchIndexMaintainer;
use App\Search\DTO\SearchIndexHealth;
use App\Search\SearchIndexMaintenanceManager;
use Tests\TestCase;

/**
 * 검색 인덱스 재생성이 **선택 사항**임을 고정하는 테스트
 *
 * 재생성은 인덱스 잠금 또는 전체 재색인을 유발합니다. 운영 중인 사이트에서 확장을
 * 업데이트했다는 이유만으로 그 비용이 발생하면 서비스가 멈출 수 있으므로,
 * 어떤 경로에서도 **명시적 선택 없이는 재생성하지 않는다**는 것이 계약입니다.
 *
 * 이 계약이 깨지면(자동 트리거 도입 등) 여기서 red 가 납니다.
 */
class SearchIndexRebuildOptInTest extends TestCase
{
    use RebuildsSearchIndexOnDemand;

    /**
     * 테스트 준비 — 판정을 통제할 수 있는 가짜 유지보수기를 활성 엔진으로 세운다.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'optin-test-engine']);
        SpyIndexMaintainer::$rebuilt = [];

        $this->app->bind(SpyIndexMaintainer::class, fn () => new SpyIndexMaintainer);

        HookManager::addFilter(
            SearchIndexMaintenanceManager::MAINTAINERS_FILTER,
            fn (array $maintainers) => $maintainers + ['optin-test-engine' => SpyIndexMaintainer::class]
        );
    }

    /**
     * 체크하지 않으면 재생성하지 않고 점검 결과만 돌려주는지 검증합니다.
     *
     * @return void
     */
    public function test_요청하지_않으면_재생성하지_않는다(): void
    {
        $payload = $this->rebuildSearchIndexIfRequested(false);

        $this->assertSame([], SpyIndexMaintainer::$rebuilt, '요청 없이 재생성이 일어났습니다');
        $this->assertFalse($payload['rebuilt']);
        $this->assertSame(1, $payload['stale_count'], '재생성하지 않아도 누락 사실은 알려야 합니다');
        $this->assertSame(['idx-stale'], $payload['stale']);
    }

    /**
     * 체크했을 때만 재생성하는지 검증합니다.
     *
     * @return void
     */
    public function test_요청했을_때만_재생성한다(): void
    {
        $payload = $this->rebuildSearchIndexIfRequested(true);

        $this->assertSame(['idx-stale'], SpyIndexMaintainer::$rebuilt);
        $this->assertTrue($payload['rebuilt']);
        $this->assertSame(['idx-stale'], $payload['repaired']);
    }

    /**
     * 점검을 제공하지 않는 엔진에서는 응답에 실을 정보가 없음을 검증합니다.
     *
     * @return void
     */
    public function test_점검_미제공_엔진에서는_null_을_돌려준다(): void
    {
        config(['scout.driver' => 'engine-without-maintainer']);

        $this->assertNull($this->rebuildSearchIndexIfRequested(true));
        $this->assertSame([], SpyIndexMaintainer::$rebuilt);
    }

    /**
     * 확장 업데이트 API 가 기본적으로 재생성하지 않는지 검증합니다.
     *
     * FormRequest 에 `rebuild_search_index` 를 넣지 않은 요청이 재생성을 유발하면 안 됩니다.
     *
     * @return void
     */
    public function test_업데이트_요청_본문에_없으면_재생성하지_않는다(): void
    {
        $rules = (new PerformModuleUpdateRequest)->rules();

        $this->assertArrayHasKey('rebuild_search_index', $rules, '선택 필드가 게이트에 선언되어 있어야 합니다');
        $this->assertContains('nullable', $rules['rebuild_search_index'], '미전송이 허용되어야 기본이 "미수행" 이 됩니다');
        $this->assertContains('boolean', $rules['rebuild_search_index']);
    }
}

/**
 * 재생성 호출을 기록하는 가짜 유지보수기.
 */
class SpyIndexMaintainer implements SearchIndexMaintainer
{
    /** @var array<int, string> rebuild() 가 호출된 식별자 */
    public static array $rebuilt = [];

    /** {@inheritDoc} */
    public function driver(): string
    {
        return 'optin-test-engine';
    }

    /** {@inheritDoc} */
    public function isAvailable(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function unavailableReason(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    public function inspect(array $filters = []): array
    {
        $staleStatus = in_array('idx-stale', self::$rebuilt, true)
            ? SearchIndexStatus::Healthy
            : SearchIndexStatus::Stale;

        return [
            new SearchIndexHealth('optin-test-engine', 'idx-healthy', SearchIndexStatus::Healthy, 'ok'),
            new SearchIndexHealth('optin-test-engine', 'idx-stale', $staleStatus, 'probe'),
        ];
    }

    /** {@inheritDoc} */
    public function rebuild(SearchIndexHealth $health): void
    {
        self::$rebuilt[] = $health->identifier;
    }
}
