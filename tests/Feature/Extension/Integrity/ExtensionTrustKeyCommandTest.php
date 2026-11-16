<?php

namespace Tests\Feature\Extension\Integrity;

use App\Extension\HookManager;
use App\Extension\Integrity\BuiltinTrustKeys;
use App\Extension\Integrity\TrustKeyStatus;
use App\Models\ExtensionTrustKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * `extension:trust-key add|remove|list` (#916 ④, D20·D31)
 *
 * vendor 범위는 하나 이상 필수, `sirsoft` vendor·서명자 이름은 내장 키 전용이라 거부, key_id 는 서버가 계산한다.
 */
class ExtensionTrustKeyCommandTest extends TestCase
{
    use PackageSignatureFixtures;
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
     * 시험 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * add 는 vendor 범위와 함께 키를 등록하고 key_id 를 계산해 보이고, list·remove 가 그 키를 다룹니다.
     *
     * @scenario case=trust_key_command_add_list_remove
     *
     * @effects trust_key_registered_with_vendor_scope_and_computed_key_id
     *
     * @return void 반환값 없음
     */
    public function test_add_list_remove(): void
    {
        $key = $this->fixtureKey('glitter');

        // 서비스 훅 순서 — before_* → 저장·삭제 → after_* (hooks.md 네이밍 `[vendor-module].[entity].[action]_[timing]`)
        $fired = [];
        foreach (['before_create', 'after_create', 'before_delete', 'after_delete'] as $timing) {
            HookManager::addAction('core.extension_trust_keys.'.$timing, function (mixed $payload) use (&$fired, $timing) {
                $fired[] = [$timing, is_array($payload) ? ($payload['label'] ?? null) : $payload?->key_id];
            });
        }

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'glitter', '--vendor' => ['glitter', 'glitterlab']])
            ->expectsOutputToContain($key['key_id'])
            ->assertExitCode(0);

        $row = ExtensionTrustKey::query()->where('key_id', $key['key_id'])->firstOrFail();
        $this->assertSame(['glitter', 'glitterlab'], $row->vendor_scope);
        $this->assertNull($row->created_by);
        $this->assertSame([['before_create', 'glitter'], ['after_create', $key['key_id']]], $fired);

        $this->artisan('extension:trust-key', ['action' => 'list'])
            ->expectsOutputToContain($key['key_id'])
            ->assertExitCode(0);

        $this->artisan('extension:trust-key', ['action' => 'remove', 'value' => $key['key_id']])->assertExitCode(0);
        $this->assertDatabaseMissing('extension_trust_keys', ['key_id' => $key['key_id']]);
        $this->assertSame([['before_delete', $key['key_id']], ['after_delete', $key['key_id']]], array_slice($fired, 2));

        $this->artisan('extension:trust-key', ['action' => 'remove', 'value' => $key['key_id']])->assertExitCode(1);
    }

    /**
     * vendor 범위 없음·sirsoft vendor·sirsoft 서명자 이름·잘못된 공개키·중복 키는 거부합니다.
     *
     * @scenario case=trust_key_command_rejects
     *
     * @effects trust_key_requires_vendor_scope_and_rejects_reserved_sirsoft
     *
     * @return void 반환값 없음
     */
    public function test_rejects_missing_scope_reserved_vendor_and_invalid_key(): void
    {
        $key = $this->fixtureKey('glitter');

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'glitter'])
            ->expectsOutputToContain(__('extension_integrity.validation.vendor_scope_required'))
            ->assertExitCode(1);

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'glitter', '--vendor' => ['sirsoft']])
            ->expectsOutputToContain(__('extension_integrity.validation.vendor_reserved'))
            ->assertExitCode(1);

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'sirsoft', '--vendor' => ['glitter']])
            ->expectsOutputToContain(__('extension_integrity.validation.label_reserved'))
            ->assertExitCode(1);

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => 'bm90LWEta2V5', '--label' => 'glitter', '--vendor' => ['glitter']])
            ->expectsOutputToContain(__('extension_integrity.validation.public_key_invalid'))
            ->assertExitCode(1);

        $this->assertSame(0, ExtensionTrustKey::query()->count());

        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'glitter', '--vendor' => ['glitter']])->assertExitCode(0);
        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $key['public_key_b64'], '--label' => 'glitter', '--vendor' => ['glitter']])
            ->expectsOutputToContain(__('extension_integrity.validation.duplicate_key'))
            ->assertExitCode(1);

        // 내장 키와 같은 키
        $builtin = $this->fixtureKey('sirsoft');
        $this->useBuiltinTestKey($builtin);
        $this->artisan('extension:trust-key', ['action' => 'add', 'value' => $builtin['public_key_b64'], '--label' => 'other', '--vendor' => ['other']])
            ->expectsOutputToContain(__('extension_integrity.validation.builtin_conflict'))
            ->assertExitCode(1);

        $this->artisan('extension:trust-key', ['action' => 'nope'])->assertExitCode(1);
    }

    /**
     * list 는 쓸 수 있는 내장 키가 없을 때(비었거나 전부 폐기) 경고하고, 은퇴 키만 있어도 쓸 수 있으니 경고하지 않는다.
     *
     * `php artisan about`·`core:check-updates` 와 같은 판정(`BuiltinTrustKeys::hasUsableKey()`)이다.
     *
     * @scenario case=builtin_key_diagnostics
     *
     * @effects trust_key_list_warns_when_no_usable_builtin_key
     *
     * @return void 반환값 없음
     */
    public function test_list_warns_without_usable_builtin_key(): void
    {
        $warning = __('extensions.commands.trust_key.builtin_empty');
        $builtin = $this->fixtureKey('sirsoft');

        BuiltinTrustKeys::useForTesting([]);
        $this->artisan('extension:trust-key', ['action' => 'list'])->expectsOutputToContain($warning)->assertExitCode(0);

        $this->useBuiltinTestKey($builtin, TrustKeyStatus::Revoked->value);
        $this->artisan('extension:trust-key', ['action' => 'list'])->expectsOutputToContain($warning)->assertExitCode(0);

        $this->useBuiltinTestKey($builtin, TrustKeyStatus::Retired->value);
        $this->artisan('extension:trust-key', ['action' => 'list'])->doesntExpectOutputToContain($warning)->assertExitCode(0);
    }
}
