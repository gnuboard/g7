<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackScope;
use App\Enums\PermissionType;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Helpers\TimezoneHelper;
use App\Models\FieldDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Marketing\Fields\MarketingConsentFieldHandler;
use Plugins\Sirsoft\Marketing\Fields\MarketingConsentFields;
use Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;
use Tests\Concerns\ActivatesBundledLanguagePack;

/**
 * 동의 항목이 코어 회원 항목(`core.user`)의 선언과 저장소 어댑터로 처리되는지.
 *
 * 가입, 관리자 회원 추가, 관리자 회원 수정, 내 정보 수정 네 경로가 같은 요청 키(접두 없는 항목 키)로 동의를 받고,
 * 동의 테이블과 이력에 한 번씩만 기록한다. 가입 경로의 옛 키(`agree_` 접두)는 계속 받는다.
 * 플러그인을 끄면 항목이 빠져 요청에 실린 값은 조용히 무시된다.
 */
class MarketingConsentFieldsTest extends PluginTestCase
{
    use ActivatesBundledLanguagePack;

    private const PLUGIN_ID = 'sirsoft-marketing';

    /**
     * 시험 중인 플러그인 설정 (설정 키 => 값)
     *
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * 가입 훅이 받은 registration_data
     *
     * @var array<string, mixed>|null
     */
    private ?array $registrationData = null;

    /**
     * 동의 변경 훅이 불린 항목 키 목록 (순서대로)
     *
     * @var array<int, string>
     */
    private array $changedKeys = [];

    /**
     * 일괄 저장 전 필터가 받은 동의 데이터 목록
     *
     * @var array<int, array<string, mixed>>
     */
    private array $filteredBatches = [];

    /**
     * 설정을 고정하고, 플러그인을 켠 상태로 리스너를 붙이고, 항목 선언을 동기화한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->settings = [
            'marketing_consent_enabled' => true,
            'marketing_consent_terms_slug' => 'marketing-terms',
            'third_party_consent_enabled' => true,
            'third_party_consent_terms_slug' => '',
            'info_disclosure_enabled' => true,
            'info_disclosure_terms_slug' => '',
            'channels' => json_encode([
                $this->channel('email_subscription', '광고성 이메일 수신', true, true, 'email-terms'),
                $this->channel('sms_subscription', 'SMS 수신'),
            ]),
        ];

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $this->settings[$key] ?? $default
        );
        $this->app->instance(PluginSettingsService::class, $settings);

        $service = new MarketingConsentService(new MarketingConsentRepository, $settings);
        $fields = new MarketingConsentFields($service, $settings);
        $this->app->instance(MarketingConsentService::class, $service);
        $this->app->instance(MarketingConsentFields::class, $fields);
        $this->app->instance(MarketingConsentListener::class, new MarketingConsentListener($service, $settings, $fields));

        $this->setPluginStatus(ExtensionStatus::Active);
        HookListenerRegistrar::register(MarketingConsentListener::class, self::PLUGIN_ID);
        $this->syncDeclarations();

        HookManager::addAction('core.auth.after_register', function ($user, array $context): void {
            $this->registrationData = $context['registration_data'] ?? null;
        });
        HookManager::addAction('sirsoft-marketing.user.consent_changed', function ($consent, array $context): void {
            $this->changedKeys[] = (string) ($context['key'] ?? '');
        });
        HookManager::addFilter('sirsoft-marketing.filter_consent_data', function (array $data): array {
            $this->filteredBatches[] = $data;

            return $data;
        });
    }

    /**
     * 채널 설정 한 건을 만듭니다.
     *
     * @param  string  $key  채널 키
     * @param  string  $label  항목명
     * @param  bool  $enabled  사용 여부
     * @param  bool  $system  시스템 채널 여부
     * @param  string  $slug  약관 페이지 slug
     * @return array<string, mixed> 채널 설정
     */
    private function channel(string $key, string $label, bool $enabled = true, bool $system = false, string $slug = ''): array
    {
        return [
            'key' => $key,
            'label' => ['ko' => $label, 'en' => $label],
            'page_slug' => $slug,
            'enabled' => $enabled,
            'is_system' => $system,
        ];
    }

    /**
     * plugins 테이블에 이 플러그인을 지정한 상태로 둡니다.
     *
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function setPluginStatus(ExtensionStatus $status): void
    {
        DB::table('plugins')->updateOrInsert(
            ['identifier' => self::PLUGIN_ID],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => '마케팅 동의', 'en' => 'Marketing Consent']),
                'version' => '1.1.4',
                'status' => $status->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        PluginManager::invalidatePluginStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 지금 설정으로 항목 선언을 동기화합니다 (설치, 업데이트, 활성화 때 코어가 하는 일).
     *
     * @return array{synced: array<int, string>, skipped: array<string, string>, removed: int} 동기화 결과
     */
    private function syncDeclarations(): array
    {
        $result = app(FieldDefinitionSyncHelper::class)->sync(
            'plugin',
            self::PLUGIN_ID,
            app(MarketingConsentFields::class)->declarations(),
        );
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $result;
    }

    /**
     * 이 플러그인이 선언한 항목 행의 키 목록을 반환합니다.
     *
     * @return array<int, string> 항목 키 (표시 순서)
     */
    private function storedKeys(): array
    {
        return FieldDefinition::where('source_type', 'plugin')
            ->where('source_identifier', self::PLUGIN_ID)
            ->orderBy('sort_order')
            ->pluck('key')
            ->all();
    }

    /**
     * 내 정보 수정 권한을 가진 일반 회원을 만듭니다.
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
     * 가입 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $overrides  더할 입력
     * @return TestResponse 응답
     */
    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/auth/register', array_merge([
            'name' => '홍길동',
            'email' => 'marketing-fields-'.uniqid().'@example.com',
            'password' => 'Str0ng!Passw0rd#490',
            'password_confirmation' => 'Str0ng!Passw0rd#490',
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $overrides));
    }

    /**
     * 회원의 이력을 「항목:행위:출처」 문자열 목록으로 읽습니다 (기록 순서).
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
     * 회원이 지금 동의한 항목 키를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, string> 동의한 항목 키 (가나다순)
     */
    private function consentedKeys(int $userId): array
    {
        return MarketingConsent::where('user_id', $userId)
            ->where('is_consented', true)
            ->orderBy('consent_key')
            ->pluck('consent_key')
            ->all();
    }

    /**
     * 선언을 동기화하면 전체 동의, 채널, 법적 동의가 예/아니오 항목 행으로 생긴다.
     *
     * @scenario case=declaration
     *
     * @effects consent_fields_declared_in_core_user_set
     *
     * @return void 반환값 없음
     */
    public function test_declarations_sync_into_core_user_field_rows(): void
    {
        $this->assertSame(
            ['marketing_consent', 'email_subscription', 'sms_subscription', 'third_party_consent', 'info_disclosure'],
            $this->storedKeys(),
        );

        $row = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'sms_subscription')->firstOrFail();
        $this->assertSame('boolean', $row->type);
        $this->assertSame(FieldDefinition::STORAGE_HANDLER, $row->storage);
        $this->assertSame(MarketingConsentFieldHandler::class, $row->storage_target);
        $this->assertSame(['agree_sms_subscription'], $row->legacy_keys);
        $this->assertEquals(['group' => 'agreements', 'role' => 'channel', 'parent' => 'marketing_consent'], $row->ui);
        $this->assertSame('SMS 수신', $row->name['ko']);
        $this->assertFalse($row->required);
        $this->assertTrue($row->show_on_signup);
        $this->assertTrue($row->isLocked('enabled'));

        $master = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'marketing_consent')->firstOrFail();
        $this->assertSame('마케팅 동의', $master->name['ko']);
        $this->assertSame('Marketing Consent', $master->name['en']);
        $this->assertNotEmpty($master->description['ko']);
    }

    /**
     * 활성 ja 언어팩이 있으면 전체 동의와 법적 동의 항목의 이름과 안내문에 ja 가 실린다.
     *
     * 팩을 막 켠 요청에서는 팩의 번역 경로가 등록되지 않아 번역기가 ja 를 모른다. 그때도 선언이 팩 파일을 직접 읽어야
     * 일본어 화면에서 이 항목들이 한국어로 남지 않는다(실측 C-O4). 시스템 채널(광고성 이메일 수신)의 이름도 같다.
     *
     * @scenario case=language_pack_names
     *
     * @effects declared_field_names_include_active_language_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_declared_field_names_include_active_ja_language_pack(): void
    {
        $this->activateBundledLanguagePack('g7-plugin-sirsoft-marketing-ja', LanguagePackScope::Plugin, self::PLUGIN_ID);

        $declared = collect(app(MarketingConsentFields::class)->declarations())->keyBy('key');

        foreach (['marketing_consent', 'third_party_consent', 'info_disclosure'] as $key) {
            $this->assertNotSame('', (string) ($declared[$key]['name']['ja'] ?? ''), "{$key} 이름에 ja 가 없다");
            $this->assertNotSame('', (string) ($declared[$key]['description']['ja'] ?? ''), "{$key} 안내문에 ja 가 없다");
        }
        $this->assertSame('マーケティング同意', $declared['marketing_consent']['name']['ja']);
        $this->assertSame('마케팅 동의', $declared['marketing_consent']['name']['ko'], '플러그인 자체 파일의 언어는 그대로다');
        // 시스템 채널 이름은 label_key(`channels.php`)로 푼다 — 같은 이유로 팩 파일을 직접 읽어야 한다
        $this->assertSame('広告メール受信', $declared['email_subscription']['name']['ja'] ?? null, '시스템 채널 이름에 ja 가 없다');
    }

    /**
     * 가입 화면 항목 계약에 동의 항목이 약관 묶음과 약관 링크를 싣고 나온다.
     *
     * @scenario case=contract
     *
     * @effects consent_fields_in_signup_contract
     *
     * @return void 반환값 없음
     */
    public function test_signup_field_contract_carries_agreement_group_and_terms_link(): void
    {
        $fields = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->keyBy('key');

        foreach (['marketing_consent', 'email_subscription', 'sms_subscription', 'third_party_consent', 'info_disclosure'] as $key) {
            $this->assertTrue($fields->has($key), "가입 항목 계약에 {$key} 가 없습니다.");
            $this->assertSame($key, $fields[$key]['name']);
            $this->assertSame('boolean', $fields[$key]['type']);
            $this->assertSame('plugin', $fields[$key]['owner_type']);
            $this->assertSame(self::PLUGIN_ID, $fields[$key]['owner']);
            $this->assertSame('agreements', $fields[$key]['ui']['group']);
            $this->assertFalse($fields[$key]['required']);
        }

        $this->assertSame('/page/marketing-terms', $fields['marketing_consent']['ui']['link']['url']);
        $this->assertSame('/api/modules/sirsoft-page/pages/marketing-terms', $fields['marketing_consent']['ui']['link']['content_api']);
        $this->assertSame('sirsoft-page', $fields['marketing_consent']['ui']['link']['requires_module']);
        $this->assertSame('/page/email-terms', $fields['email_subscription']['ui']['link']['url']);
        $this->assertArrayNotHasKey('link', $fields['sms_subscription']['ui']);
        $this->assertSame('marketing_consent', $fields['sms_subscription']['ui']['parent']);
        $this->assertNotEmpty($fields['third_party_consent']['help']);
    }

    /**
     * 가입 요청이 접두 없는 항목 키로 동의를 받아 가입 출처로 한 번씩 기록한다.
     *
     * @scenario case=signup
     *
     * @effects consent_saved_once_per_path, history_source_follows_surface
     *
     * @return void 반환값 없음
     */
    public function test_signup_saves_consents_sent_with_field_keys(): void
    {
        $email = 'marketing-fields-'.uniqid().'@example.com';

        $this->register([
            'email' => $email,
            'marketing_consent' => true,
            'email_subscription' => true,
            'sms_subscription' => false,
            'third_party_consent' => true,
            'info_disclosure' => false,
        ])->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(['email_subscription', 'marketing_consent', 'third_party_consent'], $this->consentedKeys($user->id));
        $this->assertSame([
            'marketing_consent:granted:register',
            'email_subscription:granted:register',
            'third_party_consent:granted:register',
        ], $this->historyTrail($user->id));
        $this->assertSame(['marketing_consent', 'email_subscription', 'third_party_consent'], $this->changedKeys);
        $this->assertTrue($this->registrationData['marketing_consent']);
        $this->assertArrayNotHasKey('agree_marketing_consent', $this->registrationData);
    }

    /**
     * 가입 요청의 옛 키(agree_ 접두)는 정식 키로 옮겨 저장되고, 가입 데이터에는 둘 다 실린다.
     *
     * @scenario case=signup_legacy_keys
     *
     * @effects legacy_agree_keys_accepted, consent_saved_once_per_path
     *
     * @return void 반환값 없음
     */
    public function test_signup_accepts_legacy_agree_keys(): void
    {
        $email = 'marketing-fields-'.uniqid().'@example.com';

        $this->register([
            'email' => $email,
            'agree_marketing_consent' => true,
            'agree_sms_subscription' => true,
            'agree_third_party_consent' => false,
        ])->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(['marketing_consent', 'sms_subscription'], $this->consentedKeys($user->id));
        $this->assertSame([
            'marketing_consent:granted:register',
            'sms_subscription:granted:register',
        ], $this->historyTrail($user->id));

        // 옛 키를 읽던 구독자도, 정식 키를 읽는 구독자도 값을 받는다
        $this->assertTrue($this->registrationData['agree_marketing_consent']);
        $this->assertTrue($this->registrationData['marketing_consent']);
        $this->assertTrue($this->registrationData['sms_subscription']);
        $this->assertFalse($this->registrationData['third_party_consent']);
    }

    /**
     * 정식 키와 옛 키가 함께 오면 정식 키의 값을 쓴다.
     *
     * @scenario case=signup_legacy_keys
     *
     * @effects legacy_agree_keys_accepted
     *
     * @return void 반환값 없음
     */
    public function test_signup_prefers_field_key_over_legacy_key(): void
    {
        $email = 'marketing-fields-'.uniqid().'@example.com';

        $this->register([
            'email' => $email,
            'third_party_consent' => false,
            'agree_third_party_consent' => true,
        ])->assertSuccessful();

        $this->assertSame([], $this->consentedKeys(User::where('email', $email)->firstOrFail()->id));
    }

    /**
     * 관리자 회원 추가가 동의를 관리자 출처로 한 번씩 기록한다.
     *
     * @scenario case=admin_create
     *
     * @effects consent_saved_once_per_path, history_source_follows_surface
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_saves_consents(): void
    {
        $admin = $this->createAdminUser(['core.users.create', 'core.users.read']);
        $email = 'marketing-fields-'.uniqid().'@example.com';

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Consent Fields',
            'email' => $email,
            'password' => 'Gx7!quietRiver42',
            'password_confirmation' => 'Gx7!quietRiver42',
            'marketing_consent' => true,
            'email_subscription' => true,
            'info_disclosure' => true,
        ])->assertCreated();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(['email_subscription', 'info_disclosure', 'marketing_consent'], $this->consentedKeys($user->id));
        $this->assertSame([
            'marketing_consent:granted:admin',
            'email_subscription:granted:admin',
            'info_disclosure:granted:admin',
        ], $this->historyTrail($user->id));
    }

    /**
     * 관리자 회원 수정이 바뀐 동의만 관리자 출처로 기록하고, 상세 응답에 값을 싣는다.
     *
     * @scenario case=admin_update
     *
     * @effects consent_saved_once_per_path, history_source_follows_surface, consent_values_in_user_response
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_saves_changed_consents_and_returns_them(): void
    {
        $admin = $this->createAdminUser(['core.users.update', 'core.users.read']);
        $member = $this->makeMember();
        app(MarketingConsentService::class)->updateConsents($member->id, ['third_party_consent' => true], 'register');
        $this->changedKeys = [];
        $this->filteredBatches = [];

        $this->actingAs($admin)->putJson("/api/admin/users/{$member->uuid}", [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles()->pluck('roles.id')->all(),
            'marketing_consent' => true,
            'email_subscription' => false,
            'sms_subscription' => true,
            'third_party_consent' => true,
            'info_disclosure' => false,
        ])->assertOk();

        $this->assertSame([
            'third_party_consent:granted:register',
            'marketing_consent:granted:admin',
            'sms_subscription:granted:admin',
        ], $this->historyTrail($member->id));
        $this->assertSame(['marketing_consent', 'sms_subscription'], $this->changedKeys);
        // 바뀐 항목마다 한 번씩만 저장 서비스에 들어간다 (리스너의 옛 저장 경로가 함께 돌지 않는다)
        $this->assertSame([['marketing_consent' => true], ['sms_subscription' => true]], $this->filteredBatches);

        $detail = $this->actingAs($admin)->getJson("/api/admin/users/{$member->uuid}")->assertOk();
        $this->assertTrue($detail->json('data.marketing_consent'));
        $this->assertTrue($detail->json('data.sms_subscription'));
        $this->assertFalse($detail->json('data.email_subscription'));
        $this->assertNotNull($detail->json('data.sms_subscription_at'));
        $this->assertCount(3, $detail->json('data.consent_histories'));
        $this->assertNull($detail->json('data.marketing_consent_enabled'));

        $form = collect($this->actingAs($admin)->getJson("/api/admin/users/{$member->uuid}/form-fields")->assertOk()->json('data.fields'))->keyBy('key');
        $this->assertSame('agreements', $form['sms_subscription']['ui']['group']);
        $this->assertNotNull($form['sms_subscription']['display_value']);
        $this->assertNotSame($form['sms_subscription']['display_value'], $form['email_subscription']['display_value']);
    }

    /**
     * 내 정보 수정이 바뀐 동의만 본인 출처로 기록하고, 내 정보 응답에 값을 싣는다.
     *
     * @scenario case=self_update
     *
     * @effects consent_saved_once_per_path, history_source_follows_surface, consent_values_in_user_response
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_saves_changed_consents_and_returns_them(): void
    {
        $member = $this->makeMember();

        $body = ['name' => $member->name, 'email' => $member->email];
        $this->actingAs($member)->putJson('/api/me', [
            ...$body,
            'marketing_consent' => true,
            'email_subscription' => true,
            'sms_subscription' => false,
            'third_party_consent' => false,
            'info_disclosure' => false,
        ])->assertOk();

        $this->assertSame([
            'marketing_consent:granted:profile',
            'email_subscription:granted:profile',
        ], $this->historyTrail($member->id));

        // 같은 값으로 다시 저장하면 이력이 늘지 않는다
        $this->actingAs($member)->putJson('/api/me', [...$body, 'marketing_consent' => true, 'email_subscription' => true])->assertOk();
        $this->assertCount(2, $this->historyTrail($member->id));

        // 전체 동의를 끄면 채널이 함께 꺼진다 (채널 칸이 켜진 채 와도 다시 켜지지 않는다)
        $this->actingAs($member)->putJson('/api/me', [...$body, 'marketing_consent' => false, 'email_subscription' => true])->assertOk();
        $this->assertSame([], $this->consentedKeys($member->id));
        $this->assertSame([
            'marketing_consent:granted:profile',
            'email_subscription:granted:profile',
            'marketing_consent:revoked:profile',
            'email_subscription:revoked:profile',
        ], $this->historyTrail($member->id));

        $me = $this->actingAs($member)->getJson('/api/me')->assertOk();
        $this->assertFalse($me->json('data.marketing_consent'));
        $this->assertFalse($me->json('data.email_subscription'));
        $this->assertNull($me->json('data.email_subscription_terms_slug'));

        $fields = collect($this->actingAs($member)->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key');
        $this->assertSame('agreements', $fields['marketing_consent']['ui']['group']);
        $this->assertFalse($fields['marketing_consent']['readonly']);
    }

    /**
     * 내 정보 보기의 동의 항목은 동의한 날짜를 값 아래 알림 줄로 보인다 — 항목 계약 `ui.notice`(지운 보기 조각의
     * 「동의일」 배지 복원). 동의하지 않은 항목에는 싣지 않는다.
     *
     * @scenario case=consent_date_notice
     *
     * @effects consented_on_notice_in_self_view
     *
     * @return void 반환값 없음
     */
    public function test_self_fields_carry_consent_date_notice_for_agreed_items(): void
    {
        // 새 문구 키는 원본(_bundled)에만 있다 — 설치본 반영 전에도 원본 문구로 판정한다
        app('translator')->addNamespace('sirsoft-marketing', base_path('plugins/_bundled/sirsoft-marketing/lang'));
        app('translator')->setLoaded([]);
        $member = $this->makeMember();
        $this->actingAs($member)->putJson('/api/me', ['name' => $member->name, 'email' => $member->email, 'marketing_consent' => true])->assertOk();
        $consentedAt = DB::table('user_marketing_consents')->where('user_id', $member->id)->where('consent_key', 'marketing_consent')->value('consented_at');
        $expected = __('sirsoft-marketing::fields.consented_on', [
            'date' => Carbon::parse($consentedAt)->setTimezone(TimezoneHelper::getUserTimezone())->format('Y-m-d'),
        ]);

        $fields = collect($this->actingAs($member)->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key');

        $this->assertNotSame('sirsoft-marketing::fields.consented_on', $expected, '동의일 문구 키가 없다');
        $this->assertSame($expected, $fields['marketing_consent']['ui']['notice'] ?? null);
        $this->assertArrayNotHasKey('notice', $fields['third_party_consent']['ui'] ?? []);
    }

    /**
     * 운영자가 회원 항목 표에서 동의를 필수로 바꾸면 가입 요청이 그 동의를 요구한다.
     *
     * @scenario case=operator_override
     *
     * @effects operator_required_enforced_on_signup
     *
     * @return void 반환값 없음
     */
    public function test_signup_requires_consent_when_operator_marks_it_required(): void
    {
        FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'third_party_consent')->firstOrFail()
            ->update(['required' => true]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->register(['third_party_consent' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['third_party_consent']);

        $this->register(['agree_third_party_consent' => true])->assertSuccessful();
    }

    /**
     * 플러그인 설정에서 끈 동의는 요청에 실려 와도 저장되지 않고 화면 계약에도 없다.
     *
     * @scenario case=settings_off
     *
     * @effects disabled_consent_ignored
     *
     * @return void 반환값 없음
     */
    public function test_consent_turned_off_in_settings_is_ignored(): void
    {
        $this->settings['third_party_consent_enabled'] = false;
        $member = $this->makeMember();

        $this->actingAs($member)->putJson('/api/me', [
            'name' => $member->name,
            'email' => $member->email,
            'third_party_consent' => true,
            'info_disclosure' => true,
        ])->assertOk();

        $this->assertSame(['info_disclosure'], $this->consentedKeys($member->id));
        $this->assertNull($member->fresh()->getAttribute('third_party_consent'));

        $keys = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->pluck('key')->all();
        $this->assertNotContains('third_party_consent', $keys);
        $this->assertContains('info_disclosure', $keys);
    }

    /**
     * 플러그인을 끄면 동의 항목이 회원 항목에서 빠지고, 요청에 실린 값은 조용히 무시된다.
     *
     * @scenario case=plugin_inactive
     *
     * @effects fields_absent_when_plugin_inactive
     *
     * @return void 반환값 없음
     */
    public function test_fields_disappear_when_plugin_is_inactive(): void
    {
        // 끈 플러그인은 리스너가 붙지 않는다 — 훅 구독을 걷고 상태를 비활성으로 바꾼다.
        foreach (array_keys(MarketingConsentListener::getSubscribedHooks()) as $hook) {
            HookManager::clearFilter($hook);
            HookManager::clearAction($hook);
        }
        $this->setPluginStatus(ExtensionStatus::Inactive);

        $keys = app(FieldRegistry::class)->forSet(FieldSet::CORE_USER)->map(fn (FieldDefinition $definition) => $definition->key)->all();
        $this->assertNotContains('marketing_consent', $keys);
        $this->assertNotContains('sms_subscription', $keys);
        // 행은 남는다 — 다시 켜면 운영자가 바꿔 둔 값과 함께 돌아온다
        $this->assertCount(5, $this->storedKeys());

        $member = $this->makeMember();
        $this->actingAs($member)->putJson('/api/me', [
            'name' => $member->name,
            'email' => $member->email,
            'marketing_consent' => true,
            'sms_subscription' => true,
        ])->assertOk();

        $this->assertSame(0, MarketingConsent::where('user_id', $member->id)->count());
        $this->assertSame(0, MarketingConsentHistory::where('user_id', $member->id)->count());

        $signupKeys = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->pluck('key')->all();
        $this->assertNotContains('marketing_consent', $signupKeys);
    }

    /**
     * 설정을 저장하면 채널 항목 행이 따라가고, 운영자가 표에서 바꿔 둔 값은 남는다.
     *
     * @scenario case=channel_settings_change
     *
     * @effects channel_rows_follow_settings, operator_overrides_survive_resync
     *
     * @return void 반환값 없음
     */
    public function test_saving_settings_resyncs_channel_field_rows(): void
    {
        // 운영자가 회원 항목 표에서 SMS 채널을 가입 화면에서 뺐다
        $sms = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', 'sms_subscription')->firstOrFail();
        $sms->update(['show_on_signup' => false, 'user_overrides' => ['show_on_signup']]);

        // 채널을 하나 더하고 SMS 채널의 이름을 바꿔 저장한다
        $this->settings['channels'] = json_encode([
            $this->channel('email_subscription', '광고성 이메일 수신', true, true, 'email-terms'),
            $this->channel('sms_subscription', '문자 수신'),
            $this->channel('kakao_subscription', '알림톡 수신'),
        ]);
        HookManager::doAction('core.plugin_settings.after_save', self::PLUGIN_ID, [], true);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertContains('kakao_subscription', $this->storedKeys());
        $sms->refresh();
        $this->assertSame('문자 수신', $sms->name['ko']);
        $this->assertFalse($sms->show_on_signup, '운영자가 바꾼 가입 화면 표시가 재동기화에서 되돌아갔다.');

        // 다른 플러그인의 설정 저장에는 반응하지 않는다
        $this->settings['channels'] = json_encode([$this->channel('email_subscription', '광고성 이메일 수신', true, true)]);
        HookManager::doAction('core.plugin_settings.after_save', 'sirsoft-other', [], true);
        $this->assertContains('kakao_subscription', $this->storedKeys());

        // 채널을 지우고 저장하면 그 항목 행도 사라진다
        HookManager::doAction('core.plugin_settings.after_save', self::PLUGIN_ID, [], true);
        $this->assertSame(['marketing_consent', 'email_subscription', 'third_party_consent', 'info_disclosure'], $this->storedKeys());
    }

    /**
     * 항목 키 형식에 맞지 않는 채널 키는 행이 되지 못해도 요청 시점에 항목으로 더해져 값을 받는다.
     *
     * @scenario case=channel_key_without_row
     *
     * @effects channel_without_row_still_accepted
     *
     * @return void 반환값 없음
     */
    public function test_channel_whose_key_cannot_be_a_row_is_still_accepted(): void
    {
        $this->settings['channels'] = json_encode([
            $this->channel('email_subscription', '광고성 이메일 수신', true, true),
            $this->channel('2nd_channel', '둘째 채널'),
        ]);
        $result = $this->syncDeclarations();

        $this->assertArrayHasKey('core.user|2nd_channel', $result['skipped']);
        $this->assertNotContains('2nd_channel', $this->storedKeys());

        $member = $this->makeMember();
        $this->actingAs($member)->putJson('/api/me', [
            'name' => $member->name,
            'email' => $member->email,
            '2nd_channel' => true,
        ])->assertOk();

        $this->assertSame(['2nd_channel', 'marketing_consent'], $this->consentedKeys($member->id));
    }

    /**
     * 회원을 완전히 지우면 동의 상태와 이력이 함께 지워진다.
     *
     * @scenario case=purge
     *
     * @effects consent_purged_with_owner
     *
     * @return void 반환값 없음
     */
    public function test_handler_purge_removes_consents_and_histories(): void
    {
        $member = $this->makeMember();
        app(MarketingConsentService::class)->updateConsents($member->id, ['marketing_consent' => true, 'email_subscription' => true], 'register');

        $definition = app(FieldRegistry::class)->find(FieldSet::CORE_USER, 'marketing_consent');
        $this->assertNotNull($definition);
        app(MarketingConsentFieldHandler::class)->purge($member, $definition);

        $this->assertSame(0, MarketingConsent::where('user_id', $member->id)->count());
        $this->assertSame(0, MarketingConsentHistory::where('user_id', $member->id)->count());
    }
}
