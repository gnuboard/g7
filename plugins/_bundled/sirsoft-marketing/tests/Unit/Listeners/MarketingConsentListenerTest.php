<?php

namespace Plugins\Sirsoft\Marketing\Tests\Unit\Listeners;

use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Marketing\Fields\MarketingConsentFieldHandler;
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

    /**
     * 설정 값을 바꾼 리스너를 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  설정 키 => 값
     * @return MarketingConsentListener 리스너
     */
    private function listenerWith(array $overrides): MarketingConsentListener
    {
        $pluginSettings = $this->createMock(PluginSettingsService::class);
        $pluginSettings->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => array_key_exists($key, $overrides)
                ? $overrides[$key]
                : ($key === 'channels' ? json_encode(self::DEFAULT_CHANNELS) : ($default ?? true))
        );

        return new MarketingConsentListener(
            new MarketingConsentService(new MarketingConsentRepository, $pluginSettings),
            $pluginSettings,
        );
    }

    /**
     * 다른 출처가 가진 항목 정의를 만듭니다.
     *
     * @param  string  $key  항목 키
     * @return FieldDefinition 저장하지 않은 항목 정의
     */
    private function foreignDefinition(string $key): FieldDefinition
    {
        return new FieldDefinition([
            'field_set' => FieldSet::CORE_USER,
            'key' => $key,
            'source_type' => FieldDefinition::SOURCE_CUSTOM,
            'source_identifier' => 'custom',
            'type' => 'text',
            'storage' => FieldDefinition::STORAGE_VALUE,
            'name' => ['ko' => '다른 항목', 'en' => 'Other'],
            'enabled' => true,
        ]);
    }

    // ── getSubscribedHooks 구조 검증 ──

    /**
     * 리스너가 항목 목록 필터, 옛 가입 키 규칙, 삭제, 응답, 설정 저장 훅만 구독한다.
     *
     * 동의 값의 검증과 저장은 회원 항목 선언과 저장소 어댑터가 맡으므로, 회원 생성과 수정의 검증 규칙 필터와
     * 저장 액션은 구독하지 않는다 — 구독이 남으면 같은 값이 두 번 검증되고 두 번 저장된다.
     *
     * @scenario case=subscription_contract
     *
     * @effects subscribes_published_hook_names, value_hooks_not_subscribed
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_expected_hooks(): void
    {
        $hooks = MarketingConsentListener::getSubscribedHooks();

        $this->assertSame([
            'core.field.filter_definitions',
            'core.auth.register_validation_rules',
            'core.user.before_delete',
            'core.user.filter_resource_data',
            'core.plugin_settings.filter_save_data',
            'core.plugin_settings.after_save',
        ], array_keys($hooks));

        foreach ([
            'core.user.create_validation_rules',
            'core.user.update_validation_rules',
            'core.user.update_profile_validation_rules',
            'core.user.filter_update_data',
            'core.user.after_create',
            'core.user.after_update',
            'core.auth.after_register',
            'core.auth.register',
        ] as $removed) {
            $this->assertArrayNotHasKey($removed, $hooks, "'{$removed}' 구독이 남아 있으면 동의 값이 이중으로 처리됩니다.");
        }
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
            'core.field.filter_definitions',
            'core.auth.register_validation_rules',
            'core.user.filter_resource_data',
            'core.plugin_settings.filter_save_data',
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
     * 액션 훅 구독에는 type 키가 없고, 같은 요청 안에서 실행된다(sync).
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

        foreach (['core.user.before_delete', 'core.plugin_settings.after_save'] as $hookName) {
            $this->assertArrayNotHasKey(
                'type',
                $hooks[$hookName],
                "액션 훅 '{$hookName}'에는 type 키가 없어야 합니다."
            );
            $this->assertTrue($hooks[$hookName]['sync'] ?? false, "액션 훅 '{$hookName}'은 sync 여야 합니다.");
        }
    }

    // ── filterFieldDefinitions (회원 항목 목록을 설정에 맞춘다) ──

    /**
     * 지금 받는 동의 항목인데 행이 없으면 요청 시점에 항목을 더한다.
     *
     * @scenario case=field_definitions
     *
     * @effects missing_consent_fields_added_per_request, consent_field_contract_shape
     *
     * @return void 반환값 없음
     */
    public function test_filter_field_definitions_adds_missing_consent_fields(): void
    {
        $result = $this->listener->filterFieldDefinitions(new Collection, FieldSet::CORE_USER);

        $this->assertSame(
            ['marketing_consent', 'email_subscription', 'third_party_consent', 'info_disclosure'],
            $result->map(fn (FieldDefinition $definition) => $definition->key)->all(),
        );

        foreach ($result as $definition) {
            $this->assertSame('boolean', $definition->type);
            $this->assertSame(FieldDefinition::STORAGE_HANDLER, $definition->storage);
            $this->assertSame(MarketingConsentFieldHandler::class, $definition->storage_target);
            $this->assertSame(FieldDefinition::SOURCE_PLUGIN, $definition->source_type);
            $this->assertSame('sirsoft-marketing', $definition->source_identifier);
            $this->assertSame('agreements', $definition->ui['group']);
            $this->assertSame(['agree_'.$definition->key], $definition->legacy_keys);
            $this->assertFalse($definition->required);
            $this->assertTrue($definition->show_on_signup);
            $this->assertSame(FieldDefinition::SCOPE_SELF, $definition->edit_scope);
        }

        $byKey = $result->keyBy('key');
        $this->assertSame('master', $byKey['marketing_consent']->ui['role']);
        $this->assertSame('channel', $byKey['email_subscription']->ui['role']);
        $this->assertSame('marketing_consent', $byKey['email_subscription']->ui['parent']);
        $this->assertSame('legal', $byKey['third_party_consent']->ui['role']);
        $this->assertSame('광고성 이메일 수신', $byKey['email_subscription']->name['ko']);
    }

    /**
     * 약관 페이지를 정한 항목에만 약관 「보기」 링크가 붙는다.
     *
     * @scenario case=field_definitions
     *
     * @effects terms_link_only_when_page_set
     *
     * @return void 반환값 없음
     */
    public function test_filter_field_definitions_attaches_terms_link_only_when_page_is_set(): void
    {
        $listener = $this->listenerWith([
            'marketing_consent_terms_slug' => 'marketing-terms',
            'third_party_consent_terms_slug' => '',
            'info_disclosure_terms_slug' => '',
        ]);

        $byKey = $listener->filterFieldDefinitions(new Collection, FieldSet::CORE_USER)->keyBy('key');

        $link = $byKey['marketing_consent']->ui['link'];
        $this->assertSame('/page/marketing-terms', $link['url']);
        $this->assertSame('/api/modules/sirsoft-page/pages/marketing-terms', $link['content_api']);
        $this->assertSame('sirsoft-page', $link['requires_module']);
        $this->assertNotSame('', $link['label']);
        $this->assertNotSame('', $link['title']);

        $this->assertSame('/page/email-terms', $byKey['email_subscription']->ui['link']['url']);
        $this->assertArrayNotHasKey('link', $byKey['third_party_consent']->ui);
        $this->assertArrayNotHasKey('link', $byKey['info_disclosure']->ui);
    }

    /**
     * 설정에서 끈 동의는 항목 목록에서 빠진다 (전체 동의를 끄면 채널도 함께).
     *
     * @scenario case=field_definitions
     *
     * @effects disabled_consent_fields_dropped
     *
     * @return void 반환값 없음
     */
    public function test_filter_field_definitions_drops_fields_turned_off_in_settings(): void
    {
        $all = $this->listener->filterFieldDefinitions(new Collection, FieldSet::CORE_USER);

        $withoutLegal = $this->listenerWith(['third_party_consent_enabled' => false])
            ->filterFieldDefinitions($all, FieldSet::CORE_USER);
        $this->assertSame(
            ['marketing_consent', 'email_subscription', 'info_disclosure'],
            $withoutLegal->map(fn (FieldDefinition $definition) => $definition->key)->all(),
        );

        $withoutMarketing = $this->listenerWith(['marketing_consent_enabled' => false])
            ->filterFieldDefinitions($all, FieldSet::CORE_USER);
        $this->assertSame(
            ['third_party_consent', 'info_disclosure'],
            $withoutMarketing->map(fn (FieldDefinition $definition) => $definition->key)->all(),
        );

        $channelOff = $this->listenerWith(['channels' => json_encode([[...self::DEFAULT_CHANNELS[0], 'enabled' => false]])])
            ->filterFieldDefinitions($all, FieldSet::CORE_USER);
        $this->assertNotContains('email_subscription', $channelOff->map(fn (FieldDefinition $definition) => $definition->key)->all());
    }

    /**
     * 다른 집합의 목록과 다른 출처가 가진 같은 키의 항목은 건드리지 않는다.
     *
     * @scenario case=field_definitions
     *
     * @effects foreign_fields_untouched
     *
     * @return void 반환값 없음
     */
    public function test_filter_field_definitions_leaves_other_sets_and_foreign_keys_alone(): void
    {
        $other = new Collection([$this->foreignDefinition('nickname')]);
        $this->assertSame($other, $this->listener->filterFieldDefinitions($other, 'sirsoft-board.guest_author'));

        // 운영자가 만든 항목이 채널과 같은 키를 이미 쓰고 있으면 그 항목을 유지하고 채널 항목은 더하지 않는다.
        $result = $this->listener->filterFieldDefinitions(
            new Collection([$this->foreignDefinition('email_subscription')]),
            FieldSet::CORE_USER,
        );

        $sameKey = $result->filter(fn (FieldDefinition $definition) => $definition->key === 'email_subscription')->values();
        $this->assertCount(1, $sameKey);
        $this->assertSame(FieldDefinition::SOURCE_CUSTOM, $sameKey[0]->source_type);
        $this->assertArrayNotHasKey('link', $sameKey[0]->ui ?? []);
    }

    // ── addLegacyRegisterRules (옛 가입 요청 키) ──

    /**
     * 가입 규칙에 정식 키가 있는 동의 항목만 옛 키(agree_ 접두) 규칙을 받는다.
     *
     * 정식 키의 규칙은 회원 항목 선언이 만든다 — 리스너는 정식 키의 규칙을 더하지 않는다(이중 검증 없음).
     *
     * @scenario case=legacy_register_keys
     *
     * @effects legacy_agree_rules_follow_declared_fields
     *
     * @return void 반환값 없음
     */
    public function test_add_legacy_register_rules_follows_declared_fields(): void
    {
        $rules = [
            'email' => ['required', 'email'],
            'marketing_consent' => ['nullable', 'boolean'],
            'email_subscription' => ['nullable', 'boolean'],
        ];

        $result = $this->listener->addLegacyRegisterRules($rules);

        $this->assertSame(['nullable', 'boolean'], $result['agree_marketing_consent']);
        $this->assertSame(['nullable', 'boolean'], $result['agree_email_subscription']);
        // 가입 화면에서 받지 않는 항목(정식 키 규칙 없음)의 옛 키는 받지 않는다
        $this->assertArrayNotHasKey('agree_third_party_consent', $result);
        $this->assertArrayNotHasKey('agree_info_disclosure', $result);
        // 정식 키의 규칙은 그대로다
        $this->assertSame($rules['marketing_consent'], $result['marketing_consent']);
        $this->assertArrayNotHasKey('third_party_consent', $result);
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

        // 화면이 그릴지와 약관 링크는 항목 계약이 정한다 — 응답에는 싣지 않는다
        $this->assertArrayNotHasKey('marketing_consent_enabled', $result);
        $this->assertArrayNotHasKey('marketing_consent_terms_slug', $result);
        $this->assertArrayNotHasKey('email_subscription_enabled', $result);
        $this->assertArrayNotHasKey('email_subscription_terms_slug', $result);

        // channels 배열 (동의 이력 표가 채널 키를 이름으로 바꿀 때 읽는다)
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
     * @effects consent_merged_into_user_resource, consent_history_time_uses_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_filter_resource_data_includes_channel_key_in_history(): void
    {
        $user = User::factory()->create();

        // email_subscription 동의 시 marketing_consent(master)도 자동 활성화
        // → 이력은 내림차순이므로 나중에 생성된 marketing_consent가 [0]
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:52:32', 'UTC'));
        $this->service->updateConsent($user->id, 'email_subscription', true, 'register');
        Carbon::setTestNow();

        $data = ['id' => $user->id];

        // 동의 이력 시각은 보는 사람(관리자) 시간대 — 같은 화면의 가입일과 같은 기준(공개 #160)
        app()->instance('user_timezone', 'Asia/Seoul');
        $result = $this->listener->filterResourceData($data, $user);

        $this->assertNotEmpty($result['consent_histories']);

        // 이력 구조 검증 (첫 번째 항목)
        $history = $result['consent_histories'][0];
        $this->assertArrayHasKey('channel_key', $history);
        $this->assertArrayHasKey('action', $history);
        $this->assertArrayHasKey('source', $history);
        $this->assertSame('2026-11-04 21:52:32', $history['created_at']);
        $this->assertEquals('granted', $history['action']);
        $this->assertEquals('register', $history['source']);

        // 전체 이력에서 email_subscription granted 확인
        $channelKeys = array_column($result['consent_histories'], 'channel_key');
        $this->assertContains('email_subscription', $channelKeys);
    }
}
