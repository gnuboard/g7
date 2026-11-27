<?php

namespace Tests\Feature\Api\Admin;

use App\Helpers\TimezoneHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\ScheduleLookupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ScheduleController — DB 등록제(7.2.0) 목록 필터·응답 필드·등록/수정 검증 Feature 테스트
 *
 * 화면 data_source 가 보내는 쿼리 키(search_field·search_keyword·extension_type·…)가 서버에서
 * 실제로 결과를 좁히는지, 소유 행 잠금·다국어 명칭·중복 방지 분 단위 입력이 지켜지는지 검증한다.
 * 같은 DB 를 쓰는 다른 테스트 행과 섞이지 않도록 행마다 고유 표식(marker)을 붙이고 표식으로 좁혀 센다.
 */
class ScheduleControllerRegistryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $token;

    private string $marker;

    /**
     * 관리자와 토큰, 행 표식을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
        $this->marker = 'reg'.Str::lower(Str::random(8));
    }

    /**
     * 스케줄 권한 전부를 가진 관리자를 만듭니다.
     *
     * @return User 관리자 사용자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.schedules.read', 'core.schedules.create', 'core.schedules.update', 'core.schedules.delete', 'core.schedules.run'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => 'core',
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $testRole = Role::create([
            'identifier' => 'schedule_registry_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'is_active' => true,
        ]);
        $testRole->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now()]);

        return $user->fresh();
    }

    /**
     * 인증 헤더를 붙인 요청을 돌려줍니다.
     *
     * @return static 인증된 테스트 요청
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 표식이 붙은 스케줄 행을 만듭니다.
     *
     * @param  string  $suffix  이름 뒤에 붙일 구분자
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Schedule 생성된 스케줄
     */
    private function makeSchedule(string $suffix, array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => $this->marker.'-'.$suffix,
            'label' => ['ko' => $this->marker.' 작업 '.$suffix, 'en' => $this->marker.' task '.$suffix],
            'description' => ['ko' => '설명', 'en' => 'desc'],
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    /**
     * 저장된 다음 실행이 이미 지났으면(스케줄러가 멈춘 사이트) 목록·상세는 주기로 다시 계산한 시각을 보여 준다.
     * 비활성 행은 저장값 그대로다.
     *
     * 수정 전에는 개발 사이트 52행 중 36행이 지난 시각을 「다음 실행」으로 보였다(2026-10-17 라이브 실측).
     *
     * @scenario case=next_run_display
     *
     * @effects schedule_next_run_never_shows_past_time
     *
     * @return void 반환값 없음
     */
    public function test_past_next_run_is_recomputed_from_the_cron_for_display(): void
    {
        $active = $this->makeSchedule('stale-next', ['expression' => '5 5 * * *', 'frequency' => 'custom']);
        $inactive = $this->makeSchedule('stale-next-off', ['expression' => '5 5 * * *', 'frequency' => 'custom', 'is_active' => false]);
        $past = now()->subHours(3)->startOfSecond();
        DB::table('schedules')->whereIn('id', [$active->id, $inactive->id])->update(['next_run_at' => $past]);

        $expected = TimezoneHelper::toUserTimezone(
            Carbon::instance(app(ScheduleLookupService::class)->nextRunAt($active->fresh()))
        );
        $stale = TimezoneHelper::toUserTimezone($past);

        $rows = collect($this->authRequest()->getJson('/api/admin/schedules?'.http_build_query(['search_field' => 'identifier', 'search_keyword' => $this->marker.'-stale-next', 'per_page' => 100]))->assertOk()->json('data.data'))->keyBy('name');

        $this->assertSame($expected, $rows[$this->marker.'-stale-next']['next_run_at']);
        $this->assertNotSame($stale, $rows[$this->marker.'-stale-next']['next_run_at']);
        $this->assertSame($stale, $rows[$this->marker.'-stale-next-off']['next_run_at']);

        $detail = $this->authRequest()->getJson('/api/admin/schedules/'.$active->id)->assertOk();
        $this->assertSame($expected, $detail->json('data.next_run_at'));
    }

    /**
     * 목록을 조회해 표식 행의 이름만 정렬해 돌려줍니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return array<int, string> 응답에 담긴 표식 행 이름
     */
    private function listNames(array $query): array
    {
        $response = $this->authRequest()->getJson('/api/admin/schedules?'.http_build_query($query + ['per_page' => 100]));
        $response->assertOk();

        $names = collect($response->json('data.data'))
            ->pluck('name')
            ->filter(fn ($name) => str_starts_with((string) $name, $this->marker))
            ->values()
            ->all();
        sort($names);

        return $names;
    }

    // ── 검색대상 + 키워드 ───────────────────────────────────

    /**
     * 검색대상 「명령」은 명령 문자열만 본다(명칭에 같은 글자가 있어도 걸리지 않는다).
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_search_field_command_matches_command_only(): void
    {
        $this->makeSchedule('a', ['command' => 'cache:clear --tag='.$this->marker]);
        $this->makeSchedule('b');

        $this->assertSame(
            [$this->marker.'-a'],
            $this->listNames(['search_field' => 'command', 'search_keyword' => 'tag='.$this->marker])
        );
        // 명칭에만 있는 글자는 명령 검색에 걸리지 않는다
        $this->assertSame([], $this->listNames(['search_field' => 'command', 'search_keyword' => $this->marker.' 작업']));
    }

    /**
     * 검색대상 「명칭」은 label 의 모든 번역과 이름을 본다(현재 로케일이 아닌 번역도 걸린다).
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_search_field_name_matches_any_label_translation(): void
    {
        $this->makeSchedule('a', ['label' => ['ko' => '가나다', 'en' => $this->marker.'-english-only']]);
        $this->makeSchedule('b');

        $this->assertSame(
            [$this->marker.'-a'],
            $this->listNames(['search_field' => 'name', 'search_keyword' => $this->marker.'-english-only'])
        );
    }

    /**
     * 검색대상 「식별자」는 기계 이름(name)만 본다 — 명칭 번역은 보지 않는다.
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_search_field_identifier_matches_machine_name_only(): void
    {
        $this->makeSchedule('alpha');
        $this->makeSchedule('beta', ['label' => ['ko' => 'alpha 처럼 보이는 명칭', 'en' => 'alpha-like']]);

        $this->assertSame(
            [$this->marker.'-alpha'],
            $this->listNames(['search_field' => 'identifier', 'search_keyword' => $this->marker.'-alpha'])
        );
    }

    /**
     * 검색대상 「전체」(또는 미지정)는 명칭·이름·명령·설명을 모두 본다.
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_search_field_all_covers_description(): void
    {
        $this->makeSchedule('a', ['description' => ['ko' => '설명 '.$this->marker.'-desc', 'en' => 'x']]);
        $this->makeSchedule('b');

        $this->assertSame(
            [$this->marker.'-a'],
            $this->listNames(['search_field' => 'all', 'search_keyword' => $this->marker.'-desc'])
        );
        $this->assertSame(
            [$this->marker.'-a'],
            $this->listNames(['search_keyword' => $this->marker.'-desc'])
        );
    }

    /**
     * 키워드의 LIKE 와일드카드는 글자 그대로 찾는다(`%` 가 전부를 뜻하지 않는다).
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_keyword_like_wildcards_are_literal(): void
    {
        $this->makeSchedule('a');

        $this->assertSame([], $this->listNames(['search_field' => 'identifier', 'search_keyword' => $this->marker.'%']));
    }

    /**
     * 검색대상에 정해지지 않은 값이 오면 422 다.
     *
     * @scenario case=keyword_search
     *
     * @effects schedule_keyword_search_scoped_by_field
     *
     * @return void 반환값 없음
     */
    public function test_invalid_search_field_is_rejected(): void
    {
        $this->authRequest()
            ->getJson('/api/admin/schedules?search_field=description&search_keyword=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors('search_field');
    }

    // ── 소유 필터 ───────────────────────────────────────────

    /**
     * 소유 `custom` 은 직접 등록(소유자 없음) 행만, 모듈+식별자는 그 확장 행만 고른다.
     *
     * @scenario case=owner_filter
     *
     * @effects schedule_owner_filter_custom_and_extension
     *
     * @return void 반환값 없음
     */
    public function test_owner_filter_custom_and_extension_identifier(): void
    {
        $this->makeSchedule('custom');
        $this->makeSchedule('core', ['extension_type' => 'core', 'extension_identifier' => 'core']);
        $this->makeSchedule('board', ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-board']);
        $this->makeSchedule('shop', ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce']);

        $this->assertSame([$this->marker.'-custom'], $this->listNames(['extension_type' => 'custom']));
        $this->assertSame([$this->marker.'-core'], $this->listNames(['extension_type' => 'core']));
        $this->assertSame(
            [$this->marker.'-board'],
            $this->listNames(['extension_type' => 'module', 'extension_identifier' => 'sirsoft-board'])
        );
        $this->assertSame(
            [$this->marker.'-board', $this->marker.'-shop'],
            $this->listNames(['extension_type' => 'module'])
        );
        // 「전체」는 조건을 걸지 않는다
        $this->assertCount(4, $this->listNames(['extension_type' => 'all']));
    }

    /**
     * 정해지지 않은 소유 값은 422 다.
     *
     * @scenario case=owner_filter
     *
     * @effects schedule_owner_filter_custom_and_extension
     *
     * @return void 반환값 없음
     */
    public function test_invalid_owner_type_is_rejected(): void
    {
        $this->authRequest()
            ->getJson('/api/admin/schedules?extension_type=theme')
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension_type');
    }

    // ── 기본·상세 필터 ─────────────────────────────────────

    /**
     * 각 필터 축이 해당 값의 행만 남기는지 검증합니다.
     *
     * @scenario case=filter_axes
     *
     * @effects schedule_list_filter_axes_narrow
     *
     * @param  array<string, mixed>  $matchAttrs  걸려야 하는 행의 속성
     * @param  array<string, mixed>  $otherAttrs  걸리지 않아야 하는 행의 속성
     * @param  array<string, string>  $query  필터 쿼리
     * @return void 반환값 없음
     */
    #[DataProvider('filterAxisProvider')]
    public function test_filter_axis_narrows_rows(array $matchAttrs, array $otherAttrs, array $query): void
    {
        $this->makeSchedule('hit', $matchAttrs);
        $this->makeSchedule('miss', $otherAttrs);

        $this->assertSame([$this->marker.'-hit'], $this->listNames($query));
        // 같은 축의 「전체」는 둘 다 남긴다 — 이력 기록 방식은 Enum 값에 `all` 이 있어 「전체」가 빈 값이다
        $wildcard = array_map(fn () => 'all', $query);
        if (array_key_exists('history_mode', $wildcard)) {
            $wildcard['history_mode'] = '';
        }
        $this->assertCount(2, $this->listNames($wildcard));
    }

    /**
     * 필터 축별 (걸리는 행, 안 걸리는 행, 쿼리) 조합.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}> 축 이름 => (걸리는 행 속성, 안 걸리는 행 속성, 쿼리)
     */
    public static function filterAxisProvider(): array
    {
        return [
            '유형' => [['type' => 'url', 'command' => 'https://example.com'], ['type' => 'artisan'], ['type' => 'url']],
            '주기' => [['frequency' => 'everyThirtySeconds'], ['frequency' => 'daily'], ['frequency' => 'everyThirtySeconds']],
            '상태' => [['is_active' => false], ['is_active' => true], ['status' => 'inactive']],
            '최근 결과' => [['last_result' => 'failed'], ['last_result' => 'success'], ['last_result' => 'failed']],
            '실행 방식' => [['dispatch_mode' => 'queue'], ['dispatch_mode' => 'sync'], ['dispatch_mode' => 'queue']],
            '실행 이력 — 실패만' => [['history_mode' => 'failures_only'], ['history_mode' => 'all'], ['history_mode' => 'failures_only']],
            '실행 이력 — 모든 실행' => [['history_mode' => 'all'], ['history_mode' => 'failures_only'], ['history_mode' => 'all']],
            '중복 방지 켬' => [['without_overlapping' => 30], ['without_overlapping' => 0], ['overlap' => 'on']],
            '중복 방지 끔' => [['without_overlapping' => 0], ['without_overlapping' => 1440], ['overlap' => 'off']],
        ];
    }

    /**
     * 등록일 범위(created_start_date/created_end_date)가 그 날짜 밖의 행을 뺀다.
     *
     * @scenario case=filter_axes
     *
     * @effects schedule_list_filter_axes_narrow
     *
     * @return void 반환값 없음
     */
    public function test_created_date_range_filter(): void
    {
        $old = $this->makeSchedule('old');
        $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $this->makeSchedule('new');

        $this->assertSame([$this->marker.'-new'], $this->listNames([
            'created_start_date' => now()->subDays(7)->toDateString(),
            'created_end_date' => now()->addDay()->toDateString(),
        ]));
    }

    // ── 응답 필드 ───────────────────────────────────────────

    /**
     * 목록 행이 소유자·잠금 필드·분 단위 중복 방지·삭제 능력을 소유 여부에 맞게 내려준다.
     *
     * @scenario case=row_fields
     *
     * @effects schedule_row_exposes_owner_lock_abilities
     *
     * @return void 반환값 없음
     */
    public function test_list_row_exposes_owner_lock_and_abilities(): void
    {
        $this->makeSchedule('custom', ['without_overlapping' => 0]);
        $this->makeSchedule('core', ['extension_type' => 'core', 'extension_identifier' => 'core', 'without_overlapping' => 30]);

        $rows = collect($this->authRequest()
            ->getJson('/api/admin/schedules?per_page=100&search_field=identifier&search_keyword='.$this->marker)
            ->assertOk()
            ->json('data.data'))->keyBy('name');

        $custom = $rows[$this->marker.'-custom'];
        $this->assertSame(['type' => 'custom', 'identifier' => null, 'name' => __('schedule.owner.custom')], $custom['owner']);
        $this->assertFalse($custom['is_owned']);
        $this->assertSame([], $custom['locked_fields']);
        $this->assertSame(0, $custom['without_overlapping']);
        $this->assertTrue($custom['abilities']['can_delete']);
        $this->assertSame($this->marker.' 작업 custom', $custom['label_localized']);

        $core = $rows[$this->marker.'-core'];
        $this->assertSame('core', $core['owner']['type']);
        $this->assertTrue($core['is_owned']);
        $this->assertEqualsCanonicalizing(['command', 'type', 'name', 'enabled_condition', 'timezone'], $core['locked_fields']);
        $this->assertSame(30, $core['without_overlapping']);
        // 소유 행은 선언 동기화가 다시 만드는 행이라 삭제할 수 없다
        $this->assertFalse($core['abilities']['can_delete']);
    }

    /**
     * owners API 가 소유자별 스케줄 수를 「직접 등록」(custom) 항목까지 담아 준다.
     *
     * @scenario case=owners_api
     *
     * @effects schedule_owners_api_lists_custom_bucket
     *
     * @return void 반환값 없음
     */
    public function test_owners_endpoint_includes_custom_bucket(): void
    {
        $this->makeSchedule('custom');

        $owners = collect($this->authRequest()->getJson('/api/admin/schedules/owners')->assertOk()->json('data'));

        $custom = $owners->firstWhere('type', 'custom');
        $this->assertNotNull($custom);
        $this->assertNull($custom['identifier']);
        $this->assertGreaterThanOrEqual(1, $custom['schedule_count']);
    }

    // ── 등록 검증 ───────────────────────────────────────────

    /**
     * 등록 요청 기본값을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 요청 본문
     */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->marker.'-new',
            'label' => ['ko' => '새 작업', 'en' => 'New task'],
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '0 0 * * *',
            'frequency' => 'daily',
        ], $overrides);
    }

    /**
     * 명칭을 빈 객체로 보내면 422(직접 등록은 명칭 필수), 문자열이면 다국어 객체로 올려 저장한다.
     *
     * @scenario case=create_label
     *
     * @effects schedule_create_label_required_and_promoted
     *
     * @return void 반환값 없음
     */
    public function test_create_label_required_and_string_promoted(): void
    {
        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['label' => ['ko' => '', 'en' => '']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');

        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['label' => '문자열 명칭']))
            ->assertCreated();

        $saved = Schedule::where('name', $this->marker.'-new')->firstOrFail();
        $this->assertSame('문자열 명칭', $saved->label[app()->getLocale()]);
        $this->assertSame('문자열 명칭', $saved->label[config('app.site_locale')]);
    }

    /**
     * 7.2.0 이전 형식(이름만)으로 보내도 이름을 명칭으로 채워 등록된다.
     *
     * @scenario case=create_label
     *
     * @effects schedule_create_label_required_and_promoted
     *
     * @return void 반환값 없음
     */
    public function test_create_legacy_payload_without_label_uses_name(): void
    {
        $payload = $this->createPayload();
        unset($payload['label']);

        $this->authRequest()->postJson('/api/admin/schedules', $payload)->assertCreated();

        $saved = Schedule::where('name', $this->marker.'-new')->firstOrFail();
        $this->assertSame($this->marker.'-new', $saved->localizedLabel());
    }

    /**
     * 중복 방지 분은 0..10080 이고, 옛 boolean true 는 1440 분으로 저장된다.
     *
     * @scenario case=overlap_bounds
     *
     * @effects schedule_overlap_minutes_bounded
     *
     * @param  mixed  $value  보낼 값
     * @param  int|null  $stored  저장될 분(null 이면 422 기대)
     * @return void 반환값 없음
     */
    #[DataProvider('overlapValueProvider')]
    public function test_create_without_overlapping_minutes_bounds($value, ?int $stored): void
    {
        $response = $this->authRequest()->postJson('/api/admin/schedules', $this->createPayload(['without_overlapping' => $value]));

        if ($stored === null) {
            $response->assertStatus(422)->assertJsonValidationErrors('without_overlapping');

            return;
        }

        $response->assertCreated();
        $this->assertSame($stored, Schedule::where('name', $this->marker.'-new')->value('without_overlapping'));
    }

    /**
     * 중복 방지 입력 경계값.
     *
     * @return array<string, array{0: mixed, 1: int|null}> 경우 이름 => (보낼 값, 저장될 분 — null 이면 422)
     */
    public static function overlapValueProvider(): array
    {
        return [
            '0 = 끔' => [0, 0],
            '1분' => [1, 1],
            '상한 10080' => [10080, 10080],
            '상한 초과 10081' => [10081, null],
            '음수' => [-1, null],
            '옛 boolean true' => [true, 1440],
            '옛 boolean false' => [false, 0],
        ];
    }

    /**
     * 운영자가 넣을 수 없는 필드(조건·명령 클래스·소유자)는 422 다.
     *
     * @scenario case=prohibited_fields
     *
     * @effects schedule_prohibited_fields_rejected
     *
     * @param  string  $field  금지 필드
     * @param  mixed  $value  보낼 값
     * @return void 반환값 없음
     */
    #[DataProvider('prohibitedFieldProvider')]
    public function test_create_rejects_prohibited_fields(string $field, $value): void
    {
        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    /**
     * 등록 금지 필드 목록.
     *
     * @return array<string, array{0: string, 1: mixed}> 경우 이름 => (금지 필드, 보낼 값)
     */
    public static function prohibitedFieldProvider(): array
    {
        return [
            '실행 조건' => ['enabled_condition', [['source' => 'config', 'key' => 'app.debug', 'operator' => 'truthy']]],
            '명령 클래스' => ['command_class', 'App\\Console\\Commands\\Foo'],
            '소유 타입' => ['extension_type', 'module'],
            '소유 식별자' => ['extension_identifier', 'sirsoft-board'],
        ];
    }

    /**
     * Job 유형은 보안 허용목록에 없는 클래스를 거부한다.
     *
     * @scenario case=prohibited_fields
     *
     * @effects schedule_prohibited_fields_rejected
     *
     * @return void 반환값 없음
     */
    public function test_create_job_type_rejects_unlisted_class(): void
    {
        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['type' => 'job', 'command' => 'App\\Jobs\\NotAllowedJob']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    // ── 수정 검증 ───────────────────────────────────────────

    /**
     * 소유 행은 잠금 필드를 같은 값으로 다시 보내면 통과하고, 실제로 바꾸면 422 다.
     *
     * @scenario case=owned_lock
     *
     * @effects schedule_owned_locked_fields_reject_change
     *
     * @return void 반환값 없음
     */
    public function test_update_owned_row_locks_command_type_name(): void
    {
        $core = $this->makeSchedule('core', ['extension_type' => 'core', 'extension_identifier' => 'core']);

        // 폼 전체를 같은 값으로 보내면서 운영 필드만 바꾼다 → 통과
        $this->authRequest()->putJson('/api/admin/schedules/'.$core->id, [
            'name' => $core->name,
            'type' => 'artisan',
            'command' => 'inspire',
            'without_overlapping' => 60,
        ])->assertOk();
        $this->assertSame(60, $core->fresh()->without_overlapping);

        foreach (['command' => 'cache:clear', 'type' => 'shell', 'name' => $core->name.'-x'] as $field => $value) {
            $this->authRequest()
                ->putJson('/api/admin/schedules/'.$core->id, [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
        $this->assertSame('inspire', $core->fresh()->command);
    }

    /**
     * 직접 등록 행은 명칭을 비울 수 없다.
     *
     * @scenario case=create_label
     *
     * @effects schedule_create_label_required_and_promoted
     *
     * @return void 반환값 없음
     */
    public function test_update_custom_row_requires_label(): void
    {
        $custom = $this->makeSchedule('custom');

        $this->authRequest()
            ->putJson('/api/admin/schedules/'.$custom->id, ['label' => ['ko' => '', 'en' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');
    }

    /**
     * 소유 행을 복제하면 복제본은 직접 등록 행이 된다(소유자·조건·수정 기록을 물려받지 않는다).
     *
     * @scenario case=duplicate_custom
     *
     * @effects schedule_duplicate_becomes_custom
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_owned_row_becomes_custom(): void
    {
        $core = $this->makeSchedule('core', [
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'enabled_condition' => [['source' => 'config', 'key' => 'app.debug', 'operator' => 'truthy', 'default' => false]],
        ]);

        $response = $this->authRequest()->postJson('/api/admin/schedules/'.$core->id.'/duplicate')->assertCreated();

        $copy = Schedule::findOrFail($response->json('data.id'));
        $this->assertNull($copy->extension_type);
        $this->assertNull($copy->extension_identifier);
        $this->assertEmpty($copy->enabled_condition);
        $this->assertFalse($copy->isOwned());
        $this->assertStringContainsString($this->marker.' 작업 core', $copy->label['ko']);
    }

    // ── 검증 문구·입력 정규화 ─────────────────────

    /**
     * 작업명이 모든 언어에서 비면 작업명 오류만 낸다 — 식별자는 작업명으로 채우는 규칙이라 식별자 필수 오류를 겹쳐 내지 않는다.
     *
     * @scenario case=create_label
     *
     * @effects schedule_create_blank_label_reports_label_only
     *
     * @return void 반환값 없음
     */
    public function test_create_blank_label_reports_label_error_only(): void
    {
        $blank = $this->createPayload(['label' => ['ko' => '', 'en' => '']]);
        unset($blank['name']);

        $response = $this->authRequest()->postJson('/api/admin/schedules', $blank)->assertStatus(422);
        $response->assertJsonValidationErrors('label')->assertJsonMissingValidationErrors('name');

        $missing = $this->createPayload();
        unset($missing['name'], $missing['label']);

        $this->authRequest()->postJson('/api/admin/schedules', $missing)
            ->assertStatus(422)
            ->assertJsonValidationErrors('label')
            ->assertJsonMissingValidationErrors('name');

        // 작업명이 있으면 식별자는 작업명으로 채워져 그대로 등록된다
        $named = $this->createPayload(['label' => ['ko' => $this->marker.' 작업명', 'en' => '']]);
        unset($named['name']);
        $this->authRequest()->postJson('/api/admin/schedules', $named)->assertCreated();
        $this->assertTrue(Schedule::where('name', $this->marker.' 작업명')->exists());
    }

    /**
     * 명령의 줄바꿈·연속 공백·앞뒤 공백은 저장 전에 공백 하나로 접는다(여러 줄 입력 칸 대응). 등록·수정 같다.
     *
     * @scenario case=command_normalize
     *
     * @effects schedule_command_whitespace_collapsed
     *
     * @return void 반환값 없음
     */
    public function test_command_whitespace_is_collapsed_before_validation(): void
    {
        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['command' => "  seo:prune-stats\r\n    --days=3 \n"]))
            ->assertCreated();

        $saved = Schedule::where('name', $this->marker.'-new')->firstOrFail();
        $this->assertSame('seo:prune-stats --days=3', $saved->command);

        $this->authRequest()
            ->putJson('/api/admin/schedules/'.$saved->id, ['command' => "cache:clear\n\t"])
            ->assertOk();
        $this->assertSame('cache:clear', $saved->fresh()->command);

        // 접은 뒤에도 비면 필수 오류다
        $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['name' => $this->marker.'-blank', 'command' => " \n "]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    /**
     * 일괄 삭제 성공 응답은 일괄 삭제 전용 문구(bulk_delete_success)를 쓴다.
     *
     * @scenario case=bulk_delete_message
     *
     * @effects schedule_bulk_delete_success_message
     *
     * @return void 반환값 없음
     */
    public function test_bulk_delete_uses_bulk_success_message(): void
    {
        $a = $this->makeSchedule('bulk-a');
        $b = $this->makeSchedule('bulk-b');

        $this->authRequest()
            ->deleteJson('/api/admin/schedules/bulk', ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('message', __('schedule.bulk_delete_success'));

        $this->assertNull(Schedule::find($a->id));
        $this->assertNull(Schedule::find($b->id));
    }

    /**
     * 검증 문구·칸 이름·유형 표기가 화면 용어(식별자·작업명·명령·제한 시간·중복 실행 방지·활성 여부, 유형 배지)와 같다.
     *
     * @scenario case=terminology
     *
     * @effects schedule_validation_terms_match_ui
     *
     * @return void 반환값 없음
     */
    public function test_validation_terms_match_ui_terms(): void
    {
        app()->setLocale('ko');

        $this->assertSame('식별자', __('schedule.attributes.name'));
        $this->assertSame('작업명', __('schedule.attributes.label'));
        $this->assertSame('명령', __('schedule.attributes.command'));
        $this->assertSame('제한 시간', __('schedule.attributes.timeout'));
        $this->assertSame('중복 실행 방지', __('schedule.attributes.without_overlapping'));
        $this->assertSame('활성 여부', __('schedule.attributes.is_active'));
        $this->assertSame('식별자는 필수입니다.', __('schedule.validation.name_required'));
        $this->assertSame('작업명은 필수입니다.', __('schedule.validation.label_required'));
        $this->assertSame('명령은 필수입니다.', __('schedule.validation.command_required'));
        $this->assertSame('Artisan', __('schedule.type.artisan'));
        $this->assertSame('Shell', __('schedule.type.shell'));

        $response = $this->authRequest()
            ->postJson('/api/admin/schedules', $this->createPayload(['command' => '', 'timeout' => 0]))
            ->assertStatus(422);
        $this->assertSame('명령은 필수입니다.', $response->json('errors.command.0'));
        $this->assertStringContainsString('제한 시간', (string) $response->json('errors.timeout.0'));

        $row = $this->makeSchedule('terms');
        $this->authRequest()->getJson('/api/admin/schedules/'.$row->id)
            ->assertOk()
            ->assertJsonPath('data.type_label', 'Artisan');
    }

    /**
     * 편집 창이 보내는 폼 전체(손대지 않은 칸은 저장값 그대로)를 받은 수정은, 실제로 바뀐 칸만 「운영자 수정됨」으로 남긴다.
     *
     * 확장이 등록한 작업(제한 시간 비어 있음)의 주기만 바꿔 저장한다 — 제한 시간·큐 연결·중복 방지·스위치 값은 저장값과 같다.
     *
     * @scenario case=form_untouched
     *
     * @effects schedule_form_edit_keeps_untouched_fields
     *
     * @return void 반환값 없음
     */
    public function test_full_form_update_records_only_changed_fields(): void
    {
        $row = $this->makeSchedule('owned-form', [
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-page',
            'frequency' => 'daily',
            'expression' => '0 0 * * *',
            'timeout' => null,
        ]);

        $this->authRequest()->putJson('/api/admin/schedules/'.$row->id, $this->editFormPayload($row, [
            'frequency' => 'weekly',
            'expression' => '0 0 * * 0',
        ]))->assertOk();

        $saved = $row->fresh();
        $this->assertNull($saved->timeout);
        $this->assertSame('0 0 * * 0', $saved->expression);
        $overrides = $saved->user_overrides ?? [];
        sort($overrides);
        $this->assertSame(['expression', 'frequency'], $overrides);
    }

    /**
     * 제한 시간을 비운 채 저장하면 null 로 저장된다(빈 문자열도, null 도) — 실행기가 유형별 기본을 쓴다.
     *
     * @scenario case=form_untouched
     *
     * @effects schedule_form_edit_keeps_untouched_fields
     *
     * @return void 반환값 없음
     */
    public function test_blank_timeout_is_stored_as_null(): void
    {
        foreach (['', null] as $blank) {
            $row = $this->makeSchedule('timeout-'.var_export($blank, true), [
                'extension_type' => 'module',
                'extension_identifier' => 'sirsoft-page',
                'timeout' => 120,
            ]);

            $this->authRequest()
                ->putJson('/api/admin/schedules/'.$row->id, $this->editFormPayload($row, ['timeout' => $blank]))
                ->assertOk();

            $saved = $row->fresh();
            $this->assertNull($saved->timeout);
            $this->assertSame(['timeout'], $saved->user_overrides);
        }
    }

    /**
     * 주기 「매일」에 선언 시각(04:10)이 있는 작업은, 주기를 그대로 두고 다른 칸만 바꾸면 실행 시각이 그대로다.
     *
     * @scenario case=form_untouched
     *
     * @effects schedule_form_edit_keeps_untouched_fields
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_frequency_keeps_declared_expression(): void
    {
        $row = $this->makeSchedule('declared-time', [
            'extension_type' => 'plugin',
            'extension_identifier' => 'sirsoft-sso',
            'frequency' => 'daily',
            'expression' => '10 4 * * *',
        ]);

        $this->authRequest()
            ->putJson('/api/admin/schedules/'.$row->id, $this->editFormPayload($row, ['notify_on_failure' => false]))
            ->assertOk();

        $saved = $row->fresh();
        $this->assertSame('10 4 * * *', $saved->expression);
        $this->assertSame(['notify_on_failure'], $saved->user_overrides);
    }

    /**
     * 직접 등록 작업은 값 전부가 운영자 것이라 수정해도 「운영자 수정」을 남기지 않고, 예전 판이 남긴 기록도 응답에 싣지 않는다.
     * 코어·확장 작업은 바꾼 칸을 그대로 남기고 응답에 싣는다(편집 창 「운영자 수정됨」 배지).
     *
     * @scenario case=form_untouched
     *
     * @effects schedule_overrides_only_for_owned_rows
     *
     * @return void 반환값 없음
     */
    public function test_direct_rows_do_not_record_overrides(): void
    {
        $custom = $this->makeSchedule('custom-edit');

        $this->authRequest()->putJson('/api/admin/schedules/'.$custom->id, $this->editFormPayload($custom, [
            'dispatch_mode' => 'queue',
            'history_mode' => 'failures_only',
        ]))->assertOk()->assertJsonPath('data.overridden_fields', []);
        $this->assertNull($custom->fresh()->user_overrides);

        // 예전 판이 직접 등록 행에 남긴 기록은 화면으로 내보내지 않는다
        Schedule::whereKey($custom->id)->toBase()->update(['user_overrides' => json_encode(['frequency'])]);
        $this->authRequest()->getJson('/api/admin/schedules/'.$custom->id)
            ->assertOk()
            ->assertJsonPath('data.overridden_fields', []);

        $owned = $this->makeSchedule('owned-edit', ['extension_type' => 'core', 'extension_identifier' => 'core']);
        $this->authRequest()->putJson('/api/admin/schedules/'.$owned->id, $this->editFormPayload($owned, [
            'history_mode' => 'failures_only',
        ]))->assertOk()->assertJsonPath('data.overridden_fields', ['history_mode']);
        $this->assertSame(['history_mode'], $owned->fresh()->user_overrides);

        // 일괄 켜기·끄기(한 번의 일괄 갱신)도 같은 규칙 — 소유 행만 기록한다
        $this->authRequest()->patchJson('/api/admin/schedules/bulk-status', [
            'ids' => [$custom->id, $owned->id],
            'is_active' => false,
        ])->assertOk();
        $this->assertEqualsCanonicalizing(['history_mode', 'is_active'], $owned->fresh()->user_overrides);
        $this->assertFalse($custom->fresh()->is_active);
        $this->assertSame(['frequency'], $custom->fresh()->user_overrides, '직접 등록 행에는 새 기록이 더해지지 않는다');
    }

    /**
     * 편집 창이 저장 단추에서 보내는 본문 — 잠긴 칸(이름·유형·명령)은 비활성이라 폼 값에 없고, 나머지는 저장값 그대로다.
     *
     * @param  Schedule  $row  수정 대상
     * @param  array<string, mixed>  $changes  운영자가 바꾼 칸
     * @return array<string, mixed> 요청 본문
     */
    private function editFormPayload(Schedule $row, array $changes = []): array
    {
        $row = $row->fresh();

        $payload = [
            'label' => $row->label ?? [],
            'description' => $row->description ?? [],
            'frequency' => $row->frequency->value,
            'expression' => $row->expression,
            'timeout' => $row->timeout,
            'without_overlapping' => $row->without_overlapping,
            'on_one_server' => (bool) $row->on_one_server,
            'dispatch_mode' => $row->dispatch_mode->value,
            'queue_connection' => $row->queue_connection,
            'queue_name' => $row->queue_name,
            'history_mode' => $row->history_mode->value,
            'notify_on_failure' => (bool) $row->notify_on_failure,
            'run_in_maintenance' => (bool) $row->run_in_maintenance,
            'is_active' => (bool) $row->is_active,
        ];

        if (! $row->isOwned()) {
            $payload += ['name' => $row->name, 'type' => $row->type->value, 'command' => $row->command];
        }

        return array_merge($payload, $changes);
    }
}
