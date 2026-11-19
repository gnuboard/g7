<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 옛 화면(7.1.x 템플릿, 회원 항목 조각 없음)에서 온 회원 폼 요청의 호환.
 *
 * 회원 항목을 그리는 화면은 항목 응답의 정의 버전 `fields_version` 을 요청에 늘 싣는다. 이 표식이 없는 가입, 관리자 회원 추가와 수정,
 * 내 정보 수정은 옛 화면으로 보고 운영자 추가 항목과 확장 선언 항목의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다.
 * 7.1.x 화면에 없던 코어 칸(가입의 주소, 홈페이지 등 — LegacyScreenFields)도 같다. 형식 규칙과 7.1.x 화면에 있던 코어 칸 규칙은 그대로다.
 * 옛 화면 요청에는 추가 정보 입력 대기 표시를 남기지 않는다 — 쓰기 요청이 403 `required_fields_pending` 으로 막히지 않고,
 * 빈 항목은 템플릿을 7.2.0 으로 올린 뒤 운영자가 채운다. 로그에 「옛 화면 호환」은 남긴다.
 */
class LegacyMemberFieldScreenTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    /**
     * 권한과 코어 회원 항목을 시딩하고 최고관리자 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $admin = User::factory()->create(['is_super' => true, 'nickname' => null]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->adminToken = $admin->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 관리자 인증 헤더를 만듭니다.
     *
     * @return array<string, string> 헤더
     */
    private function adminAuth(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken, 'Accept' => 'application/json'];
    }

    /**
     * 회원 항목을 만듭니다 (기본은 운영자 추가 항목).
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function field(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key.' 이름'], 'sort_order' => 500,
            'show_on_signup' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 코어 회원 항목의 속성을 바꿉니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function core(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', 'core.user')->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 지금 정의 버전을 반환합니다 (새 화면이 항목 응답에서 받는 값).
     *
     * @return string 정의 버전
     */
    private function version(): string
    {
        return app(FieldRegistry::class)->version('core.user');
    }

    /**
     * 가입 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 입력
     * @return TestResponse 응답
     */
    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => '옛화면회원',
            'email' => 'issue916+'.uniqid().'@example.com',
            'password' => 'Str0ng!Passw0rd#916',
            'password_confirmation' => 'Str0ng!Passw0rd#916',
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $overrides));
    }

    /**
     * 일반 회원과 그 인증 헤더를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return array{0: User, 1: array<string, string>} 회원과 헤더
     */
    private function member(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $token = $user->fresh()->createToken('member')->plainTextToken;

        return [$user->fresh(), ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    /**
     * 모듈 라우트 모양(`api/modules/{모듈}` 접두, `api` 그룹)의 글쓰기 대역 라우트를 세웁니다 — 추가 정보 입력 관문이 닿는 쓰기 요청.
     *
     * @return void 반환값 없음
     */
    private function registerWriteRoute(): void
    {
        Route::prefix('api/modules/sirsoft-board')
            ->name('api.modules.sirsoft-board.')
            ->middleware('api')
            ->group(function () {
                Route::post('boards/{slug}/posts', fn () => response()->json(['ok' => true], 201))
                    ->middleware('optional.sanctum')->name('boards.posts.store');
            });
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * 요청 사이에 인증 상태가 넘어가지 않게 가드를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 표식 없는 가입은 운영자 추가 필수 항목과 확장 선언 필수 항목을 건너뛰고 201 로 만들어진다. 추가 정보 입력 대기 표시가 없어
     * 다음 쓰기 요청이 403 으로 막히지 않고, 로그에 「옛 화면 호환」이 남는다.
     *
     * @scenario surface=signup, marker=absent, field=operator_and_extension_required
     *
     * @effects legacy_signup_skips_operator_required_fields, legacy_signup_member_not_deferred, legacy_signup_next_write_not_blocked, legacy_screen_compat_logged
     *
     * @return void 반환값 없음
     */
    public function test_unmarked_signup_skips_operator_required_fields_without_deferral(): void
    {
        $this->registerWriteRoute();
        // 확장 선언 항목은 선언한 확장이 켜져 있어야 항목으로 쓰인다.
        Plugin::factory()->create(['identifier' => 'vendor-sample916', 'status' => 'active']);
        Cache::flush();
        $this->field('lg916_job', ['required' => true]);
        $this->field('lg916_ext', ['required' => true, 'source_type' => 'plugin', 'source_identifier' => 'vendor-sample916']);
        $this->field('lg916_optional');
        Log::spy();

        $email = 'issue916+legacy@example.com';
        $response = $this->register(['email' => $email])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->required_fields_deferred_at, '옛 화면 가입에 추가 정보 입력 대기 표시가 붙었다');
        $this->assertSame([], app(RequiredFieldsChecker::class)->pendingKeys($user, fresh: true), '옛 화면 가입 회원이 추가 정보 입력 대상으로 판정됐다');
        $this->assertSame('옛화면회원', $user->name, '코어 기본 항목 값이 저장되지 않았다');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '옛 화면 호환')
                && $context['surface'] === FieldContext::SURFACE_SIGNUP
                && $context['skipped'] === ['lg916_job', 'lg916_ext'];
        })->once();

        // 다음 쓰기 요청은 추가 정보 입력 요구로 막히지 않는다.
        $this->resetAuth();
        $this->postJson('/api/modules/sirsoft-board/boards/free/posts', ['title' => '글'], [
            'Authorization' => 'Bearer '.$response->json('data.token'),
            'Accept' => 'application/json',
        ])->assertStatus(201);
    }

    /**
     * 운영자가 7.1.x 가입 화면에 없던 코어 칸(주소)을 가입 표시와 필수로 켜도 표식 없는 가입은 201 이다. 표식을 실은 가입은 종전대로 422,
     * 표식이 없어도 그 칸을 보내면 형식과 필수를 본다. 7.1.x 가입 화면에 있던 코어 칸(휴대폰)은 표식이 없어도 그대로 필수다.
     *
     * @scenario surface=signup, marker=absent, marker=present, field=core_missing_on_7_1
     *
     * @effects legacy_signup_skips_core_fields_missing_on_7_1, marked_signup_missing_core_field_rejected
     *
     * @return void 반환값 없음
     */
    public function test_core_field_missing_on_7_1_signup_is_relaxed_only_without_marker(): void
    {
        $this->core('address', ['required' => true, 'show_on_signup' => true]);
        $this->core('homepage', ['required' => true, 'show_on_signup' => true]);
        Log::spy();

        $this->register(['fields_version' => $this->version()])
            ->assertStatus(422)->assertJsonValidationErrors(['zipcode', 'homepage']);

        $email = 'issue916+coreaddr@example.com';
        $this->register(['email' => $email])->assertStatus(201);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertNull($user->zipcode);
        $this->assertNull($user->required_fields_deferred_at);

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, '옛 화면 호환')
            && $context['skipped'] === ['homepage', 'address'])->once();

        // 표식이 없어도 보낸 값은 형식을 본다.
        $this->register(['homepage' => 'not a url'])->assertStatus(422)->assertJsonValidationErrors(['homepage']);

        // 7.1.x 가입 화면에 있던 코어 칸은 그대로 필수다.
        $this->core('mobile', ['required' => true]);
        $this->register()->assertStatus(422)->assertJsonValidationErrors(['mobile']);
    }

    /**
     * 표식을 실은 가입(새 화면)은 종전대로 운영자 필수 항목이 빠지면 422 이고, 회원이 만들어지지 않으며 호환 로그도 남지 않는다.
     *
     * @scenario surface=signup, marker=present, field=operator_required
     *
     * @effects marked_signup_missing_required_rejected
     *
     * @return void 반환값 없음
     */
    public function test_marked_signup_missing_operator_required_field_is_rejected(): void
    {
        $this->field('lg916_job', ['required' => true]);
        Log::spy();

        $email = 'issue916+marked@example.com';
        $this->register(['email' => $email, 'fields_version' => $this->version()])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);
        $this->assertDatabaseMissing('users', ['email' => $email]);

        // 새 화면에서 빈 값을 내도 같다 — 「값이 없음」이 아니라 표식으로 가른다.
        $this->register(['email' => $email, 'fields_version' => $this->version(), 'custom_fields' => ['lg916_job' => '']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);

        $this->register(['email' => $email, 'fields_version' => $this->version(), 'custom_fields' => ['lg916_job' => '개발자']])->assertStatus(201);
        $this->assertNull(User::where('email', $email)->firstOrFail()->required_fields_deferred_at, '항목을 다 받은 가입에 나중 입력 표시가 붙었다');

        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/옛 화면 호환/'), \Mockery::any()]);
    }

    /**
     * 표식 없는 가입이라도 형식 규칙과 코어 기본 항목 규칙은 그대로다 — 보낸 값의 형식 위반, 보낸 빈 값, 필수로 바꾼 코어 항목 누락은 422.
     *
     * @scenario surface=signup, marker=absent, field=format_and_core
     *
     * @effects legacy_signup_format_rules_kept, legacy_signup_core_required_kept
     *
     * @return void 반환값 없음
     */
    public function test_unmarked_signup_keeps_format_and_core_rules(): void
    {
        $this->field('lg916_job', ['required' => true, 'type' => 'choice', 'options' => [['value' => 'student', 'label' => ['ko' => '학생']]]]);
        $this->field('lg916_code', ['constraints' => ['regex' => '/^[A-Z]{3}$/']]);

        $this->register(['custom_fields' => ['lg916_job' => 'hacker']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);
        $this->register(['custom_fields' => ['lg916_code' => 'abc']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_code']);
        $this->register(['custom_fields' => ['lg916_job' => '']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);

        $this->core('mobile', ['required' => true]);
        $this->register()->assertStatus(422)->assertJsonValidationErrors(['mobile']);
        $this->register(['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);

        // 값을 보냈으면 종전대로 저장된다.
        $email = 'issue916+sent@example.com';
        $this->register(['email' => $email, 'mobile' => '010-1234-5678', 'custom_fields' => ['lg916_job' => 'student']])->assertStatus(201);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('student', app(FieldValueService::class)->read($user, 'core.user')['lg916_job'] ?? null);
        $this->assertNull($user->required_fields_deferred_at, '건너뛴 항목이 없는데 나중 입력 표시가 붙었다');
    }

    /**
     * 표식 없는 가입에서 묶음 항목(기간·주소)은 통째로 보내지 않을 때만 건너뛴다 — 일부 칸만 보내면 나머지 필수 칸도 본다.
     *
     * 하위 경로마다 따로 건너뛰면 기간의 시작만, 주소의 우편번호만 보내도 통과해 「보냈으면 종전대로 필수와 형식」이 깨진다.
     *
     * @scenario surface=signup, marker=absent, field=composite_partial
     *
     * @effects legacy_signup_composite_partial_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unmarked_signup_rejects_partially_sent_composite_field(): void
    {
        $this->field('lg916_period', ['required' => true, 'type' => 'date_range']);
        $this->field('lg916_addr', ['required' => true, 'type' => 'address']);

        $this->register(['custom_fields' => ['lg916_period' => ['start' => '2026-01-01']]])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_period.end']);
        $this->register(['custom_fields' => ['lg916_addr' => ['zipcode' => '06236']]])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_addr.address']);

        // 통째로 보내지 않으면 종전대로 건너뛴다.
        $this->register()->assertStatus(201);

        // 다 보내면 저장된다.
        $this->register(['custom_fields' => [
            'lg916_period' => ['start' => '2026-01-01', 'end' => '2026-02-01'],
            'lg916_addr' => ['zipcode' => '06236', 'address' => '서울 강남구 테헤란로 1'],
        ]])->assertStatus(201);
    }

    /**
     * 운영자 필수 항목이 없는 사이트의 표식 없는 가입은 종전과 같다 — 나중 입력 표시도 호환 로그도 없다.
     *
     * @scenario surface=signup, marker=absent, field=none
     *
     * @effects legacy_signup_unchanged_without_operator_required
     *
     * @return void 반환값 없음
     */
    public function test_unmarked_signup_without_operator_required_fields_is_unchanged(): void
    {
        $this->field('lg916_optional');
        Log::spy();

        $email = 'issue916+plain@example.com';
        $this->register(['email' => $email])->assertStatus(201);
        $this->assertNull(User::where('email', $email)->firstOrFail()->required_fields_deferred_at);

        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/옛 화면 호환/'), \Mockery::any()]);
    }

    /**
     * 관리자 회원 추가 — 표식 없는 추가(옛 관리자 화면)는 운영자 필수 항목을 건너뛰고 만들며, 추가 정보 입력 대기 표시는 남기지 않는다.
     * 표식을 실은 추가는 종전대로 422 다.
     *
     * @scenario surface=admin_create, marker=absent, marker=present, field=operator_required
     *
     * @effects legacy_admin_create_skips_without_deferral, marked_admin_create_missing_required_rejected
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_follows_marker(): void
    {
        $this->field('lg916_grade', ['required' => true, 'edit_scope' => FieldDefinition::SCOPE_SELF]);
        $body = [
            'name' => '관리자추가', 'email' => 'issue916+admincreate@example.com',
            'password' => 'Str0ng!Passw0rd#916', 'password_confirmation' => 'Str0ng!Passw0rd#916',
        ];

        $this->postJson('/api/admin/users', $body + ['fields_version' => $this->version()], $this->adminAuth())
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_grade']);
        $this->assertDatabaseMissing('users', ['email' => $body['email']]);

        $this->postJson('/api/admin/users', $body, $this->adminAuth())->assertStatus(201);
        $user = User::where('email', $body['email'])->firstOrFail();
        $this->assertNull($user->required_fields_deferred_at, '옛 관리자 화면 추가에 추가 정보 입력 대기 표시가 붙었다');
        $this->assertSame([], app(RequiredFieldsChecker::class)->pendingKeys($user, fresh: true));
    }

    /**
     * 관리자 회원 수정 — 값이 있는 운영자 필수 항목을 표식 없이(옛 관리자 화면) 빼고 보내면 저장된 값을 그대로 두고 다른 칸을 저장한다.
     * 표식을 실으면 종전대로 422, 표식이 없어도 값을 비워 보내면 422 다.
     *
     * @scenario surface=admin_update, marker=absent, marker=present, field=operator_required
     *
     * @effects legacy_admin_update_keeps_stored_value, marked_admin_update_missing_required_rejected
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_follows_marker(): void
    {
        $this->field('lg916_job', ['required' => true]);
        $target = User::factory()->create(['name' => '대상회원']);
        app(FieldValueService::class)->write($target, 'core.user', ['lg916_job' => '디자이너'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $target));
        $base = ['name' => '대상회원', 'email' => $target->email, 'role_ids' => [(int) Role::where('identifier', 'user')->value('id')]];

        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['bio' => '새 소개', 'fields_version' => $this->version()], $this->adminAuth())
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);

        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['bio' => '새 소개'], $this->adminAuth())->assertOk();
        $this->assertSame('새 소개', $target->fresh()->bio);
        $this->assertSame('디자이너', app(FieldValueService::class)->read($target, 'core.user')['lg916_job'] ?? null, '보내지 않은 항목의 값이 바뀌었다');

        $this->putJson("/api/admin/users/{$target->uuid}", $base + ['custom_fields' => ['lg916_job' => '']], $this->adminAuth())
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);
    }

    /**
     * 내 정보 수정 — 표식 없는 수정(옛 화면)은 운영자 필수 항목을 건너뛰고 다른 칸을 저장한다. 비어 있던 필수 항목은 종전대로
     * 추가 정보 입력 요구가 받는다. 표식을 실은 수정은 종전대로 422 다.
     *
     * @scenario surface=self_update, marker=absent, marker=present, field=operator_required
     *
     * @effects legacy_profile_update_skips_required, marked_profile_update_missing_required_rejected
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_follows_marker(): void
    {
        $this->field('lg916_job', ['required' => true, 'require_existing_members' => true]);
        [$user, $auth] = $this->member(['name' => '내정보회원']);

        $this->putJson('/api/me', ['name' => '고친이름', 'email' => $user->email, 'fields_version' => $this->version()], $auth)
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.lg916_job']);

        $this->resetAuth();
        $this->putJson('/api/me', ['name' => '고친이름', 'email' => $user->email], $auth)->assertOk();
        $this->assertSame('고친이름', $user->fresh()->name);
        $this->assertSame(['lg916_job'], app(RequiredFieldsChecker::class)->pendingKeys($user->fresh(), fresh: true), '비어 있는 필수 항목 판정이 사라졌다');
    }
}
