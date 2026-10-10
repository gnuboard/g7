<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 개인정보 조치 직권 실행 상태 조회 요청
 *
 * 설정 화면이 자기 카드의 대상 키를 쉼표로 이어 보낸다
 * (`?targets=retention:user-withdrawn,dormant:convert`). 진행 중 작업을 지켜보는 동안에는 직전 응답의
 * `checked_at` 을 `since` 로 함께 보낸다.
 */
class PrivacyManualRunStatusRequest extends FormRequest
{
    /** 대상 목록 문자열 최대 길이 */
    public const MAX_TARGETS_LENGTH = 2000;

    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (대상별 실행 권한은 서비스가 가려 응답한다)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'targets' => ['required', 'string', 'max:'.self::MAX_TARGETS_LENGTH],
            'since' => ['nullable', 'date'],
        ];

        return HookManager::applyFilters('core.privacy.manual_run_status_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'targets.required' => __('validation.privacy_manual_run.targets.required'),
            'targets.string' => __('validation.privacy_manual_run.targets.string'),
            'targets.max' => __('validation.privacy_manual_run.targets.max', ['max' => self::MAX_TARGETS_LENGTH]),
            'since.date' => __('validation.privacy_manual_run.since.date'),
        ];
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'targets' => __('validation.attributes.targets'),
            'since' => __('validation.attributes.since'),
        ];
    }

    /**
     * 「이 시각 이후에 끝난 실행이 있는가」 판정 기준 시각을 반환합니다.
     *
     * 화면이 직전 응답의 `checked_at` 을 그대로 돌려보낸다 — 진행 중이던 작업이 끝났는지를
     * 응답만으로 판정하게 해, 화면이 자기 전역 상태의 낡은 값에 기대지 않게 한다.
     *
     * @return Carbon|null 기준 시각 (없으면 null)
     */
    public function since(): ?Carbon
    {
        $since = $this->validated('since');

        return $since ? Carbon::parse($since) : null;
    }

    /**
     * 요청한 대상 키 목록을 반환합니다.
     *
     * @return array<int, string> 대상 키 목록 (빈 조각 제거)
     */
    public function targets(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->validated('targets')),
        ), fn (string $key) => $key !== ''));
    }
}
