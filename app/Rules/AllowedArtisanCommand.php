<?php

namespace App\Rules;

use App\Support\ScheduleCommandValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Artisan 타입 스케줄의 command 가 차단목록에 걸리지 않는지 검증하는 Custom Rule.
 *
 * `tinker` 처럼 임의 PHP 코드를 실행하거나 `db:wipe` 처럼 파괴적인 명령을 스케줄로 등록하는 것을
 * 저장 시점에 차단한다. 차단목록은 `config/schedule_security.php` 가 소유하며 관리자 환경설정에
 * 노출되지 않는다.
 */
class AllowedArtisanCommand implements ValidationRule
{
    /**
     * 값이 실행 허용된 artisan 명령인지 검증합니다.
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
            $fail(__('validation.schedule_command.artisan_denied'));

            return;
        }

        if (! ScheduleCommandValidator::isArtisanCommandAllowed($value)) {
            $fail(__('validation.schedule_command.artisan_denied'));
        }
    }
}
