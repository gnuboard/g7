<?php

namespace App\Rules;

use App\Support\ScheduleCommandValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Artisan 타입 스케줄의 command 가 실행 허용 목록 안인지 검증하는 Custom Rule.
 *
 * 허용목록에 없는 명령, 선언되지 않은 옵션, 위치 인자, 그리고 Symfony 가 다르게 해석할 수 있는
 * 형태(따옴표·백슬래시·단축 옵션)를 저장 시점에 차단한다. 정책은 `config/schedule_security.php`
 * 가 소유하며 관리자 환경설정에 노출되지 않는다.
 *
 * 어느 항목이 왜 막혔는지 드러나도록 거부 사유별로 메시지를 분기한다.
 */
class AllowedArtisanCommand implements ValidationRule
{
    /** 거부 사유 코드 → 다국어 키 */
    private const REASON_MESSAGES = [
        ScheduleCommandValidator::ARTISAN_REASON_DENIED => 'validation.schedule_command.artisan_denied',
        ScheduleCommandValidator::ARTISAN_REASON_NOT_ALLOWED => 'validation.schedule_command.artisan_not_allowlisted',
        ScheduleCommandValidator::ARTISAN_REASON_MALFORMED => 'validation.schedule_command.artisan_malformed',
        ScheduleCommandValidator::ARTISAN_REASON_OPTION => 'validation.schedule_command.artisan_option_denied',
        ScheduleCommandValidator::ARTISAN_REASON_ARGUMENT => 'validation.schedule_command.artisan_argument_denied',
    ];

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
            $fail(__('validation.schedule_command.artisan_malformed'));

            return;
        }

        $verdict = ScheduleCommandValidator::inspectArtisanCommand($value);

        if ($verdict['allowed']) {
            return;
        }

        $key = self::REASON_MESSAGES[$verdict['reason']] ?? 'validation.schedule_command.artisan_not_allowlisted';

        $fail(__($key));
    }
}
