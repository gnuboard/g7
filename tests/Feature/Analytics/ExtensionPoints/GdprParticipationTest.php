<?php

namespace Tests\Feature\Analytics\ExtensionPoints;

use App\Analytics\AnalyticsConfig;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsAction;
use App\Models\Analytics\AnalyticsConversion;
use App\Models\Analytics\AnalyticsVisit;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\DataSubjectService;
use App\Services\Analytics\PurgeService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Api\AnalyticsApiTestCase;
use Tests\Feature\Analytics\Concerns\FakesAnalyticsExtensions;

/**
 * 개인정보 경로에 확장이 참여한다 — 삭제·내보내기·전체 삭제·소급 익명화.
 *
 * 코어 원장만 지우고 확장 표를 남기면 정보주체 삭제가 새는 구멍이 된다. 확장은 코어와 같은
 * 트랜잭션 안에서 지우고, 실패하면 코어 삭제도 함께 되돌아가야 「일부만 지워졌는데 완료」가 없다.
 */
class GdprParticipationTest extends AnalyticsApiTestCase
{
    use FakesAnalyticsExtensions;

    /**
     * 확장 표 대용 — 스키마를 새로 만들면(DDL) MySQL 이 트랜잭션을 암묵 커밋해 테스트 격리가
     * 깨지므로, 이미 있는 캐시 표에 확장 몫 행을 키 접두로 둔다.
     */
    private const EXT_TABLE = 'cache';

    /**
     * 테스트 준비 — 개인정보 차원을 선언한 확장과 확장 표.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('vendor-shop', [], [
                ['key' => 'email_domain', 'scope' => 'visit', 'label' => '메일 도메인', 'pii' => true],
                ['key' => 'category', 'label' => '분류'],
            ]),
        ]);
    }

    /**
     * 확장 몫 행을 심습니다.
     *
     * @param  int  ...$userIds  회원 번호들
     * @return void 없음
     */
    private function seedExtensionRows(int ...$userIds): void
    {
        foreach ($userIds as $index => $userId) {
            DB::table(self::EXT_TABLE)->insert([
                'key' => "vendor-shop:{$userId}:{$index}",
                'value' => 'x',
                'expiration' => time() + 3600,
            ]);
        }
    }

    /**
     * 확장이 한 회원 몫 행을 지웁니다.
     *
     * @param  int|null  $userId  회원 번호 (null 이면 확장 몫 전부)
     * @return int 지운 행 수
     */
    private function deleteExtensionRows(?int $userId = null): int
    {
        return DB::table(self::EXT_TABLE)
            ->where('key', 'like', $userId === null ? 'vendor-shop:%' : "vendor-shop:{$userId}:%")
            ->delete();
    }

    /**
     * 남은 확장 몫 행 수를 셉니다.
     *
     * @return int 행 수
     */
    private function extensionRowCount(): int
    {
        return DB::table(self::EXT_TABLE)->where('key', 'like', 'vendor-shop:%')->count();
    }

    /**
     * 정보주체 삭제가 확장 표를 같은 트랜잭션으로 지우고 건수를 돌려준다.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects subject_delete_filter_runs_inside_the_core_transaction, subject_delete_returns_extension_counts
     *
     * @return void 없음
     */
    public function test_subject_delete_includes_extension_rows(): void
    {
        $this->visit(['user_id' => 42]);
        $this->seedExtensionRows(42, 42, 7);

        $levels = [];
        HookManager::addFilter('core.analytics.subject_delete', function (array $counts, array $subject, array $visitIds) use (&$levels): array {
            $levels[] = DB::transactionLevel();
            $counts['vendor-shop'] = $this->deleteExtensionRows((int) $subject['user_id']);

            return $counts;
        });

        $counts = app(DataSubjectService::class)->delete(['user_id' => 42], AnalyticsConfig::fromSettings());

        $this->assertSame(['vendor-shop' => 2], $counts['extensions']);
        $this->assertSame(1, $counts['visits']);
        $this->assertSame(1, $this->extensionRowCount());
        $this->assertGreaterThanOrEqual(2, $levels[0], '테스트 트랜잭션 + 코어 삭제 트랜잭션 안에서 불린다');
    }

    /**
     * 확장이 던지면 코어 삭제도 되돌아간다.
     *
     * @scenario point=gdpr, extension_state=active, input=unregistered
     *
     * @effects subject_delete_rolls_back_when_extension_fails
     *
     * @return void 없음
     */
    public function test_extension_failure_rolls_back_core_delete(): void
    {
        $this->visit(['user_id' => 42]);

        HookManager::addFilter('core.analytics.subject_delete', function (): array {
            throw new \RuntimeException('extension table locked');
        });

        try {
            app(DataSubjectService::class)->delete(['user_id' => 42], AnalyticsConfig::fromSettings());
            $this->fail('확장 실패가 삼켜지면 운영자는 삭제가 끝난 줄 안다');
        } catch (\RuntimeException $e) {
            $this->assertSame('extension table locked', $e->getMessage());
        }

        $this->assertSame(1, AnalyticsVisit::count(), '코어 방문도 남아 있어야 한다');
        $this->assertSame(1, AnalyticsAction::count());
    }

    /**
     * 코어 원장에 방문이 없어도 확장 삭제는 돈다.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects subject_delete_reaches_extensions_without_core_visits
     *
     * @return void 없음
     */
    public function test_extension_delete_runs_without_core_visits(): void
    {
        $this->seedExtensionRows(99);
        HookManager::addFilter('core.analytics.subject_delete', fn (array $counts, array $subject): array => $counts + [
            'vendor-shop' => $this->deleteExtensionRows((int) $subject['user_id']),
        ]);

        $counts = app(DataSubjectService::class)->delete(['user_id' => 99], AnalyticsConfig::fromSettings());

        $this->assertSame(0, $counts['visits']);
        $this->assertSame(['vendor-shop' => 1], $counts['extensions']);
    }

    /**
     * 내보내기가 차원 값과 확장 데이터를 싣는다.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects subject_export_includes_custom_dimensions, subject_export_merges_extension_data
     *
     * @return void 없음
     */
    public function test_subject_export_includes_dimensions_and_extension_data(): void
    {
        $visit = $this->visit(['user_id' => 42, 'custom_dimensions' => ['vendor-shop:email_domain' => 'example.com']]);
        AnalyticsAction::query()->where('visit_id', $visit->id)->update(['custom_dimensions' => json_encode(['vendor-shop:category' => 'tv'])]);
        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'vendor-shop.order',
            'currency' => 'KRW',
            'custom_dimensions' => ['vendor-shop:category' => 'tv'],
        ]);

        HookManager::addFilter('core.analytics.subject_export', fn (array $data, array $subject, array $visitIds): array => $data + [
            'vendor-shop' => ['wishlist' => ['TV'], 'visit_ids' => $visitIds],
        ]);

        $export = app(DataSubjectService::class)->export(['user_id' => 42], AnalyticsConfig::fromSettings());

        $this->assertSame(['vendor-shop:email_domain' => 'example.com'], $export['visits'][0]['custom_dimensions']);
        $this->assertSame(['vendor-shop:category' => 'tv'], $export['visits'][0]['actions'][0]['custom_dimensions']);
        $this->assertSame('KRW', $export['visits'][0]['conversions'][0]['currency']);
        $this->assertSame(['TV'], $export['extensions']['vendor-shop']['wishlist']);
        $this->assertSame([$visit->id], $export['extensions']['vendor-shop']['visit_ids']);
    }

    /**
     * 운영자가 화면에서 고른 기간이 삭제·내보내기 필터에 그대로 닿는다 — 방문 ID 로 묶이지 않는
     * 확장 표는 기간을 알아야 그 범위만 지우고 내보낸다(모르면 전 기간을 지운다). 기간이 없으면 null.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects subject_filters_receive_the_requested_period
     *
     * @return void 없음
     */
    public function test_subject_filters_receive_the_requested_period(): void
    {
        $this->visit(['user_id' => 42]);
        $admin = $this->adminWith(['core.analytics.privacy']);

        $received = [];
        foreach (['core.analytics.subject_delete', 'core.analytics.subject_export'] as $filter) {
            HookManager::addFilter($filter, function (array $data, array $subject, array $visitIds, ?string $from, ?string $to) use (&$received, $filter): array {
                $received[$filter][] = [$from, $to];

                return $data;
            });
        }

        $period = ['from' => '2026-09-01', 'to' => '2026-09-07'];

        $this->actingAs($admin)
            ->postJson('/api/admin/analytics/privacy/subjects/export', ['user_id' => 42] + $period)
            ->assertOk();
        $this->actingAs($admin)
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 42, 'confirm' => true] + $period)
            ->assertOk();
        $this->actingAs($admin)
            ->deleteJson('/api/admin/analytics/privacy/subjects', ['user_id' => 42, 'confirm' => true])
            ->assertOk();

        $this->assertSame([['2026-09-01', '2026-09-07']], $received['core.analytics.subject_export'] ?? null);
        $this->assertSame([['2026-09-01', '2026-09-07'], [null, null]], $received['core.analytics.subject_delete'] ?? null);
    }

    /**
     * 전체 삭제는 확장 단계를 먼저 돌리고, 확장이 실패하면 코어 행을 건드리지 않는다.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects purge_all_runs_extension_step_first, purge_all_aborts_before_core_when_extension_fails
     *
     * @return void 없음
     */
    public function test_purge_all_extension_step(): void
    {
        $this->visit();
        $this->seedExtensionRows(1);

        HookManager::addFilter('core.analytics.purge_all', fn (array $counts): array => $counts + [
            'vendor-shop' => $this->deleteExtensionRows(),
        ]);

        $counts = app(PurgeService::class)->purgeAll();

        $this->assertSame(1, $counts['ext:vendor-shop']);
        $this->assertSame(0, AnalyticsVisit::count());

        // 실패 경로 — 새 행을 넣고 확장이 던지게 한다.
        HookManager::resetAll();
        $this->visit();
        HookManager::addFilter('core.analytics.purge_all', function (): array {
            throw new \RuntimeException('purge failed');
        });

        try {
            app(PurgeService::class)->purgeAll();
            $this->fail('확장 실패가 삼켜졌다');
        } catch (\RuntimeException) {
            $this->assertSame(1, AnalyticsVisit::count(), '코어 행은 하나도 지워지지 않는다');
        }
    }

    /**
     * 해시 모드 소급 익명화가 개인정보 차원을 원장 세 곳에서 걷어 내고, 확장 필터를 부른다.
     *
     * @scenario point=gdpr, extension_state=active, input=registered
     *
     * @effects anonymize_removes_pii_dimensions_everywhere, anonymize_attributes_filter_receives_each_visit
     *
     * @return void 없음
     */
    public function test_anonymize_removes_pii_dimensions(): void
    {
        $visit = $this->visit(['custom_dimensions' => ['vendor-shop:email_domain' => 'example.com', 'vendor-shop:category' => 'tv']]);
        AnalyticsAction::query()->where('visit_id', $visit->id)->update(['custom_dimensions' => json_encode([
            'vendor-shop:email_domain' => 'example.com',
            'vendor-shop:category' => 'tv',
        ])]);
        AnalyticsConversion::factory()->create([
            'visit_id' => $visit->id,
            'visitor_key' => $visit->visitor_key,
            'goal_key' => 'vendor-shop.order',
            'custom_dimensions' => ['vendor-shop:email_domain' => 'example.com'],
        ]);

        $seen = [];
        HookManager::addFilter('core.analytics.anonymize_attributes', function (array $attributes, $row, array $context) use (&$seen): array {
            $seen[] = [$row->id, $context['hash']];
            // 코어 값을 되돌리려는 시도는 무시돼야 한다.
            $attributes['ip'] = '198.51.100.1';

            return $attributes;
        });

        app(AnonymizeService::class)->anonymize(['hash' => true]);

        $visit->refresh();
        $this->assertSame(['vendor-shop:category' => 'tv'], $visit->custom_dimensions);
        $this->assertNull($visit->ip, '확장이 주소를 되살리지 못한다');
        $this->assertSame(['vendor-shop:category' => 'tv'], AnalyticsAction::query()->where('visit_id', $visit->id)->first()->custom_dimensions);
        $this->assertNull(AnalyticsConversion::first()->custom_dimensions);
        $this->assertSame([[$visit->id, true]], $seen);
    }
}
