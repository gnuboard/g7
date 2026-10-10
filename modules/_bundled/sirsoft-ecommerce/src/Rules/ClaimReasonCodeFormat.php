<?php

namespace Modules\Sirsoft\Ecommerce\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 클레임 사유 코드 형식 검증
 *
 * 형식은 이 클래스가 단일 출처다. 종전에는 같은 `code` 필드에 설정 저장 경로와 상세 REST
 * 경로가 **서로 다른 정규식**을 썼다 — 설정 탭은 이중·말미 언더스코어를 거부하는데 상세
 * 경로는 허용했다. 느슨한 경로로 만든 코드가 한 건이라도 접수되면, 설정 탭은 저장할 때마다
 * 사유 카탈로그 전체를 되보내므로 **운영자가 손대지 않은 행 하나 때문에 클레임 탭이 영구히
 * 422** 가 됐다. 그리고 사용 중인 사유는 코드 변경이 잠겨(`codeLockedInUse`) 제품 안에
 * 복구 수단이 없었다.
 *
 * 그래서 형식은 엄격한 쪽으로 통일하되, **이미 저장된 값은 그대로 통과**시킨다. 규칙이
 * 좁혀지기 전에 저장된 코드가 규칙 밖에 놓여도 그 행이 실린 저장을 막지 않는다 — 형식은
 * 새로 만들거나 바꾸는 값에만 적용된다.
 */
class ClaimReasonCodeFormat implements ValidationRule
{
    /**
     * 허용 형식: 소문자로 시작, 소문자·숫자, 언더스코어는 구분자로 1개씩만.
     *
     * 이중 언더스코어(`a__b`)와 말미 언더스코어(`a_`)를 거부한다.
     */
    public const PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * @param  array<int, string>  $exemptCodes  형식 검사를 면제할 기존 저장 코드
     */
    public function __construct(private array $exemptCodes = []) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 타입·필수 여부는 형제 규칙(`string`/`required`)이 담당한다. 여기서 다시 판정하면
        // 같은 입력에 메시지가 두 번 붙는다.
        if (! is_string($value) || $value === '') {
            return;
        }

        if (in_array($value, $this->exemptCodes, true)) {
            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail('sirsoft-ecommerce::validation.claim_reason_code_format')->translate();
        }
    }
}
