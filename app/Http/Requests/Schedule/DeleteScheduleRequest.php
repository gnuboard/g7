<?php

namespace App\Http\Requests\Schedule;

use App\Models\Schedule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 스케줄 단건 삭제 요청
 *
 * 코어·확장이 등록한 행은 선언 동기화가 다시 만들므로 지울 수 없다 — 실행을 멈추려면 비활성으로 끈다.
 * 일괄 삭제는 BulkDeleteScheduleRequest 가 같은 규칙을 건다.
 */
class DeleteScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 권한 검사는 라우트의 permission 미들웨어가 담당하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * 본문 입력이 없다 — 대상 행의 소유 여부만 withValidator 에서 판정한다.
     *
     * @return array<string, mixed> 빈 규칙
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 소유 행 삭제를 거부하는 검증을 덧붙입니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $schedule = $this->route('schedule');

            if ($schedule instanceof Schedule && $schedule->isOwned()) {
                $validator->errors()->add('general', __('schedule.owned_not_deletable'));
            }
        });
    }
}
