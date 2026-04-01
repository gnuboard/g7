<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * 쿨다운 검증 규칙
 *
 * 게시글 또는 댓글 작성 시 지정된 시간(초) 내 연속 작성을 제한합니다.
 * 회원은 user_id, 비회원은 IP 주소로 식별합니다.
 */
class CooldownRule implements ValidationRule
{
    /**
     * CooldownRule 생성자
     *
     * @param  string  $type  쿨다운 유형 ('post' 또는 'comment')
     * @param  int  $cooldownSeconds  쿨다운 시간(초), 0이면 비활성화
     * @param  string  $slug  게시판 슬러그
     */
    public function __construct(
        private string $type,
        private int $cooldownSeconds,
        private string $slug
    ) {}

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 쿨다운이 0이면 비활성화
        if ($this->cooldownSeconds <= 0) {
            return;
        }

        $identifier = Auth::id() ?? request()->ip();
        $cacheKey = "{$this->type}_cooldown_{$this->slug}_{$identifier}";

        if (Cache::has($cacheKey)) {
            $formattedTime = $this->formatDuration($this->cooldownSeconds);

            // 타입별 전용 메시지가 있으면 사용, 없으면 공통 메시지
            $typeKey = "sirsoft-board::validation.cooldown_required_{$this->type}";
            $typeMessage = __($typeKey, ['time' => $formattedTime]);

            $fail($typeMessage !== $typeKey
                ? $typeMessage
                : __('sirsoft-board::validation.cooldown_required', ['time' => $formattedTime])
            );
        }
    }

    /**
     * 초를 사람이 읽기 쉬운 시간 형식으로 변환합니다.
     *
     * @param  int  $seconds  초 단위 시간
     * @return string 변환된 시간 문자열
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds >= 3600) {
            $hours = intdiv($seconds, 3600);
            $remaining = $seconds % 3600;
            $minutes = intdiv($remaining, 60);

            if ($minutes > 0) {
                return __('sirsoft-board::validation.cooldown_duration.hours_minutes', [
                    'hours' => $hours,
                    'minutes' => $minutes,
                ]);
            }

            return __('sirsoft-board::validation.cooldown_duration.hours', [
                'hours' => $hours,
            ]);
        }

        if ($seconds >= 60) {
            $minutes = intdiv($seconds, 60);
            $remainingSeconds = $seconds % 60;

            if ($remainingSeconds > 0) {
                return __('sirsoft-board::validation.cooldown_duration.minutes_seconds', [
                    'minutes' => $minutes,
                    'seconds' => $remainingSeconds,
                ]);
            }

            return __('sirsoft-board::validation.cooldown_duration.minutes', [
                'minutes' => $minutes,
            ]);
        }

        return __('sirsoft-board::validation.cooldown_duration.seconds', [
            'seconds' => $seconds,
        ]);
    }
}
