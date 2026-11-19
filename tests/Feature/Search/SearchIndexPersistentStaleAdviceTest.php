<?php

namespace Tests\Feature\Search;

use App\Enums\SearchIndexStatus;
use App\Extension\HookManager;
use App\Search\Contracts\ProvidesPersistentStaleAdvice;
use App\Search\Contracts\SearchIndexMaintainer;
use App\Search\DTO\SearchIndexHealth;
use App\Search\Engines\Maintenance\FulltextIndexMaintainer;
use App\Search\SearchIndexMaintenanceManager;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 재생성 뒤에도 남는 색인 누락에 표 재구축 안내를 붙이는지 검증합니다.
 *
 * InnoDB 의 숨은 문서 번호(FTS_DOC_ID) 매핑이 어긋난 표는 FULLTEXT 인덱스를 DROP/ADD 해도 고쳐지지 않고
 * `ALTER TABLE … ENGINE=InnoDB, ALGORITHM=COPY` 로 표를 다시 만들어야 회복된다(개발 DB `g7_ecommerce_products` 실례).
 * 종전에는 「잔존 N개」 만 보고해 운영자가 다음에 할 일을 알 수 없었다. 자동 실행은 하지 않는다(큰 표는 오래 걸리고
 * 쓰기가 잠긴다) — 표 이름과 정확한 명령을 명령 출력·로그·관리자 API 응답에 싣는다.
 *
 * 재생성해도 낫지 않는 엔진을 가짜 유지보수기로 흉내 낸다(잔존 재현). 안내 문장은 엔진이 정한다 — 안내를 주지 않는
 * 엔진(외부 검색 서버 등)에는 MySQL 명령을 붙이지 않는다.
 *
 * @scenario-manifest tests/scenarios/search-index-persistent-stale-advice.yaml
 */
class SearchIndexPersistentStaleAdviceTest extends TestCase
{
    /**
     * 가짜 엔진을 활성 드라이버로 등록합니다.
     *
     * @param  class-string<SearchIndexMaintainer>  $maintainer  유지보수기 클래스
     * @return void 반환값 없음
     */
    private function useFakeEngine(string $maintainer): void
    {
        config(['scout.driver' => 'acme-advice']);

        HookManager::addFilter(
            SearchIndexMaintenanceManager::MAINTAINERS_FILTER,
            fn (array $maintainers) => ['acme-advice' => $maintainer] + $maintainers
        );

        PersistentStaleMaintainer::$rebuilt = [];
        PersistentStaleMaintainer::$heal = false;
    }

    /**
     * 재생성 뒤 잔존하면 보고에 표 이름과 재구축 명령이 실리고 로그에 경고가 남는지 검증합니다.
     *
     * @scenario case=advice_report
     *
     * @effects persistent_stale_advice_in_report, persistent_stale_advice_logged
     *
     * @return void 반환값 없음
     */
    public function test_재생성_뒤_잔존하면_표_재구축_안내가_보고와_로그에_실린다(): void
    {
        $this->useFakeEngine(PersistentStaleMaintainer::class);
        Log::spy();

        $report = app(SearchIndexMaintenanceManager::class)->repairStale();

        $this->assertSame(['g7_ecommerce_products.ft_products_name'], $report->remaining);
        $this->assertSame([[
            'target' => 'g7_ecommerce_products',
            'command' => 'ALTER TABLE `g7_ecommerce_products` ENGINE=InnoDB, ALGORITHM=COPY;',
        ]], $report->advice);
        $this->assertSame($report->advice, $report->toArray()['advice'], '관리자 API 응답(toArray)에도 실려야 한다');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => ($context['advice'][0]['command'] ?? null) === 'ALTER TABLE `g7_ecommerce_products` ENGINE=InnoDB, ALGORITHM=COPY;')
            ->once();
    }

    /**
     * 재생성으로 나으면 안내를 붙이지 않는지 검증합니다.
     *
     * @scenario case=advice_healed
     *
     * @effects no_advice_when_healed
     *
     * @return void 반환값 없음
     */
    public function test_재생성으로_나으면_안내를_붙이지_않는다(): void
    {
        $this->useFakeEngine(PersistentStaleMaintainer::class);
        PersistentStaleMaintainer::$heal = true;

        $report = app(SearchIndexMaintenanceManager::class)->repairStale();

        $this->assertSame([], $report->remaining);
        $this->assertSame([], $report->advice);
    }

    /**
     * 안내를 주지 않는 엔진에는 잔존해도 명령을 붙이지 않는지 검증합니다(엔진 중립).
     *
     * @scenario case=advice_no_provider
     *
     * @effects no_advice_for_engine_without_provider
     *
     * @return void 반환값 없음
     */
    public function test_안내를_주지_않는_엔진에는_명령을_붙이지_않는다(): void
    {
        $this->useFakeEngine(PersistentStaleNoAdviceMaintainer::class);

        $report = app(SearchIndexMaintenanceManager::class)->repairStale();

        $this->assertSame(['g7_ecommerce_products.ft_products_name'], $report->remaining);
        $this->assertSame([], $report->advice);
    }

    /**
     * FULLTEXT 유지보수기가 잔존 인덱스의 표마다 재구축 명령 하나를 내는지 검증합니다.
     *
     * @scenario case=fulltext_per_table
     *
     * @effects fulltext_advice_one_command_per_table
     *
     * @return void 반환값 없음
     */
    public function test_fulltext_유지보수기는_표마다_재구축_명령_하나를_낸다(): void
    {
        $maintainer = app(FulltextIndexMaintainer::class);
        $health = fn (string $table, string $name) => new SearchIndexHealth(
            driver: FulltextIndexMaintainer::DRIVER,
            identifier: $table.'.'.$name,
            status: SearchIndexStatus::Stale,
            context: ['table' => $table, 'name' => $name, 'columns' => ['name'], 'parser' => null],
        );

        $advice = $maintainer->persistentStaleAdvice([
            $health('g7_ecommerce_products', 'ft_products_name'),
            $health('g7_ecommerce_products', 'ft_products_desc'),
            $health('g7_board_posts', 'ft_posts'),
        ]);

        $this->assertSame([
            ['target' => 'g7_ecommerce_products', 'command' => 'ALTER TABLE `g7_ecommerce_products` ENGINE=InnoDB, ALGORITHM=COPY;'],
            ['target' => 'g7_board_posts', 'command' => 'ALTER TABLE `g7_board_posts` ENGINE=InnoDB, ALGORITHM=COPY;'],
        ], $advice);
    }

    /**
     * `search:index --repair` 출력에 표 이름·명령·잠금 경고가 나오는지 검증합니다.
     *
     * @scenario case=command_output
     *
     * @effects persistent_stale_advice_in_command_output
     *
     * @return void 반환값 없음
     */
    public function test_search_index_repair_출력에_표_재구축_안내가_나온다(): void
    {
        $this->useFakeEngine(PersistentStaleMaintainer::class);

        $this->artisan('search:index', ['--repair' => true, '--yes' => true])
            ->expectsOutputToContain(__('search.index.persistent_stale.title'))
            ->expectsOutputToContain('ALTER TABLE `g7_ecommerce_products` ENGINE=InnoDB, ALGORITHM=COPY;')
            ->expectsOutputToContain(__('search.index.persistent_stale.caution'))
            ->assertExitCode(1);
    }

    /**
     * `--json` 출력의 재생성 보고에도 안내가 실리는지 검증합니다.
     *
     * @scenario case=json_output
     *
     * @effects persistent_stale_advice_in_report
     *
     * @return void 반환값 없음
     */
    public function test_search_index_json_출력의_보고에도_안내가_실린다(): void
    {
        $this->useFakeEngine(PersistentStaleMaintainer::class);

        $this->artisan('search:index', ['--repair' => true, '--yes' => true, '--json' => true])
            ->expectsOutputToContain('ENGINE=InnoDB, ALGORITHM=COPY')
            ->assertExitCode(1);
    }
}

/**
 * 재생성해도 낫지 않는 표를 흉내 내는 유지보수기 — 안내를 준다.
 */
class PersistentStaleMaintainer implements ProvidesPersistentStaleAdvice, SearchIndexMaintainer
{
    /** @var array<int, string> rebuild() 가 호출된 식별자 */
    public static array $rebuilt = [];

    /** 재생성하면 낫는지 여부 */
    public static bool $heal = false;

    /** {@inheritDoc} */
    public function driver(): string
    {
        return 'acme-advice';
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
        $healed = static::$heal && static::$rebuilt !== [];

        return [new SearchIndexHealth(
            driver: 'acme-advice',
            identifier: 'g7_ecommerce_products.ft_products_name',
            status: $healed ? SearchIndexStatus::Healthy : SearchIndexStatus::Stale,
            measurement: $healed ? '5/5' : '0/5',
            context: ['table' => 'g7_ecommerce_products', 'name' => 'ft_products_name'],
        )];
    }

    /** {@inheritDoc} */
    public function rebuild(SearchIndexHealth $health): void
    {
        static::$rebuilt[] = $health->identifier;
    }

    /** {@inheritDoc} */
    public function persistentStaleAdvice(array $remaining): array
    {
        return array_map(fn (SearchIndexHealth $h) => [
            'target' => (string) $h->context['table'],
            'command' => 'ALTER TABLE `'.$h->context['table'].'` ENGINE=InnoDB, ALGORITHM=COPY;',
        ], $remaining);
    }
}

/**
 * 안내 계약 없이 잔존만 흉내 내는 기반 클래스.
 */
class PersistentStaleMaintainerBase implements SearchIndexMaintainer
{
    /** {@inheritDoc} */
    public function driver(): string
    {
        return 'acme-advice';
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
        return [new SearchIndexHealth(
            driver: 'acme-advice',
            identifier: 'g7_ecommerce_products.ft_products_name',
            status: SearchIndexStatus::Stale,
            measurement: '0/5',
            context: ['table' => 'g7_ecommerce_products', 'name' => 'ft_products_name'],
        )];
    }

    /** {@inheritDoc} */
    public function rebuild(SearchIndexHealth $health): void {}
}

/**
 * 재생성해도 낫지 않지만 안내를 주지 않는 엔진(외부 검색 서버 등).
 */
class PersistentStaleNoAdviceMaintainer extends PersistentStaleMaintainerBase {}
