<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Validation\ValidationException;

/**
 * 토스페이먼츠 플러그인 설정의 서버측 범위 검증.
 *
 * 설정 UI 의 input[max] · Select 옵션은 클라이언트 힌트일 뿐이라, 관리자 설정 저장 API 를
 * 직접 호출하면 범위 밖 값이 그대로 저장된다. 저장된 잘못된 값은 결제창 호출 시점에
 * 토스가 거부하므로(가상계좌 유효시간 초과 등) 저장 단계에서 차단한다.
 *
 * KG 의 ValidateCbtSettingsListener 와 동일하게 core.plugin_settings.before_save 훅을 구독한다.
 */
class ValidateTossSettingsListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /** 토스 가상계좌 유효시간 하한 (시간) */
    private const VBANK_HOURS_MIN = 1;

    /** 토스 가상계좌 유효시간 상한 (시간) — 90일 */
    private const VBANK_HOURS_MAX = 2160;

    /** 에스크로 사용 3-상태 (buyer_choice = 결제창에서 구매자가 선택) */
    private const USE_ESCROW_VALUES = ['off', 'on', 'buyer_choice'];

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.before_save' => [
                'method' => 'validateBeforeSave',
                'priority' => 10,
                // 저장을 차단하는 인라인 가드 — Action 훅 기본값(큐 디스패치)이면 ValidationException 이
                // 워커 안에서 죽고 PluginSettingsService::save() 가 저장을 그대로 진행한다.
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 설정 저장 전 범위 검증. 위반 시 ValidationException 으로 422 응답을 유도한다.
     *
     * 전송된 키만 검증한다 — 부분 저장(다른 섹션만 전송)에서 미포함 키를 강제하지 않는다.
     *
     * @param  string  $identifier  저장 대상 플러그인 식별자
     * @param  array<string, mixed>  $settings  저장 요청 설정값
     *
     * @throws ValidationException 범위를 벗어난 값이 있을 때
     */
    public function validateBeforeSave(string $identifier, array $settings): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER) {
            return;
        }

        $errors = [];

        if (array_key_exists('vbank_valid_hours', $settings)) {
            $hours = $settings['vbank_valid_hours'];

            if (! is_numeric($hours)
                || (int) $hours < self::VBANK_HOURS_MIN
                || (int) $hours > self::VBANK_HOURS_MAX
            ) {
                $errors['vbank_valid_hours'][] = __(
                    'sirsoft-tosspayments::messages.settings_validation.vbank_valid_hours_range',
                    ['min' => self::VBANK_HOURS_MIN, 'max' => self::VBANK_HOURS_MAX],
                );
            }
        }

        if (array_key_exists('use_escrow', $settings)
            && ! in_array((string) $settings['use_escrow'], self::USE_ESCROW_VALUES, true)
        ) {
            $errors['use_escrow'][] = __('sirsoft-tosspayments::messages.settings_validation.use_escrow_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
