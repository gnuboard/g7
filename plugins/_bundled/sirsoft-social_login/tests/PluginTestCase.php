<?php

namespace Plugins\Sirsoft\SocialLogin\Tests;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Providers\SocialLoginServiceProvider;
use Plugins\Sirsoft\SocialLogin\Services\OAuthFlowService;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Tests\TestCase;

/**
 * 소셜 로그인 플러그인 테스트 베이스.
 *
 * 테스트 앱은 `plugins` 테이블의 활성 행으로 확장을 고르지 않으므로 서비스 프로바이더, 라우트, 훅 리스너를 여기서 직접
 * 등록한다. 라우트 접두·이름은 `PluginRouteServiceProvider` 와 같게 맞춘다 — 어긋나면 시험이 운영 주소와 다른 곳을 밟는다.
 * 데이터는 `DatabaseTransactions` 로 되돌린다(시험 DB 에 플러그인 마이그레이션을 미리 적용해 둔다).
 *
 * @since 1.0.0
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array{hooks: array, filters: array, dispatching: array}|null 훅 스냅샷 */
    private ?array $hookSnapshot = null;

    /** @var array<string, mixed> 가짜 카카오 프로필 응답 본문 */
    private array $kakaoProfileBody = [];

    /** @var bool 카카오 가짜 응답을 걸었는지 */
    private bool $kakaoFaked = false;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SocialLoginServiceProvider::class);
        $this->registerPluginRoutes();

        $this->snapshotHookManager();
        $this->registerPluginHookListeners();
    }

    /**
     * 테스트를 정리합니다 — 시험이 더한 훅을 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();

        parent::tearDown();
    }

    /**
     * 플러그인 웹·API 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function registerPluginRoutes(): void
    {
        $base = dirname(__DIR__).'/src/routes';

        Route::prefix('plugins/sirsoft-social_login')
            ->name('web.plugins.sirsoft-social_login.')
            ->middleware('web')
            ->group($base.'/web.php');

        Route::prefix('api/plugins/sirsoft-social_login')
            ->name('api.plugins.sirsoft-social_login.')
            ->middleware('api')
            ->group($base.'/api.php');

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * plugin.php 의 리스너를 컨테이너로 만들어 등록합니다(생성자 주입 포함).
     *
     * @return void 반환값 없음
     */
    protected function registerPluginHookListeners(): void
    {
        $plugin = new Plugin([
            'identifier' => 'sirsoft-social_login',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '소셜 로그인', 'en' => 'Social Login'],
            'version' => '1.0.0',
        ]);

        foreach ($plugin->getHookListeners() as $listenerClass) {
            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = $config['method'] ?? 'handle';
                $priority = $config['priority'] ?? 10;
                $callback = fn (...$args) => app($listenerClass)->{$method}(...$args);

                if (($config['type'] ?? 'action') === 'filter') {
                    HookManager::addFilter($hookName, $callback, $priority);
                } else {
                    HookManager::addAction($hookName, $callback, $priority);
                }
            }
        }
    }

    /**
     * HookManager 상태를 저장합니다.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 저장한 시점으로 HookManager 를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);
        $this->hookSnapshot = null;
    }

    /**
     * 플러그인 설정을 이 시험 안에서만 바꿉니다 (설정 파일은 건드리지 않는다).
     *
     * `PluginSettingsService` 의 요청 캐시에 기본값 + 바꿀 값을 넣은 인스턴스를 컨테이너에 둔다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 설정
     * @return void 반환값 없음
     */
    protected function setPluginSettings(array $overrides): void
    {
        $plugin = new Plugin(['identifier' => 'sirsoft-social_login', 'vendor' => 'sirsoft', 'version' => '1.0.0']);
        $service = app(PluginSettingsService::class);
        $ref = new \ReflectionProperty(PluginSettingsService::class, 'settingsCache');
        $cache = $ref->getValue($service);
        $cache['sirsoft-social_login'] = array_merge($plugin->getConfigValues(), $cache['sirsoft-social_login'] ?? [], $overrides);
        $ref->setValue($service, $cache);
        $this->app->instance(PluginSettingsService::class, $service);
    }

    /**
     * 내장 제공자를 시딩하고 카카오를 켭니다 (자격증명 포함).
     *
     * @param  array<string, mixed>  $attributes  카카오 행에 더할 값
     * @return void 반환값 없음
     */
    protected function enableKakao(array $attributes = []): void
    {
        (new ProviderSeeder)->run();
        $row = SocialLoginProvider::query()->where('key', 'kakao')->firstOrFail();
        $row->forceFill(['enabled' => true, 'client_id' => 'kakao-client', 'client_secret' => 'kakao-secret'] + $attributes)->save();
        app(ProviderRegistry::class)->flush();
    }

    /**
     * 카카오 프로필 응답을 만듭니다.
     *
     * @param  string  $id  제공자 사용자 ID
     * @param  string|null  $email  이메일 (null 이면 동의하지 않음)
     * @param  bool  $verified  이메일 검증 표시
     * @param  string|null  $nickname  별명
     * @return array<string, mixed> 프로필 본문
     */
    protected function kakaoProfile(string $id, ?string $email = null, bool $verified = true, ?string $nickname = '카카오닉'): array
    {
        $account = ['email_needs_agreement' => $email === null];
        if ($email !== null) {
            $account += ['email' => $email, 'is_email_valid' => true, 'is_email_verified' => $verified];
        }
        if ($nickname !== null) {
            $account['profile'] = ['nickname' => $nickname];
        }

        return ['id' => $id, 'kakao_account' => $account];
    }

    /**
     * 카카오 토큰·프로필 응답을 가짜로 둡니다.
     *
     * @param  array<string, mixed>  $profile  프로필 본문
     * @return void 반환값 없음
     */
    protected function fakeKakaoProfile(array $profile): void
    {
        // Http::fake 는 부를 때마다 앞 스텁 뒤에 쌓여 첫 스텁이 이긴다 — 한 번만 걸고 응답은 현재 값을 읽는다.
        $this->kakaoProfileBody = $profile;

        if ($this->kakaoFaked) {
            return;
        }

        $this->kakaoFaked = true;
        Http::fake([
            'kauth.kakao.com/oauth/token' => fn () => Http::response(['access_token' => 'at-'.Str::random(6), 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'kapi.kakao.com/v2/user/me' => fn () => Http::response($this->kakaoProfileBody),
            'kapi.kakao.com/v1/user/unlink' => fn () => Http::response(['id' => 1]),
        ]);
    }

    /**
     * 카카오로 왕복을 시작하고 콜백까지 마칩니다.
     *
     * @param  array<string, mixed>  $profile  콜백이 받을 프로필
     * @param  AttemptIntent  $intent  의도
     * @param  string|null  $redirect  복귀 주소
     * @param  int|null  $userId  시작 회원 (연결·재확인)
     * @param  string|null  $challengeId  본인인증 요청 ID (재확인)
     * @return TestResponse 콜백 응답
     */
    protected function kakaoRoundTrip(array $profile, AttemptIntent $intent = AttemptIntent::Login, ?string $redirect = '/mypage', ?int $userId = null, ?string $challengeId = null): TestResponse
    {
        $this->fakeKakaoProfile($profile);
        $url = app(OAuthFlowService::class)->start('kakao', $intent, $redirect, $userId, $challengeId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $this->get('/plugins/sirsoft-social_login/oauth/kakao/callback?code=c&state='.$q['state']);
    }

    /**
     * 302 응답의 이동 주소에서 쿼리를 꺼냅니다.
     *
     * @param  TestResponse  $response  응답
     * @return array<string, string> 쿼리
     */
    protected function redirectQuery(TestResponse $response): array
    {
        $response->assertStatus(302);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $q);

        return $q;
    }

    /**
     * 302 응답의 이동 경로를 꺼냅니다.
     *
     * @param  TestResponse  $response  응답
     * @return string 경로
     */
    protected function redirectPath(TestResponse $response): string
    {
        return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
    }

    /**
     * 관리 권한(종류 admin)을 가진 회원을 만듭니다 — 공용 admin 역할은 건드리지 않는다.
     *
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 관리자
     */
    protected function makeAdminUser(array $attributes = []): User
    {
        $role = Role::query()->create([
            'identifier' => 'sl-admin-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 관리자', 'en' => 'Test Admin'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-social_login.settings.view'],
            ['name' => ['ko' => '설정 열람', 'en' => 'View Settings'], 'type' => PermissionType::Admin]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create($attributes);
        $user->roles()->attach($role->id);
        $user->flushPermissionCaches();

        return $user;
    }

    /**
     * 메일 인증번호 요청에 알려진 인증번호를 심습니다.
     *
     * @param  string  $challengeId  본인인증 요청 ID
     * @return string 심은 인증번호
     */
    protected function plantCode(string $challengeId): string
    {
        $log = IdentityVerificationLog::query()->findOrFail($challengeId);
        $metadata = $log->metadata ?? [];
        $metadata['code_hash'] = Hash::make('135790');
        $log->metadata = $metadata;
        $log->save();

        return '135790';
    }

    /**
     * 실행마다 겹치지 않는 시험 이메일을 만듭니다.
     *
     * @param  string  $prefix  앞부분
     * @return string 이메일
     */
    protected function uniqueEmail(string $prefix = 'sl'): string
    {
        return $prefix.'.'.Str::lower(Str::random(10)).'@example.test';
    }

    /**
     * 제공자 응답 픽스처를 읽습니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @return array<string, mixed> 본문
     */
    protected function fixture(string $name): array
    {
        $path = dirname(__DIR__).'/tests/fixtures/provider-responses/'.$name.'.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
