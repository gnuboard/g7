<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Rules\DailyReportLimitRule;
use Modules\Sirsoft\Board\Rules\RejectionLimitRule;
use Modules\Sirsoft\Board\Services\BoardSettingsService;

/**
 * 사용자 신고 생성 요청 폼 검증
 */
class StoreReportRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason_type' => ['required', 'string', Rule::in(ReportReasonType::values())],
            'reason_detail' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }

    /**
     * 검증기에 추가 규칙을 등록합니다.
     *
     * 신고 남발 방지: 일일 횟수 제한, 반려 누적 제한, 연속 신고 쿨타임
     *
     * @param  \Illuminate\Validation\Validator  $validator  검증기 인스턴스
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        if (! Auth::check()) {
            return;
        }

        $userId = (int) Auth::id();
        $settings = app(BoardSettingsService::class);
        $policy = $settings->getSettings('report_policy');
        $security = $settings->getSettings('spam_security');
        $repository = app(ReportRepositoryInterface::class);

        $rules = [];

        // 일일 신고 횟수 제한
        $dailyLimit = (int) ($policy['daily_report_limit'] ?? 10);
        $rules[] = new DailyReportLimitRule($repository, $userId, $dailyLimit);

        // 반려 누적 제한
        $rejectionCount = (int) ($policy['rejection_limit_count'] ?? 5);
        $rejectionDays = (int) ($policy['rejection_limit_days'] ?? 30);
        $rules[] = new RejectionLimitRule($repository, $userId, $rejectionCount, $rejectionDays);

        $validator->addRules(['reason_type' => $rules]);

        // 연속 신고 쿨타임 (기존 CooldownRule 재사용)
        $cooldown = (int) ($security['report_cooldown_seconds'] ?? 60);
        if ($cooldown > 0) {
            $slug = $this->route('slug') ?? '';
            $validator->addRules([
                'reason_type' => [new CooldownRule('report', $cooldown, $slug)],
            ]);
        }
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason_type.required' => __('sirsoft-board::validation.report.reason_type.required'),
            'reason_type.in' => __('sirsoft-board::validation.report.reason_type.in'),
            'reason_detail.required' => __('sirsoft-board::validation.report.reason_detail.required'),
            'reason_detail.min' => __('sirsoft-board::validation.report.reason_detail.min'),
            'reason_detail.max' => __('sirsoft-board::validation.report.reason_detail.max'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason_type' => __('sirsoft-board::attributes.report.reason_type'),
            'reason_detail' => __('sirsoft-board::attributes.report.reason_detail'),
        ];
    }
}
