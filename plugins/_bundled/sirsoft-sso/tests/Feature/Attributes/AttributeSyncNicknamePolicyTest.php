<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Attributes;

use App\Models\User;
use App\Services\AuthService;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;
use Plugins\Sirsoft\Sso\Services\NicknameRejectionLedger;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * 속성 동기화와 사이트의 닉네임 정책 — 회사 계정이 준 닉네임이 중복이거나 금지어면 닉네임만 반영하지 않고
 * 나머지 칸은 반영하며, 동기화 결과에 사유를 남긴다. 동기화는 실패하지 않는다(로그인을 막지 않는다).
 *
 * @since 1.0.0
 */
class AttributeSyncNicknamePolicyTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * 닉네임 정책 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  회원 범주 설정
     * @return void 반환값 없음
     */
    private function policy(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
    }

    /**
     * 다른 회원이 쓰는 닉네임이면 닉네임만 빠지고 이름과 휴대폰은 반영된다.
     *
     * @scenario violation=duplicate
     *
     * @effects synced_nickname_skipped_on_policy_violation, other_fields_still_synced, skip_reason_recorded
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_nickname_is_skipped_and_other_fields_are_synced(): void
    {
        $this->policy(['nickname_unique' => true]);
        app(AuthService::class)->register(
            ['name' => '먼저 쓴 회원', 'nickname' => 'Kim', 'email' => $this->uniqueEmail('corp490.test')],
            ['issue_token' => false, 'record_consents' => false],
        );

        $idp = $this->makeIdp(['attribute_map' => [
            ['field' => 'name', 'source' => 'display_name', 'mode' => 'sync'],
            ['field' => 'nickname', 'source' => 'preferred_username', 'mode' => 'sync'],
            ['field' => 'mobile', 'source' => 'mobile', 'mode' => 'sync'],
        ]]);
        $user = User::factory()->create(['name' => '전', 'nickname' => 'old', 'mobile' => '010-000']);
        $account = $this->link($user, $idp, 'nick-1');
        $identity = new ExternalIdentity(subject: 'nick-1', displayName: '김직원', attributes: ['preferred_username' => 'ｋｉｍ', 'mobile' => '010-111']);

        $result = app(AttributeSyncService::class)->sync($user, $account, $idp, $identity, false);
        $user->refresh();

        $this->assertSame(['김직원', 'old', '010-111'], [$user->name, $user->nickname, $user->mobile]);
        $this->assertSame(['name', 'mobile'], $result['fields']);
        $this->assertSame(['nickname' => 'duplicate'], $result['skipped']);
        $this->assertNull(app(NicknameRejectionLedger::class)->pull((int) $user->id), '받아 둔 사유가 꺼낸 뒤에도 남아 있다');
    }

    /**
     * 금지어가 든 닉네임이면 닉네임만 빠진다. 정책에 걸리지 않는 닉네임은 그대로 반영된다.
     *
     * @scenario violation=forbidden_word
     *
     * @effects synced_nickname_skipped_on_policy_violation, skip_reason_recorded
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_nickname_is_skipped(): void
    {
        $this->policy(['nickname_banned_words' => ['관리자']]);
        $idp = $this->makeIdp(['attribute_map' => [
            ['field' => 'nickname', 'source' => 'preferred_username', 'mode' => 'sync'],
        ]]);
        $user = User::factory()->create(['nickname' => 'old']);
        $account = $this->link($user, $idp, 'nick-2');
        $sync = app(AttributeSyncService::class);

        $blocked = $sync->sync($user, $account, $idp, new ExternalIdentity(subject: 'nick-2', attributes: ['preferred_username' => '총관리자']), false);
        $this->assertSame('old', $user->fresh()->nickname);
        $this->assertSame([[], ['nickname' => 'forbidden_word']], [$blocked['fields'], $blocked['skipped']]);

        $passed = $sync->sync($user->fresh(), $account, $idp, new ExternalIdentity(subject: 'nick-2', attributes: ['preferred_username' => '직원']), false);
        $this->assertSame('직원', $user->fresh()->nickname);
        $this->assertSame([['nickname'], []], [$passed['fields'], $passed['skipped']]);
    }
}
