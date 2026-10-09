<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;

/**
 * 게시판 요청의 공용 대체 검증 문구와 칸 이름.
 *
 * 요청이 칸별 문구를 정하지 않은 규칙(in·exists·required_with 등)은 코어 기본 문구로 떨어지는데,
 * 코어 한국어 문구는 「선택한 :attribute이(가) 올바르지 않습니다.」처럼 받침을 모르는 자리에 조사를
 * 괄호로 남긴다. 이 트레이트는 그런 규칙에 받침과 무관하게 읽히는 게시판 문구
 * (「선택한 :attribute 값이 올바르지 않습니다.」)를 규칙 이름 키로 더한다. 요청이 `messages()` 로
 * 정한 칸별 문구(`status.in` 등)는 규칙 이름 키보다 먼저 찾으므로 그대로 이긴다.
 *
 * 대체 문구의 :attribute 자리도 요청이 `attributes()` 로 이름을 주지 않은 칸은 필드 키
 * (「선택한 sort 값이…」)로 보였다. 그래서 게시판 공용 칸 이름(`validation.fallback_attributes`)을
 * 함께 더하고, 배열 항목(`ids.*`)은 이름이 있는 부모 칸(`ids`)의 이름을 물려받게 한다.
 * 요청이 정한 칸 이름이 언제나 먼저다.
 */
trait ProvidesFallbackRuleMessages
{
    /**
     * 공용 대체 문구와 칸 이름을 더한 검증기를 만듭니다.
     *
     * @param  ValidationFactory  $factory  검증기 팩토리
     * @return Validator 검증기
     */
    protected function createDefaultValidator(ValidationFactory $factory)
    {
        $validator = parent::createDefaultValidator($factory);

        // 요청이 같은 규칙 이름 키를 이미 정했으면 그쪽을 둔다
        $validator->setCustomMessages(array_diff_key($this->fallbackRuleMessages(), $validator->customMessages));

        // 칸 이름도 요청이 정한 것이 먼저다
        $validator->addCustomAttributes(array_diff_key(
            $this->fallbackAttributeNames($validator->customAttributes),
            $validator->customAttributes
        ));

        return $validator;
    }

    /**
     * 규칙 이름 => 게시판 대체 문구.
     *
     * @return array<string, string> 규칙 이름 => 문구
     */
    protected function fallbackRuleMessages(): array
    {
        $messages = [];
        foreach (['in', 'not_in', 'enum', 'exists', 'unique', 'required_if', 'required_with', 'required_without', 'same', 'different'] as $rule) {
            $messages[$rule] = __("sirsoft-board::validation.fallback.{$rule}");
        }

        return $messages;
    }

    /**
     * 칸 키 => 게시판 공용 칸 이름 (배열 항목 `키.*` 는 부모 칸 이름을 물려받는다).
     *
     * @param  array<string, string>  $requestAttributes  요청이 정한 칸 이름
     * @return array<string, string> 칸 키 => 칸 이름
     */
    protected function fallbackAttributeNames(array $requestAttributes): array
    {
        $common = __('sirsoft-board::validation.fallback_attributes');
        $names = $requestAttributes + (is_array($common) ? $common : []);

        $items = [];
        foreach ($names as $key => $name) {
            if (is_string($name) && ! isset($names["{$key}.*"])) {
                $items["{$key}.*"] = $name;
            }
        }

        return $names + $items;
    }
}
