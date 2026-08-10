<?php

namespace App\Rules;

use App\Services\NotificationChannelService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 알림 채널 식별자 검증 규칙
 *
 * 사용 가능한 채널 목록은 config('notification.default_channels') 에
 * `core.notification.filter_available_channels` 훅으로 모듈/플러그인이 추가한 채널을
 * 더해 런타임에 결정됩니다. 따라서 허용 목록을 요청 클래스에 하드코딩하면
 * 플러그인이 등록한 채널이 저장되지 않습니다.
 *
 * 이미 저장되어 있던 채널은 통과시킵니다. 채널을 제공하던 플러그인이 비활성화되면
 * 그 값이 목록 밖으로 나가는데, 이때 기존 레코드 수정 자체가 차단되면 안 되기 때문입니다.
 * (신규로 알 수 없는 채널을 밀어 넣는 것만 차단합니다)
 */
class AvailableNotificationChannel implements ValidationRule
{
    /**
     * AvailableNotificationChannel 생성자
     *
     * @param  array<int, string>  $persistedChannels  대상 레코드에 이미 저장된 채널 목록
     */
    public function __construct(
        private array $persistedChannels = []
    ) {}

    /**
     * 검증 규칙 실행
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('validation.notification_channel.invalid', ['value' => (string) $value]));

            return;
        }

        if (in_array($value, $this->persistedChannels, true)) {
            return;
        }

        if (! app(NotificationChannelService::class)->isChannelAvailable($value)) {
            $fail(__('validation.notification_channel.unavailable', ['value' => $value]));
        }
    }
}
