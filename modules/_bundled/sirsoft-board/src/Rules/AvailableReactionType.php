<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 반응 유형 검증 규칙
 *
 * `reaction_type_id` 가 존재하는 활성 유형이면서 대상 게시판이 켠(활성) 유형인지
 * 판정합니다. 게시판이 켠 유형 목록은 DB(`boards.active_reaction_types`, code 배열)에
 * 저장되므로 허용 목록을 요청 클래스에 하드코딩하지 않습니다
 * (`AvailableNotificationChannel` 과 동일한 서비스 기반 검증 구조).
 */
class AvailableReactionType implements ValidationRule
{
    /**
     * @param  array<int, string>  $activeCodes  대상 게시판이 켠 반응 유형 code 목록
     */
    public function __construct(
        private array $activeCodes = []
    ) {}

    /**
     * 검증 규칙 실행
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값 (reaction_type_id)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail(__('sirsoft-board::messages.reaction.inactive_type'));

            return;
        }

        $type = app(ReactionTypeRepositoryInterface::class)->findById((int) $value);

        if ($type === null
            || ! $type->is_active
            || ! in_array($type->code, $this->activeCodes, true)) {
            $fail(__('sirsoft-board::messages.reaction.inactive_type'));
        }
    }
}
