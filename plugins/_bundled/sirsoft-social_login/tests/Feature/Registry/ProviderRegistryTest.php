<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Registry;

use App\Extension\HookManager;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Drivers\FacebookDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\GoogleDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\KakaoDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\NaverDriver;
use Plugins\Sirsoft\SocialLogin\Drivers\OidcDriver;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\ProviderRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 제공자 레지스트리 시험 — 시딩, 확장 등록, 내장 덮어쓰기 무시, 커스텀 한도, 모의 게이트.
 * 시딩 두 진입점(설치, 언어팩 재시딩) 시험은 트러블슈팅 `[case:backend-58]` 의 회귀 그물이다.
 *
 * @since 1.0.0
 */
class ProviderRegistryTest extends PluginTestCase
{
    /**
     * 새 레지스트리를 반환합니다(요청당 캐시 비움).
     *
     * @return ProviderRegistry 레지스트리
     */
    private function registry(): ProviderRegistry
    {
        $registry = app(ProviderRegistry::class);
        $registry->flush();

        return $registry;
    }

    /**
     * 내장 제공자 4개를 꺼진 채로 만들고, 다시 시딩해도 운영자가 바꾼 값을 지키는지 확인한다.
     *
     * @scenario seed=builtin
     *
     * @effects builtin_four_seeded_disabled
     *
     * @return void 반환값 없음
     */
    public function test_seeder_creates_four_builtin_providers_disabled_and_keeps_operator_changes(): void
    {
        (new ProviderSeeder)->run();

        $rows = SocialLoginProvider::query()->orderBy('sort_order')->get();
        $this->assertSame(['kakao', 'naver', 'google', 'facebook'], $rows->pluck('key')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->enabled === false && $r->type === 'builtin'));

        SocialLoginProvider::query()->where('key', 'kakao')->update(['enabled' => true, 'sort_order' => 99, 'client_id' => 'operator-id']);
        (new ProviderSeeder)->run();

        $kakao = SocialLoginProvider::query()->where('key', 'kakao')->first();
        $this->assertTrue($kakao->enabled, '다시 시딩해도 운영자가 켠 값을 되돌리지 않는다');
        $this->assertSame(99, $kakao->sort_order);
        $this->assertSame('operator-id', $kakao->client_id);
        $this->assertSame(4, SocialLoginProvider::query()->count());
    }

    /**
     * 내장 제공자의 이메일 신뢰 기본값이 제공자 성격대로인지 확인한다 — 이메일 검증 표시를 주는 카카오·구글은 「검증 표시만
     * 믿음」, 표시를 주지 않는 네이버·페이스북은 「항상 믿음」(그러지 않으면 두 제공자 가입자 모두가 인증번호를 받아야 한다).
     * 다시 시딩해도 운영자가 바꾼 값은 지킨다.
     *
     * @scenario seed=builtin, email_trust=flag|trust
     *
     * @effects builtin_email_trust_defaults
     *
     * @return void 반환값 없음
     */
    public function test_builtin_email_trust_defaults_follow_provider_flag_support(): void
    {
        (new ProviderSeeder)->run();

        $expected = ['kakao' => 'flag', 'naver' => 'trust', 'google' => 'flag', 'facebook' => 'trust'];
        $seeded = SocialLoginProvider::query()->pluck('email_trust', 'key')->all();
        $this->assertEqualsCanonicalizing(array_column((new ProviderSeeder)->getDefaults(), 'key'), array_keys($expected), '기대값 표가 내장 제공자 전체를 덮는다');
        $this->assertEquals($expected, $seeded);

        SocialLoginProvider::query()->where('key', 'naver')->update(['email_trust' => 'verify']);
        (new ProviderSeeder)->run();
        $this->assertSame('verify', SocialLoginProvider::query()->where('key', 'naver')->value('email_trust'), '운영자가 바꾼 값은 다시 시딩해도 그대로');
    }

    /**
     * 내장 제공자 이름 기본값은 기본 언어(ko·en)만 갖고, 다른 언어는 활성 언어팩의 시드(`seed/providers.json`)가 시딩 때
     * 채운다 — 시더가 일본어를 직접 들고 있으면 언어팩 번역과 값이 갈린다.
     *
     * @scenario seed=builtin, locale=ko|en|ja
     *
     * @effects builtin_labels_base_locales_only
     *
     * @return void 반환값 없음
     */
    public function test_seeder_defaults_carry_only_base_locales(): void
    {
        foreach ((new ProviderSeeder)->getDefaults() as $row) {
            $this->assertSame(['ko', 'en'], array_keys($row['label']), $row['key']);
        }

        // 플러그인을 먼저 설치하고(행에 ko·en 만) 나중에 언어팩을 켜면 코어가 시더를 다시 돌린다(plugin:seed) —
        // 그때 빠진 언어만 채우고, 운영자가 바꾼 이름은 그대로 둔다.
        (new ProviderSeeder)->run();
        $naver = SocialLoginProvider::query()->where('key', 'naver')->first();
        $naver->forceFill(['label' => ['ko' => '운영자 네이버', 'en' => 'Naver']])->save();

        HookManager::addFilter('seed.sirsoft-social_login.providers.translations', function (array $rows) {
            foreach ($rows as $i => $row) {
                $rows[$i]['label']['ja'] = 'JA-'.$row['key'];
            }

            return $rows;
        });
        (new ProviderSeeder)->run();

        $labels = SocialLoginProvider::query()->pluck('label', 'key');
        $this->assertSame('JA-naver', $labels['naver']['ja'] ?? null);
        $this->assertSame('운영자 네이버', $labels['naver']['ko'], '운영자가 바꾼 이름은 덮지 않는다');
        $this->assertSame('JA-kakao', $labels['kakao']['ja'] ?? null);
    }

    /**
     * 언어팩을 켜고 끌 때 코어는 `plugin:seed` 로 이 플러그인 시더를 다시 돌린다 — 그 명령이 찾는 `DatabaseSeeder` 가
     * 설치 때와 같은 시더(`getSeeders()`)를 돌려야 다른 언어 이름이 기존 행에 들어간다. 없으면 재시딩이 「시더 클래스를
     * 찾을 수 없습니다」로 조용히 실패한다.
     *
     * @scenario seed=language_pack_reseed
     *
     * @effects reseed_entrypoint_runs_install_seeders
     *
     * @return void 반환값 없음
     */
    public function test_plugin_seed_entrypoint_runs_the_install_seeders(): void
    {
        $class = 'Plugins\\Sirsoft\\SocialLogin\\Database\\Seeders\\DatabaseSeeder';
        $this->assertTrue(class_exists($class), 'plugin:seed 가 찾는 기본 시더');

        SocialLoginProvider::query()->delete();
        app($class)->run();

        $this->assertSame(['kakao', 'naver', 'google', 'facebook'], SocialLoginProvider::query()->orderBy('sort_order')->pluck('key')->all());

        // 진입 시더는 설치 시더 목록을 사본으로 들지 않고 그 목록을 그대로 돈다 — 설치 시더가 늘면 재시딩도 같이 는다.
        $this->assertSame((new Plugin)->getSeeders(), (new $class)->seeders());
    }

    /**
     * 「없으면 만들기」의 빠진 언어 병합은 다국어 칸(연관 배열)에만 쓴다 — 목록형 칸(요청 범위 등)에 쓰면 운영자가 지운 값이
     * 인덱스 기준 병합으로 되살아난다.
     *
     * @scenario seed=list_column
     *
     * @effects seed_merge_limited_to_translatable_columns
     *
     * @return void 반환값 없음
     */
    public function test_seed_merge_skips_list_columns(): void
    {
        (new ProviderSeeder)->run();
        $repository = app(ProviderRepositoryInterface::class);
        SocialLoginProvider::query()->where('key', 'kakao')->first()->forceFill(['scopes' => ['profile_nickname']])->save();

        $repository->seedIfMissing('kakao', ['scopes' => ['profile_nickname', 'account_email'], 'label' => ['ko' => '카카오', 'en' => 'Kakao', 'ja' => 'カカオ']]);

        $kakao = SocialLoginProvider::query()->where('key', 'kakao')->first();
        $this->assertSame(['profile_nickname'], $kakao->scopes, '운영자가 줄인 목록은 되살리지 않는다');
        $this->assertSame('カカオ', $kakao->label['ja'] ?? null, '다국어 칸은 빠진 언어를 더한다');
    }

    /**
     * 제공자 행이 driver 컬럼에 맞는 드라이버로 만들어지는지 확인한다.
     *
     * @scenario registry=builtin
     *
     * @effects drivers_instantiated_by_driver_column
     *
     * @return void 반환값 없음
     */
    public function test_rows_become_drivers_by_driver_column(): void
    {
        (new ProviderSeeder)->run();
        SocialLoginProvider::query()->create(['key' => 'acme_idp', 'driver' => 'oidc', 'type' => 'custom', 'label' => ['ko' => '사내', 'en' => 'Corp']]);

        $all = $this->registry()->all();

        $this->assertInstanceOf(KakaoDriver::class, $all['kakao']);
        $this->assertInstanceOf(NaverDriver::class, $all['naver']);
        $this->assertInstanceOf(GoogleDriver::class, $all['google']);
        $this->assertInstanceOf(FacebookDriver::class, $all['facebook']);
        $this->assertInstanceOf(OidcDriver::class, $all['acme_idp']);
        $this->assertSame('acme_idp', $all['acme_idp']->key());
    }

    /**
     * 확장은 새 제공자를 더할 수 있지만 내장 제공자는 바꿀 수 없는지 확인한다.
     *
     * @scenario registry=extension
     *
     * @effects extension_adds_new_key_builtin_override_ignored
     *
     * @return void 반환값 없음
     */
    public function test_extension_can_add_new_key_but_cannot_replace_builtin(): void
    {
        (new ProviderSeeder)->run();
        $foreign = app(OidcDriver::class)->withConfig(['key' => 'ext_idp', 'client_id' => 'x']);
        $impostor = app(OidcDriver::class)->withConfig(['key' => 'kakao']);

        HookManager::addFilter(ProviderRegistry::FILTER_REGISTERED, function (array $drivers) use ($foreign, $impostor) {
            $drivers['ext_idp'] = $foreign;
            $drivers['kakao'] = $impostor;
            $drivers['Bad Key'] = $foreign;

            return $drivers;
        }, 20);

        $all = $this->registry()->all();

        $this->assertSame($foreign, $all['ext_idp']);
        $this->assertInstanceOf(KakaoDriver::class, $all['kakao'], '내장 키 덮어쓰기는 무시');
        $this->assertArrayNotHasKey('Bad Key', $all, '키 형식이 틀리면 받지 않는다');
    }

    /**
     * 커스텀 제공자가 한도까지만 등록되는지 확인한다.
     *
     * @scenario registry=custom_limit
     *
     * @effects custom_providers_capped
     *
     * @return void 반환값 없음
     */
    public function test_custom_providers_are_capped_at_the_limit(): void
    {
        for ($i = 1; $i <= ProviderRegistry::DEFAULT_CUSTOM_LIMIT + 1; $i++) {
            SocialLoginProvider::query()->create(['key' => 'idp_'.$i, 'driver' => 'oidc', 'type' => 'custom', 'sort_order' => $i, 'label' => ['ko' => 'x']]);
        }

        $keys = array_keys($this->registry()->all());

        $this->assertCount(ProviderRegistry::DEFAULT_CUSTOM_LIMIT, $keys);
        $this->assertNotContains('idp_'.(ProviderRegistry::DEFAULT_CUSTOM_LIMIT + 1), $keys);
    }

    /**
     * 모의 제공자가 게이트가 켜졌을 때만 등록되는지 확인한다.
     *
     * @scenario registry=mock_gate
     *
     * @effects mock_providers_only_when_gate_on
     *
     * @return void 반환값 없음
     */
    public function test_mock_providers_appear_only_while_gate_is_on(): void
    {
        $gate = app(MockGate::class);
        $gate->disable();
        $this->assertSame([], array_values(array_filter(array_keys($this->registry()->all()), fn ($k) => str_starts_with($k, 'mock_'))));

        $gate->enable(10);
        (new ProviderSeeder)->run();
        $all = $this->registry()->all();
        $mockKeys = array_values(array_filter(array_keys($all), fn ($k) => str_starts_with($k, 'mock_')));
        $gate->disable();

        $this->assertSame(['mock_kakao', 'mock_naver', 'mock_google', 'mock_facebook', 'mock_oidc'], $mockKeys);
        // TLS 검증을 끄는 것은 모의 제공자(이 사이트 자신을 부름)뿐이다 — 실제 제공자는 언제나 검증한다.
        foreach ($mockKeys as $key) {
            $this->assertTrue($all[$key]->isMock(), $key);
        }
        foreach (['kakao', 'naver', 'google', 'facebook'] as $key) {
            $this->assertFalse($all[$key]->isMock(), $key);
        }
    }
}
