<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 알림톡 템플릿 목록 실시간 조회 검증 (Phase 5).
 *
 * 목록은 카카오 관리 API(kapi) 위임 조회이므로 값 해석은 kapi 가 담당한다. 여기서는
 * 형태(문자열/정수)와 상한만 확인해 비정상 형태(배열 주입 등)의 전달을 차단한다.
 * status 값 어휘는 kapi 의 templateStatus 정의를 따르므로 서버에서 닫힌 집합으로
 * 좁히지 않는다(kapi 스펙 변경 시 화면만 갱신하면 되도록).
 */
class AlimtalkTemplateListRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'max:30'],
            'keyword' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'count' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * 서비스 list() 에 전달할 필터 배열을 반환합니다.
     *
     * @return array<string, mixed> status·keyword·page·count (미전달 키는 null)
     */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'keyword' => $this->validated('keyword'),
            'page' => $this->validated('page'),
            'count' => $this->validated('count'),
        ];
    }
}
