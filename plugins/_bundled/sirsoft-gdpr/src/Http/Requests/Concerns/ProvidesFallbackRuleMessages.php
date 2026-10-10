<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;

/**
 * GDPR 요청의 공용 대체 검증 문구.
 *
 * 요청이 칸별 문구를 정하지 않은 규칙(in·required_with 등)에 받침과 무관하게 읽히는 플러그인 문구를
 * 규칙 이름 키로 더한다. 코어 7.1.0 의 한국어 기본 문구도 같은 이유로 괄호 조사(「이(가)」)를 걷어 냈으므로
 * 지금은 코어 문구와 거의 같은 말을 플러그인 lang 에서 한 번 더 정하는 셈이다(걷어낼지는 별도 작업에서 정한다).
 * 요청이 `messages()` 로 정한 칸별 문구(`source.in` 등)는 규칙 이름 키보다 먼저 찾으므로 그대로 이긴다.
 */
trait ProvidesFallbackRuleMessages
{
    /**
     * 공용 대체 문구를 더한 검증기를 만듭니다.
     *
     * @param  ValidationFactory  $factory  검증기 팩토리
     * @return Validator 검증기
     */
    protected function createDefaultValidator(ValidationFactory $factory)
    {
        $validator = parent::createDefaultValidator($factory);

        // 요청이 같은 규칙 이름 키를 이미 정했으면 그쪽을 둔다
        $validator->setCustomMessages(array_diff_key($this->fallbackRuleMessages(), $validator->customMessages));

        return $validator;
    }

    /**
     * 규칙 이름 => 플러그인 대체 문구.
     *
     * @return array<string, string> 규칙 이름 => 문구
     */
    protected function fallbackRuleMessages(): array
    {
        $messages = [];
        foreach (['in', 'not_in', 'enum', 'exists', 'unique', 'required_if', 'required_with', 'required_without', 'same', 'different'] as $rule) {
            $messages[$rule] = __("sirsoft-gdpr::messages.validation.fallback.{$rule}");
        }

        return $messages;
    }
}
