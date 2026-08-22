<?php

namespace App\Http\Requests\NotificationDefinition;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Rules\AvailableNotificationChannel;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationDefinitionRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // audit:allow formrequest-sometimes-array-min-inert reason: 채널 0개는 유효한 상태가 아니다.
            // 발송 채널을 모두 해제하면 알림이 사라지므로, 저장을 막고 오류를 노출하는 것이 올바른 동작이다.
            'channels' => ['sometimes', 'array', 'min:1'],
            // 허용 채널 목록은 config + filter_available_channels 훅으로 런타임 결정되므로
            // 요청 클래스에 하드코딩하지 않는다. 이미 저장된 채널은 통과시켜, 채널 제공
            // 플러그인이 비활성화된 뒤에도 기존 레코드 수정이 막히지 않게 한다.
            'channels.*' => ['string', 'max:50', new AvailableNotificationChannel($this->persistedChannels())],
            'hooks' => ['sometimes', 'array'],
            'hooks.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.notification_definition.update_validation_rules',
            $rules,
            $this->route('definition')
        );
    }

    /**
     * 수정 대상 알림 정의에 이미 저장되어 있는 채널 목록을 반환합니다.
     *
     * @return array<int, string> 저장된 채널 식별자 목록
     */
    private function persistedChannels(): array
    {
        $definition = $this->route('definition');

        if (! $definition instanceof NotificationDefinition) {
            $definition = is_numeric($definition)
                ? app(NotificationDefinitionRepositoryInterface::class)->findById((int) $definition)
                : null;
        }

        $channels = $definition?->channels;

        return is_array($channels) ? array_values(array_filter($channels, 'is_string')) : [];
    }
}
