<?php

namespace Tests\Feature\Queue;

use App\Enums\ExtensionOwnerType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\QueueJobLog;
use App\Models\Role;
use App\Models\User;
use App\Services\JobQueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\Queue\QueueProbeJob;
use Tests\TestCase;

/**
 * 큐 관리 API (`/api/admin/schedules/queue/*`) 테스트 (#17)
 *
 * 실패 작업은 진짜 작업을 database 큐에 넣은 뒤 그 payload 를 failer 로 옮겨 만든다 — 재시도하면 실제로
 * 처리되는 payload 다. 스키마를 새로 만들지 않도록 트랜잭션으로 격리한다.
 */
class JobQueueControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/admin/schedules/queue';

    /**
     * 큐를 database 드라이버·기본 워커 등록으로 고정하고 권한 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        QueueProbeJob::reset();
        $this->clearPermissionCache();
        config([
            'queue.default' => 'database',
            'g7_settings.core.drivers.queue_connections' => [],
            'g7_settings.core.drivers.queue_names' => [],
        ]);
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        QueueJobLog::query()->delete();
    }

    /**
     * 권한 캐시를 비우고 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearPermissionCache();
        QueueProbeJob::reset();

        parent::tearDown();
    }

    /**
     * PermissionHelper 정적 캐시를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionCache(): void
    {
        $prop = (new \ReflectionClass(PermissionHelper::class))->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 주어진 권한만 가진 관리자를 만들고 Bearer 토큰을 돌려줍니다.
     *
     * @param  list<string>  $permissions  부여할 권한 식별자
     * @return string Bearer 토큰
     */
    private function tokenFor(array $permissions): string
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'queue_test_'.Str::lower(Str::random(10)),
            'name' => ['ko' => '큐 테스트', 'en' => 'Queue test'],
            'description' => ['ko' => '큐 테스트', 'en' => 'Queue test'],
            'is_active' => true,
        ]);
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $sync = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $sync[$permission->id] = ['scope_type' => null];
        }
        $role->permissions()->sync($sync);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->createToken('queue-test')->plainTextToken;
    }

    /**
     * 큐 전 권한을 가진 관리자의 요청 빌더를 돌려줍니다.
     *
     * @return static 요청 빌더
     */
    private function asQueueAdmin(): static
    {
        return $this->withToken($this->tokenFor(['core.queue_jobs.read', 'core.queue_jobs.retry', 'core.queue_jobs.delete']))
            ->withHeader('Accept', 'application/json');
    }

    /**
     * 진짜 payload 를 가진 실패 작업을 만듭니다.
     *
     * @param  string  $label  작업 표지 (payload 에 실린다)
     * @param  string  $connection  실패 작업에 기록할 큐 연결 이름
     * @param  string  $queue  실패 작업에 기록할 큐 이름
     * @param  string|null  $error  실패 예외 메시지 (생략하면 표지로 만든다)
     * @return string 만든 실패 작업의 UUID
     */
    private function makeFailedJob(string $label = 'probe', string $connection = 'database', string $queue = 'default', ?string $error = null): string
    {
        Queue::connection('database')->push(new QueueProbeJob($label), '', 'default');
        $row = DB::table('jobs')->orderByDesc('id')->first();
        DB::table('jobs')->where('id', $row->id)->delete();

        app('queue.failer')->log($connection, $queue, $row->payload, new RuntimeException($error ?? 'boom '.$label));

        return (string) json_decode($row->payload, true)['uuid'];
    }

    /**
     * 토큰 없는 요청은 401 이다.
     *
     * @scenario permission=anonymous
     *
     * @effects queue_api_requires_auth
     *
     * @return void 반환값 없음
     */
    public function test_비인증_요청은_401(): void
    {
        $this->getJson(self::BASE.'/pending')->assertStatus(401);
        $this->postJson(self::BASE.'/failed/retry', ['uuids' => [(string) Str::uuid()]])->assertStatus(401);
    }

    /**
     * 큐 권한이 없으면 조회도 403, 조회 권한만 있으면 재시도·삭제가 403 이다.
     *
     * @scenario permission=none|read_only
     *
     * @effects queue_api_permission_gate
     *
     * @return void 반환값 없음
     */
    public function test_권한별로_조회_재시도_삭제가_막힌다(): void
    {
        $uuid = $this->makeFailedJob();

        $none = $this->tokenFor(['core.schedules.read']);
        foreach (['/status', '/pending', '/failed', '/logs', '/failed/'.$uuid] as $path) {
            $this->withToken($none)->getJson(self::BASE.$path)->assertStatus(403);
        }

        // 같은 테스트 안에서 토큰을 바꾸면 가드가 앞 사용자를 붙들고 있다 — 가드·권한 캐시를 비운다
        $this->app['auth']->forgetGuards();
        $this->clearPermissionCache();
        $readOnly = $this->tokenFor(['core.queue_jobs.read']);
        $this->withToken($readOnly)->getJson(self::BASE.'/failed')->assertOk();
        $this->withToken($readOnly)->postJson(self::BASE.'/failed/'.$uuid.'/retry')->assertStatus(403);
        $this->withToken($readOnly)->postJson(self::BASE.'/failed/retry', ['uuids' => [$uuid]])->assertStatus(403);
        $this->withToken($readOnly)->deleteJson(self::BASE.'/failed/'.$uuid)->assertStatus(403);
        $this->withToken($readOnly)->deleteJson(self::BASE.'/failed', ['uuids' => [$uuid]])->assertStatus(403);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        $this->withToken($readOnly)->getJson(self::BASE.'/failed')->assertJsonPath('data.abilities', ['can_retry' => false, 'can_delete' => false]);
    }

    /**
     * 대기 목록은 payload 에서 표시 이름·UUID 를 꺼내고 측정 가능 메타를 싣는다. 검색은 작업·UUID 로 거른다.
     *
     * @scenario list=pending, driver=database, search_field=all|job|uuid
     *
     * @effects pending_list_parses_payload, pending_list_search
     *
     * @return void 반환값 없음
     */
    public function test_대기_목록은_payload_메타와_측정가능_메타를_준다(): void
    {
        Queue::connection('database')->push(new QueueProbeJob('alpha-label'), '', 'default');
        Queue::connection('database')->push(new QueueProbeJob('beta-label'), '', 'default');
        $alphaUuid = json_decode(DB::table('jobs')->orderBy('id')->value('payload'), true)['uuid'];

        $response = $this->asQueueAdmin()->getJson(self::BASE.'/pending')->assertOk();
        $response->assertJsonPath('data.meta.measurable', true)
            ->assertJsonPath('data.meta.connection', 'database')
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.display_name', QueueProbeJob::class)
            ->assertJsonPath('data.data.0.job_class', QueueProbeJob::class)
            ->assertJsonPath('data.data.0.state', 'waiting')
            ->assertJsonPath('data.data.0.abilities', ['can_retry' => false, 'can_delete' => false]);
        $this->assertNotNull($response->json('data.pagination.total'));

        $this->asQueueAdmin()->getJson(self::BASE.'/pending?search_field=job&search_keyword=alpha-label')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.uuid', $alphaUuid);
        $this->asQueueAdmin()->getJson(self::BASE.'/pending?search_field=uuid&search_keyword='.$alphaUuid)
            ->assertOk()->assertJsonCount(1, 'data.data');
        $this->asQueueAdmin()->getJson(self::BASE.'/pending?queue=other')->assertOk()->assertJsonCount(0, 'data.data');
    }

    /**
     * database 가 아닌 드라이버면 대기 목록은 비고 measurable=false 다.
     *
     * @scenario list=pending, driver=sync
     *
     * @effects pending_list_unmeasurable_driver
     *
     * @return void 반환값 없음
     */
    public function test_database_가_아닌_드라이버면_대기목록은_측정불가(): void
    {
        config(['queue.default' => 'sync']);

        $this->asQueueAdmin()->getJson(self::BASE.'/pending')
            ->assertOk()
            ->assertJsonPath('data.meta.measurable', false)
            ->assertJsonPath('data.meta.driver', 'sync')
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 실패 목록은 검색 대상(작업·오류·UUID)·큐·연결·실패 일시로 거르고 능력 메타를 싣는다.
     *
     * @scenario list=failed, search_field=job|error|uuid, filter=queue|connection|failed_date
     *
     * @effects failed_list_filters, failed_list_abilities
     *
     * @return void 반환값 없음
     */
    public function test_실패_목록_필터와_검색(): void
    {
        $a = $this->makeFailedJob('first-label', 'database', 'default', 'disk full');
        $b = $this->makeFailedJob('second-label', 'database', 'mail', 'smtp timeout');
        $c = $this->makeFailedJob('third-label', 'redis', 'default', 'disk full again');
        DB::table('failed_jobs')->where('uuid', $c)->update(['failed_at' => Carbon::now()->subDays(10)]);

        $api = $this->asQueueAdmin();
        $all = $api->getJson(self::BASE.'/failed')->assertOk();
        $all->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.abilities', ['can_retry' => true, 'can_delete' => true])
            ->assertJsonPath('data.data.0.abilities', ['can_retry' => true, 'can_delete' => true]);
        $this->assertStringStartsWith('RuntimeException', (string) $all->json('data.data.0.exception_summary'));

        $uuids = fn ($r) => collect($r->json('data.data'))->pluck('uuid')->sort()->values()->all();
        $this->assertSame(collect([$a, $c])->sort()->values()->all(), $uuids($api->getJson(self::BASE.'/failed?search_field=error&search_keyword=disk')));
        $this->assertSame([$b], $uuids($api->getJson(self::BASE.'/failed?search_field=job&search_keyword=second-label')));
        $this->assertSame([$a], $uuids($api->getJson(self::BASE.'/failed?search_field=uuid&search_keyword='.$a)));
        $this->assertSame([$b], $uuids($api->getJson(self::BASE.'/failed?queue=mail')));
        $this->assertSame([$c], $uuids($api->getJson(self::BASE.'/failed?connection=redis')));
        $this->assertSame([$c], $uuids($api->getJson(self::BASE.'/failed?failed_end_date='.Carbon::now()->subDays(5)->toDateString())));

        $page = $api->getJson(self::BASE.'/failed?per_page=1')->assertOk();
        $page->assertJsonCount(1, 'data.data')->assertJsonPath('data.pagination.has_more_pages', true);
    }

    /**
     * 실패 상세는 예외 전문과 payload 메타를 주고 직렬화된 작업 객체는 싣지 않는다. 없는 UUID 는 404 다.
     *
     * @scenario detail=failed
     *
     * @effects failed_detail_hides_serialized_command
     *
     * @return void 반환값 없음
     */
    public function test_실패_상세는_예외전문과_payload메타만_준다(): void
    {
        $uuid = $this->makeFailedJob('secret-arg-value');

        $response = $this->asQueueAdmin()->getJson(self::BASE.'/failed/'.$uuid)->assertOk();
        $response->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('data.payload_meta.displayName', QueueProbeJob::class)
            ->assertJsonPath('data.payload_meta.commandName', QueueProbeJob::class);
        $this->assertStringContainsString('boom secret-arg-value', (string) $response->json('data.exception'));
        $this->assertArrayNotHasKey('data', $response->json('data.payload_meta'));
        // 예외 메시지에 표지를 넣었으므로 payload 직렬화 문자열(s:N:"label")로만 검사한다
        $this->assertStringNotContainsString('s:5:\\"label\\"', $response->getContent());

        $this->asQueueAdmin()->getJson(self::BASE.'/failed/'.Str::uuid())->assertStatus(404);
    }

    /**
     * 실패 작업의 시도 횟수 — database 드라이버는 시도 수를 payload 가 아니라 jobs.attempts 열에 둬서 실패 기록의 payload 에
     * 값이 없다. 같은 UUID 의 처리 기록 시도 수로 채우고, 그것도 없으면 0 이 아니라 미상(null)이다. payload 에 시도 수를 싣는
     * 드라이버(redis)는 그 값을 쓴다. 목록과 상세가 같은 값이다.
     *
     * @scenario list=failed, driver=database
     *
     * @effects failed_attempts_from_log_or_unknown
     *
     * @return void 반환값 없음
     */
    public function test_실패_작업_시도_횟수는_처리기록으로_채우고_모르면_미상이다(): void
    {
        $logged = $this->makeFailedJob('attempt-logged');
        $unknown = $this->makeFailedJob('attempt-unknown');
        $this->assertArrayNotHasKey('attempts', json_decode((string) DB::table('failed_jobs')->where('uuid', $logged)->value('payload'), true));

        $logRow = fn (int $attempts, string $status) => [
            'uuid' => $logged, 'connection' => 'database', 'queue' => 'default',
            'job_class' => QueueProbeJob::class, 'display_name' => QueueProbeJob::class, 'status' => $status,
            'attempts' => $attempts, 'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];
        // 앞선 회차 기록(재시도 전)과 마지막 실패 기록 — 마지막 기록의 시도 수를 쓴다
        QueueJobLog::query()->insert([$logRow(3, 'failed'), $logRow(1, 'failed')]);

        // payload 에 시도 수를 싣는 드라이버(redis) — 처리 기록이 없어도 payload 값을 쓴다
        $redisPayload = json_decode((string) DB::table('failed_jobs')->where('uuid', $unknown)->value('payload'), true);
        $redisUuid = (string) Str::uuid();
        $redisPayload['uuid'] = $redisUuid;
        $redisPayload['attempts'] = 2;
        app('queue.failer')->log('redis', 'default', json_encode($redisPayload), new RuntimeException('boom redis'));

        $api = $this->asQueueAdmin();
        $listed = function (string $uuid) use ($api) {
            $rows = $api->getJson(self::BASE.'/failed?search_field=uuid&search_keyword='.$uuid)->assertOk()->json('data.data');
            $this->assertCount(1, $rows, $uuid);

            return $rows[0]['attempts'];
        };

        $this->assertSame(1, $listed($logged));
        $this->assertNull($listed($unknown));
        $this->assertSame(2, $listed($redisUuid));

        $api->getJson(self::BASE.'/failed/'.$logged)->assertOk()->assertJsonPath('data.attempts', 1);
        $this->assertNull($api->getJson(self::BASE.'/failed/'.$unknown)->assertOk()->json('data.attempts'));
        $api->getJson(self::BASE.'/failed/'.$redisUuid)->assertOk()->assertJsonPath('data.attempts', 2);
    }

    /**
     * 재시도는 원래 연결·큐로 되넣고 실패 기록을 지우며 JobRetryRequested 를 발화한다.
     *
     * @scenario action=retry, placement=registered
     *
     * @effects retry_pushes_back_and_forgets, retry_fires_retry_requested
     *
     * @return void 반환값 없음
     */
    public function test_재시도는_원래_연결로_되넣고_실패기록을_지운다(): void
    {
        $uuid = $this->makeFailedJob();
        $fired = [];
        Event::listen(JobRetryRequested::class, function (JobRetryRequested $e) use (&$fired): void {
            // failer 는 UUID 를 id 로 바꿔 돌려준다 (DatabaseUuidFailedJobProvider::find)
            $fired[] = $e->job->id ?? null;
        });

        $this->asQueueAdmin()->postJson(self::BASE.'/failed/'.$uuid.'/retry')
            ->assertOk()
            ->assertJsonPath('data.results.0.uuid', $uuid)
            ->assertJsonPath('data.results.0.ok', true)
            ->assertJsonPath('data.results.0.connection', 'database');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $pushed = DB::table('jobs')->first();
        $this->assertNotNull($pushed);
        $this->assertSame('default', $pushed->queue);
        $this->assertSame($uuid, json_decode($pushed->payload, true)['uuid']);
        $this->assertSame([$uuid], $fired);
    }

    /**
     * 워커가 보지 않는(등록되지 않은) 연결의 실패 작업은 기본 연결로 되넣는다.
     *
     * @scenario action=retry, placement=unregistered_connection
     *
     * @effects retry_falls_back_to_registered_connection
     *
     * @return void 반환값 없음
     */
    public function test_미등록_연결은_기본연결로_폴백한다(): void
    {
        $uuid = $this->makeFailedJob('fallback', 'redis', 'default');

        $this->asQueueAdmin()->postJson(self::BASE.'/failed/'.$uuid.'/retry')
            ->assertOk()
            ->assertJsonPath('data.results.0.ok', true)
            ->assertJsonPath('data.results.0.connection', 'database');

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    /**
     * 기본 드라이버가 sync 면 되넣을 워커가 없어 422 로 거부하고 실패 기록을 남긴다
     * (SyncQueue::pushRaw 는 아무것도 하지 않으므로 그대로 두면 작업이 사라진다).
     *
     * @scenario action=retry, driver=sync
     *
     * @effects retry_refuses_non_worker_driver
     *
     * @return void 반환값 없음
     */
    public function test_sync_드라이버면_재시도를_거부하고_실패기록을_남긴다(): void
    {
        $uuid = $this->makeFailedJob('sync-case', 'sync', 'default');
        config(['queue.default' => 'sync']);

        $this->asQueueAdmin()->postJson(self::BASE.'/failed/'.$uuid.'/retry')
            ->assertStatus(422)
            ->assertJsonPath('errors.results.0.ok', false)
            ->assertJsonPath('errors.results.0.message', __('queue.messages.retry_no_worker'));

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    /**
     * 없는 UUID 재시도는 404 다.
     *
     * @scenario action=retry, target=missing
     *
     * @effects retry_missing_is_404
     *
     * @return void 반환값 없음
     */
    public function test_없는_uuid_재시도는_404(): void
    {
        $this->asQueueAdmin()->postJson(self::BASE.'/failed/'.Str::uuid().'/retry')
            ->assertStatus(404)
            ->assertJsonPath('errors.results.0.ok', false);
    }

    /**
     * 일괄 재시도는 건별 결과와 성공·실패 건수를 주고, 101건은 422 다.
     *
     * @scenario action=retry_bulk, size=mixed|over_limit
     *
     * @effects bulk_retry_per_item_results, bulk_limit_100
     *
     * @return void 반환값 없음
     */
    public function test_일괄_재시도는_건별_결과를_주고_상한을_넘으면_422(): void
    {
        $a = $this->makeFailedJob('bulk-a');
        $b = $this->makeFailedJob('bulk-b');
        $missing = (string) Str::uuid();

        $response = $this->asQueueAdmin()->postJson(self::BASE.'/failed/retry', ['uuids' => [$a, $missing, $b]])->assertOk();
        $response->assertJsonPath('data.succeeded', 2)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonCount(3, 'data.results')
            ->assertJsonPath('data.results.1.uuid', $missing)
            ->assertJsonPath('data.results.1.ok', false);
        $this->assertSame(2, DB::table('jobs')->count());

        $tooMany = array_map(fn () => (string) Str::uuid(), range(1, 101));
        $this->asQueueAdmin()->postJson(self::BASE.'/failed/retry', ['uuids' => $tooMany])
            ->assertStatus(422)->assertJsonValidationErrors('uuids');
        $this->asQueueAdmin()->deleteJson(self::BASE.'/failed', ['uuids' => $tooMany])
            ->assertStatus(422)->assertJsonValidationErrors('uuids');
        $this->asQueueAdmin()->postJson(self::BASE.'/failed/retry', ['uuids' => ['not-a-uuid']])
            ->assertStatus(422)->assertJsonValidationErrors('uuids.0');
    }

    /**
     * 단건·일괄 삭제는 실패 기록을 지우고, 이미 없는 건은 404·실패 건수로 알린다.
     *
     * @scenario action=delete|delete_bulk
     *
     * @effects delete_forgets_failed_job
     *
     * @return void 반환값 없음
     */
    public function test_삭제_단건과_일괄(): void
    {
        $a = $this->makeFailedJob('del-a');
        $b = $this->makeFailedJob('del-b');
        $c = $this->makeFailedJob('del-c');

        $this->asQueueAdmin()->deleteJson(self::BASE.'/failed/'.$a)->assertOk()->assertJsonPath('data.results.0.ok', true);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $a]);
        $this->asQueueAdmin()->deleteJson(self::BASE.'/failed/'.$a)->assertStatus(404);

        $this->asQueueAdmin()->deleteJson(self::BASE.'/failed', ['uuids' => [$b, $c, $a]])
            ->assertOk()->assertJsonPath('data.succeeded', 2)->assertJsonPath('data.failed', 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * 처리 기록 목록은 결과·검색 대상·기록 일시로 거르고, 단건은 없으면 404 다.
     *
     * @scenario list=logs, filter=status|search_field|created_date
     *
     * @effects logs_list_filters
     *
     * @return void 반환값 없음
     */
    public function test_처리기록_목록과_필터(): void
    {
        $now = Carbon::now();
        $row = fn (string $status, string $name, ?string $error, Carbon $at) => [
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'job_class' => 'App\\Jobs\\'.$name, 'display_name' => 'App\\Jobs\\'.$name, 'status' => $status,
            'attempts' => 1, 'exception_message' => $error, 'created_at' => $at->format('Y-m-d H:i:s'),
        ];
        QueueJobLog::query()->insert([
            $row('processed', 'AlphaJob', null, $now),
            $row('failed', 'BetaJob', 'connection refused', $now),
            $row('processed', 'GammaJob', null, $now->copy()->subDays(20)),
        ]);

        $api = $this->asQueueAdmin();
        $names = fn ($r) => collect($r->json('data.data'))->pluck('display_name')->sort()->values()->all();

        $api->getJson(self::BASE.'/logs')->assertOk()->assertJsonCount(3, 'data.data');
        $this->assertSame(['App\\Jobs\\BetaJob'], $names($api->getJson(self::BASE.'/logs?status=failed')));
        $this->assertSame(['App\\Jobs\\BetaJob'], $names($api->getJson(self::BASE.'/logs?search_field=error&search_keyword=refused')));
        $this->assertSame(['App\\Jobs\\AlphaJob'], $names($api->getJson(self::BASE.'/logs?search_field=job&search_keyword=Alpha')));
        $this->assertSame(
            ['App\\Jobs\\AlphaJob', 'App\\Jobs\\BetaJob'],
            $names($api->getJson(self::BASE.'/logs?created_start_date='.$now->copy()->subDays(1)->toDateString()))
        );
        $api->getJson(self::BASE.'/logs?status=released')->assertStatus(422);

        $id = QueueJobLog::query()->where('status', 'failed')->value('id');
        $api->getJson(self::BASE.'/logs/'.$id)->assertOk()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.exception_message', 'connection refused');
        $api->getJson(self::BASE.'/logs/999999999')->assertStatus(404);
    }

    /**
     * 대기 목록은 sort_by(created_at·available_at·attempts)·sort_order 로 정렬하고, 지정이 없으면 최근 등록순(id 역순)이다.
     *
     * @scenario list=pending, driver=database, sort=default|attempts|available_at|created_at
     *
     * @effects list_sort_orders_rows
     *
     * @return void 반환값 없음
     */
    public function test_대기_목록_정렬(): void
    {
        foreach (['p-one', 'p-two', 'p-three'] as $label) {
            Queue::connection('database')->push(new QueueProbeJob($label), '', 'default');
        }
        $ids = DB::table('jobs')->orderBy('id')->pluck('id')->all();
        // attempts: one=2, two=0, three=1 / available_at: one 가장 늦음, two 가장 이름 / created_at: two 가장 이름
        $base = Carbon::now()->getTimestamp();
        DB::table('jobs')->where('id', $ids[0])->update(['attempts' => 2, 'available_at' => $base + 300, 'created_at' => $base - 10]);
        DB::table('jobs')->where('id', $ids[1])->update(['attempts' => 0, 'available_at' => $base + 100, 'created_at' => $base - 30]);
        DB::table('jobs')->where('id', $ids[2])->update(['attempts' => 1, 'available_at' => $base + 200, 'created_at' => $base - 20]);

        $api = $this->asQueueAdmin();
        $order = fn (string $query) => collect($api->getJson(self::BASE.'/pending'.$query)->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertSame([$ids[2], $ids[1], $ids[0]], $order(''));
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $order('?sort_by=attempts&sort_order=asc'));
        $this->assertSame([$ids[0], $ids[2], $ids[1]], $order('?sort_by=available_at&sort_order=desc'));
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $order('?sort_by=created_at&sort_order=asc'));
        $api->getJson(self::BASE.'/pending?sort_by=payload')->assertStatus(422)->assertJsonValidationErrors('sort_by');
    }

    /**
     * 실패 목록은 sort_by(failed_at·id)·sort_order 로 정렬하고, 지정이 없으면 최근 실패순이다.
     *
     * @scenario list=failed, sort=default|failed_at|id
     *
     * @effects list_sort_orders_rows
     *
     * @return void 반환값 없음
     */
    public function test_실패_목록_정렬(): void
    {
        $a = $this->makeFailedJob('sort-a');
        $b = $this->makeFailedJob('sort-b');
        $c = $this->makeFailedJob('sort-c');
        DB::table('failed_jobs')->where('uuid', $a)->update(['failed_at' => Carbon::now()->subHours(1)]);
        DB::table('failed_jobs')->where('uuid', $b)->update(['failed_at' => Carbon::now()->subHours(3)]);
        DB::table('failed_jobs')->where('uuid', $c)->update(['failed_at' => Carbon::now()->subHours(2)]);

        $api = $this->asQueueAdmin();
        $order = fn (string $query) => collect($api->getJson(self::BASE.'/failed'.$query)->assertOk()->json('data.data'))->pluck('uuid')->all();

        $this->assertSame([$a, $c, $b], $order(''));
        $this->assertSame([$b, $c, $a], $order('?sort_by=failed_at&sort_order=asc'));
        $this->assertSame([$a, $b, $c], $order('?sort_by=id&sort_order=asc'));
        $this->assertSame([$c, $b, $a], $order('?sort_by=id&sort_order=desc'));
        $api->getJson(self::BASE.'/failed?sort_order=up')->assertStatus(422)->assertJsonValidationErrors('sort_order');
    }

    /**
     * 처리 기록은 sort_by(finished_at·duration_ms·attempts)·sort_order 로 정렬하고, 지정이 없으면 최근 기록순이다.
     * 같은 값은 id 로 순서를 정한다(페이지 경계 중복·누락 방지).
     *
     * @scenario list=logs, sort=default|finished_at|duration_ms|attempts
     *
     * @effects list_sort_orders_rows
     *
     * @return void 반환값 없음
     */
    public function test_처리기록_정렬(): void
    {
        $now = Carbon::now();
        $row = fn (string $name, int $duration, int $attempts, Carbon $finished, Carbon $created) => [
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'job_class' => 'App\\Jobs\\'.$name, 'display_name' => $name, 'status' => 'processed',
            'attempts' => $attempts, 'duration_ms' => $duration,
            'finished_at' => $finished->format('Y-m-d H:i:s'), 'created_at' => $created->format('Y-m-d H:i:s'),
        ];
        QueueJobLog::query()->insert([
            $row('L1', 300, 1, $now->copy()->subMinutes(5), $now->copy()->subMinutes(1)),
            $row('L2', 100, 3, $now->copy()->subMinutes(1), $now->copy()->subMinutes(3)),
            $row('L3', 200, 1, $now->copy()->subMinutes(3), $now->copy()->subMinutes(2)),
        ]);

        $api = $this->asQueueAdmin();
        $order = fn (string $query) => collect($api->getJson(self::BASE.'/logs'.$query)->assertOk()->json('data.data'))->pluck('display_name')->all();

        $this->assertSame(['L1', 'L3', 'L2'], $order(''));
        $this->assertSame(['L2', 'L3', 'L1'], $order('?sort_by=finished_at&sort_order=desc'));
        $this->assertSame(['L2', 'L3', 'L1'], $order('?sort_by=duration_ms&sort_order=asc'));
        $this->assertSame(['L2', 'L3', 'L1'], $order('?sort_by=attempts&sort_order=desc'));
        $this->assertSame(['L1', 'L3', 'L2'], $order('?sort_by=attempts&sort_order=asc'));
    }

    /**
     * 큐 상태는 요약(driver·measurable·pending_count·failed_count·workers)과 보고서·등록 목록을 준다.
     *
     * @scenario surface=status, driver=database|sync
     *
     * @effects status_carries_k7_summary
     *
     * @return void 반환값 없음
     */
    public function test_큐_상태는_요약과_보고서를_준다(): void
    {
        $this->makeFailedJob();
        Queue::connection('database')->push(new QueueProbeJob('pending'), '', 'default');

        $response = $this->asQueueAdmin()->getJson(self::BASE.'/status')->assertOk();
        $response->assertJsonPath('data.driver', 'database')
            ->assertJsonPath('data.measurable', true)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonStructure(['data' => [
                'connection', 'workers' => ['registered', 'alive', 'warning_count', 'long_worker'],
                'report' => ['rows', 'commands'], 'entries', 'connections',
            ]]);

        config(['queue.default' => 'sync']);
        $summary = app(JobQueueService::class)->summary();
        $this->assertSame(['driver', 'connection', 'measurable', 'pending_count', 'failed_count', 'workers'], array_keys($summary));
        $this->assertFalse($summary['measurable']);
        $this->assertNull($summary['pending_count']);
        $this->assertSame(1, $summary['failed_count']);
    }
}
