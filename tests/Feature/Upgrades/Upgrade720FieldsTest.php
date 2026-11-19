<?php

namespace Tests\Feature\Upgrades;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;
use App\Support\NicknameNormalizer;
use App\Upgrades\Data\V7_2_0\Migrations\BackfillMemberSettingsAreas;
use App\Upgrades\Data\V7_2_0\Migrations\BackfillNicknameNormalized;
use App\Upgrades\Data\V7_2_0\Migrations\ResyncCoreDeclarations;
use App\Upgrades\Data\V7_2_0\Migrations\SeedCoreFieldDefinitions;
use App\Upgrades\Data\V7_2_0\Migrations\SyncActiveExtensionSchedules;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 7.2.0 업그레이드 — 회원 항목 설정과 표시 이름이 들어온 뒤의 기존 사이트 보정 (스텝 07, 08, 09)
 *
 * 이 스텝들이 틀리면 오류 없이 동작만 달라진다. 닉네임 정규화 값이 비면 기존 회원과 같은 닉네임이 중복으로 잡히지 않고,
 * 코어 항목 기본값이 종전과 다르면 설정을 바꾸지 않은 사이트의 가입 화면과 검증이 업그레이드 직후 달라지며,
 * 스텝이 운영자가 만든 항목이나 고친 값을 건드리면 운영자 설정이 사라진다.
 */
class Upgrade720FieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 이 시험 전용 settings 디스크 루트 */
    private string $settingsRoot;

    /** @var string 테스트 시작 때의 기본 인증 가드 (`actingAs()` 가 바꾸기 전 값) */
    private string $defaultGuard;

    /** 수정 전 가입 응답의 회원 정보 키 (7.2.0 회원 항목 작업 전 `data.user`) */
    private const LEGACY_USER_KEYS = [
        'uuid', 'name', 'nickname', 'email', 'avatar', 'language', 'language_label', 'country', 'country_name',
        'status', 'status_label', 'status_variant', 'is_admin', 'has_password', 'email_deliverable', 'homepage',
        'mobile', 'phone', 'zipcode', 'address', 'address_detail', 'signature', 'bio', 'last_login_at',
        'email_verified_at', 'timezone',
    ];

    /**
     * 테스트 환경 준비 — 데이터 스텝 적재, 역할 시딩, 전용 설정 디스크.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        foreach ([
            '00_ResyncCoreDeclarations', '01_SyncActiveExtensionSchedules', '07_BackfillNicknameNormalized',
            '08_SeedCoreFieldDefinitions', '09_BackfillMemberSettingsAreas',
        ] as $file) {
            require_once base_path("upgrades/data/7.2.0/migrations/{$file}.php");
        }

        $this->seed(RolePermissionSeeder::class);
        $this->defaultGuard = (string) config('auth.defaults.guard');

        // 공용 가짜 디스크 경로는 다른 PHPUnit 프로세스의 Storage::fake() 가 통째로 지운다 — 이 시험만의 루트를 쓴다.
        $this->settingsRoot = storage_path('framework/testing/upgrade720-fields-'.uniqid());
        File::ensureDirectoryExists($this->settingsRoot);
        Storage::set('settings', Storage::build(['driver' => 'local', 'root' => $this->settingsRoot]));
    }

    /**
     * 전용 설정 디스크를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->settingsRoot);

        parent::tearDown();
    }

    /**
     * 07 은 정규화 값이 빈 회원만 채우고, 실제 저장 경로와 같은 값을 쓰며, 다시 돌리면 아무것도 쓰지 않는다.
     *
     * @scenario case=nickname_backfill
     *
     * @effects nickname_normalized_filled_for_existing_members, nickname_backfill_matches_runtime_normalizer, nickname_backfill_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_nickname_backfill_fills_only_empty_rows_and_is_idempotent(): void
    {
        // 업그레이드 전 회원: 닉네임은 있고 정규화 값은 비어 있다(컬럼은 7.2.0 마이그레이션이 비운 채로 더한다).
        $nicknames = [
            'wide' => 'ＡＢｃ　길동',      // 전각 영문과 전각 공백
            'upper' => 'Gil Dong',
            'zero_width' => "길\u{200B}동\u{FEFF}이",
            'plain' => '길동이',
        ];
        $members = [];
        foreach ($nicknames as $label => $nickname) {
            $members[$label] = $this->legacyMember(['nickname' => $nickname]);
        }
        $none = $this->legacyMember(['nickname' => null]);
        $blank = $this->legacyMember(['nickname' => '   ']);

        // 7.2.0 에서 이미 저장된 회원(값이 있다)은 건드리지 않는다 — 일부러 규칙과 다른 값을 넣어 덮지 않음을 본다.
        $saved = $this->legacyMember(['nickname' => '새회원']);
        DB::table('users')->where('id', $saved->id)->update(['nickname_normalized' => 'kept-as-is', 'nickname_unique_key' => 'kept-as-is']);

        $stamps = DB::table('users')->pluck('updated_at', 'id')->all();

        $this->runStep(new BackfillNicknameNormalized);

        foreach ($nicknames as $label => $nickname) {
            $row = DB::table('users')->where('id', $members[$label]->id)->first();
            $this->assertNotNull($row->nickname_normalized, "{$label}: 정규화 값이 채워지지 않았다");
            $this->assertSame(NicknameNormalizer::normalize($nickname), $row->nickname_normalized, "{$label}: 스텝의 정규화가 저장 경로의 정규화와 다르다");
            $this->assertSame($nickname, $row->nickname, "{$label}: 닉네임 원문이 바뀌었다");
            $this->assertNull($row->nickname_unique_key, "{$label}: 중복 금지용 값은 정책을 켤 때의 재계산이 채운다");
        }
        if (class_exists(\Normalizer::class)) {
            $this->assertSame('abc길동', DB::table('users')->where('id', $members['wide']->id)->value('nickname_normalized'));
        }
        $this->assertSame('gildong', DB::table('users')->where('id', $members['upper']->id)->value('nickname_normalized'));
        $this->assertSame('길동이', DB::table('users')->where('id', $members['zero_width']->id)->value('nickname_normalized'));

        $this->assertNull(DB::table('users')->where('id', $none->id)->value('nickname_normalized'));
        $this->assertNull(DB::table('users')->where('id', $blank->id)->value('nickname_normalized'), '공백뿐인 닉네임에 정규화 값이 들어갔다');
        $kept = DB::table('users')->where('id', $saved->id)->first();
        $this->assertSame(['kept-as-is', 'kept-as-is'], [$kept->nickname_normalized, $kept->nickname_unique_key], '이미 값이 있는 회원을 덮었다');
        $this->assertSame($stamps, DB::table('users')->pluck('updated_at', 'id')->all(), '회원의 수정 시각이 바뀌었다');

        // 멱등: 두 번째 실행은 회원 행을 하나도 쓰지 않는다.
        $writes = $this->countWrites(fn () => $this->runStep(new BackfillNicknameNormalized));
        $this->assertSame(0, $writes, '다시 돌리자 회원 행을 썼다');
    }

    /**
     * 00 의 재동기화가 새 권한과 코어 항목을 만들고, 그 뒤의 08 은 한 글자도 바꾸지 않는다.
     *
     * @scenario case=seed_after_resync
     *
     * @effects resync_creates_new_permission_and_core_fields, seed_step_is_noop_after_resync
     *
     * @return void 반환값 없음
     */
    public function test_seed_step_changes_nothing_after_core_resync(): void
    {
        // 7.1.x 사이트: 표시 이름 선택 권한과 코어 항목 행이 없다.
        $permissionId = DB::table('permissions')->where('identifier', 'core.profile.display_name.choose')->value('id');
        $this->assertNotNull($permissionId, '시딩된 권한 목록에 표시 이름 선택 권한이 없다');
        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        $this->assertSame(0, FieldDefinition::count());

        $this->runStep(new ResyncCoreDeclarations);
        $this->runStep(new SyncActiveExtensionSchedules);

        $newPermissionId = DB::table('permissions')->where('identifier', 'core.profile.display_name.choose')->value('id');
        $this->assertNotNull($newPermissionId, '재동기화가 새 권한을 만들지 않았다');
        $userRoleId = DB::table('roles')->where('identifier', 'user')->value('id');
        $this->assertTrue(
            DB::table('role_permissions')->where('role_id', $userRoleId)->where('permission_id', $newPermissionId)->exists(),
            '재동기화가 새 권한을 기본 회원 역할에 붙이지 않았다',
        );
        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'],
            DB::table('field_definitions')->where('source_type', 'core')->orderBy('sort_order')->pluck('key')->all(),
            '재동기화가 코어 회원 항목을 만들지 않았다',
        );

        $before = $this->rawDefinitions();
        $writes = $this->countWrites(function () {
            $this->runStep(new SeedCoreFieldDefinitions);
            $this->runStep(new SeedCoreFieldDefinitions);
        });

        $this->assertSame(0, $writes, '재동기화 뒤의 08 이 행을 썼다');
        $this->assertSame($before, $this->rawDefinitions(), '재동기화 뒤의 08 이 항목 행을 바꿨다');
    }

    /**
     * 코어 항목 행이 하나도 없으면 08 이 종전 동작과 같은 기본값으로 넣고, 그 값은 선언 동기화가 만드는 값과 같다.
     *
     * @scenario case=seed_when_empty
     *
     * @effects seed_step_inserts_legacy_defaults_when_empty, seed_step_matches_declared_sync, seed_step_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_seed_step_inserts_legacy_defaults_when_no_core_rows(): void
    {
        $this->runStep(new SeedCoreFieldDefinitions);

        $rows = FieldDefinition::where('field_set', 'core.user')->orderBy('sort_order')->get()->keyBy('key');
        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'],
            $rows->keys()->all(),
        );
        foreach ($rows as $key => $row) {
            $this->assertTrue($row->enabled, "{$key}: 기본 사용이 아니다");
            $this->assertSame($key === 'name', $row->required, "{$key}: 필수 기본값이 종전과 다르다");
            $this->assertSame(['core', 'core', 'column', 'self'], [$row->source_type, $row->source_identifier, $row->storage, $row->edit_scope], $key);
            $this->assertNull($row->user_overrides, "{$key}: 스텝이 넣은 행이 운영자 수정으로 기록됐다");
        }
        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language'],
            $rows->filter(fn ($row) => $row->show_on_signup)->keys()->all(),
            '가입 화면 기본 표시 항목이 종전과 다르다',
        );

        // 멱등: 다시 돌려도 행이 늘거나 바뀌지 않는다.
        $seeded = $this->comparableDefinitions();
        $writes = $this->countWrites(fn () => $this->runStep(new SeedCoreFieldDefinitions));
        $this->assertSame(0, $writes, '다시 돌리자 행을 썼다');
        $this->assertSame($seeded, $this->comparableDefinitions());

        // 스텝이 넣은 값은 선언 동기화가 만드는 값과 같다 — 다음 재동기화가 고칠 속성이 없다.
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->assertSame($seeded, $this->comparableDefinitions(), '08 이 넣은 값이 코어 선언의 동기화 결과와 다르다');
        $this->assertSame(0, FieldDefinition::whereNotNull('user_overrides')->count());
    }

    /**
     * 운영자가 만든 항목과 그 값, 운영자가 고친 코어 항목은 재동기화와 세 스텝을 모두 돌려도 그대로다.
     *
     * @scenario case=operator_rows_kept
     *
     * @effects custom_fields_survive_every_step, operator_edits_survive_every_step
     *
     * @return void 반환값 없음
     */
    public function test_operator_rows_survive_resync_and_every_step(): void
    {
        $this->seed(CoreFieldDefinitionSeeder::class);

        $custom = $this->customField('t490_job', ['required' => true, 'name' => ['ko' => '직업']]);
        $member = $this->legacyMember(['nickname' => '길동이']);
        $value = FieldValue::create([
            'field_definition_id' => $custom->id, 'owner_type' => $member->getMorphClass(), 'owner_id' => $member->id,
            'value_text' => '개발자',
        ]);

        // 운영자가 휴대폰을 필수로, 자기소개를 사용 안 함으로 바꿨다.
        FieldDefinition::where('key', 'mobile')->firstOrFail()->update(['required' => true]);
        FieldDefinition::where('key', 'bio')->firstOrFail()->update(['enabled' => false]);
        Storage::disk('settings')->put('member.json', json_encode([
            'display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']],
        ]));

        $customBefore = (array) DB::table('field_definitions')->where('id', $custom->id)->first();

        foreach ($this->allSteps() as $step) {
            $this->runStep($step);
        }
        foreach ($this->allSteps() as $step) {
            $this->runStep($step); // 강제 재실행
        }

        $this->assertSame($customBefore, (array) DB::table('field_definitions')->where('id', $custom->id)->first(), '운영자가 만든 항목 행이 바뀌었다');
        $this->assertSame('개발자', FieldValue::findOrFail($value->id)->value_text, '운영자가 만든 항목의 값이 지워졌다');
        $this->assertTrue(FieldDefinition::where('key', 'mobile')->firstOrFail()->required, '운영자가 켠 필수가 꺼졌다');
        $this->assertFalse(FieldDefinition::where('key', 'bio')->firstOrFail()->enabled, '운영자가 끈 항목이 다시 켜졌다');
        $this->assertSame(12, FieldDefinition::where('field_set', 'core.user')->count(), '항목 수가 달라졌다(코어 11 + 운영자 1)');
        $this->assertSame(
            ['mode' => 'fixed', 'kind' => 'nickname'],
            json_decode(Storage::disk('settings')->get('member.json'), true)['display_name_areas']['core:site'],
            '운영자가 바꾼 영역 설정이 기본값으로 돌아갔다',
        );
    }

    /**
     * 코어 항목 행이 없는데 같은 키의 항목이 이미 있으면 08 은 그 키만 건너뛰고 기존 행을 그대로 둔다.
     *
     * @scenario case=seed_key_collision
     *
     * @effects seed_step_skips_taken_keys, custom_fields_survive_every_step
     *
     * @return void 반환값 없음
     */
    public function test_seed_step_skips_keys_already_taken(): void
    {
        $taken = $this->customField('bio', ['name' => ['ko' => '운영자 소개 칸']]);
        $before = (array) DB::table('field_definitions')->where('id', $taken->id)->first();

        $this->runStep(new SeedCoreFieldDefinitions);

        $this->assertSame($before, (array) DB::table('field_definitions')->where('id', $taken->id)->first(), '이미 있던 행이 바뀌었다');
        $this->assertSame(1, DB::table('field_definitions')->where('field_set', 'core.user')->where('key', 'bio')->count());
        $this->assertSame(10, DB::table('field_definitions')->where('source_type', 'core')->count(), '겹치지 않는 코어 항목 10개가 들어가지 않았다');
    }

    /**
     * 09 는 저장된 영역 묶음에 빠진 기본 영역만 더하고, 묶음이나 파일이 없으면 아무것도 쓰지 않는다.
     *
     * @scenario case=settings_backfill
     *
     * @effects missing_default_areas_filled_only, stored_area_values_kept, settings_untouched_without_stored_areas, settings_backfill_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_member_settings_areas_backfill(): void
    {
        $disk = Storage::disk('settings');
        $defaults = [
            'core:site' => ['mode' => 'member'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
            'sirsoft-ecommerce:inquiry' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ];

        // 스텝의 동결 기본값은 설정 기본값 파일의 값과 같다(7.2.0).
        $shipped = json_decode(File::get(config_path('settings/defaults.json')), true)['defaults']['member']['display_name_areas'];
        $this->assertSame($defaults, $shipped, '스텝이 동결한 기본 영역이 설정 기본값과 다르다');

        // 1) 파일 없음(7.1.x 에서 올린 사이트) — 파일을 만들지 않는다.
        $this->runStep(new BackfillMemberSettingsAreas);
        $this->assertFalse($disk->exists('member.json'), '없던 설정 파일을 만들었다');

        // 2) 쓰지 않는 모양들 — 내용이 한 바이트도 바뀌지 않는다.
        $untouched = [
            '묶음 없음' => json_encode(['nickname_unique' => true, '_meta' => ['version' => '1.0.0']]),
            '빠진 영역 없음' => json_encode(['display_name_areas' => $defaults + ['sirsoft-board:posts' => ['mode' => 'member']]]),
            '깨진 JSON' => '{not json',
            '목록 모양' => json_encode(['a', 'b']),
            '묶음이 목록' => json_encode(['display_name_areas' => ['name']]),
        ];
        foreach ($untouched as $label => $content) {
            $disk->put('member.json', $content);
            $this->runStep(new BackfillMemberSettingsAreas);
            $this->assertSame($content, $disk->get('member.json'), "{$label}: 파일을 고쳤다");
        }

        // 3) 일부만 있는 묶음 — 빠진 영역만 더하고 있는 값과 다른 키는 그대로.
        $disk->put('member.json', json_encode([
            '_meta' => ['version' => '1.0.0', 'updated_at' => '2026-11-01T00:00:00+00:00'],
            'nickname_banned_words' => ['관리자'],
            'display_name_areas' => [
                'core:site' => ['mode' => 'member'],
                'sirsoft-board:posts' => ['mode' => 'fixed', 'kind' => 'nickname'],
            ],
        ]));

        $this->runStep(new BackfillMemberSettingsAreas);
        $after = json_decode($disk->get('member.json'), true);

        $this->assertSame(['mode' => 'member'], $after['display_name_areas']['core:site'], '운영자가 바꾼 영역을 덮었다');
        $this->assertSame(['mode' => 'fixed', 'kind' => 'nickname'], $after['display_name_areas']['sirsoft-board:posts'], '기본 목록 밖 영역을 건드렸다');
        $this->assertSame($defaults['core:admin'], $after['display_name_areas']['core:admin']);
        $this->assertSame($defaults['sirsoft-ecommerce:inquiry'], $after['display_name_areas']['sirsoft-ecommerce:inquiry']);
        $this->assertCount(4, $after['display_name_areas']);
        $this->assertSame(['관리자'], $after['nickname_banned_words']);
        $this->assertSame(['version' => '1.0.0', 'updated_at' => '2026-11-01T00:00:00+00:00'], $after['_meta']);

        // 빈 묶음도 채운다.
        $filled = $disk->get('member.json');
        $this->runStep(new BackfillMemberSettingsAreas);
        $this->assertSame($filled, $disk->get('member.json'), '다시 돌리자 파일이 바뀌었다');

        $disk->put('member.json', json_encode(['display_name_areas' => new \stdClass]));
        $this->runStep(new BackfillMemberSettingsAreas);
        $this->assertSame($defaults, json_decode($disk->get('member.json'), true)['display_name_areas']);
    }

    /**
     * 업그레이드 뒤(재동기화가 된 사이트와 08 의 보정만 된 사이트 모두) 설정을 바꾸지 않았으면
     * 종전 본문의 가입과 내 정보 수정이 그대로 통과하고 응답 모양도 종전 키를 그대로 담는다.
     *
     * @scenario case=legacy_requests_after_upgrade
     *
     * @effects legacy_signup_passes_after_upgrade, legacy_profile_update_passes_after_upgrade, signup_response_shape_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_legacy_signup_and_profile_update_pass_after_upgrade(): void
    {
        // 업그레이드 전부터 있던 회원 (닉네임 정규화 값 없음)
        $existing = $this->legacyMember(['name' => '기존회원', 'nickname' => '올드비', 'email' => 'issue490+existing@example.com']);
        $existing->roles()->sync([DB::table('roles')->where('identifier', 'user')->value('id')]);

        $paths = [
            'resync' => fn () => $this->allSteps(),
            'seed_only' => fn () => [new BackfillNicknameNormalized, new SeedCoreFieldDefinitions, new BackfillMemberSettingsAreas],
        ];

        foreach ($paths as $path => $steps) {
            FieldDefinition::query()->delete();
            app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

            foreach ($steps() as $step) {
                $this->runStep($step);
            }
            app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
            $this->assertSame(11, FieldDefinition::where('source_type', 'core')->count(), $path);

            // 가입: 종전 필수(이름, 이메일, 비밀번호, 약관)와 종전 선택 칸이 그대로다.
            $legacyBody = [
                'name' => '홍길동',
                'nickname' => "길동이{$path}",
                'email' => "issue490+{$path}@example.com",
                'password' => 'Str0ng!Passw0rd#490',
                'password_confirmation' => 'Str0ng!Passw0rd#490',
                'mobile' => '010-1234-5678',
                'phone' => '02-123-4567',
                'language' => 'en',
                'agree_terms' => true,
                'agree_privacy' => true,
            ];

            $this->postJson('/api/auth/register', array_diff_key($legacyBody, ['name' => 1]))
                ->assertStatus(422)->assertJsonValidationErrors(['name']);
            $this->postJson('/api/auth/register', ['agree_terms' => false] + $legacyBody)
                ->assertStatus(422)->assertJsonValidationErrors(['agree_terms']);

            $response = $this->postJson('/api/auth/register', $legacyBody);
            $response->assertStatus(201)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.token_type', 'Bearer')
                ->assertJsonStructure(['success', 'message', 'data' => ['user' => self::LEGACY_USER_KEYS, 'token', 'token_type']]);
            $this->assertSame(
                ['홍길동', "길동이{$path}", "issue490+{$path}@example.com", 'en', '010-1234-5678', '02-123-4567'],
                array_map(fn (string $key) => $response->json("data.user.{$key}"), ['name', 'nickname', 'email', 'language', 'mobile', 'phone']),
                "{$path}: 가입 응답의 값이 보낸 값과 다르다",
            );

            $created = User::where('email', "issue490+{$path}@example.com")->firstOrFail();
            $this->assertSame(['홍길동', "길동이{$path}", '010-1234-5678', '02-123-4567', 'en'], [$created->name, $created->nickname, $created->mobile, $created->phone, $created->language], $path);
            $this->assertNull($created->required_fields_deferred_at, "{$path}: 화면 가입에 나중 입력 표시가 붙었다");
            $this->assertNull($created->display_name_kind, "{$path}: 고르지 않은 표시 이름 종류가 저장됐다");
            $this->becomeGuest();

            // 내 정보 수정: 종전 본문(이름, 이메일, 언어, 국가)이 그대로 통과한다. 기존 회원은 추가 정보 입력 대상이 아니다.
            $this->actingAs($existing, 'sanctum')
                ->putJson('/api/user/profile', ['email' => $existing->email, 'language' => 'ko'])
                ->assertStatus(422)->assertJsonValidationErrors(['name']);
            $this->actingAs($existing, 'sanctum')
                ->putJson('/api/user/profile', ['name' => "수정{$path}", 'email' => $existing->email, 'language' => 'ko', 'country' => 'KR'])
                ->assertStatus(200)->assertJsonPath('success', true);
            $this->assertSame(["수정{$path}", 'KR', '올드비'], array_values((array) DB::table('users')->where('id', $existing->id)->first(['name', 'country', 'nickname'])), $path);
            $this->becomeGuest();
        }

        // 07 이 채운 기존 회원의 정규화 값은 그 뒤의 수정에서도 유지된다.
        $this->assertSame('올드비', DB::table('users')->where('id', $existing->id)->value('nickname_normalized'));
    }

    /**
     * 다음 요청이 비회원 요청이 되도록 인증 상태를 비웁니다.
     *
     * `actingAs()` 는 기본 가드까지 바꾼다. 그대로 두면 뒤따르는 가입 요청이 「방금 가입한 본인」으로 판정되지 않아
     * 응답의 이메일이 가려진다(실제 가입 요청은 비회원이 보낸다).
     *
     * @return void 반환값 없음
     */
    private function becomeGuest(): void
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse($this->defaultGuard);
    }

    /**
     * 실제 업그레이드와 같은 순서의 스텝 목록을 만듭니다 (00, 01, 07, 08, 09).
     *
     * @return array<int, DataMigration> 스텝 목록
     */
    private function allSteps(): array
    {
        return [
            new ResyncCoreDeclarations,
            new SyncActiveExtensionSchedules,
            new BackfillNicknameNormalized,
            new SeedCoreFieldDefinitions,
            new BackfillMemberSettingsAreas,
        ];
    }

    /**
     * 스텝을 7.1.5 → 7.2.0 컨텍스트로 실행합니다.
     *
     * @param  DataMigration  $step  실행할 스텝
     * @return void 반환값 없음
     */
    private function runStep(DataMigration $step): void
    {
        $step->run(new UpgradeContext(fromVersion: '7.1.5', toVersion: '7.2.0', currentStep: '7.2.0'));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 업그레이드 전 회원을 만듭니다 — 닉네임 정규화 값이 비어 있다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 만든 회원
     */
    private function legacyMember(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        DB::table('users')->where('id', $user->id)->update(['nickname_normalized' => null, 'nickname_unique_key' => null]);

        return $user->refresh();
    }

    /**
     * 운영자가 만든 항목을 넣습니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function customField(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
            'enabled' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 항목 표의 모든 행을 저장된 그대로 반환합니다 (시각 포함 — 「아무것도 쓰지 않음」 비교용).
     *
     * @return array<int, array<string, mixed>> id 순 행 목록
     */
    private function rawDefinitions(): array
    {
        return DB::table('field_definitions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * 항목 표의 행을 값 비교용으로 반환합니다 (id 와 시각 제외, JSON 컬럼은 풀어서).
     *
     * @return array<string, array<string, mixed>> 항목 키 => 속성
     */
    private function comparableDefinitions(): array
    {
        $jsonColumns = ['name', 'description', 'placeholder', 'options', 'constraints', 'locks', 'legacy_keys', 'ui', 'user_overrides'];
        $rows = [];

        foreach (DB::table('field_definitions')->orderBy('sort_order')->get() as $row) {
            $attributes = (array) $row;
            unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
            foreach ($jsonColumns as $column) {
                $attributes[$column] = $attributes[$column] === null ? null : json_decode((string) $attributes[$column], true);
            }
            ksort($attributes);
            $rows[$attributes['key']] = $attributes;
        }

        return $rows;
    }

    /**
     * 콜백이 도는 동안 회원 표와 항목 표에 나간 쓰기 쿼리 수를 셉니다.
     *
     * @param  callable(): void  $callback  실행할 동작
     * @return int insert, update, delete 쿼리 수
     */
    private function countWrites(callable $callback): int
    {
        $count = 0;
        $counting = true;
        DB::listen(function (QueryExecuted $query) use (&$count, &$counting): void {
            if ($counting
                && preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1
                && preg_match('/\b\w*(users|field_definitions|field_values)\b/i', $query->sql) === 1) {
                $count++;
            }
        });

        $callback();
        $counting = false;

        return $count;
    }
}
