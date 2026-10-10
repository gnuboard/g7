<?php

namespace Tests\Unit\Extension;

use App\Exceptions\CoreVersionMismatchException;
use App\Extension\CoreVersionChecker;
use Tests\TestCase;

/**
 * 플러그인 코어 버전 호환성 검증 단위 테스트.
 *
 * PluginManager 가 활성화/업데이트 경로에서 사용하는 검증 헬퍼
 * (CoreVersionChecker::validateExtension / isCompatible) 의 결과 도메인을
 * 플러그인 컨텍스트에서 검증합니다.
 *
 * 케이스:
 *   1. 현재 코어 < 요구 → 예외 + payload(extension_type=plugin, identifier, required, current, guide_url)
 *   2. 현재 코어 == 요구 → true 통과
 *   3. 현재 코어 > 요구 (caret) → true 통과
 *   4. requireVersion=null → 검증 생략 true 통과
 */
class PluginManagerVersionCheckTest extends TestCase
{
    private ?string $originalEnvVersion = null;

    /**
     * APP_VERSION 환경변수를 백업·제거하고 코어 버전을 7.0.0-beta.1 로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        putenv('APP_VERSION');
        config()->set('app.version', '7.0.0-beta.1');
    }

    /**
     * 테스트 중 바꾼 APP_VERSION 환경변수를 원래 값으로 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        if ($this->originalEnvVersion !== null) {
            $_ENV['APP_VERSION'] = $this->originalEnvVersion;
            $_SERVER['APP_VERSION'] = $this->originalEnvVersion;
            putenv('APP_VERSION='.$this->originalEnvVersion);
        } else {
            putenv('APP_VERSION');
        }
        parent::tearDown();
    }

    /**
     * 요구 코어 버전을 만족하지 못하면 플러그인 정보를 담은 CoreVersionMismatchException 이 발생하는지 검증한다.
     *
     * @effects extension_required_core_version_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_incompatible_required_version_throws_with_plugin_payload(): void
    {
        try {
            CoreVersionChecker::validateExtension('>=7.1.0', 'sirsoft-payment', 'plugin');
            $this->fail('CoreVersionMismatchException 이 발생해야 합니다.');
        } catch (CoreVersionMismatchException $e) {
            $payload = $e->getPayload();
            $this->assertSame('plugin', $payload['extension_type']);
            $this->assertSame('sirsoft-payment', $payload['identifier']);
            $this->assertSame('>=7.1.0', $payload['required_core_version']);
            $this->assertSame('7.0.0-beta.1', $payload['current_core_version']);
            $this->assertSame('/admin/core/update', $payload['guide_url']);
        }
    }

    /**
     * 현재 코어 버전이 >= 제약을 만족하면 호환으로 판정되는지 검증한다.
     *
     * @effects extension_required_core_version_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_compatible_when_required_constraint_is_satisfied_loose(): void
    {
        config()->set('app.version', '7.1.0');
        $this->assertTrue(CoreVersionChecker::isCompatible('>=7.0.0'));
        $this->assertTrue(CoreVersionChecker::validateExtension('>=7.0.0', 'sirsoft-payment', 'plugin'));
    }

    /**
     * 패치 버전이 더 높아도 ^ 제약 범위 안이면 호환으로 판정되는지 검증한다.
     *
     * @effects extension_required_core_version_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_compatible_when_caret_constraint_with_higher_patch(): void
    {
        config()->set('app.version', '7.0.5');
        $this->assertTrue(CoreVersionChecker::isCompatible('^7.0.0'));
    }

    /**
     * 요구 버전이 null 이면 검증을 건너뛰고 통과하는지 검증한다.
     *
     * @effects extension_required_core_version_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_null_required_version_skips_validation(): void
    {
        $this->assertTrue(CoreVersionChecker::isCompatible(null));
        $this->assertTrue(CoreVersionChecker::validateExtension(null, 'sirsoft-payment', 'plugin'));
    }

    /**
     * 코어 업데이트 진행 중(`G7_UPDATE_IN_PROGRESS=1`)에는 환경변수 APP_VERSION 이 config 보다 우선한다.
     *
     * 환경변수 우선은 업데이트 트리 안에서만 성립한다(#658) — 그 밖에서는 stale 한 프로세스 환경이 config 를 이기면 안 된다.
     *
     * @effects getCoreVersion_prefers_env_APP_VERSION_only_inside_update_tree
     *
     * @return void 반환값 없음
     */
    public function test_env_app_version_overrides_config_for_plugin_check(): void
    {
        config()->set('app.version', '7.0.0-beta.1');
        $_ENV['APP_VERSION'] = '7.2.0';
        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';

        try {
            $this->assertTrue(CoreVersionChecker::isCompatible('>=7.1.0'));
        } finally {
            unset($_ENV['G7_UPDATE_IN_PROGRESS']);
        }
    }

    /**
     * 업데이트 트리 밖에서는 환경변수 APP_VERSION 이 있어도 config 버전으로 판정한다 (#658).
     *
     * @effects getCoreVersion_ignores_env_APP_VERSION_outside_update_tree
     *
     * @return void 반환값 없음
     */
    public function test_env_app_version_is_ignored_outside_core_update(): void
    {
        config()->set('app.version', '7.0.0-beta.1');
        $_ENV['APP_VERSION'] = '7.2.0';

        $this->assertFalse(CoreVersionChecker::isCompatible('>=7.1.0'));
    }
}
