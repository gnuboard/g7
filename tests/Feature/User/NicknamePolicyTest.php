<?php

namespace Tests\Feature\User;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Jobs\RecomputeNicknameUniqueKeysJob;
use App\Models\FieldDefinition;
use App\Models\Role;
use App\Models\User;
use App\Rules\NicknamePolicy;
use App\Services\AuthService;
use App\Services\NicknamePolicyService;
use App\Services\UserService;
use App\Support\ExtensionSettingsMirror;
use App\Support\NicknameNormalizer;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 닉네임 정책(환경설정 > 회원) — 정규화, 중복 금지, 금지어, 변경 주기, 탈퇴 뒤 재사용, 서비스 계층 판정, 중복 키 재계산.
 */
class NicknamePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    /** @var array<int, array{0: int|null, 1: string, 2: string, 3: array<string, mixed>}> 닉네임 거절 훅이 받은 인자 */
    private array $rejections = [];

    /**
     * 역할과 코어 회원 항목을 시딩하고 최고관리자와 거절 훅 기록을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->admin = User::factory()->create(['is_super' => true, 'nickname' => null]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->admin = $this->admin->fresh();
        $this->adminToken = $this->admin->createToken('test')->plainTextToken;

        HookManager::addAction('core.user.nickname_rejected', function (?User $user, string $nickname, string $reason, array $context) {
            $this->rejections[] = [$user?->id, $nickname, $reason, $context];
        });
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.user.nickname_rejected');
        parent::tearDown();
    }

    /**
     * 닉네임 정책 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  회원 범주 설정
     * @return void 반환값 없음
     */
    private function policy(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
    }

    /**
     * 인증 헤더를 만듭니다 (한 테스트 안에서 토큰을 바꿔 쓰므로 가드를 비운다).
     *
     * @param  string|null  $token  토큰 (null 이면 최고관리자)
     * @return array<string, string> 헤더
     */
    private function auth(?string $token = null): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.($token ?? $this->adminToken), 'Accept' => 'application/json'];
    }

    /**
     * 서비스로 회원을 만듭니다 (정규화 값과 중복 키가 채워진다).
     *
     * @param  string  $local  이메일 앞부분
     * @param  string|null  $nickname  닉네임
     * @return User 만든 회원
     */
    private function member(string $local, ?string $nickname): User
    {
        return app(AuthService::class)->register(
            ['name' => '회원 '.$local, 'nickname' => $nickname, 'email' => "issue490+{$local}@example.com"],
            ['issue_token' => false],
        )['user']->fresh();
    }

    /**
     * 공개 가입 요청 본문을 만듭니다.
     *
     * @param  string  $local  이메일 앞부분
     * @param  string  $nickname  닉네임
     * @return array<string, mixed> 본문
     */
    private function signupBody(string $local, string $nickname): array
    {
        return [
            'name' => '가입자', 'nickname' => $nickname, 'email' => "issue490+{$local}@example.com",
            'password' => 'Iss490!policyAa1', 'password_confirmation' => 'Iss490!policyAa1',
            'agree_terms' => true, 'agree_privacy' => true,
        ];
    }

    /**
     * 전각, 대문자, 공백만 다른 닉네임은 같은 정규화 값이 된다. 정책이 꺼져 있으면 중복 키는 비어 있고 같은 닉네임도 만들어진다.
     *
     * @scenario check=normalize
     *
     * @effects nickname_normalized_stored_on_save, nickname_policy_off_allows_duplicates
     *
     * @return void 반환값 없음
     */
    public function test_normalized_value_is_stored_and_duplicates_pass_when_policy_is_off(): void
    {
        $this->assertSame('abc길동', NicknameNormalizer::normalize('ＡＢＣ 길 동'));
        $this->assertNull(NicknameNormalizer::normalize('   '));

        $first = $this->member('n1', 'ＡＢＣ 길동');
        $second = $this->member('n2', 'abc길동');

        $this->assertSame(['ＡＢＣ 길동', 'abc길동', null], [$first->nickname, $first->nickname_normalized, $first->nickname_unique_key]);
        $this->assertSame(['abc길동', null], [$second->nickname_normalized, $second->nickname_unique_key]);
    }

    /**
     * 중복 금지를 켜면 가입, 내 정보 수정, 관리자 수정에서 정규화 기준으로 겹치는 닉네임이 막힌다.
     * 이미 겹치던 회원은 닉네임을 그대로 둔 채 다른 칸을 고칠 수 있다.
     *
     * @scenario check=duplicate
     *
     * @effects duplicate_nickname_blocked_on_change, existing_duplicate_nickname_kept
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_is_blocked_on_change_but_existing_duplicates_stay(): void
    {
        $a = $this->member('d1', '길동이');
        $b = $this->member('d2', '길동이');
        $this->policy(['nickname_unique' => true]);
        $this->member('d5', '새닉네임');

        // 이미 겹치던 회원 — 닉네임을 그대로 두면 다른 칸을 고칠 수 있다
        $token = $a->createToken('test')->plainTextToken;
        $this->putJson('/api/me', ['name' => $a->name, 'email' => $a->email, 'nickname' => '길동이', 'bio' => '소개'], $this->auth($token))->assertOk();
        $this->assertSame('소개', $a->fresh()->bio);
        $this->assertNull($a->fresh()->nickname_unique_key, '겹치는 닉네임에 중복 키가 채워졌다');

        // 다른 회원이 쓰는 닉네임으로 바꾸면 막힌다
        $this->putJson('/api/me', ['name' => $a->name, 'email' => $a->email, 'nickname' => '새 닉네임'], $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors(['nickname']);

        // 관리자 수정도 중복은 면제가 아니다
        $roleIds = [(int) Role::where('identifier', 'user')->value('id')];
        $this->putJson("/api/admin/users/{$b->uuid}", ['name' => $b->name, 'email' => $b->email, 'role_ids' => $roleIds, 'nickname' => '새닉네임'], $this->auth())
            ->assertStatus(422)->assertJsonValidationErrors(['nickname']);
        $this->putJson("/api/admin/users/{$b->uuid}", ['name' => $b->name, 'email' => $b->email, 'role_ids' => $roleIds, 'nickname' => '혼자쓰는닉'], $this->auth())->assertOk();
        $this->assertSame(['혼자쓰는닉', '혼자쓰는닉'], [$b->fresh()->nickname_normalized, $b->fresh()->nickname_unique_key]);

        // 가입 — 전각과 공백만 다른 닉네임 (가입 요청은 세션 로그인을 남기므로 마지막에 확인한다)
        $this->postJson('/api/auth/register', $this->signupBody('d3', '길 동이'))
            ->assertStatus(422)->assertJsonValidationErrors(['nickname'])
            ->assertJsonPath('errors.nickname.0', __('user.validation.nickname_duplicate'));
        $this->postJson('/api/auth/register', $this->signupBody('d4', '가입한닉'))->assertStatus(201);
        $created = User::where('email', 'issue490+d4@example.com')->firstOrFail();
        $this->assertSame('가입한닉', $created->nickname_unique_key, '중복 금지가 켜진 뒤 만든 회원의 중복 키가 비어 있다');
    }

    /**
     * 금지어는 정규화 기준 부분 일치로 막는다. 관리자가 관리자 화면에서 정하는 닉네임은 면제다.
     *
     * @scenario check=forbidden_word
     *
     * @effects forbidden_word_blocked, forbidden_word_admin_exempt, forbidden_word_existing_nickname_kept
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_words_block_members_but_not_admin_surfaces(): void
    {
        $existing = $this->member('f1', '관리자');
        $this->policy(['nickname_banned_words' => ['관리자', 'Admin']]);

        $this->postJson('/api/auth/register', $this->signupBody('f2', '관 리자1'))
            ->assertStatus(422)
            ->assertJsonPath('errors.nickname.0', __('user.validation.nickname_forbidden_word', ['word' => '관리자']));
        $this->postJson('/api/auth/register', $this->signupBody('f3', 'ＡＤＭＩＮ'))->assertStatus(422)->assertJsonValidationErrors(['nickname']);

        // 금지어가 되기 전부터 쓰던 닉네임은 그대로 두고 다른 칸을 고칠 수 있다
        $token = $existing->createToken('test')->plainTextToken;
        $this->putJson('/api/me', ['name' => $existing->name, 'email' => $existing->email, 'nickname' => '관리자', 'bio' => '소개'], $this->auth($token))->assertOk();
        $this->putJson('/api/me', ['name' => $existing->name, 'email' => $existing->email, 'nickname' => '부관리자'], $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors(['nickname']);

        // 관리자 화면에서는 금지어가 든 닉네임을 정할 수 있다
        $staff = $this->member('f4', null);
        $roleIds = [(int) Role::where('identifier', 'user')->value('id')];
        $this->putJson("/api/admin/users/{$staff->uuid}", ['name' => $staff->name, 'email' => $staff->email, 'role_ids' => $roleIds, 'nickname' => '운영 관리자'], $this->auth())->assertOk();
        $this->assertSame('운영 관리자', $staff->fresh()->nickname);
    }

    /**
     * 표시 이름 종류로 쓸 수 있게 켠 추가 항목에는 닉네임 금지어가 적용된다. 조건은 닉네임과 같다.
     *
     * 다른 회원에게 이름으로 보이는 값이라 사칭을 막는다. 켜지 않은 항목에는 적용하지 않는다.
     *
     * @scenario check=display_name_field_forbidden_word
     *
     * @effects display_name_field_forbidden_word_blocked, display_name_field_forbidden_word_normalized, plain_field_ignores_forbidden_words, display_name_field_forbidden_word_admin_exempt, display_name_field_existing_value_kept
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_words_apply_to_display_name_fields(): void
    {
        foreach ([['alias', '활동명', true], ['motto', '좌우명', false]] as [$key, $label, $usable]) {
            FieldDefinition::create([
                'field_set' => FieldSet::CORE_USER, 'key' => $key, 'type' => 'text', 'source_type' => 'custom', 'source_identifier' => 'custom',
                'storage' => 'value', 'name' => ['ko' => $label, 'en' => $label], 'enabled' => true, 'show_on_signup' => true,
                'display_name_usable' => $usable, 'sort_order' => 900,
            ]);
        }
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->policy(['nickname_banned_words' => ['관리자', 'Admin']]);

        // 가입: 표시 이름으로 쓸 수 있는 항목은 막히고(전각, 공백 차이 무시), 그렇지 않은 항목은 통과한다
        $errors = $this->postJson('/api/auth/register', $this->signupBody('d1', '새내기') + ['custom_fields' => ['alias' => '관 리자 대행']])
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame(
            __('fields.validation.forbidden_word', ['attribute' => '활동명', 'word' => '관리자']),
            $errors['custom_fields.alias'][0] ?? null,
        );
        $this->postJson('/api/auth/register', $this->signupBody('d2', '새내기2') + ['custom_fields' => ['alias' => 'ＡＤＭＩＮ']])
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.alias']);

        // 내 정보 수정도 같은 규칙이다
        $member = $this->member('d4', '길동이');
        $token = $member->createToken('test')->plainTextToken;
        $profile = ['name' => $member->name, 'email' => $member->email, 'nickname' => $member->nickname];
        $this->putJson('/api/me', $profile + ['custom_fields' => ['alias' => '부관리자']], $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.alias']);

        // 관리자 화면에서는 금지어가 든 값을 정할 수 있다 (닉네임과 같은 면제)
        $roleIds = [(int) Role::where('identifier', 'user')->value('id')];
        $this->putJson("/api/admin/users/{$member->uuid}", ['name' => $member->name, 'email' => $member->email, 'role_ids' => $roleIds, 'custom_fields' => ['alias' => '운영 관리자']], $this->auth())
            ->assertOk();

        // 이미 저장된 값은 그대로 두고 다른 칸을 고칠 수 있다. 다른 금지어 값으로 바꾸는 것은 막힌다
        $this->putJson('/api/me', $profile + ['bio' => '소개', 'custom_fields' => ['alias' => '운영 관리자']], $this->auth($token))->assertOk();
        $this->putJson('/api/me', $profile + ['custom_fields' => ['alias' => '총관리자']], $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors(['custom_fields.alias']);

        // 표시 이름으로 쓰지 않는 항목은 금지어가 들어 있어도 통과한다 (가입 성공은 로그인 상태를 남기므로 마지막에 잰다)
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/register', $this->signupBody('d3', '새내기3') + ['custom_fields' => ['alias' => '바람의 검객', 'motto' => '관리자가 되자']])
            ->assertStatus(201);
    }

    /**
     * 변경 주기 — 본인이 바꾼 시각부터 세고, 주기 안의 재변경은 막고, 관리자 수정은 면제다.
     *
     * @scenario check=change_interval
     *
     * @effects nickname_change_interval_blocks_self_change, nickname_change_interval_admin_exempt, nickname_changed_at_set_on_self_change
     *
     * @return void 반환값 없음
     */
    public function test_change_interval_applies_to_self_updates_only(): void
    {
        $this->policy(['nickname_change_interval_days' => 30]);
        $member = $this->member('i1', '처음');
        $this->assertNull($member->nickname_changed_at, '가입은 변경으로 세지 않는다');
        $token = $member->createToken('test')->plainTextToken;
        $body = fn (string $nickname) => ['name' => $member->name, 'email' => $member->email, 'nickname' => $nickname];

        $this->putJson('/api/me', $body('둘째'), $this->auth($token))->assertOk();
        $this->assertNotNull($member->fresh()->nickname_changed_at);
        $this->assertNotNull(app(NicknamePolicyService::class)->nextChangeableAt($member->fresh()));

        $this->putJson('/api/me', $body('셋째'), $this->auth($token))->assertStatus(422)->assertJsonValidationErrors(['nickname']);
        // 대소문자, 공백만 바꾸는 것은 변경으로 세지 않는다
        $this->putJson('/api/me', $body('둘 째'), $this->auth($token))->assertOk();

        // 관리자 수정은 주기와 무관하고 본인의 변경 시각도 건드리지 않는다
        $changedAt = $member->fresh()->nickname_changed_at;
        $roleIds = [(int) Role::where('identifier', 'user')->value('id')];
        $this->putJson("/api/admin/users/{$member->uuid}", $body('관리자가정함') + ['role_ids' => $roleIds], $this->auth())->assertOk();
        $this->assertEquals($changedAt, $member->fresh()->nickname_changed_at);

        $this->travel(31)->days();
        $this->putJson('/api/me', $body('넷째'), $this->auth($token))->assertOk();
        $this->assertSame('넷째', $member->fresh()->nickname);
    }

    /**
     * 탈퇴하면 닉네임에 회원 ID 가 든 접미사가 붙고 중복 키가 비어, 같은 닉네임을 다른 회원이 다시 쓸 수 있다.
     *
     * @scenario check=withdraw
     *
     * @effects withdrawn_nickname_suffix_has_user_id, withdrawn_nickname_reusable
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_nickname_can_be_reused(): void
    {
        $this->policy(['nickname_unique' => true]);
        $first = $this->member('w1', '다시쓸닉');
        $this->assertSame('다시쓸닉', $first->nickname_unique_key);

        app(UserService::class)->withdrawUser($first);
        $first = $first->fresh();
        $this->assertSame('다시쓸닉_탈퇴_'.$first->id, $first->nickname);
        $this->assertNull($first->nickname_unique_key);

        // 같은 닉네임으로 재가입하고 다시 탈퇴해도 서로 겹치지 않는다
        $second = $this->member('w2', '다시쓸닉');
        $this->assertSame('다시쓸닉', $second->nickname_unique_key);
        app(UserService::class)->withdrawUser($second);
        $this->assertSame('다시쓸닉_탈퇴_'.$second->id, $second->fresh()->nickname);
    }

    /**
     * 요청 없이 서비스를 부르면 정책에 걸린 닉네임은 검증 예외다. `skip_field` 를 넘기면 닉네임만 빠지고 액션이 발화한다.
     *
     * @scenario check=service_layer
     *
     * @effects service_nickname_violation_throws, service_skip_field_drops_nickname_and_fires_action
     *
     * @return void 반환값 없음
     */
    public function test_service_layer_throws_or_skips_the_nickname(): void
    {
        $this->policy(['nickname_unique' => true, 'nickname_banned_words' => ['운영자']]);
        $taken = $this->member('s1', '이미있음');

        $this->assertSame(NicknamePolicyService::REASON_DUPLICATE, NicknamePolicy::check(null, '이미 있음', FieldContext::SURFACE_SIGNUP));
        $this->assertSame(NicknamePolicyService::REASON_FORBIDDEN_WORD, NicknamePolicy::check(null, '운영자', FieldContext::SURFACE_SIGNUP));
        $this->assertNull(NicknamePolicy::check($taken, '이미있음', FieldContext::SURFACE_SELF_UPDATE));

        // 가입 — 옵션이 없으면 예외
        try {
            app(AuthService::class)->register(['name' => '외부', 'nickname' => '이미있음', 'email' => 'issue490+s2@example.com'], ['issue_token' => false]);
            $this->fail('겹치는 닉네임으로 회원이 만들어졌다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nickname', $e->errors());
        }
        $this->assertFalse(User::where('email', 'issue490+s2@example.com')->exists());

        // 가입 — skip_field 면 닉네임만 비우고 만든다
        $created = app(AuthService::class)->register(
            ['name' => '외부', 'nickname' => '이미있음', 'email' => 'issue490+s3@example.com'],
            ['issue_token' => false, 'nickname_policy' => 'skip_field'],
        )['user']->fresh();
        $this->assertSame(['외부', null, null], [$created->name, $created->nickname, $created->nickname_unique_key]);
        $this->assertSame([$created->id, '이미있음', 'duplicate', 'register'], [
            $this->rejections[0][0], $this->rejections[0][1], $this->rejections[0][2], $this->rejections[0][3]['operation'],
        ]);

        // 수정 — 옵션이 없으면 예외, skip_field 면 닉네임만 빼고 저장
        $target = $this->member('s4', '원래닉');
        try {
            app(UserService::class)->updateUser($target, ['name' => '바뀐 이름', 'nickname' => '운영자']);
            $this->fail('금지어 닉네임이 저장됐다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nickname', $e->errors());
        }
        $this->assertSame('회원 s4', $target->fresh()->name, '예외가 났는데 다른 칸이 저장됐다');

        app(UserService::class)->updateUser($target->fresh(), ['name' => '바뀐 이름', 'mobile' => '010-0000-0000', 'nickname' => '운영자'], ['nickname_policy' => 'skip_field']);
        $fresh = $target->fresh();
        $this->assertSame(['바뀐 이름', '010-0000-0000', '원래닉'], [$fresh->name, $fresh->mobile, $fresh->nickname]);
        $this->assertSame([$target->id, '운영자', 'forbidden_word', 'update'], [
            $this->rejections[1][0], $this->rejections[1][1], $this->rejections[1][2], $this->rejections[1][3]['operation'],
        ]);
    }

    /**
     * 재계산 작업은 비어 있던 정규화 값을 채우고, 중복 금지가 켜져 있으면 한 회원만 쓰는 닉네임에만 중복 키를 채운다.
     * 중복 금지를 끄면 중복 키를 모두 비운다.
     *
     * @scenario check=recompute
     *
     * @effects recompute_fills_normalized, recompute_sets_unique_key_only_for_unique_nicknames, recompute_clears_keys_when_policy_off, recompute_reports_progress
     *
     * @return void 반환값 없음
     */
    public function test_recompute_job_fills_keys_and_reports_progress(): void
    {
        // 업그레이드 전 가입 회원 — 정규화 값이 비어 있다
        $solo = User::factory()->create(['nickname' => 'Solo Nick']);
        $dupA = User::factory()->create(['nickname' => '겹침']);
        $dupB = User::factory()->create(['nickname' => '겹 침']);
        $this->assertNull($solo->fresh()->nickname_normalized);

        $this->policy(['nickname_unique' => true]);
        RecomputeNicknameUniqueKeysJob::dispatchSync();

        $this->assertSame(['solonick', 'solonick'], [$solo->fresh()->nickname_normalized, $solo->fresh()->nickname_unique_key]);
        $this->assertSame(['겹침', null], [$dupA->fresh()->nickname_normalized, $dupA->fresh()->nickname_unique_key]);
        $this->assertSame(['겹침', null], [$dupB->fresh()->nickname_normalized, $dupB->fresh()->nickname_unique_key]);

        $progress = RecomputeNicknameUniqueKeysJob::progress();
        $this->assertSame(['done', true], [$progress['status'], $progress['unique']]);
        $this->assertSame($progress['total'], $progress['processed']);
        $this->assertNotNull($progress['finished_at']);

        $this->policy(['nickname_unique' => false]);
        RecomputeNicknameUniqueKeysJob::dispatchSync();
        $this->assertNull($solo->fresh()->nickname_unique_key);
        $this->assertSame('solonick', $solo->fresh()->nickname_normalized);
    }

    /**
     * 회원 탭 저장에서 중복 금지 값이 바뀔 때만 재계산 작업이 접수된다.
     *
     * @scenario check=settings_toggle
     *
     * @effects recompute_dispatched_only_when_unique_setting_changes
     *
     * @return void 반환값 없음
     */
    public function test_recompute_job_is_dispatched_only_when_the_unique_setting_changes(): void
    {
        Queue::fake();
        $save = fn (array $member) => $this->postJson('/api/admin/settings', ['_tab' => 'member', 'member' => $member], $this->auth())->assertOk();

        $save(['nickname_change_interval_days' => 7]);
        Queue::assertNotPushed(RecomputeNicknameUniqueKeysJob::class);

        $save(['nickname_unique' => true]);
        Queue::assertPushed(RecomputeNicknameUniqueKeysJob::class, 1);

        $save(['nickname_unique' => true, 'nickname_change_interval_days' => 14]);
        Queue::assertPushed(RecomputeNicknameUniqueKeysJob::class, 1);
    }

    /**
     * 관리자 화면에서 저장한 닉네임 정책이 서버가 읽는 설정(`g7_core_settings`)에 실린다.
     *
     * 설정 미러의 코어 범주 목록에 회원 범주가 빠지면 저장은 성공하는데 서버는 기본값만 읽어 정책이 조용히 꺼진다
     * (개발 사이트 실측에서 금지어 닉네임 가입이 201 로 통과해 드러난 결함).
     *
     * @scenario check=settings_mirror
     *
     * @effects saved_member_settings_reach_runtime_config
     *
     * @return void 반환값 없음
     */
    public function test_saved_policy_is_loaded_into_runtime_settings(): void
    {
        $this->postJson('/api/admin/settings', ['_tab' => 'member', 'member' => [
            'nickname_unique' => true, 'nickname_banned_words' => ['운영자'], 'nickname_change_interval_days' => 30,
        ]], $this->auth())->assertOk();

        // 다음 요청의 부팅과 같게 미러를 저장 파일에서 다시 채운다.
        config(['g7_settings.core' => []]);
        app(ExtensionSettingsMirror::class)->refreshCore();

        $policy = app(NicknamePolicyService::class);
        $this->assertTrue($policy->uniqueEnabled(), '저장한 중복 금지가 서버 설정에 실리지 않았다');
        $this->assertSame(['운영자'], $policy->bannedWords());
        $this->assertSame(30, $policy->intervalDays());
    }
}
