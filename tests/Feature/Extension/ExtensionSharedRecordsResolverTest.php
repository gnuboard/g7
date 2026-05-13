<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\IdentityMessageDefinitionRepositoryInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Extension\Concerns\ResolvesExtensionSharedRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * ResolvesExtensionSharedRecords trait 단위 검증.
 *
 * Manager 가 uninstall 모달용 데이터를 Repository 경유로 정확히 집계하는지 + 0건 항목 제외 +
 * 부분 실패 (Repository throw) 시에도 다른 영역은 정상 동작하는지 검증.
 */
class ExtensionSharedRecordsResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_includes_only_nonzero_records(): void
    {
        $this->bindRepositoryStubs([
            'permissions' => 3,
            'menus' => 0, // 0건 → 결과에서 제외되어야 함
            'notification_definitions' => 5,
            'identity_policies' => 8,
            'identity_message_definitions' => 1,
        ]);

        $records = $this->makeResolver()->call('module', 'sirsoft-board');

        $byTable = collect($records)->keyBy('table');
        $this->assertCount(4, $records, '0건인 menus 는 제외되어야 함');
        $this->assertSame(3, $byTable['permissions']['count']);
        $this->assertArrayNotHasKey('menus', $byTable->all());
        $this->assertSame(5, $byTable['notification_definitions']['count']);
        $this->assertSame(8, $byTable['identity_policies']['count']);
        $this->assertSame(1, $byTable['identity_message_definitions']['count']);
    }

    public function test_resolver_skips_failing_repository_and_continues(): void
    {
        // permissions 만 throw, 나머지는 정상 응답
        $this->bindRepositoryStubs([
            'permissions' => 'throw',
            'menus' => 2,
            'notification_definitions' => 0,
            'identity_policies' => 4,
            'identity_message_definitions' => 0,
        ]);

        $records = $this->makeResolver()->call('module', 'sirsoft-board');

        $tables = collect($records)->pluck('table')->all();
        $this->assertNotContains('permissions', $tables, '실패 Repository 는 결과에서 제외');
        $this->assertContains('menus', $tables, '다른 Repository 는 정상 동작 유지');
        $this->assertContains('identity_policies', $tables);
    }

    private function makeResolver(): object
    {
        return new class
        {
            use ResolvesExtensionSharedRecords;

            public function call(string $type, string $id): array
            {
                return $this->resolveExtensionSharedRecords($type, $id);
            }
        };
    }

    /**
     * Container 에 stub 객체 바인딩. 컨테이너는 type-check 강제 안 하므로
     * 인터페이스 implements 없이 trait 람다가 호출하는 메서드만 정의해도 충분.
     *
     * @param  array<string, int|string>  $counts  'throw' 문자열은 메서드가 예외 던지도록 시뮬레이션
     */
    private function bindRepositoryStubs(array $counts): void
    {
        $makeCollection = function ($n): Collection {
            if ($n === 'throw' || $n === 0) {
                return new Collection();
            }

            return new Collection(array_fill(0, $n, (object) []));
        };

        $stubGetByExtension = fn ($count) => new class($count, $makeCollection)
        {
            public function __construct(private $count, private $factory) {}

            public function getByExtension(...$args): Collection
            {
                if ($this->count === 'throw') {
                    throw new \RuntimeException('boom');
                }

                return ($this->factory)($this->count);
            }

            public function getMenusByExtension(...$args): Collection
            {
                return $this->getByExtension(...$args);
            }
        };

        $this->app->instance(PermissionRepositoryInterface::class, $stubGetByExtension($counts['permissions']));
        $this->app->instance(MenuRepositoryInterface::class, $stubGetByExtension($counts['menus']));
        $this->app->instance(NotificationDefinitionRepositoryInterface::class, $stubGetByExtension($counts['notification_definitions']));
        $this->app->instance(IdentityMessageDefinitionRepositoryInterface::class, $stubGetByExtension($counts['identity_message_definitions']));

        $this->app->instance(IdentityPolicyRepositoryInterface::class, new class($counts['identity_policies'])
        {
            public function __construct(private $count) {}

            public function countBySource(string $sourceType, string $sourceIdentifier): int
            {
                if ($this->count === 'throw') {
                    throw new \RuntimeException('boom');
                }

                return (int) $this->count;
            }
        });
    }
}
