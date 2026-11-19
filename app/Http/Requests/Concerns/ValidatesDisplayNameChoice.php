<?php

namespace App\Http\Requests\Concerns;

use App\DisplayName\DisplayNameResolver;
use App\DisplayName\NicknameRequirement;
use App\Models\User;
use App\Rules\DisplayNameKindChoice;

/**
 * 가입과 내 정보 수정 요청이 「표시 이름 종류 선택」 칸(`display_name_kind`)을 같은 규칙으로 받는 공통부.
 *
 * - `display_name_kind` 는 선택 칸이다. 보내지 않으면 지금 값을 유지하고, 빈 값(null)을 보내면 고른 종류를 지운다(회원 기본 종류로 보인다).
 * - 권한이 없거나 운영자가 허용하지 않은 종류면 검증 오류다({@see DisplayNameKindChoice}).
 * - 이 회원에게 적용될 종류가 닉네임이 필요한 종류이면 닉네임 칸을 필수로 바꾼다. 닉네임으로 보이기로 한 회원만 닉네임을 요구하기 위해서다.
 *
 * @since 7.2.0
 */
trait ValidatesDisplayNameChoice
{
    /**
     * 규칙에 표시 이름 종류 선택 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  지금까지의 규칙
     * @param  User|null  $user  고르는 회원 (가입이면 null)
     * @return array<string, mixed> 규칙
     */
    protected function withDisplayNameChoiceRules(array $rules, ?User $user): array
    {
        $rules['display_name_kind'] = ['sometimes', 'nullable', 'string', 'max:120', new DisplayNameKindChoice($user)];

        $requirement = app(NicknameRequirement::class);
        if ($requirement->level() !== NicknameRequirement::LEVEL_CHOSEN || ! array_key_exists('nickname', $rules)) {
            return $rules;
        }

        if ($requirement->kindRequiresNickname($this->displayNameKindAfterSave($user))) {
            $rules['nickname'] = $this->requireRule($rules['nickname']);
        }

        return $rules;
    }

    /**
     * 이 요청을 저장한 뒤 회원에게 적용될 종류 키를 반환합니다.
     *
     * @param  User|null  $user  회원 (가입이면 null)
     * @return string 종류 키 (보낸 값이 허용 목록에 없으면 회원 기본 종류)
     */
    private function displayNameKindAfterSave(?User $user): string
    {
        $subject = $user !== null ? clone $user : new User;
        if ($this->has('display_name_kind')) {
            $submitted = $this->input('display_name_kind');
            $subject->setAttribute('display_name_kind', is_string($submitted) && $submitted !== '' ? $submitted : null);
        }

        return app(DisplayNameResolver::class)->memberKind($subject);
    }

    /**
     * 규칙을 필수로 바꿉니다 (`nullable` 을 `required` 로).
     *
     * @param  mixed  $rule  규칙 (배열 또는 `|` 로 이은 문자열)
     * @return array<int, mixed> 필수 규칙
     */
    private function requireRule(mixed $rule): array
    {
        $rule = is_string($rule) ? explode('|', $rule) : (array) $rule;
        $rest = array_values(array_filter($rule, fn ($item) => ! in_array($item, ['nullable', 'required', 'sometimes'], true)));

        return ['required', ...$rest];
    }
}
