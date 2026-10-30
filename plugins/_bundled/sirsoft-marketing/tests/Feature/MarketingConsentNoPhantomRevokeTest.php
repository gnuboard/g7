<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 저장할 때 실제로 바뀌지 않은 동의 항목에는 「철회」도 「동의」도 기록되지 않는다.
 *
 * 화면은 저장할 때 모든 동의 칸을 함께 보낸다. 기록이 없는 항목에 false 가 오면 이미 「동의 안 함」 상태이므로
 * 동의 행·이력·철회 시각·철회 훅이 생기면 안 된다. 전체 동의를 끌 때 함께 꺼지는 것은 수신 채널뿐이고,
 * 채널이 아닌 법적 동의(제3자 제공·정보 공개)는 그대로 남아야 한다 — 같은 저장에서 철회했다가 다시 동의한
 * 것처럼 기록되면 회원 상세의 약관 동의 이력에 일어나지 않은 철회·동의가 남고 동의 시각이 바뀐다.
 */
class MarketingConsentNoPhantomRevokeTest extends PluginTestCase
{
    /**
     * 시험에 쓰는 동의 채널 (기본 시스템 채널 + 운영자가 더한 채널 + 사용중지한 채널)
     */
    private const CHANNELS = [
        [
            'key' => 'email_subscription',
            'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
            'page_slug' => '',
            'enabled' => true,
            'is_system' => true,
        ],
        [
            'key' => 'sms_subscription',
            'label' => ['ko' => 'SMS 수신', 'en' => 'SMS'],
            'page_slug' => '',
            'enabled' => true,
            'is_system' => false,
        ],
        [
            'key' => 'kakao_subscription',
            'label' => ['ko' => '알림톡 수신', 'en' => 'Kakao'],
            'page_slug' => '',
            'enabled' => false,
            'is_system' => false,
        ],
    ];

    /**
     * 화면이 보내는 동의 항목 전체 (전체 동의 + 사용 중 채널 2 + 법적 동의 2)
     */
    private const ALL_KEYS = [
        'marketing_consent',
        'email_subscription',
        'sms_subscription',
        'third_party_consent',
        'info_disclosure',
    ];

    /**
     * 철회 훅이 불린 항목 키 목록
     *
     * @var array<int, string>
     */
    private array $unsubscribedKeys = [];

    /**
     * 동의 테이블이 있으면 테스트 DB 재생성 없이 트랜잭션으로 격리합니다.
     *
     * 테이블이 없는 새 테스트 DB 에서는 기반 클래스의 재생성을 한 번 거친다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        if (! Schema::hasTable('user_marketing_consents') || ! Schema::hasTable('user_marketing_consent_histories')) {
            parent::refreshDatabase();

            return;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * 채널 설정을 고정한 동의 리스너를 운영과 같은 등록기로 붙이고, 철회 훅 호출을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $id, string $key, mixed $default = null) => $key === 'channels'
                ? json_encode(self::CHANNELS)
                : ($default ?? true)
        );

        $service = new MarketingConsentService(new MarketingConsentRepository, $settings);
        $this->app->instance(MarketingConsentService::class, $service);
        $this->app->instance(MarketingConsentListener::class, new MarketingConsentListener($service, $settings));
        HookListenerRegistrar::register(MarketingConsentListener::class, 'sirsoft-marketing');

        $this->unsubscribedKeys = [];
        HookManager::addAction('sirsoft-marketing.user.unsubscribed', function ($consent, string $key): void {
            $this->unsubscribedKeys[] = $key;
        });
    }

    /**
     * 모든 동의 칸을 같은 값으로 채운 요청 본문 조각을 만듭니다.
     *
     * @param  bool  $value  동의 여부
     * @param  string  $prefix  필드 접두사 (가입 폼은 agree_)
     * @return array<string, bool> 필드 → 값
     */
    private function allConsents(bool $value, string $prefix = ''): array
    {
        $fields = [];
        foreach (self::ALL_KEYS as $key) {
            $fields[$prefix.$key] = $value;
        }

        return $fields;
    }

    /**
     * 마이페이지 정보 수정 권한을 가진 일반 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function makeMember(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '회원', 'en' => 'User']]);
        foreach (['core.profile.read', 'core.profile.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::User]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /**
     * 회원의 이력을 「항목:행위:출처」 문자열 목록으로 읽습니다(기록 순서).
     *
     * @param  int  $userId  회원 ID
     * @return array<int, string> 이력 요약
     */
    private function historyTrail(int $userId): array
    {
        return MarketingConsentHistory::where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn ($history) => $history->channel_key.':'.$history->action.':'.$history->source)
            ->all();
    }

    /**
     * 회원에게 동의 행·이력이 하나도 없고 철회 훅도 불리지 않았음을 확인합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $path  경로 이름 (실패 메시지용)
     * @return void 반환값 없음
     */
    private function assertNothingRecorded(int $userId, string $path): void
    {
        $this->assertSame([], $this->historyTrail($userId), "{$path}: 동의한 적 없는 항목에 이력이 생겼다.");

        $rows = MarketingConsent::where('user_id', $userId)->get(['consent_key', 'is_consented', 'revoked_at']);
        $this->assertSame([], $rows->toArray(), "{$path}: 동의한 적 없는 항목에 동의 행(철회 시각 포함)이 생겼다.");

        $this->assertSame([], $this->unsubscribedKeys, "{$path}: 동의한 적 없는 항목으로 철회 훅이 불렸다.");
    }

    /**
     * 동의한 적 없는 회원이 동의 칸을 끈 채 닉네임만 바꿔 저장하면 동의 기록이 생기지 않는다. (실패-먼저)
     *
     * @scenario case=profile_update
     *
     * @effects unconsented_false_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_profile_save_with_unchecked_boxes_records_nothing(): void
    {
        $member = $this->makeMember();

        $this->actingAs($member)->putJson('/api/me', [
            'name' => $member->name,
            'email' => $member->email,
            'nickname' => 'renamed-only',
            ...$this->allConsents(false),
        ])->assertOk();

        $this->assertSame('renamed-only', $member->fresh()->nickname);
        $this->assertNothingRecorded($member->id, '정보 수정');
    }

    /**
     * 관리자가 동의한 적 없는 회원을 동의 칸을 끈 채 수정해도 동의 기록이 생기지 않는다. (실패-먼저)
     *
     * @scenario case=admin_update
     *
     * @effects unconsented_false_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_with_unchecked_boxes_records_nothing(): void
    {
        $admin = $this->createAdminUser(['core.users.update', 'core.users.read']);
        $member = $this->makeMember();

        $this->actingAs($admin)->putJson("/api/admin/users/{$member->uuid}", [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles()->pluck('roles.id')->all(),
            ...$this->allConsents(false),
        ])->assertOk();

        $this->assertNothingRecorded($member->id, '관리자 회원 수정');
    }

    /**
     * 관리자가 동의 칸을 끈 채 회원을 추가하면 새 회원에게 동의 기록이 생기지 않는다. (실패-먼저)
     *
     * @scenario case=admin_create
     *
     * @effects unconsented_false_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_with_unchecked_boxes_records_nothing(): void
    {
        $admin = $this->createAdminUser(['core.users.create', 'core.users.read']);
        $email = 'phantom-revoke-'.uniqid().'@example.com';

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Phantom Revoke',
            'email' => $email,
            'password' => 'Gx7!quietRiver42',
            'password_confirmation' => 'Gx7!quietRiver42',
            ...$this->allConsents(false),
        ])->assertCreated();

        $created = User::where('email', $email)->firstOrFail();
        $this->assertNothingRecorded($created->id, '관리자 회원 추가');
    }

    /**
     * 동의 칸을 끈 채 가입하면 동의 기록이 생기지 않는다. (실패-먼저)
     *
     * 코어는 검증된 가입 데이터를 `core.auth.after_register` 의 `registration_data` 로 넘긴다 — 그 훅을 그대로 발화한다.
     *
     * @scenario case=register
     *
     * @effects unconsented_false_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_register_with_unchecked_boxes_records_nothing(): void
    {
        $user = User::factory()->create();

        HookManager::doAction('core.auth.after_register', $user, [
            'registration_data' => ['email' => $user->email, ...$this->allConsents(false, 'agree_')],
        ]);

        $this->assertNothingRecorded($user->id, '가입');
    }

    /**
     * 제3자 제공만 동의하고 가입한 회원이 아무것도 바꾸지 않고 정보 수정을 저장하면 이력이 가입 때 그대로다. (실패-먼저)
     *
     * 전체 동의 기록이 없는데 false 가 와서 전체 동의 철회로 처리되면, 그 연쇄가 제3자 제공 동의까지 철회하고 같은
     * 저장이 다시 동의로 기록해 「철회 → 동의」 쌍이 남았다.
     *
     * @scenario case=profile_update
     *
     * @effects unconsented_false_writes_nothing, master_revoke_keeps_legal_consents
     *
     * @return void 반환값 없음
     */
    public function test_untouched_profile_save_after_register_keeps_register_history_only(): void
    {
        $member = $this->makeMember();
        $consents = [...$this->allConsents(false), 'third_party_consent' => true];

        HookManager::doAction('core.auth.after_register', $member, [
            'registration_data' => [
                'email' => $member->email,
                ...$this->allConsents(false, 'agree_'),
                'agree_third_party_consent' => true,
            ],
        ]);
        $grantedAt = MarketingConsent::where('user_id', $member->id)->where('consent_key', 'third_party_consent')->value('consented_at');

        $this->travel(5)->minutes();

        $this->actingAs($member)->putJson('/api/me', [
            'name' => $member->name,
            'email' => $member->email,
            ...$consents,
        ])->assertOk();

        $this->assertSame(['third_party_consent:granted:register'], $this->historyTrail($member->id));

        $rows = MarketingConsent::where('user_id', $member->id)->get()->keyBy('consent_key');
        $this->assertSame(['third_party_consent'], $rows->keys()->all(), '동의한 적 없는 항목에 상태 행이 생겼다.');
        $this->assertTrue($rows['third_party_consent']->is_consented);
        $this->assertSame(1, $rows['third_party_consent']->consent_count);
        $this->assertNull($rows['third_party_consent']->revoked_at);
        $this->assertEquals($grantedAt, $rows['third_party_consent']->consented_at, '제3자 제공 동의 시각이 바뀌었다.');
        $this->assertSame([], $this->unsubscribedKeys);
    }

    /**
     * 전체 동의를 끄면 수신 채널만 함께 꺼지고 법적 동의(제3자 제공·정보 공개)는 그대로 남는다. (실패-먼저)
     *
     * 화면은 전체 동의를 끌 때 채널 칸만 함께 끄고 법적 동의 칸은 그대로 보낸다.
     *
     * @scenario case=profile_update
     *
     * @effects master_revoke_keeps_legal_consents, real_revoke_still_recorded
     *
     * @return void 반환값 없음
     */
    public function test_master_revoke_from_profile_keeps_legal_consents(): void
    {
        $member = $this->makeMember();
        $base = ['name' => $member->name, 'email' => $member->email];

        $this->actingAs($member)->putJson('/api/me', [
            ...$base,
            ...$this->allConsents(true),
            'sms_subscription' => false,
        ])->assertOk();
        $before = MarketingConsent::where('user_id', $member->id)->get()->keyBy('consent_key');

        $this->travel(5)->minutes();

        $this->actingAs($member)->putJson('/api/me', [
            ...$base,
            ...$this->allConsents(false),
            'third_party_consent' => true,
            'info_disclosure' => true,
        ])->assertOk();

        $trail = $this->historyTrail($member->id);
        $legalTrail = array_values(array_filter($trail, fn ($line) => str_starts_with($line, 'third_party_consent:') || str_starts_with($line, 'info_disclosure:')));
        sort($legalTrail);
        $this->assertSame(
            ['info_disclosure:granted:profile', 'third_party_consent:granted:profile'],
            $legalTrail,
            '전체 동의 철회가 법적 동의까지 철회·재동의로 기록했다.'
        );

        $after = MarketingConsent::where('user_id', $member->id)->get()->keyBy('consent_key');
        foreach (MarketingConsent::LEGAL_KEYS as $key) {
            $this->assertTrue($after[$key]->is_consented, "{$key} 가 동의 상태가 아니다.");
            $this->assertSame(1, $after[$key]->consent_count, "{$key} 의 동의 횟수가 늘었다.");
            $this->assertNull($after[$key]->revoked_at, "{$key} 에 철회 시각이 생겼다.");
            $this->assertEquals($before[$key]->consented_at, $after[$key]->consented_at, "{$key} 의 동의 시각이 바뀌었다.");
        }

        foreach (['marketing_consent', 'email_subscription'] as $key) {
            $this->assertFalse($after[$key]->is_consented, "{$key} 가 철회되지 않았다.");
            $this->assertNotNull($after[$key]->revoked_at);
        }
        $this->assertArrayNotHasKey('sms_subscription', $after->all(), '동의한 적 없는 채널에 상태 행이 생겼다.');

        $unsubscribed = $this->unsubscribedKeys;
        sort($unsubscribed);
        $this->assertSame(['email_subscription', 'marketing_consent'], $unsubscribed);
    }

    /**
     * 전체 동의 철회는 사용중지한 채널의 남은 동의까지 철회하지만 법적 동의는 건드리지 않는다. (실패-먼저)
     *
     * @scenario case=service_direct
     *
     * @effects master_revoke_keeps_legal_consents, real_revoke_still_recorded
     *
     * @return void 반환값 없음
     */
    public function test_master_revoke_cascades_to_disabled_channel_but_not_legal(): void
    {
        $user = User::factory()->create();
        $service = $this->app->make(MarketingConsentService::class);

        $service->updateConsent($user->id, 'kakao_subscription', true, 'register');
        $service->updateConsent($user->id, MarketingConsent::MASTER_KEY, true, 'register');
        $service->updateConsent($user->id, 'third_party_consent', true, 'register');
        $service->updateConsent($user->id, 'info_disclosure', true, 'register');

        $service->updateConsent($user->id, MarketingConsent::MASTER_KEY, false, 'admin');

        $rows = MarketingConsent::where('user_id', $user->id)->get()->keyBy('consent_key');
        $this->assertFalse($rows['kakao_subscription']->is_consented, '사용중지한 채널의 동의가 남았다.');
        $this->assertTrue($rows['third_party_consent']->is_consented);
        $this->assertTrue($rows['info_disclosure']->is_consented);

        $this->assertSame([
            'kakao_subscription:granted:register',
            'marketing_consent:granted:register',
            'third_party_consent:granted:register',
            'info_disclosure:granted:register',
            'marketing_consent:revoked:admin',
            'kakao_subscription:revoked:admin',
        ], $this->historyTrail($user->id));
    }

    /**
     * 동의했다가 끄면 종전대로 동의·철회 이력이 모두 남고 철회 시각이 채워진다.
     *
     * 이미 철회한 항목을 다시 끈 채 저장해도 이력은 더 생기지 않는다.
     *
     * @scenario case=profile_update
     *
     * @effects real_revoke_still_recorded
     *
     * @return void 반환값 없음
     */
    public function test_grant_then_revoke_is_still_recorded(): void
    {
        $member = $this->makeMember();
        $base = ['name' => $member->name, 'email' => $member->email];

        $this->actingAs($member)->putJson('/api/me', [
            ...$base,
            ...$this->allConsents(false),
            'email_subscription' => true,
        ])->assertOk();

        $this->actingAs($member)->putJson('/api/me', [...$base, ...$this->allConsents(false)])->assertOk();
        $this->actingAs($member)->putJson('/api/me', [...$base, ...$this->allConsents(false)])->assertOk();

        $actions = array_map(
            fn ($line) => implode(':', array_slice(explode(':', $line), 0, 2)),
            $this->historyTrail($member->id)
        );
        sort($actions);
        $this->assertSame([
            'email_subscription:granted',
            'email_subscription:revoked',
            'marketing_consent:granted',
            'marketing_consent:revoked',
        ], $actions, '동의한 두 항목만 동의·철회가 한 번씩 남아야 한다.');

        $rows = MarketingConsent::where('user_id', $member->id)->get()->keyBy('consent_key');
        $this->assertSame(['email_subscription', 'marketing_consent'], $rows->keys()->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertFalse($row->is_consented);
            $this->assertNotNull($row->revoked_at);
            $this->assertSame(1, $row->consent_count);
        }

        $unsubscribed = $this->unsubscribedKeys;
        sort($unsubscribed);
        $this->assertSame(['email_subscription', 'marketing_consent'], $unsubscribed);
    }
}
