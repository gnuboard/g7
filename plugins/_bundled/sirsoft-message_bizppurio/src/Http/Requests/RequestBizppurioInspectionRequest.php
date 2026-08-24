<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 알림톡 템플릿 검수 신청 요청 검증 (#597 §18.7 — 제품 결정 2026-08-23).
 *
 * 카카오 심사 가이드는 템플릿 변수마다 '예시 텍스트' 를 요구하지만 kapi `template/add` 에는
 * 예시 필드가 없다. 검수자에게 무언가를 전달할 유일한 통로가 `template/request` 의
 * comment(≤500)이므로 그 값을 선택 입력으로 받는다. 행에는 저장하지 않고 신청 호출에만 싣는다.
 */
class RequestBizppurioInspectionRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어(messaging.manage)에서 처리한다.
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * 검수자 전달 의견을 반환합니다 (앞뒤 공백 제거, 비어 있으면 null).
     *
     * @return string|null 전달 의견
     */
    public function comment(): ?string
    {
        $comment = trim((string) ($this->validated()['comment'] ?? ''));

        return $comment === '' ? null : $comment;
    }

    /**
     * 검증 오류 문구에 쓰일 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    public function attributes(): array
    {
        return [
            'comment' => __('sirsoft-message_bizppurio::messages.validation.attributes.comment'),
        ];
    }
}
