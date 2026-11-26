<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider 웹서버 파일 위임 주입 사슬 테스트
 *
 * drivers.file_delegation·drivers.file_delegation_accel_prefix 저장값 → core.file_serving.{delegation,accel_prefix}
 * 주입과 정규화(빈/모르는 방식 → none, 끝 `/` 보정, 빈 접두사는 주입하지 않음)를 검증합니다. 주입부
 * (injectFileDelegationConfig)는 테스트 격리 가드와 분리되어 있어 직접 단언할 수 있고, 가드
 * (applyFileDelegationConfig)가 testing 환경에서 주입을 실제로 막는지도 함께 고정합니다.
 *
 * @scenario-manifest tests/scenarios/file-serving-delegation.yaml
 */
class SettingsServiceProviderFileDelegationTest extends TestCase
{
    /**
     * 시험마다 config 를 기본값으로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.file_serving.delegation', 'none');
        Config::set('core.file_serving.accel_prefix', '/_g7_sendfile/');
    }

    /**
     * 지정 메서드를 리플렉션으로 호출합니다.
     *
     * @param  string  $method  호출할 private 메서드명
     * @param  array  $driverSettings  drivers 카테고리 설정 데이터
     * @return void 반환값 없음
     */
    private function callWithDriverSettings(string $method, array $driverSettings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->with('drivers')
            ->willReturn($driverSettings);

        $provider = new SettingsServiceProvider($this->app);
        $reflection = new ReflectionMethod($provider, $method);
        $reflection->invoke($provider, $configRepository);
    }

    /**
     * 저장값이 core.file_serving 으로 주입되고 접두사 끝 `/` 가 보정되는지 테스트합니다.
     *
     * @scenario case=inject_settings
     *
     * @effects stored_delegation_injects_core_config_with_normalization
     *
     * @return void 반환값 없음
     */
    public function test_stored_values_are_injected_into_core_config(): void
    {
        $this->callWithDriverSettings('injectFileDelegationConfig', [
            'file_delegation' => 'x-accel-redirect',
            'file_delegation_accel_prefix' => '/protected-files',
        ]);

        $this->assertSame('x-accel-redirect', config('core.file_serving.delegation'));
        $this->assertSame('/protected-files/', config('core.file_serving.accel_prefix'));
    }

    /**
     * 빈 값·모르는 값·대소문자 섞인 값의 정규화를 테스트합니다.
     *
     * @scenario case=inject_settings
     *
     * @effects stored_delegation_injects_core_config_with_normalization
     *
     * @return void 반환값 없음
     */
    public function test_blank_and_unknown_modes_are_normalized_to_none(): void
    {
        Config::set('core.file_serving.delegation', 'x-sendfile');
        $this->callWithDriverSettings('injectFileDelegationConfig', ['file_delegation' => '']);
        $this->assertSame('none', config('core.file_serving.delegation'), '빈 값은 위임 안 함');

        Config::set('core.file_serving.delegation', 'x-sendfile');
        $this->callWithDriverSettings('injectFileDelegationConfig', ['file_delegation' => 'apache']);
        $this->assertSame('none', config('core.file_serving.delegation'), '모르는 값은 위임 안 함');

        $this->callWithDriverSettings('injectFileDelegationConfig', ['file_delegation' => ' X-Sendfile ']);
        $this->assertSame('x-sendfile', config('core.file_serving.delegation'), '앞뒤 공백·대소문자는 정규화');
    }

    /**
     * 키가 없거나 접두사가 비면 config 값(`.env` 또는 기본값)을 그대로 둡니다.
     *
     * @scenario case=inject_settings
     *
     * @effects stored_delegation_injects_core_config_with_normalization
     *
     * @return void 반환값 없음
     */
    public function test_missing_keys_leave_config_untouched(): void
    {
        Config::set('core.file_serving.delegation', 'x-sendfile');
        Config::set('core.file_serving.accel_prefix', '/from-env/');

        $this->callWithDriverSettings('injectFileDelegationConfig', ['file_delegation_accel_prefix' => '']);

        $this->assertSame('x-sendfile', config('core.file_serving.delegation'));
        $this->assertSame('/from-env/', config('core.file_serving.accel_prefix'));
    }

    /**
     * `.env` 우선 모드에서 `.env` 가 명시한 키는 저장값으로 덮지 않습니다 (EnvPriority::MAP).
     *
     * @scenario case=inject_settings
     *
     * @effects stored_delegation_injects_core_config_with_normalization
     *
     * @return void 반환값 없음
     */
    public function test_env_locked_keys_are_not_overwritten(): void
    {
        Config::set('env-priority.enabled', true);
        Config::set('env-priority.explicit', ['G7_FILE_DELEGATION' => true]);
        Config::set('core.file_serving.delegation', 'x-sendfile');

        $this->callWithDriverSettings('injectFileDelegationConfig', [
            'file_delegation' => 'none',
            'file_delegation_accel_prefix' => '/saved/',
        ]);

        $this->assertSame('x-sendfile', config('core.file_serving.delegation'), '.env 가 소유한 방식은 저장값이 덮지 않는다');
        $this->assertSame('/saved/', config('core.file_serving.accel_prefix'), '잠기지 않은 접두사는 저장값이 이긴다');
    }

    /**
     * testing 환경에서 가드가 주입을 차단하는지 테스트합니다 (테스트 격리).
     *
     * @scenario case=inject_testing_guard
     *
     * @effects delegation_injection_is_skipped_in_testing_env
     *
     * @return void 반환값 없음
     */
    public function test_guard_skips_injection_in_testing_env(): void
    {
        $this->callWithDriverSettings('applyFileDelegationConfig', [
            'file_delegation' => 'x-sendfile',
            'file_delegation_accel_prefix' => '/leak/',
        ]);

        $this->assertSame('none', config('core.file_serving.delegation'));
        $this->assertSame('/_g7_sendfile/', config('core.file_serving.accel_prefix'));
    }
}
