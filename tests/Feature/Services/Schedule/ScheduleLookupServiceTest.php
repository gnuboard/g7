<?php

namespace Tests\Feature\Services\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Schedule;
use App\Services\Schedule\ScheduleLookupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 스케줄 조회 서비스(K4) 테스트.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 schedule-registry-model.yaml).
 */
class ScheduleLookupServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ScheduleLookupService $lookup;

    /**
     * 조회 서비스를 준비하고 기존 스케줄 행을 비웁니다(트랜잭션 안).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->lookup = $this->app->make(ScheduleLookupService::class);
        Schedule::query()->lazyById()->each(fn (Schedule $s) => $s->delete());
    }

    /**
     * findOwned 는 소유 타입·식별자·이름이 모두 맞는 행만 찾는다.
     *
     * @scenario owner=plugin, frequency=cron
     *
     * @effects find_owned_scopes_by_owner
     *
     * @return void 반환값 없음
     */
    public function test_find_owned(): void
    {
        // @scenario owner=module, frequency=cron
        // @scenario owner=custom, frequency=cron
        $plugin = Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->create(['name' => 'refresh']);
        Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-p')->create(['name' => 'refresh']);
        Schedule::factory()->create(['name' => 'refresh']);

        $this->assertSame($plugin->id, $this->lookup->findOwned('plugin', 'vendor-p', 'refresh')?->id);
        $this->assertNull($this->lookup->findOwned('plugin', 'vendor-p', 'nope'));
        $this->assertNull($this->lookup->findOwned('custom', 'vendor-p', 'refresh'));
    }

    /**
     * 다음 실행 시각 — 행 시간대 기준 cron, 30초 주기는 다음 :00/:30 초. 반환은 저장 시간대(UTC).
     * 소유자와 무관하게 같은 규칙이다(코어·모듈·플러그인·직접 등록 각각 두 주기).
     *
     * @scenario owner=core, frequency=cron
     *
     * @effects next_run_respects_row_timezone_and_thirty_seconds
     *
     * @return void 반환값 없음
     */
    public function test_next_run_at(): void
    {
        // @scenario owner=core, frequency=every_thirty_seconds
        // @scenario owner=module, frequency=cron
        // @scenario owner=module, frequency=every_thirty_seconds
        // @scenario owner=plugin, frequency=cron
        // @scenario owner=plugin, frequency=every_thirty_seconds
        // @scenario owner=custom, frequency=cron
        // @scenario owner=custom, frequency=every_thirty_seconds
        config(['app.timezone' => 'UTC', 'app.schedule_timezone' => 'UTC']);
        $from = Carbon::parse('2026-10-16 01:02:10', 'UTC');

        $seoul = Schedule::factory()->make(['expression' => '30 11 * * *', 'timezone' => 'Asia/Seoul']);
        $this->assertSame('2026-10-16 02:30:00', $this->lookup->nextRunAt($seoul, $from)->format('Y-m-d H:i:s'));

        $siteTz = Schedule::factory()->make(['expression' => '30 11 * * *', 'timezone' => null]);
        $this->assertSame('2026-10-16 11:30:00', $this->lookup->nextRunAt($siteTz, $from)->format('Y-m-d H:i:s'));

        $thirty = Schedule::factory()->make(['frequency' => 'everyThirtySeconds', 'expression' => '* * * * *']);
        $this->assertSame('2026-10-16 01:02:30', $this->lookup->nextRunAt($thirty, $from)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-16 01:03:00', $this->lookup->nextRunAt($thirty, $from->copy()->setSecond(30))->format('Y-m-d H:i:s'));

        $broken = Schedule::factory()->make(['expression' => 'not cron']);
        $this->assertNull($this->lookup->nextRunAt($broken, $from));

        $owners = [[ExtensionOwnerType::Core, 'core'], [ExtensionOwnerType::Module, 'vendor-m'], [ExtensionOwnerType::Plugin, 'vendor-p'], [null, null]];
        foreach ($owners as [$type, $id]) {
            $factory = $type ? Schedule::factory()->ownedBy($type, $id) : Schedule::factory();
            $cron = $factory->create(['expression' => '30 11 * * *', 'timezone' => 'Asia/Seoul']);
            $every = $factory->create(['frequency' => 'everyThirtySeconds', 'expression' => '* * * * *']);
            $label = $type?->value ?? 'custom';
            $this->assertSame('2026-10-16 02:30:00', $this->lookup->nextRunAt($cron->fresh(), $from)->format('Y-m-d H:i:s'), $label);
            $this->assertSame('2026-10-16 01:02:30', $this->lookup->nextRunAt($every->fresh(), $from)->format('Y-m-d H:i:s'), $label);
        }
    }

    /**
     * 소유자 요약 — 코어 → 모듈 → 플러그인 → 직접 등록(custom) 순, 0건 소유자 없음.
     *
     * @scenario owner=core, frequency=cron
     *
     * @effects owners_summary_orders_core_extensions_custom
     *
     * @return void 반환값 없음
     */
    public function test_owners_summary(): void
    {
        // @scenario owner=module, frequency=cron
        // @scenario owner=plugin, frequency=cron
        // @scenario owner=custom, frequency=cron
        Schedule::factory()->count(2)->ownedBy(ExtensionOwnerType::Core, 'core')->create();
        Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->create();
        Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-m')->create();
        Schedule::factory()->count(3)->create();

        $summary = $this->lookup->ownersSummary('ko');

        $this->assertSame(['core', 'module', 'plugin', 'custom'], array_column($summary, 'type'));
        $this->assertSame([2, 1, 1, 3], array_column($summary, 'schedule_count'));
        $this->assertSame(['core', 'vendor-m', 'vendor-p', null], array_column($summary, 'identifier'));
        $this->assertSame(__('extension_owner_type.core', [], 'ko'), $summary[0]['name']);
        $this->assertSame(__('schedule.owner.custom', [], 'ko'), $summary[3]['name']);
        $this->assertSame('vendor-m', $summary[1]['name'], '로드되지 않은 확장은 식별자로 표시');
    }

    /**
     * 확장 이름은 설치 행(언어팩이 ja 등을 넣은 다국어 name)을 먼저 쓴다 — 모듈·플러그인 관리 목록과 같은 해석(M1 실측 D9).
     *
     * 회귀: 매니페스트(getName — ko/en 만)만 보던 때 ja 요청에서 소유자 7개가 한국어로 떨어졌다.
     *
     * @scenario owner=module, frequency=cron
     *
     * @effects owners_summary_orders_core_extensions_custom
     *
     * @return void 반환값 없음
     */
    public function test_owner_name_uses_installed_row_locale(): void
    {
        // @scenario owner=plugin, frequency=cron
        Module::factory()->create([
            'identifier' => 'vendor-m', 'name' => ['ko' => '게시판', 'en' => 'Board', 'ja' => '掲示板'],
        ]);
        Plugin::factory()->create([
            'identifier' => 'vendor-p', 'name' => ['ko' => '환율', 'en' => 'Exchange rates'],
        ]);
        Schedule::factory()->ownedBy(ExtensionOwnerType::Module, 'vendor-m')->create();
        Schedule::factory()->ownedBy(ExtensionOwnerType::Plugin, 'vendor-p')->create();

        $ja = collect($this->lookup->ownersSummary('ja'))->keyBy('identifier');
        $this->assertSame('掲示板', $ja['vendor-m']['name']);
        $this->assertSame('환율', $ja['vendor-p']['name'], 'ja 가 없으면 폴백 로케일(ko)');
        $this->assertSame('Board', $this->lookup->ownerName('module', 'vendor-m', 'en'));
        $this->assertSame('Exchange rates', $this->lookup->ownerName('plugin', 'vendor-p', 'en'));
    }
}
