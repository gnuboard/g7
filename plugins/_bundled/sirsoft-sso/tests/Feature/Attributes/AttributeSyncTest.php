<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Attributes;

use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\SsoUserAttribute;
use Plugins\Sirsoft\Sso\Services\AttributeQuery;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 속성 동기화(D8, W15) — sync_lock, sync, first_only, ignore, 추가 속성, 값이 없으면 지우지 않음, 이메일은 규칙 없이 바뀌지 않음.
 *
 * @since 1.0.0
 */
class AttributeSyncTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 모드별 반영 — 로그인(`created=false`)과 처음 생성(`created=true`).
     *
     * @scenario mode=sync_lock|sync|first_only|ignore, created=false|true
     *
     * @effects core_fields_written, first_only_on_create, ignore_untouched, attribute_saved
     *
     * @return void 반환값 없음
     */
    public function test_modes(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [
            ['field' => 'name', 'source' => 'display_name', 'mode' => 'sync_lock'],
            ['field' => 'nickname', 'source' => 'preferred_username', 'mode' => 'sync'],
            ['field' => 'phone', 'source' => 'phone_number', 'mode' => 'first_only'],
            ['field' => 'mobile', 'source' => 'mobile', 'mode' => 'ignore'],
            ['field' => 'attr:department', 'source' => 'department', 'mode' => 'sync'],
        ]]);
        $user = User::factory()->create(['name' => '전', 'nickname' => 'old', 'phone' => '02-000', 'mobile' => '010-000']);
        $account = $this->link($user, $idp, 'attr-1');
        $identity = new ExternalIdentity(subject: 'attr-1', displayName: '김직원', attributes: [
            'preferred_username' => 'kim', 'phone_number' => '02-111', 'mobile' => '010-111', 'department' => '인사팀',
        ]);

        $result = app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, false);
        $user->refresh();
        $this->assertSame(['name', 'nickname'], $result['fields']);
        $this->assertSame(['김직원', 'kim', '02-000', '010-000'], [$user->name, $user->nickname, $user->phone, $user->mobile]);
        $this->assertSame(['department' => '인사팀'], app(AttributeQuery::class)->forUser($user));

        app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, true);
        $this->assertSame('02-111', $user->fresh()->phone);
    }

    /**
     * IdP 가 값을 주지 않으면 지우지 않고, 바뀌지 않았으면 기록도 하지 않는다.
     *
     * @scenario value=missing|unchanged
     *
     * @effects field_kept, no_write
     *
     * @return void 반환값 없음
     */
    public function test_missing_value_keeps_field(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [
            ['field' => 'name', 'source' => 'display_name', 'mode' => 'sync'],
            ['field' => 'attr:title', 'source' => 'title', 'mode' => 'sync'],
        ]]);
        $user = User::factory()->create(['name' => '그대로']);
        $account = $this->link($user, $idp, 'attr-2');

        $first = app(AttributeSyncService::class)->sync($user, $account, $idp, new ExternalIdentity(subject: 'attr-2', attributes: ['title' => '대리']), false);
        $second = app(AttributeSyncService::class)->sync($user, $account, $idp, new ExternalIdentity(subject: 'attr-2', attributes: ['title' => '대리']), false);

        $this->assertSame('그대로', $user->fresh()->name);
        $this->assertSame(['title'], $first['attributes']);
        $this->assertSame([], $second['attributes']);
        $this->assertSame(1, SsoUserAttribute::query()->where('user_id', $user->id)->count());
    }

    /**
     * 이메일은 규칙(`field: email`)이 없으면 IdP 가 다른 주소를 줘도 바뀌지 않는다(기본 「가져오지 않음」).
     *
     * @scenario rule=none, idp_email=different
     *
     * @effects email_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_email_not_synced_without_rule(): void
    {
        $idp = $this->makeIdp(['attribute_map' => []]);
        $email = $this->uniqueEmail('corp798.test');
        $user = User::factory()->create(['email' => $email]);

        app(AttributeSyncService::class)->sync($user, $this->link($user, $idp, 'attr-3'), $idp, new ExternalIdentity(subject: 'attr-3', email: $this->uniqueEmail('corp798.test')), false);

        $this->assertSame($email, $user->fresh()->email);
    }

    /**
     * 지원하지 않는 언어는 버리고, 지원하는 언어는 지역 꼬리를 떼어 받는다.
     *
     * @scenario language=en-US|xx
     *
     * @effects language_normalized
     *
     * @return void 반환값 없음
     */
    public function test_language_normalization(): void
    {
        $idp = $this->makeIdp(['attribute_map' => [['field' => 'language', 'source' => 'locale', 'mode' => 'sync']]]);
        $user = User::factory()->create(['language' => 'ko']);
        $account = $this->link($user, $idp, 'attr-4');
        $sync = app(AttributeSyncService::class);

        $sync->sync($user, $account, $idp, new ExternalIdentity(subject: 'attr-4', attributes: ['locale' => 'xx']), false);
        $this->assertSame('ko', $user->fresh()->language);

        $sync->sync($user, $account, $idp, new ExternalIdentity(subject: 'attr-4', attributes: ['locale' => 'en-US']), false);
        $this->assertSame('en', $user->fresh()->language);
    }
}
