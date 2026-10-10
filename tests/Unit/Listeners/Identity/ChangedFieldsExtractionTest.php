<?php

namespace Tests\Unit\Listeners\Identity;

use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 훅 경로 본인인증 정책의 바뀐 항목 계산 규칙 테스트 (#736 D21)
 *
 * HTTP 경로는 입력 정리 미들웨어가 앞뒤 공백을 지우고, 회원 테이블에는 지금 배열 값 컬럼이 없어 HTTP 테스트만으로는
 * 정규화·배열 건너뛰기 규칙을 되돌려도 red 가 되지 않는다. 그래서 계산 함수를 직접 부른다 — 서비스·잡·확장이
 * 모델과 입력 배열을 넘기는 경로에서도 같은 판정이어야 한다.
 */
class ChangedFieldsExtractionTest extends TestCase
{
    /**
     * 보호 메서드를 그대로 노출하는 리스너를 만듭니다.
     *
     * @return EnforceIdentityPolicyListener 리스너
     */
    private function listener(): EnforceIdentityPolicyListener
    {
        return new class(app(\App\Services\IdentityPolicyService::class), app(\App\Contracts\Repositories\IdentityPolicyRepositoryInterface::class)) extends EnforceIdentityPolicyListener
        {
            /**
             * 바뀐 항목 계산을 노출합니다.
             *
             * @param  array<int, mixed>  $args  훅 인자
             * @return array<int, string> 바뀐 항목
             */
            public function changed(array $args): array
            {
                return $this->extractChangedFields($args);
            }
        };
    }

    /**
     * 저장된 원시 속성을 가진 회원 모델을 만듭니다(DB 없이).
     *
     * @param  array<string, mixed>  $attributes  원시 속성
     * @return User 회원
     */
    private function user(array $attributes): User
    {
        $user = new User;
        $user->setRawAttributes($attributes, true);

        return $user;
    }

    /**
     * 앞뒤 공백·빈 문자열(=null)·불리언 표현 차이는 바뀜이 아니고, 실제로 다른 값만 센다.
     *
     * @scenario entry_point=update_profile, changed=same_values|mobile
     *
     * @effects non_contact_changes_pass_without_verification, contact_change_requires_identity_verification
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function normalization_ignores_whitespace_empty_strings_and_boolean_forms(): void
    {
        $user = $this->user(['mobile' => '010-1111-2222', 'phone' => null, 'is_super' => 0, 'email' => 'a@example.com']);

        $this->assertSame([], $this->listener()->changed([$user, [
            'mobile' => ' 010-1111-2222 ',
            'phone' => '',
            'is_super' => false,
            'email' => 'a@example.com',
        ]]));

        $this->assertSame(['mobile'], $this->listener()->changed([$user, [
            'mobile' => '010-9999-8888',
            'phone' => '',
        ]]));
    }

    /**
     * 컬럼에 있는 키라도 배열 값은 비교하지 않고(문자열화 경고 → 500 방지), 컬럼에 없는 키는 보지 않는다.
     * 비밀번호는 비어 있지 않으면 바뀐 것으로 보고, 명시된 `changed_fields` 가 있으면 그것이 우선한다.
     *
     * @scenario entry_point=admin_update_user, changed=mobile|password
     *
     * @effects admins_skip_the_self_contact_policy, non_contact_changes_pass_without_verification
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function array_values_are_skipped_and_password_and_explicit_list_are_honoured(): void
    {
        $user = $this->user(['mobile' => '010', 'meta' => '{"a":1}', 'password' => 'hash']);

        $this->assertSame([], $this->listener()->changed([$user, [
            'meta' => ['a' => 2],
            'role_ids' => [1, 2],
            'password' => '',
        ]]));

        $this->assertSame(['password'], $this->listener()->changed([$user, ['password' => 'Passw0rd!new']]));

        $this->assertSame(['phone'], $this->listener()->changed([$user, ['mobile' => '999', 'changed_fields' => ['phone']]]));
    }
}
