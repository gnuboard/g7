<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\EnvPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `.env` 우선 모드의 설정 API 표면 (응답 메타 · 표시값 · 저장 게이트) 계약.
 *
 * 화면의 `disabled` 는 게이트가 아니다 — 저장 API 를 직접 호출하면 그대로 통과한다.
 * 그래서 잠금은 응답 메타(표시)와 저장 필터(차단) 두 축으로 나뉘고, 둘 다 여기서 고정한다.
 *
 * @effects meta_env_locked_uses_frontend_keys, locked_plain_shows_effective_value, locked_sensitive_hides_effective_value, locked_key_filtered_on_save_including_advanced, switch_off_behaves_identically
 */
class SettingsEnvLockedMetaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 스위치를 켜고 지정한 env 변수만 명시 상태로 만듭니다.
     *
     * `.env.testing` 을 건드리지 않고 config 를 직접 주입합니다 — 테스트가 개발 머신의
     * `.env` 상태에 좌우되면 재현이 불가능해집니다.
     *
     * @param  array<int, string>  $explicitVars  명시 상태로 만들 env 변수 목록
     */
    private function enableWith(array $explicitVars): void
    {
        config([
            'env-priority.enabled' => true,
            'env-priority.explicit' => array_fill_keys($explicitVars, true),
        ]);
    }

    /**
     * 관리자 사용자와 API 토큰을 만듭니다.
     *
     * @return array{0: User, 1: string} 사용자와 토큰
     */
    private function createAdminWithToken(): array
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permissionIds = [];

        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $scopedRole = Role::create([
            'identifier' => 'admin_env_priority_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);
        $scopedRole->permissions()->sync($permissionIds);

        foreach ([$adminRole->id, $scopedRole->id] as $roleId) {
            $user->roles()->attach($roleId, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        $user = $user->fresh();

        return [$user, $user->createToken('env-priority-test')->plainTextToken];
    }

    // ------------------------------------------------------------ 응답 메타

    /**
     * 잠금 목록은 화면이 참조하는 프론트엔드 키 형태로 내려갑니다.
     *
     * 저장소 키(`debug.mode`)를 그대로 내보내면 고급 탭의 잠금 표시가 조용히 미발동합니다.
     *
     * @effects meta_env_locked_uses_frontend_keys
     *
     * @scenario switch=on, key_state=locked_plain, surface=get_meta
     */
    public function test_env_locked_는_프론트엔드_키로_내려간다(): void
    {
        $this->enableWith(['APP_DEBUG', 'APP_NAME', 'GEOIP_LICENSE_KEY']);

        $meta = app(SettingsService::class)->envLockedMeta();

        $this->assertArrayHasKey('advanced.debug_mode', $meta, 'merge_into 변환(debug → advanced)이 반영되어야 합니다.');
        $this->assertArrayHasKey('advanced.geoip_license_key', $meta, 'frontend_key 변환이 반영되어야 합니다.');
        $this->assertArrayHasKey('general.site_name', $meta, '변환이 없는 키는 그대로 실립니다.');

        // getAllSettings() 가 값을 두 위치에 싣는 것과 같은 규칙 — 원본 카테고리도 함께 담긴다.
        $this->assertArrayHasKey('debug.debug_mode', $meta);
    }

    /**
     * 스위치가 꺼져 있으면 잠금 목록이 비어 있습니다.
     *
     * @effects switch_off_behaves_identically
     *
     * @scenario switch=off, key_state=unlocked, surface=get_meta
     */
    public function test_스위치가_꺼지면_잠금_목록이_비어있다(): void
    {
        config([
            'env-priority.enabled' => false,
            'env-priority.explicit' => ['APP_NAME' => true, 'APP_DEBUG' => true],
        ]);

        $this->assertSame([], app(SettingsService::class)->envLockedMeta());
    }

    /**
     * 잠긴 일반 키의 표시값은 저장값이 아니라 유효값(적용 중인 값)입니다.
     *
     * @effects locked_plain_shows_effective_value
     */
    /** @scenario switch=on, key_state=locked_plain, surface=get_effective_value */
    public function test_잠긴_일반_키는_유효값을_보여준다(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('general', [
            'site_name' => '저장된이름',
            'site_description' => '설명',
        ]);
        app(ConfigRepositoryInterface::class)->saveCategory('upload', [
            'max_file_size' => 10,
        ]);

        config(['app.name' => 'env유래이름', 'attachment.max_file_size' => 51200]);
        $this->enableWith(['APP_NAME', 'ATTACHMENT_MAX_FILE_SIZE']);

        $settings = app(SettingsService::class)->getAllSettings();

        $this->assertSame('env유래이름', $settings['general']['site_name']);
        $this->assertSame(50, $settings['upload']['max_file_size'], 'KB(config) → MB(화면) 환산이 적용되어야 합니다.');
        $this->assertSame('설명', $settings['general']['site_description'], '미잠금 키는 저장값 그대로입니다.');
    }

    /**
     * 잠긴 민감 키의 `.env` 값은 응답에 실리지 않습니다.
     *
     * 잠금 표시만 하고 값은 저장값 그대로 둡니다 — `.env` 비밀값의 admin UI 유출 차단.
     *
     * @effects locked_sensitive_hides_effective_value
     *
     * @scenario switch=on, key_state=locked_sensitive, surface=get_effective_value
     */
    public function test_잠긴_민감_키는_유효값을_노출하지_않는다(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('mail', [
            'password' => '저장된비밀',
        ]);

        config(['mail.mailers.smtp.password' => 'ENV_비밀값']);
        $this->enableWith(['MAIL_PASSWORD']);

        $settings = app(SettingsService::class)->getAllSettings();

        $this->assertSame('저장된비밀', $settings['mail']['password']);
        $this->assertNotSame('ENV_비밀값', $settings['mail']['password']);
        $this->assertArrayHasKey('mail.password', app(SettingsService::class)->envLockedMeta(), '민감 키도 잠금 표시는 됩니다.');
    }

    // ------------------------------------------------------------ 저장 게이트

    /**
     * 잠긴 키를 포함한 저장 요청은 그 키만 반영되지 않습니다 (저장 자체는 성공).
     *
     * @effects locked_key_filtered_on_save_including_advanced
     *
     * @scenario switch=on, key_state=locked_plain, surface=post_filter
     */
    public function test_잠긴_키는_저장에서_필터된다(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('general', [
            'site_name' => '원래이름',
            'site_description' => '원래설명',
        ]);

        $this->enableWith(['APP_NAME']);

        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'general',
            'general' => [
                'site_name' => '공격자가_보낸_이름',
                'site_description' => '바뀐설명',
            ],
        ]);

        $this->assertTrue($saved);

        $general = $repository->getCategory('general');

        $this->assertSame('원래이름', $general['site_name'], '잠긴 키는 저장 상태가 불변이어야 합니다.');
        $this->assertSame('바뀐설명', $general['site_description'], '미잠금 키는 종전대로 저장됩니다.');
    }

    /**
     * 고급 탭(카테고리 분리 경로)에서도 잠긴 키가 필터됩니다.
     *
     * 고급 탭은 여러 카테고리가 한 폼에 섞여 오므로 분리 후에 필터하지 않으면 이 경로만
     * 조용히 뚫립니다.
     *
     * @effects locked_key_filtered_on_save_including_advanced
     *
     * @scenario switch=on, key_state=locked_falsy, surface=post_filter
     */
    public function test_고급탭_저장에서도_잠긴_키가_필터된다(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('debug', ['mode' => false, 'sql_query_log' => false]);
        $repository->saveCategory('core_update', ['github_url' => 'https://github.com/original/repo']);

        $this->enableWith(['APP_DEBUG', 'G7_UPDATE_GITHUB_URL']);

        $saved = app(SettingsService::class)->saveSettings([
            '_tab' => 'advanced',
            'advanced' => [
                'debug_mode' => true,
                'sql_query_log' => true,
                'core_update_github_url' => 'https://github.com/attacker/repo',
            ],
        ]);

        $this->assertTrue($saved);

        $this->assertFalse((bool) $repository->getCategory('debug')['mode'], '잠긴 debug.mode 는 불변이어야 합니다.');
        $this->assertTrue((bool) $repository->getCategory('debug')['sql_query_log'], '미잠금 키는 저장됩니다.');
        $this->assertSame(
            'https://github.com/original/repo',
            $repository->getCategory('core_update')['github_url'],
            '잠긴 core_update.github_url 은 불변이어야 합니다.'
        );
    }

    /**
     * 스위치가 꺼져 있으면 저장이 종전과 동일합니다.
     *
     * @effects switch_off_behaves_identically
     *
     * @scenario switch=off, key_state=unlocked, surface=post_filter
     */
    public function test_스위치가_꺼지면_저장이_종전과_같다(): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('general', ['site_name' => '원래이름']);

        config([
            'env-priority.enabled' => false,
            'env-priority.explicit' => ['APP_NAME' => true],
        ]);

        app(SettingsService::class)->saveSettings([
            '_tab' => 'general',
            'general' => ['site_name' => '새이름'],
        ]);

        $this->assertSame('새이름', $repository->getCategory('general')['site_name']);
    }

    // ------------------------------------------------------------ HTTP 왕복

    /**
     * 조회·저장 두 응답이 같은 `_meta` 모양을 갖습니다.
     *
     * 한쪽만 필드가 늘면 저장 직후 화면의 잠금 표시가 조용히 사라집니다.
     *
     * @effects meta_env_locked_uses_frontend_keys
     *
     * @scenario switch=on, key_state=unlocked, surface=post_filter
     */
    public function test_조회와_저장_응답이_같은_meta_를_동봉한다(): void
    {
        [, $token] = $this->createAdminWithToken();

        $this->enableWith(['APP_NAME']);

        $index = $this->withToken($token)->getJson('/api/admin/settings');
        $index->assertOk();

        $indexMeta = $index->json('data._meta');

        $this->assertIsArray($indexMeta);
        $this->assertArrayHasKey('limits', $indexMeta);
        $this->assertTrue($indexMeta['env_priority_enabled']);
        $this->assertArrayHasKey('general.site_name', $indexMeta['env_locked']);

        // general 탭은 필수 필드를 함께 보내야 한다 (화면 폼도 탭 전체를 제출한다).
        $store = $this->withToken($token)->postJson('/api/admin/settings', [
            '_tab' => 'general',
            'general' => [
                'site_name' => '테스트사이트',
                'site_url' => 'https://example.test',
                'admin_email' => 'admin@example.test',
                'timezone' => 'Asia/Seoul',
                'language' => 'ko',
                'site_description' => '설명변경',
            ],
        ]);
        $store->assertOk();

        $storeMeta = $store->json('data.settings._meta');

        $this->assertIsArray($storeMeta);
        $this->assertSame(array_keys($indexMeta), array_keys($storeMeta), '조회·저장 응답의 _meta 키가 같아야 합니다.');
        $this->assertArrayHasKey('general.site_name', $storeMeta['env_locked']);
    }

    /**
     * 스위치가 꺼진 응답은 종전 형태를 유지합니다 (잠금 목록만 비어 있음).
     *
     * @effects switch_off_behaves_identically
     */
    /** @scenario switch=off, key_state=unlocked, surface=get_effective_value */
    public function test_스위치가_꺼진_응답은_잠금이_비어있다(): void
    {
        [, $token] = $this->createAdminWithToken();

        config([
            'env-priority.enabled' => false,
            'env-priority.explicit' => ['APP_NAME' => true],
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/settings');
        $response->assertOk();

        $this->assertFalse($response->json('data._meta.env_priority_enabled'));
        $this->assertSame([], $response->json('data._meta.env_locked'));
    }

    /**
     * 매핑에 sensitive 로 표시된 키는 표시값 오버레이 대상에서 제외됩니다.
     *
     * 오버레이 목록과 sensitive 목록이 갈라지면 그 키만 조용히 값이 새어 나갑니다.
     *
     * @effects locked_sensitive_hides_effective_value
     */
    /** @scenario switch=on, key_state=locked_sensitive, surface=get_effective_value */
    public function test_민감_키는_오버레이_대상에서_전부_제외된다(): void
    {
        $sensitiveKeys = array_keys(array_filter(
            EnvPriority::MAP,
            fn (array $entry) => ($entry['sensitive'] ?? false) === true
        ));

        $this->assertNotEmpty($sensitiveKeys);

        $this->enableWith(EnvPriority::envVars());

        $overlaySource = (string) file_get_contents(app_path('Services/SettingsService.php'));

        $this->assertStringContainsString(
            'EnvPriority::isSensitive($storageKey)',
            $overlaySource,
            '표시값 오버레이는 sensitive 키를 건너뛰어야 합니다.'
        );

        // 모든 키를 잠근 상태에서도 민감 키의 저장값이 유지되는지 실측한다.
        app(ConfigRepositoryInterface::class)->saveCategory('core_update', [
            'github_token' => '저장된토큰',
        ]);
        config(['app.update.github_token' => 'ENV_토큰']);

        $settings = app(SettingsService::class)->getAllSettings();

        $this->assertSame('저장된토큰', $settings['advanced']['core_update_github_token']);
    }
}
