<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackScope;
use App\Extension\ModuleManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\Module as ModuleRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Fields\UserPreferenceFieldHandler;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultShippingCountryOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserPreferenceFieldDefinitionListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserShippingCountryInfoListener;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ActivatesBundledLanguagePack;

/**
 * 회원의 결제 통화와 배송국가를 회원 항목 집합(core.user)의 항목으로 선언하고 핸들러로 잇는 계약을 검증한다.
 *
 * 가입, 관리자 수정, 내 정보 수정은 코어가 선언대로 검증하고 같은 요청 안에서 핸들러로 저장한다. 관리자 추가는 받지 않는다.
 * 이커머스 리스너는 검증과 저장 훅을 더는 구독하지 않아 같은 칸을 두 번 검증하거나 두 번 저장하지 않는다.
 */
#[Group('ecommerce')]
#[Group('fields')]
class UserPreferenceFieldSetTest extends ModuleTestCase
{
    use ActivatesBundledLanguagePack;

    private const TABLE = 'ecommerce_user_profiles';

    private const PASSWORD = 'Str0ng!Passw0rd#490';

    private const CURRENCY = UserPreferenceFieldHandler::CURRENCY_FIELD;

    private const COUNTRY = UserPreferenceFieldHandler::SHIPPING_COUNTRY_FIELD;

    /**
     * 해외배송을 켜고 배송 가능 국가(KR, US 활성, JP 비활성)를 준비한 뒤 항목 선언을 동기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.default_country', 'KR');
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국', 'en' => 'United States'], 'is_active' => true],
            ['code' => 'JP', 'name' => ['ko' => '일본', 'en' => 'Japan'], 'is_active' => false],
        ]);

        $this->createDefaultRoles();
        $this->syncMemberFieldDeclarations();
    }

    /**
     * 모듈 상태와 항목 정의 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        parent::tearDown();
    }

    /**
     * 유저 프로필 Repository 를 반환합니다.
     *
     * @return EcommerceUserProfileRepositoryInterface 유저 프로필 Repository
     */
    private function profiles(): EcommerceUserProfileRepositoryInterface
    {
        return app(EcommerceUserProfileRepositoryInterface::class);
    }

    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $extra  덧붙일 필드
     * @return array<string, mixed> 요청 본문
     */
    private function signupBody(array $extra = []): array
    {
        return array_merge([
            'name' => '가입 회원',
            'email' => 'issue490+shop'.uniqid().'@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $extra);
    }

    /**
     * 관리자 회원 수정 요청 본문을 만듭니다.
     *
     * @param  User  $target  수정 대상 회원
     * @param  array<string, mixed>  $extra  덧붙일 필드
     * @return array<string, mixed> 요청 본문
     */
    private function adminBody(User $target, array $extra = []): array
    {
        return array_merge([
            'name' => '바뀐 이름',
            'email' => $target->email,
            'role_ids' => [Role::where('identifier', 'user')->value('id')],
        ], $extra);
    }

    /**
     * 콜백이 도는 동안 회원 쇼핑 프로필 테이블에 나간 쓰기 쿼리 수를 셉니다.
     *
     * @param  callable  $callback  요청을 보내는 콜백
     * @return int insert 와 update 쿼리 수
     */
    private function countProfileWrites(callable $callback): int
    {
        $writes = 0;
        $counting = true;
        DB::listen(function ($query) use (&$writes, &$counting) {
            if ($counting && str_contains($query->sql, self::TABLE) && preg_match('/^\s*(insert|update)\b/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $callback();
        $counting = false;

        return $writes;
    }

    /**
     * 항목 정의의 속성을 운영자가 바꾼 것처럼 고칩니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return void 반환값 없음
     */
    private function overrideField(string $key, array $attributes): void
    {
        FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', $key)->update($attributes);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 활성 ja 언어팩이 있으면 모듈이 선언한 모든 항목(회원 집합의 통화와 배송 국가, 주문서 집합의 항목) 이름에 ja 가 실린다.
     *
     * 선언은 모듈 자체 언어 파일만 읽어 일본어 화면에서 이 항목들만 한국어로 보였다(실측 C-O4). 이제
     * `ExtensionLangTexts` 로 모듈을 대상으로 하는 활성 팩의 항목 이름 파일(번들 팩은 `lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja/fields.php`)도 읽는다.
     *
     * @scenario case=language_pack_names
     *
     * @effects declared_field_names_include_active_language_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_declared_field_names_include_active_ja_language_pack(): void
    {
        $this->activateBundledLanguagePack('g7-module-sirsoft-ecommerce-ja', LanguagePackScope::Module, 'sirsoft-ecommerce');

        $declared = collect((new Module)->getFields());
        $this->assertNotEmpty($declared);

        foreach ($declared as $declaration) {
            $this->assertNotSame('', (string) ($declaration['name']['ja'] ?? ''), "{$declaration['field_set']}.{$declaration['key']} 이름에 ja 가 없다");
            $this->assertNotSame($declaration['name']['ko'], $declaration['name']['ja']);
        }
    }

    /**
     * 두 항목이 회원 항목 집합에 선택 유형, 핸들러 저장, 옛 키, 관리자 추가를 뺀 표면으로 선언되는지 확인한다.
     *
     * @scenario case=declaration
     *
     * @effects preference_fields_declared_in_core_user_set
     *
     * @return void 반환값 없음
     */
    public function test_declares_currency_and_shipping_country_fields(): void
    {
        // 모듈은 주문서 항목 집합에도 항목을 선언한다. 회원 항목 집합의 선언만 본다.
        $declared = collect((new Module)->getFields())->where('field_set', FieldSet::CORE_USER)->keyBy('key');

        $this->assertSame([self::CURRENCY, self::COUNTRY], $declared->keys()->all());

        foreach ([self::CURRENCY => 'preferred_currency', self::COUNTRY => 'preferred_shipping_country'] as $key => $legacy) {
            $row = FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('key', $key)->firstOrFail();

            $this->assertSame('module', $row->source_type);
            $this->assertSame('sirsoft-ecommerce', $row->source_identifier);
            $this->assertSame('choice', $row->type);
            $this->assertSame('handler', $row->storage);
            $this->assertSame(UserPreferenceFieldHandler::class, $row->storage_target);
            $this->assertSame('select', $row->constraint('display'));
            $this->assertSame([$legacy], $row->legacy_keys);
            $this->assertSame('shop', $row->ui['group']);
            $this->assertSame(['signup', 'self_update', 'admin_update', 'required_info'], $row->ui['surfaces']);
            $this->assertTrue($row->enabled);
            $this->assertFalse($row->required);
            $this->assertTrue($row->show_on_signup);
            $this->assertSame('self', $row->edit_scope);
            $this->assertNotEmpty($row->name['ko']);
            $this->assertNotEmpty($row->name['en']);
            $this->assertNotEmpty($row->description['ko']);
        }
    }

    /**
     * 이커머스 리스너가 회원 검증 규칙 훅과 저장 훅을 더는 구독하지 않는지 확인한다 (이중 검증, 이중 저장 방지).
     *
     * @scenario case=subscription_contract
     *
     * @effects listeners_do_not_subscribe_validation_or_save_hooks
     *
     * @return void 반환값 없음
     */
    public function test_listeners_no_longer_subscribe_validation_and_save_hooks(): void
    {
        $this->assertFalse(class_exists('Modules\\Sirsoft\\Ecommerce\\Listeners\\UserPreferenceCoreSaveListener'));

        $subscribed = [];
        foreach ((new Module)->getHookListeners() as $listener) {
            foreach (array_keys($listener::getSubscribedHooks()) as $hook) {
                $subscribed[$hook][] = $listener;
            }
        }

        foreach ([
            'core.auth.register_validation_rules',
            'core.user.create_validation_rules',
            'core.user.update_validation_rules',
            'core.user.update_profile_validation_rules',
            'core.user.filter_create_data',
            'core.user.filter_update_data',
            'core.user.after_update',
        ] as $hook) {
            $this->assertArrayNotHasKey($hook, $subscribed, "{$hook} 을 구독하는 리스너가 남아 있다");
        }

        $this->assertSame(
            ['core.auth.after_register', 'core.user.after_create'],
            array_keys(AssignDefaultCurrencyOnRegisterListener::getSubscribedHooks()),
        );
        $this->assertSame(
            ['core.auth.after_register', 'core.user.after_create'],
            array_keys(AssignDefaultShippingCountryOnRegisterListener::getSubscribedHooks()),
        );
        $this->assertSame(['core.user.filter_resource_data'], array_keys(UserCurrencyInfoListener::getSubscribedHooks()));
        $this->assertSame(['core.user.filter_resource_data'], array_keys(UserShippingCountryInfoListener::getSubscribedHooks()));
        $this->assertSame(['core.field.filter_definitions'], array_keys(UserPreferenceFieldDefinitionListener::getSubscribedHooks()));
    }

    /**
     * 가입 화면 항목 계약에 두 항목이 선택지와 함께 나오는지 확인한다 (통화는 고를 수 있는 통화, 국가는 활성 국가).
     *
     * @scenario case=signup_schema
     *
     * @effects signup_schema_lists_preference_fields_with_site_options
     *
     * @return void 반환값 없음
     */
    public function test_signup_schema_lists_fields_with_selectable_options(): void
    {
        $fields = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->keyBy('key');

        $this->assertTrue($fields->has(self::CURRENCY));
        $this->assertTrue($fields->has(self::COUNTRY));

        $currency = $fields[self::CURRENCY];
        $this->assertSame(self::CURRENCY, $currency['name']);
        $this->assertSame('choice', $currency['type']);
        $this->assertSame('module', $currency['owner_type']);
        $this->assertSame('sirsoft-ecommerce', $currency['owner']);
        $this->assertSame('select', $currency['constraints']['display']);
        $this->assertSame('shop', $currency['ui']['group']);
        $this->assertFalse($currency['required']);
        $currencyValues = array_column($currency['options'], 'value');
        $this->assertContains('KRW', $currencyValues);
        $this->assertContains('USD', $currencyValues);
        $this->assertSame('KRW', $currency['default']);

        $country = $fields[self::COUNTRY];
        $this->assertSame(['KR', 'US'], array_column($country['options'], 'value'));
        $this->assertSame('KR', $country['default']);
    }

    /**
     * 고를 통화가 하나뿐이면 가입 화면에서 결제 통화를, 해외배송을 끄면 모든 화면에서 배송국가를 빼는지 확인한다.
     *
     * @scenario case=visibility
     *
     * @effects currency_hidden_on_signup_when_single_option, shipping_country_hidden_when_international_shipping_off
     *
     * @return void 반환값 없음
     */
    public function test_fields_follow_site_settings_visibility(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $currencies = $settings->getSettings('language_currency')['currencies'] ?? [];
        $onlyDefault = array_values(array_filter($currencies, fn ($currency) => $currency['is_default'] ?? false));
        $settings->setSetting('language_currency.currencies', $onlyDefault);
        $settings->setSetting('shipping.international_shipping_enabled', false);

        $signupKeys = collect($this->getJson('/api/auth/register/fields')->assertOk()->json('data.fields'))->pluck('key')->all();
        $this->assertNotContains(self::CURRENCY, $signupKeys);
        $this->assertNotContains(self::COUNTRY, $signupKeys);

        // 내 정보 수정에서는 통화 칸이 남고(선택지 하나), 배송국가는 빠진다
        $user = $this->createUser();
        $context = FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $user, $user);
        $selfKeys = app(FieldRegistry::class)->forSurface(FieldSet::CORE_USER, $context)->pluck('key')->all();
        $this->assertContains(self::CURRENCY, $selfKeys);
        $this->assertNotContains(self::COUNTRY, $selfKeys);

        // 해외배송을 끈 사이트에서 배송국가를 실어 보내도 저장하지 않는다
        Sanctum::actingAs($user);
        $this->putJson('/api/me', ['name' => '내 이름', 'email' => $user->email, self::COUNTRY => 'US'])->assertOk();
        $this->assertNull($this->profiles()->getPreferredShippingCountry($user->id));

        // 운영자 항목 표(표면 없는 조회)에는 남는다
        $this->assertContains(self::COUNTRY, app(FieldRegistry::class)->forSet(FieldSet::CORE_USER)->pluck('key')->all());
    }

    /**
     * 가입 요청의 정식 키와 옛 키가 모두 저장되고, 고를 수 없는 값은 정식 키의 오류로 거절되는지 확인한다.
     *
     * @scenario case=signup
     *
     * @effects signup_saves_preferences_with_canonical_keys, signup_accepts_legacy_keys, signup_rejects_unselectable_value_on_canonical_key
     *
     * @return void 반환값 없음
     */
    public function test_signup_saves_values_with_canonical_and_legacy_keys(): void
    {
        $canonical = $this->signupBody([self::CURRENCY => 'USD', self::COUNTRY => 'US']);
        $writes = $this->countProfileWrites(fn () => $this->postJson('/api/auth/register', $canonical)->assertStatus(201));
        $userId = (int) User::where('email', $canonical['email'])->value('id');
        $this->assertSame('USD', $this->profiles()->getPreferredCurrency($userId));
        $this->assertSame('US', $this->profiles()->getPreferredShippingCountry($userId));
        // 핸들러가 칸마다 한 번 쓰고, 기본값 지정 리스너는 이미 정해진 값을 덮어쓰지 않는다
        $this->assertSame(2, $writes);

        $this->app['auth']->forgetGuards();
        $legacy = $this->signupBody(['preferred_currency' => 'USD', 'preferred_shipping_country' => 'US']);
        $this->postJson('/api/auth/register', $legacy)->assertStatus(201);
        $legacyId = (int) User::where('email', $legacy['email'])->value('id');
        $this->assertSame('USD', $this->profiles()->getPreferredCurrency($legacyId));
        $this->assertSame('US', $this->profiles()->getPreferredShippingCountry($legacyId));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/register', $this->signupBody(['preferred_currency' => 'GBP', 'preferred_shipping_country' => 'JP']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([self::CURRENCY, self::COUNTRY]);
    }

    /**
     * 가입에서 두 값을 내지 않으면 기본값 지정 리스너가 정하고, 운영자가 가입 화면 표시를 끄면 낸 값도 받지 않는지 확인한다.
     *
     * @scenario case=signup_default
     *
     * @effects signup_without_values_assigns_defaults, signup_ignores_values_when_hidden_by_operator
     *
     * @return void 반환값 없음
     */
    public function test_signup_falls_back_to_default_assignment(): void
    {
        $plain = $this->signupBody();
        $this->postJson('/api/auth/register', $plain)->assertStatus(201);
        $plainId = (int) User::where('email', $plain['email'])->value('id');
        $this->assertNotNull($this->profiles()->getPreferredCurrency($plainId));
        $this->assertNotNull($this->profiles()->getPreferredShippingCountry($plainId));

        $this->overrideField(self::COUNTRY, ['show_on_signup' => false]);
        $this->app['auth']->forgetGuards();

        $hidden = $this->signupBody([self::COUNTRY => 'US', 'preferred_shipping_country' => 'US']);
        $this->postJson('/api/auth/register', $hidden)->assertStatus(201);
        $hiddenId = (int) User::where('email', $hidden['email'])->value('id');
        // 받지 않은 값 대신 기본 국가(접속 위치를 알 수 없는 테스트 환경에서는 KR)가 정해진다
        $this->assertSame('KR', $this->profiles()->getPreferredShippingCountry($hiddenId));
    }

    /**
     * 관리자 회원 추가는 두 값을 받지 않고(항목 계약에도 없음) 기본값 지정 리스너가 정하는지 확인한다.
     *
     * @scenario case=admin_create
     *
     * @effects admin_create_does_not_accept_preferences, admin_create_assigns_defaults
     *
     * @return void 반환값 없음
     */
    public function test_admin_create_does_not_accept_values(): void
    {
        $admin = $this->createAdminUser(['core.users.create', 'core.users.read', 'core.users.update']);
        Sanctum::actingAs($admin);

        $createKeys = collect($this->getJson('/api/admin/users/form-fields')->assertOk()->json('data.fields'))->pluck('key')->all();
        $this->assertNotContains(self::CURRENCY, $createKeys);
        $this->assertNotContains(self::COUNTRY, $createKeys);

        $rules = app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, FieldContext::for(FieldContext::SURFACE_ADMIN_CREATE, null, $admin));
        $this->assertArrayNotHasKey(self::CURRENCY, $rules);
        $this->assertArrayNotHasKey(self::COUNTRY, $rules);

        $email = 'issue490+shopadmin'.uniqid().'@example.com';
        $this->postJson('/api/admin/users', [
            'name' => '관리자가 만든 회원',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'language' => 'ko',
            'role_ids' => [Role::where('identifier', 'user')->value('id')],
            self::CURRENCY => 'USD',
            self::COUNTRY => 'US',
        ])->assertSuccessful();

        $created = User::where('email', $email)->firstOrFail();
        $this->assertArrayNotHasKey(self::CURRENCY, $created->getAttributes());
        // 보낸 값(USD, US)이 아니라 기본값 지정 리스너가 정한 값이다
        $this->assertSame('KRW', $this->profiles()->getPreferredCurrency($created->id));
        $this->assertSame('KR', $this->profiles()->getPreferredShippingCountry($created->id));
    }

    /**
     * 관리자 수정과 내 정보 수정이 바뀐 칸마다 한 번만 쓰고, 회원 응답의 최상위 키로 값을 돌려주는지 확인한다.
     *
     * @scenario case=update_once
     *
     * @effects single_write_per_changed_preference, preferences_in_user_response, unchanged_preference_not_rewritten
     *
     * @return void 반환값 없음
     */
    public function test_updates_write_once_and_return_values_in_response(): void
    {
        $admin = $this->createAdminUser([
            'core.users.update', 'core.users.read',
            'sirsoft-ecommerce.user-currency.manage', 'sirsoft-ecommerce.user-shipping-country.manage',
        ]);
        $target = $this->createUser();
        Sanctum::actingAs($admin);

        $writes = $this->countProfileWrites(function () use ($target) {
            $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
                self::CURRENCY => 'USD',
                self::COUNTRY => 'US',
            ]))->assertOk();
        });
        $this->assertSame(2, $writes);

        $this->getJson("/api/admin/users/{$target->uuid}")
            ->assertOk()
            ->assertJsonPath('data.'.self::CURRENCY, 'USD')
            ->assertJsonPath('data.'.self::COUNTRY, 'US')
            ->assertJsonPath('data.ecommerce_preferred_shipping_country_name', 'United States');

        // 같은 값을 다시 보내면 쓰지 않는다
        $again = $this->countProfileWrites(function () use ($target) {
            $this->putJson("/api/admin/users/{$target->uuid}", $this->adminBody($target, [
                self::CURRENCY => 'USD',
                self::COUNTRY => 'US',
            ]))->assertOk();
        });
        $this->assertSame(0, $again);

        // 내 정보 수정
        $this->app['auth']->forgetGuards();
        $self = $this->createUser();
        Sanctum::actingAs($self);

        $selfWrites = $this->countProfileWrites(function () use ($self) {
            $this->putJson('/api/me', ['name' => '내 이름', 'email' => $self->email, self::CURRENCY => 'USD'])->assertOk();
        });
        $this->assertSame(1, $selfWrites);
        $this->assertNull($this->profiles()->getPreferredShippingCountry($self->id));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.'.self::CURRENCY, 'USD');
        $this->getJson('/api/auth/user')->assertOk()->assertJsonPath('data.'.self::CURRENCY, 'USD');
    }

    /**
     * 관리자 수정 화면의 항목 계약이 두 항목을 모듈 소유로 싣고, 이커머스 권한이 없는 관리자에게는 읽기 전용으로 내는지 확인한다.
     *
     * @scenario case=admin_form_schema
     *
     * @effects preference_fields_in_admin_form_schema, preference_fields_readonly_without_permission, stored_unselectable_value_kept_in_options
     *
     * @return void 반환값 없음
     */
    public function test_admin_form_schema_lists_fields_and_locks_without_permission(): void
    {
        $target = $this->createUser();
        $this->profiles()->setPreferredCurrency($target->id, 'USD');
        $this->profiles()->setPreferredShippingCountry($target->id, 'JP');

        Sanctum::actingAs($this->createAdminUser([
            'core.users.read', 'core.users.update',
            'sirsoft-ecommerce.user-currency.manage', 'sirsoft-ecommerce.user-shipping-country.manage',
        ]));
        $fields = collect($this->getJson("/api/admin/users/{$target->uuid}/form-fields")->assertOk()->json('data.fields'))->keyBy('key');

        foreach ([self::CURRENCY, self::COUNTRY] as $key) {
            $this->assertTrue($fields->has($key), "{$key} 항목이 계약에 없다");
            $this->assertSame($key, $fields[$key]['name']);
            $this->assertSame('choice', $fields[$key]['type']);
            $this->assertSame('module', $fields[$key]['owner_type']);
            $this->assertSame('sirsoft-ecommerce', $fields[$key]['owner']);
            $this->assertFalse($fields[$key]['readonly']);
        }
        $this->assertSame('USD ($)', $fields[self::CURRENCY]['display_value']);
        // 저장된 값이 지금은 고를 수 없는 나라(비활성)여도 선택지에 남아 다른 칸만 고친 저장이 막히지 않는다
        $this->assertSame(['KR', 'US', 'JP'], array_column($fields[self::COUNTRY]['options'], 'value'));
        $this->assertSame('Japan', $fields[self::COUNTRY]['display_value']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->createAdminUser(['core.users.read', 'core.users.update']));
        $locked = collect($this->getJson("/api/admin/users/{$target->uuid}/form-fields")->assertOk()->json('data.fields'))->keyBy('key');

        foreach ([self::CURRENCY, self::COUNTRY] as $key) {
            $this->assertTrue($locked[$key]['readonly']);
            $this->assertSame('permission', $locked[$key]['locked_reason']);
        }
    }

    /**
     * 저장된 배송국가가 지금 배송할 수 없는 국가면 본인 항목 계약이 「적용되지 않는다」는 문장을 요청 언어로 완성해 주는지 확인한다.
     *
     * 수정 화면은 칸 아래 안내(`help`)로, 보기 화면은 값 아래 알림(`ui.notice`)으로 같은 문장을 그린다.
     * 문장에는 저장 국가와 지금 적용 중인 국가(사이트 기본 배송국가)의 이름이 들어가고, 영어에서도 국가 이름 앞뒤가 띄어진다.
     * 목록에서 아예 빠진 국가는 이름을 알 수 없어 코드로 적는다. 저장 국가가 배송 가능하면 문장이 없다.
     *
     * @scenario case=saved_country_not_applied
     *
     * @effects saved_unshippable_country_notice_in_self_schema, saved_country_notice_absent_when_shippable
     *
     * @return void 반환값 없음
     */
    public function test_self_schema_tells_saved_country_is_not_applied(): void
    {
        $member = $this->createUser();
        $this->profiles()->setPreferredShippingCountry($member->id, 'JP');
        Sanctum::actingAs($member);

        $ko = collect($this->withHeaders(['Accept-Language' => 'ko'])->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key')[self::COUNTRY];
        $sentence = '저장된 배송국가 일본: 지금 배송할 수 없는 국가라 적용되지 않습니다. 지금 적용 중인 배송국가: 대한민국';
        $this->assertSame($sentence, $ko['help']);
        $this->assertSame($sentence, $ko['ui']['notice'] ?? null);

        $en = collect($this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key')[self::COUNTRY];
        $this->assertSame(
            'Your saved shipping country Japan is not available for shipping now, so it is not applied. Shipping country in use: South Korea',
            $en['help'],
        );
        $this->assertSame($en['help'], $en['ui']['notice'] ?? null);

        // 목록에서 빠진 국가 — 이름을 알 수 없어 코드로 적는다
        $this->profiles()->setPreferredShippingCountry($member->id, 'FR');
        $missing = collect($this->withHeaders(['Accept-Language' => 'ko'])->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key')[self::COUNTRY];
        $this->assertStringContainsString('FR', (string) $missing['help']);
        $this->assertStringContainsString('대한민국', (string) $missing['help']);

        // 배송 가능한 국가면 문장이 없다
        $this->profiles()->setPreferredShippingCountry($member->id, 'US');
        $active = collect($this->getJson('/api/me/fields')->assertOk()->json('data.fields'))->keyBy('key')[self::COUNTRY];
        $this->assertArrayNotHasKey('notice', (array) ($active['ui'] ?? []));
        $this->assertStringNotContainsString('적용되지 않습니다', (string) ($active['help'] ?? ''));
    }

    /**
     * 모듈을 끄면 두 항목이 규칙, 활성 항목, 저장에서 빠지고 정의 행은 남는지 확인한다.
     *
     * @scenario case=module_inactive
     *
     * @effects preference_fields_removed_when_module_inactive
     *
     * @return void 반환값 없음
     */
    public function test_fields_disappear_when_module_is_inactive(): void
    {
        $target = $this->createUser();

        ModuleRecord::where('identifier', 'sirsoft-ecommerce')->update(['status' => ExtensionStatus::Inactive->value]);
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertNotContains('sirsoft-ecommerce', ModuleManager::getActiveModuleIdentifiers());

        $context = FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $target);
        $rules = app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, $context);
        $this->assertArrayNotHasKey(self::CURRENCY, $rules);
        $this->assertArrayNotHasKey(self::COUNTRY, $rules);

        $activeKeys = app(FieldRegistry::class)->active(FieldSet::CORE_USER)->pluck('key')->all();
        $this->assertEmpty(array_intersect([self::CURRENCY, self::COUNTRY], $activeKeys));

        $values = app(FieldValueService::class)->partition(FieldSet::CORE_USER, [self::CURRENCY => 'USD'], $context);
        $this->assertSame([], $values['values']);

        // 행은 지워지지 않는다 — 다시 켜면 운영자 설정과 함께 돌아온다
        $this->assertSame(2, FieldDefinition::where('field_set', FieldSet::CORE_USER)->where('source_type', 'module')->where('source_identifier', 'sirsoft-ecommerce')->count());
    }

    /**
     * 회원 항목 값 파기가 그 회원의 쇼핑 프로필 행만 지우는지 확인한다.
     *
     * @scenario case=purge
     *
     * @effects profile_row_purged_with_member_values
     *
     * @return void 반환값 없음
     */
    public function test_purge_owner_deletes_profile_row(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->profiles()->setPreferredCurrency($user->id, 'USD');
        $this->profiles()->setPreferredCurrency($other->id, 'USD');

        app(FieldValueService::class)->purgeOwner($user, FieldSet::CORE_USER);

        $this->assertDatabaseMissing(self::TABLE, ['user_id' => $user->id]);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => $other->id]);
    }
}
