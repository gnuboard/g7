<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Repositories\MarketingConsentRepository;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

class MarketingConsentListenerTest extends PluginTestCase
{
    private MarketingConsentListener $listener;

    private MarketingConsentService $service;

    /**
     * 기본 채널 목록 (테스트용)
     */
    private const DEFAULT_CHANNELS = [
        [
            'key' => 'email_subscription',
            'label' => ['ko' => '광고성 이메일 수신', 'en' => 'Email Marketing'],
            'page_slug' => 'email-terms',
            'enabled' => true,
            'is_system' => true,
        ],
    ];

    /**
     * 테스트용 설정 서비스와 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $pluginSettings = $this->createMock(PluginSettingsService::class);
        $pluginSettings->method('get')->willReturnCallback(
            function (string $id, string $key, mixed $default = null) {
                return match ($key) {
                    'channels' => json_encode(self::DEFAULT_CHANNELS),
                    'marketing_consent_enabled' => true,
                    'marketing_consent_terms_slug' => 'marketing-terms',
                    default => $default ?? true,
                };
            }
        );

        $repository = new MarketingConsentRepository;
        $this->service = new MarketingConsentService($repository, $pluginSettings);
        $this->listener = new MarketingConsentListener($this->service, $pluginSettings);
    }

    // ── getSubscribedHooks 구조 검증 ──

    /**
     * 리스너가 코어가 실제로 발화하는 훅 이름을 구독한다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_published_hook_names
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_expected_hooks(): void
    {
        $hooks = MarketingConsentListener::getSubscribedHooks();

        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('core.auth.register_validation_rules', $hooks);
        $this->assertArrayHasKey('core.user.create_validation_rules', $hooks);
        $this->assertArrayHasKey('core.user.update_validation_rules', $hooks);
        $this->assertArrayHasKey('core.user.update_profile_validation_rules', $hooks);
        $this->assertArrayHasKey('core.user.filter_update_data', $hooks);
        $this->assertArrayHasKey('core.user.after_create', $hooks);
        $this->assertArrayHasKey('core.user.after_update', $hooks);
        $this->assertArrayHasKey('core.user.before_delete', $hooks);
        $this->assertArrayHasKey('core.auth.after_register', $hooks);
        // 코어는 가입 완료를 after_register 로만 발화한다 — 옛 이름 구독은 한 번도 실행되지 않았다(#138 후속)
        $this->assertArrayNotHasKey('core.auth.register', $hooks);
        $this->assertArrayHasKey('core.user.filter_resource_data', $hooks);
    }

    /**
     * 필터 훅 구독에는 type=filter 가 명시된다.
     *
     * @scenario case=subscription_contract
     *
     * @effects filter_hooks_declare_filter_type
     *
     * @return void 반환값 없음
     */
    public function test_filter_hooks_have_type_filter(): void
    {
        $hooks = MarketingConsentListener::getSubscribedHooks();

        $filterHooks = [
            'core.auth.register_validation_rules',
            'core.user.create_validation_rules',
            'core.user.update_validation_rules',
            'core.user.update_profile_validation_rules',
            'core.user.filter_update_data',
            'core.user.filter_resource_data',
        ];

        foreach ($filterHooks as $hookName) {
            $this->assertEquals(
                'filter',
                $hooks[$hookName]['type'],
                "훅 '{$hookName}'에 type => 'filter'가 명시되어야 합니다."
            );
        }
    }

    /**
     * 액션 훅 구독에는 type 키가 없다.
     *
     * @scenario case=subscription_contract
     *
     * @effects action_hooks_have_no_filter_type
     *
     * @return void 반환값 없음
     */
    public function test_action_hooks_do_not_have_type_filter(): void
    {
        $hooks = MarketingConsentListener::getSubscribedHooks();

        $actionHooks = [
            'core.user.after_create',
            'core.user.after_update',
            'core.user.before_delete',
            'core.auth.after_register',
        ];

        foreach ($actionHooks as $hookName) {
            $this->assertArrayNotHasKey(
                'type',
                $hooks[$hookName],
                "액션 훅 '{$hookName}'에는 type 키가 없어야 합니다."
            );
        }
    }

    // ── addRegisterValidationRules ──

    /**
     * 가입 검증 규칙에 동의 항목(agree_*)을 더한다.
     *
     * @scenario case=validation_rules
     *
     * @effects agree_fields_added_to_rules
     *
     * @return void 반환값 없음
     */
    public function test_add_register_validation_rules_appends_agree_fields(): void
    {
        $existingRules = ['name' => 'required|string', 'email' => 'required|email'];

        $result = $this->listener->addRegisterValidationRules($existingRules);

        // agree_ 접두사 + 동의 키
        $this->assertArrayHasKey('agree_email_subscription', $result);
        $this->assertArrayHasKey('agree_marketing_consent', $result);
        $this->assertArrayHasKey('agree_third_party_consent', $result);
        $this->assertArrayHasKey('agree_info_disclosure', $result);

        // 기존 rules 유지
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);

        // 모두 nullable|boolean
        $this->assertEquals('nullable|boolean', $result['agree_email_subscription']);
    }

    // ── addValidationRules ──

    /**
     * 회원 생성·수정 검증 규칙에 마케팅 동의 항목을 더한다.
     *
     * @scenario case=validation_rules
     *
     * @effects agree_fields_added_to_rules
     *
     * @return void 반환값 없음
     */
    public function test_add_validation_rules_appends_marketing_fields(): void
    {
        $existingRules = ['name' => 'required|string'];

        $result = $this->listener->addValidationRules($existingRules);

        // 접두사 없는 키
        $this->assertArrayHasKey('email_subscription', $result);
        $this->assertArrayHasKey('marketing_consent', $result);
        $this->assertArrayHasKey('third_party_consent', $result);
        $this->assertArrayHasKey('info_disclosure', $result);
        $this->assertArrayHasKey('name', $result);

        $this->assertEquals('nullable|boolean', $result['email_subscription']);
    }

    // ── filterUpdateData (Filter 훅: DB 저장 없음) ──

    /**
     * 회원 수정 데이터에서 마케팅 필드를 빼되 이 단계에서는 저장하지 않는다.
     *
     * @scenario case=update_filter
     *
     * @effects marketing_fields_stripped_from_update_data
     *
     * @return void 반환값 없음
     */
    public function test_filter_update_data_removes_marketing_fields_without_db_save(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => 'Updated Name',
            'email_subscription' => true,
            'marketing_consent' => true,
        ];

        $result = $this->listener->filterUpdateData($data, $user);

        // 마케팅 필드가 제거됨
        $this->assertArrayNotHasKey('email_subscription', $result);
        $this->assertArrayNotHasKey('marketing_consent', $result);
        $this->assertArrayHasKey('name', $result);

        // DB에 저장되지 않아야 함
        $this->assertCount(0, MarketingConsent::where('user_id', $user->id)->get());
    }

    /**
     * 회원 수정 데이터에서 모든 동의 키를 뺀다.
     *
     * @scenario case=update_filter
     *
     * @effects marketing_fields_stripped_from_update_data
     *
     * @return void 반환값 없음
     */
    public function test_filter_update_data_removes_all_consent_keys(): void
    {
        $user = User::factory()->create();

        $data = [
            'name' => 'Test',
            'email_subscription' => true,
            'marketing_consent' => false,
            'third_party_consent' => true,
            'info_disclosure' => false,
        ];

        $result = $this->listener->filterUpdateData($data, $user);

        $this->assertArrayNotHasKey('email_subscription', $result);
        $this->assertArrayNotHasKey('marketing_consent', $result);
        $this->assertArrayNotHasKey('third_party_consent', $result);
        $this->assertArrayNotHasKey('info_disclosure', $result);
        $this->assertArrayHasKey('name', $result);
    }

    // ── afterCreate ($originalData 직접 사용) ──

    /**
     * 회원 생성 뒤 원본 데이터의 동의 값을 저장한다.
     *
     * @scenario case=after_create
     *
     * @effects consent_saved_from_hook_args
     *
     * @return void 반환값 없음
     */
    public function test_after_create_saves_consent_from_original_data(): void
    {
        $user = User::factory()->create();

        $originalData = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_subscription' => true,
            'third_party_consent' => true,
        ];

        $this->listener->afterCreate($user, $originalData);

        $consents = MarketingConsent::where('user_id', $user->id)->get()->keyBy('consent_key');
        $this->assertTrue($consents->get('email_subscription')?->is_consented ?? false);
        $this->assertTrue($consents->get('third_party_consent')?->is_consented ?? false);
    }

    /**
     * 회원 생성 데이터에 마케팅 필드가 없으면 아무것도 저장하지 않는다.
     *
     * @scenario case=after_create
     *
     * @effects consent_saved_from_hook_args
     *
     * @return void 반환값 없음
     */
    public function test_after_create_does_nothing_when_no_marketing_fields(): void
    {
        $user = User::factory()->create();

        $this->listener->afterCreate($user, ['name' => 'Test', 'email' => 'test@example.com']);

        $this->assertCount(0, MarketingConsent::where('user_id', $user->id)->get());
    }

    // ── afterUpdate ($originalData에서 마케팅 필드 추출) ──

    /**
     * 회원 수정 뒤 원본 데이터의 동의 값을 저장한다.
     *
     * @scenario case=after_update
     *
     * @effects consent_saved_from_hook_args
     *
     * @return void 반환값 없음
     */
    public function test_after_update_saves_consent_from_original_data(): void
    {
        // admin 라우트로 요청 시뮬레이션
        $request = Request::create('/api/admin/users/1', 'PUT');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route('PUT', 'api/admin/users/{id}', []);
            $route->name('api.admin.users.update');
            $route->bind($request);

            return $route;
        });
        $this->app->instance('request', $request);

        $user = User::factory()->create();

        $originalData = [
            'name' => 'Updated Name',
            'email_subscription' => true,
            'marketing_consent' => true,
        ];

        $this->listener->afterUpdate($user, $originalData);

        $consents = MarketingConsent::where('user_id', $user->id)->get()->keyBy('consent_key');
        $this->assertTrue($consents->get('email_subscription')?->is_consented ?? false);
        $this->assertTrue($consents->get('marketing_consent')?->is_consented ?? false);
        $this->assertEquals('admin', $consents->get('email_subscription')?->last_source);
    }

    /**
     * 회원 수정 데이터에 마케팅 필드가 없으면 아무것도 저장하지 않는다.
     *
     * @scenario case=after_update
     *
     * @effects consent_saved_from_hook_args
     *
     * @return void 반환값 없음
     */
    public function test_after_update_does_nothing_when_no_marketing_fields(): void
    {
        $user = User::factory()->create();

        $this->listener->afterUpdate($user, ['name' => 'Updated Name']);

        $this->assertCount(0, MarketingConsent::where('user_id', $user->id)->get());
    }

    // ── afterRegister ──

    /**
     * 가입 훅 인자의 문자열 동의 값(1·빈 값·0)을 동의 여부로 해석한다.
     *
     * @scenario case=after_register
     *
     * @effects consent_saved_from_hook_args
     *
     * @return void 반환값 없음
     */
    public function test_after_register_reads_string_agree_values_from_registration_data(): void
    {
        $user = User::factory()->create();

        $this->listener->afterRegister($user, [
            'registration_data' => [
                'agree_email_subscription' => '1',
                'agree_marketing_consent' => '1',
                'agree_third_party_consent' => '',
                'agree_info_disclosure' => '0',
            ],
        ]);

        $consents = MarketingConsent::where('user_id', $user->id)->get()->keyBy('consent_key');

        $this->assertTrue($consents->get('email_subscription')?->is_consented ?? false);
        $this->assertTrue($consents->get('marketing_consent')?->is_consented ?? false);
        // '' 값은 !empty() === false → 동의하지 않음 (레코드가 있어도 is_consented=false)
        $this->assertFalse($consents->get('third_party_consent')?->is_consented ?? false);
        // '0' 값은 false → 동의하지 않음
        $this->assertFalse($consents->get('info_disclosure')?->is_consented ?? false);
    }

    /**
     * 큐 워커(요청 없음)에서도 가입 데이터를 훅 인자로 받아 동의를 저장한다.
     *
     * 코어는 가입 폼의 검증된 데이터를 after_register 의 registration_data 로 넘긴다. 워커의 request() 는
     * 비어 있으므로 요청에서 읽으면 동의가 조용히 빠진다.
     *
     * @scenario case=after_register
     *
     * @effects consent_saved_from_hook_args, register_consent_saved_without_request
     *
     * @return void 반환값 없음
     */
    public function test_after_register_reads_agree_fields_from_registration_data_without_request(): void
    {
        $user = User::factory()->create();
        $this->app->instance('request', Request::create('/', 'GET'));

        $this->listener->afterRegister($user, [
            'registration_data' => [
                'email' => $user->email,
                'agree_email_subscription' => true,
                'agree_info_disclosure' => false,
            ],
        ]);

        $consents = MarketingConsent::where('user_id', $user->id)->get()->keyBy('consent_key');

        $this->assertTrue($consents->get('email_subscription')?->is_consented ?? false);
        $this->assertFalse($consents->get('info_disclosure')?->is_consented ?? false);
    }

    // ── beforeDelete (명시적 삭제) ──

    /**
     * 회원 삭제 전에 동의와 이력을 명시적으로 지운다.
     *
     * @scenario case=before_delete
     *
     * @effects consent_deleted_before_user_delete
     *
     * @return void 반환값 없음
     */
    public function test_before_delete_removes_consent_data(): void
    {
        $user = User::factory()->create();

        // email_subscription 동의 시 marketing_consent(master)도 자동 활성화 → 2개 레코드
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');

        $this->assertGreaterThan(0, MarketingConsent::where('user_id', $user->id)->count());

        $this->listener->beforeDelete($user);

        $this->assertCount(0, MarketingConsent::where('user_id', $user->id)->get());
        $this->assertCount(0, MarketingConsentHistory::where('user_id', $user->id)->get());
    }

    // ── filterResourceData ──

    /**
     * 회원 API 응답에 동의 필드를 병합한다.
     *
     * @scenario case=resource_filter
     *
     * @effects consent_merged_into_user_resource
     *
     * @return void 반환값 없음
     */
    public function test_filter_resource_data_merges_consent_fields(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsents($user->id, [
            'email_subscription' => true,
            'marketing_consent' => true,
        ], 'register');

        $data = ['id' => $user->id, 'name' => $user->name];

        $result = $this->listener->filterResourceData($data, $user);

        // 동의 상태
        $this->assertTrue($result['email_subscription']);
        $this->assertTrue($result['marketing_consent']);

        // ISO8601 타임스탬프 필드
        $this->assertNotNull($result['email_subscription_at']);
        // marketing_consent는 updateConsents로 직접 동의 시 consented_at이 생성됨

        // 마케팅 전체 동의 플래그
        $this->assertTrue($result['marketing_consent_enabled']);
        $this->assertNotNull($result['marketing_consent_terms_slug']);
        $this->assertTrue($result['marketing_consent_terms_slug_set']);

        // 채널별 플래그 (DEFAULT_CHANNELS: email_subscription, enabled=true, page_slug='email-terms')
        $this->assertTrue($result['email_subscription_enabled']);
        $this->assertEquals('email-terms', $result['email_subscription_terms_slug']);
        $this->assertTrue($result['email_subscription_terms_slug_set']);

        // channels 배열 (프론트엔드 iteration용)
        $this->assertArrayHasKey('channels', $result);
        $this->assertIsArray($result['channels']);
        $this->assertCount(1, $result['channels']);
        $this->assertEquals('email_subscription', $result['channels'][0]['key']);

        // consent_histories 배열
        $this->assertArrayHasKey('consent_histories', $result);
        $this->assertIsArray($result['consent_histories']);
    }

    /**
     * 동의 기록이 없으면 응답의 동의 필드는 false 다.
     *
     * @scenario case=resource_filter
     *
     * @effects consent_merged_into_user_resource
     *
     * @return void 반환값 없음
     */
    public function test_filter_resource_data_returns_false_when_no_consent(): void
    {
        $user = User::factory()->create();

        $data = ['id' => $user->id, 'name' => $user->name];

        $result = $this->listener->filterResourceData($data, $user);

        $this->assertFalse($result['email_subscription']);
        $this->assertFalse($result['marketing_consent']);
        $this->assertNull($result['email_subscription_at']);
        $this->assertEmpty($result['consent_histories']);

        // channels 배열은 항상 포함
        $this->assertArrayHasKey('channels', $result);
    }

    /**
     * 응답의 동의 이력에 채널 키가 포함된다.
     *
     * @scenario case=resource_filter
     *
     * @effects consent_merged_into_user_resource
     *
     * @return void 반환값 없음
     */
    public function test_filter_resource_data_includes_channel_key_in_history(): void
    {
        $user = User::factory()->create();

        // email_subscription 동의 시 marketing_consent(master)도 자동 활성화
        // → 이력은 내림차순이므로 나중에 생성된 marketing_consent가 [0]
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');

        $data = ['id' => $user->id];

        $result = $this->listener->filterResourceData($data, $user);

        $this->assertNotEmpty($result['consent_histories']);

        // 이력 구조 검증 (첫 번째 항목)
        $history = $result['consent_histories'][0];
        $this->assertArrayHasKey('channel_key', $history);
        $this->assertArrayHasKey('action', $history);
        $this->assertArrayHasKey('source', $history);
        $this->assertArrayHasKey('created_at', $history);
        $this->assertEquals('granted', $history['action']);
        $this->assertEquals('register', $history['source']);

        // 전체 이력에서 email_subscription granted 확인
        $channelKeys = array_column($result['consent_histories'], 'channel_key');
        $this->assertContains('email_subscription', $channelKeys);
    }
}
