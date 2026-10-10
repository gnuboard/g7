<?php

namespace Tests\Feature\Extension;

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

    /**
     * 저장소별 건수를 그대로 집계하되 0건인 영역(menus)은 결과에서 빼고, 본인인증 메시지용 별도 항목을 만들지 않는다.
     *
     * @scenario repository_state=all_respond
     *
     * @effects nonzero_records_listed_with_counts, zero_count_records_excluded, identity_messages_not_listed_separately
     *
     * @return void 반환값 없음
     */
    public function test_resolver_includes_only_nonzero_records(): void
    {
        $this->bindRepositoryStubs([
            'permissions' => 3,
            'menus' => 0, // 0건 → 결과에서 제외되어야 함
            'notification_definitions' => 5,
            'identity_policies' => 8,
        ]);

        $records = $this->makeResolver()->call('module', 'sirsoft-board');

        $byTable = collect($records)->keyBy('table');
        $this->assertCount(3, $records, '0건인 menus 는 제외되어야 함');
        $this->assertSame(3, $byTable['permissions']['count']);
        $this->assertArrayNotHasKey('menus', $byTable->all());
        $this->assertSame(5, $byTable['notification_definitions']['count']);
        $this->assertSame(8, $byTable['identity_policies']['count']);
        // 본인인증 메시지는 7.1.0 부터 알림 정의에 함께 집계된다 — 별도 표 항목이 없다(#699).
        $this->assertArrayNotHasKey('identity_message_definitions', $byTable->all());
    }

    /**
     * 한 저장소(permissions)가 예외를 던지면 그 영역만 빠지고 나머지 영역 집계는 계속된다.
     *
     * @scenario repository_state=one_throws
     *
     * @effects failing_repository_skipped_others_listed
     *
     * @return void 반환값 없음
     */
    public function test_resolver_skips_failing_repository_and_continues(): void
    {
        // permissions 만 throw, 나머지는 정상 응답
        $this->bindRepositoryStubs([
            'permissions' => 'throw',
            'menus' => 2,
            'notification_definitions' => 0,
            'identity_policies' => 4,
        ]);

        $records = $this->makeResolver()->call('module', 'sirsoft-board');

        $tables = collect($records)->pluck('table')->all();
        $this->assertNotContains('permissions', $tables, '실패 Repository 는 결과에서 제외');
        $this->assertContains('menus', $tables, '다른 Repository 는 정상 동작 유지');
        $this->assertContains('identity_policies', $tables);
    }

    /**
     * ResolvesExtensionSharedRecords 트레이트의 protected 집계 메서드를 공개 call() 로 노출하는 익명 객체를 만듭니다.
     *
     * @return object call(type, id) 를 가진 집계 객체
     */
    private function makeResolver(): object
    {
        return new class
        {
            use ResolvesExtensionSharedRecords;

            /**
             * 트레이트의 공유 레코드 집계를 호출합니다.
             *
             * @param  string  $type  확장 종류 (module|plugin)
             * @param  string  $id  확장 식별자
             * @return array 영역별 집계 목록
             */
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
     * @return void 반환값 없음
     */
    private function bindRepositoryStubs(array $counts): void
    {
        $makeCollection = function ($n): Collection {
            if ($n === 'throw' || $n === 0) {
                return new Collection;
            }

            return new Collection(array_fill(0, $n, (object) []));
        };

        $stubGetByExtension = fn ($count) => new class($count, $makeCollection)
        {
            /**
             * 돌려줄 건수와 컬렉션 생성기를 보관합니다.
             *
             * @param  int|string  $count  반환할 건수 ('throw' 면 예외)
             * @param  callable  $factory  건수로 컬렉션을 만드는 함수
             */
            public function __construct(private $count, private $factory) {}

            /**
             * 확장 소유 레코드 컬렉션을 돌려줍니다 (건수가 throw 면 예외).
             *
             * @param  mixed  ...$args  저장소 원래 시그니처의 인자 (무시)
             * @return Collection 지정 건수만큼의 항목
             */
            public function getByExtension(...$args): Collection
            {
                if ($this->count === 'throw') {
                    throw new \RuntimeException('boom');
                }

                return ($this->factory)($this->count);
            }

            /**
             * 메뉴 저장소 메서드 이름으로 같은 집계를 돌려줍니다.
             *
             * @param  mixed  ...$args  저장소 원래 시그니처의 인자 (무시)
             * @return Collection 지정 건수만큼의 항목
             */
            public function getMenusByExtension(...$args): Collection
            {
                return $this->getByExtension(...$args);
            }
        };

        $this->app->instance(PermissionRepositoryInterface::class, $stubGetByExtension($counts['permissions']));
        $this->app->instance(MenuRepositoryInterface::class, $stubGetByExtension($counts['menus']));
        $this->app->instance(NotificationDefinitionRepositoryInterface::class, $stubGetByExtension($counts['notification_definitions']));

        $this->app->instance(IdentityPolicyRepositoryInterface::class, new class($counts['identity_policies'])
        {
            /**
             * 돌려줄 정책 건수를 보관합니다.
             *
             * @param  int|string  $count  반환할 건수 ('throw' 면 예외)
             */
            public function __construct(private $count) {}

            /**
             * 출처별 본인인증 정책 건수를 돌려줍니다 (건수가 throw 면 예외).
             *
             * @param  string  $sourceType  출처 종류
             * @param  string  $sourceIdentifier  출처 식별자
             * @return int 정책 건수
             */
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
