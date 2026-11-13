<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\UserConsent;
use App\Services\AuthService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 요청 클래스 없이 `AuthService::register()` 를 직접 부르는 경로(소셜 로그인, SSO, SCIM, 사전 등록).
 *
 * 이 경로는 요청 규칙을 타지 않으므로 항목 저장과 「필수 항목 나중 입력」 표시가 서비스 계층에서 일어나야 한다.
 */
class RegisterServiceLayerTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $auth;

    /**
     * 역할과 코어 회원 항목을 시딩한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->auth = app(AuthService::class);
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.auth.filter_register_data');
        parent::tearDown();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 서비스를 직접 불러도 컬럼 항목과 추가 항목 값이 저장되고, 필수 항목이 없어도 회원이 만들어진다.
     *
     * @scenario path=service_direct
     *
     * @effects service_register_stores_field_values_without_request
     *
     * @return void 반환값 없음
     */
    public function test_direct_register_stores_fields_and_ignores_missing_required(): void
    {
        $this->custom('job');
        $this->custom('must_have', ['required' => true]);

        $user = $this->auth->register([
            'name' => '외부 회원', 'nickname' => '외부닉', 'email' => 'issue490+direct@example.com',
            'language' => 'en', 'custom_fields' => ['job' => '기획자'],
        ], ['issue_token' => false])['user'];

        $this->assertSame(['외부 회원', '외부닉', 'en'], [$user->name, $user->nickname, $user->language]);
        $this->assertNull($user->password, '비밀번호 없는 가입에 비밀번호가 생겼다');
        $values = app(FieldValueService::class)->read($user, 'core.user');
        $this->assertSame('기획자', $values['job']);
        $this->assertNull($values['must_have']);
        $this->assertNull($user->fresh()->required_fields_deferred_at, '표시를 요청하지 않았는데 붙었다');
    }

    /**
     * `required_fields => defer` 면 생성 시각이 남는다 — 그 회원이 처음 로그인하면 추가 정보 입력으로 보낼 근거다.
     *
     * @scenario path=service_defer
     *
     * @effects service_register_marks_deferred_required_fields
     *
     * @return void 반환값 없음
     */
    public function test_defer_option_marks_the_member(): void
    {
        $user = $this->auth->register(
            ['name' => '사전 등록', 'email' => 'issue490+defer@example.com'],
            ['issue_token' => false, 'required_fields' => 'defer', 'record_consents' => false],
        )['user'];

        $this->assertNotNull($user->fresh()->required_fields_deferred_at);
        $this->assertSame(0, UserConsent::where('user_id', $user->id)->count(), '동의 기록을 남기지 말라는 옵션이 무시됐다');
    }

    /**
     * 이름 없이 불러도(이름을 주지 않는 공급자) 회원이 만들어지고 이름은 비어 있다.
     *
     * @scenario path=service_direct
     *
     * @effects service_register_accepts_missing_name
     *
     * @return void 반환값 없음
     */
    public function test_direct_register_without_name(): void
    {
        $user = $this->auth->register(['nickname' => '닉만있음', 'email' => 'issue490+noname@example.com'], ['issue_token' => false])['user'];

        $this->assertNull($user->name);
        $this->assertSame('닉만있음', $user->nickname);
        $this->assertSame('ko', $user->language, '언어를 주지 않았을 때 종전 기본값이 들어가지 않았다');
    }

    /**
     * 기존 옵션의 동작은 그대로다 — 토큰 발급 여부와 동의 기록.
     *
     * @scenario path=service_direct
     *
     * @effects service_register_legacy_options_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_existing_options_are_unchanged(): void
    {
        $withToken = $this->auth->register(['name' => '토큰', 'email' => 'issue490+token@example.com', 'agree_terms' => true, 'agree_privacy' => true]);
        $this->assertNotNull($withToken['token']);
        $this->assertSame('Bearer', $withToken['token_type']);
        $this->assertGreaterThan(0, UserConsent::where('user_id', $withToken['user']->id)->count());

        $withoutToken = $this->auth->register(['name' => '무토큰', 'email' => 'issue490+notoken@example.com'], ['issue_token' => false]);
        $this->assertNull($withoutToken['token']);
        $this->assertTrue($withoutToken['user']->roles()->where('identifier', 'user')->exists(), '기본 역할이 붙지 않았다');
    }

    /**
     * 가입 저장 데이터 필터는 회원 행의 컬럼 값을 조정할 수 있지만 이메일, 비밀번호, 상태는 바꾸지 못한다.
     *
     * @scenario path=service_direct
     *
     * @effects register_data_filter_cannot_override_identity_columns
     *
     * @return void 반환값 없음
     */
    public function test_register_data_filter_can_adjust_columns_but_not_identity(): void
    {
        HookManager::addFilter('core.auth.filter_register_data', function (array $userData, array $data, array $options) {
            $userData['timezone'] = 'Asia/Tokyo';
            $userData['email'] = 'hijacked@example.com';
            $userData['status'] = 'blocked';

            return $userData;
        });

        $user = $this->auth->register(['name' => '필터', 'email' => 'issue490+filter@example.com'], ['issue_token' => false])['user']->fresh();

        $this->assertSame('Asia/Tokyo', $user->timezone);
        $this->assertSame('issue490+filter@example.com', $user->email, '필터가 이메일을 바꿨다');
        $this->assertSame('active', $user->status, '필터가 상태를 바꿨다');
    }
}
