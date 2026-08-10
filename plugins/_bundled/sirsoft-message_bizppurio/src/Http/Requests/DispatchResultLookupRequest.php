<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 코어 알림 발송 이력 결과 배치 조회 검증 (A-2 표시 주입).
 *
 * 코어 알림 발송 이력 화면이 현재 페이지의 코어 알림 로그 id 배열을 넘겨 비즈뿌리오 결과를 한 번에
 * 조회한다. 한 페이지(최대 100건) 분량으로 배열 크기를 제한해 과도한 조회를 막는다.
 */
class DispatchResultLookupRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어(messaging.view)에서 처리한다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 빈 배열 허용: 코어 알림 발송 이력이 0건(또는 로드 전)이면 화면이 빈 배열을 보낸다.
            // 이때 422 로 막으면 결과 컬럼 조회 자체가 에러가 되므로, present(키 존재)만 요구하고
            // 빈 배열은 통과시켜 빈 결과 맵을 돌려준다.
            'notification_log_ids' => ['present', 'array', 'max:100'],
            'notification_log_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * 검증된 코어 알림 로그 id 목록을 정수 배열로 반환합니다.
     *
     * @return array<int, int> 로그 id 목록
     */
    public function logIds(): array
    {
        return array_map('intval', $this->validated('notification_log_ids', []));
    }
}
