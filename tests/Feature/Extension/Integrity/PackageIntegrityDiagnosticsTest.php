<?php

namespace Tests\Feature\Extension\Integrity;

use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\TrustKeyStatus;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 내장 신뢰 키 진단 경고
 *
 * 운영 공개키가 아직 코어에 들어가지 않은 상태로 출시되면 공식 서명 패키지도 「신뢰하지 않는 키」가 된다. 그 상태를
 * `php artisan about` 의 「패키지 무결성」 구획과 `core:check-updates` 출력에서 알아볼 수 있어야 한다.
 */
class PackageIntegrityDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 테스트마다 바꾼 내장 키 목록을 원래대로 돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        BuiltinTrustKeys::useForTesting(null);

        parent::tearDown();
    }

    /**
     * 내장 키가 비면 about 구획에 0 과 경고가 붙고, 키가 있으면 개수만 보인다. sodium 과 차단 스위치 줄도 함께 나온다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=builtin_key_diagnostics
     *
     * @effects about_shows_package_integrity_section_with_empty_builtin_warning
     */
    public function test_about_section_warns_when_builtin_keys_empty(): void
    {
        BuiltinTrustKeys::useForTesting([]);

        $this->artisan('about')
            ->expectsOutputToContain(__('extension_integrity.about.section'))
            ->expectsOutputToContain(__('extensions.commands.trust_key.builtin_empty'))
            ->expectsOutputToContain(__('extension_integrity.about.block_unsigned'))
            ->assertSuccessful();

        BuiltinTrustKeys::useForTesting([
            ['key_id' => 'sirsoft-test-1', 'public_key' => base64_encode(str_repeat("\x01", 32)), 'label' => 'TEST', 'status' => TrustKeyStatus::Active->value],
        ]);

        $this->artisan('about')
            ->expectsOutputToContain(__('extension_integrity.about.builtin_keys'))
            ->doesntExpectOutputToContain(__('extensions.commands.trust_key.builtin_empty'))
            ->assertSuccessful();

        // 키가 있어도 전부 폐기면 쓸 수 있는 키가 0 이다 — 빈 목록과 같은 경고
        BuiltinTrustKeys::useForTesting([
            ['key_id' => 'sirsoft-test-1', 'public_key' => base64_encode(str_repeat("\x01", 32)), 'label' => 'TEST', 'status' => TrustKeyStatus::Revoked->value],
        ]);

        $this->artisan('about')
            ->expectsOutputToContain(__('extensions.commands.trust_key.builtin_empty'))
            ->assertSuccessful();
    }

    /**
     * 코어 업데이트 확인은 쓸 수 있는 내장 키가 없을 때(비었거나 전부 폐기)만 경고한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=builtin_key_diagnostics
     *
     * @effects core_check_updates_warns_when_no_usable_builtin_key
     */
    public function test_core_check_updates_warns_only_without_usable_builtin_key(): void
    {
        $this->mock(CoreUpdateService::class, function ($mock): void {
            $mock->shouldReceive('checkForUpdates')->andReturn([
                'current_version' => '7.2.0',
                'latest_version' => '7.2.0',
                'update_available' => false,
            ]);
        });
        $warning = __('extensions.commands.trust_key.builtin_empty');

        BuiltinTrustKeys::useForTesting([]);
        $this->artisan('core:check-updates')->expectsOutputToContain($warning)->assertSuccessful();

        BuiltinTrustKeys::useForTesting([
            ['key_id' => 'sirsoft-test-1', 'public_key' => base64_encode(str_repeat("\x01", 32)), 'label' => 'TEST', 'status' => TrustKeyStatus::Revoked->value],
        ]);
        $this->artisan('core:check-updates')->expectsOutputToContain($warning)->assertSuccessful();

        BuiltinTrustKeys::useForTesting([
            ['key_id' => 'sirsoft-test-2', 'public_key' => base64_encode(str_repeat("\x02", 32)), 'label' => 'TEST', 'status' => TrustKeyStatus::Retired->value],
        ]);
        $this->artisan('core:check-updates')->doesntExpectOutputToContain($warning)->assertSuccessful();
    }
}
