<?php

namespace App\Rules;

use App\Support\ScheduleCommandValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shell 타입 스케줄의 command 가 실행 허용된 명령인지 검증하는 Custom Rule.
 *
 * 스케줄 생성 권한만 위임받은 계정이 임의 OS 명령을 서버에서 실행하는 것을 저장 시점에 차단한다.
 * 허용 여부는 `config/schedule_security.php` 의 opt-in 게이트와 실행 파일 화이트리스트가 결정하며,
 * 관리자 환경설정에 노출되지 않으므로 위임 관리자가 스스로 범위를 넓힐 수 없다.
 */
class AllowedShellCommand implements ValidationRule
{
    /**
     * 값이 실행 허용된 shell 명령인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.schedule_command.shell_not_allowed'));

            return;
        }

        if (! ScheduleCommandValidator::isShellCommandAllowed($value)) {
            $fail(__('validation.schedule_command.shell_not_allowed'));
        }
    }
}
