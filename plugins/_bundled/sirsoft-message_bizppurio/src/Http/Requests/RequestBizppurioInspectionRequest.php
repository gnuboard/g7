<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\Concerns\ValidatesTemplateContent;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTemplateService;

/**
 * 알림톡 템플릿 검수 신청 요청 검증 (#597 §18.7 — 제품 결정 2026-08-23).
 *
 * 카카오 심사 가이드는 템플릿 변수마다 '예시 텍스트' 를 요구하지만 kapi `template/add` 에는
 * 예시 필드가 없다. 검수자에게 무언가를 전달할 유일한 통로가 `template/request` 의
 * comment(≤500)이므로 그 값을 선택 입력으로 받는다. 행에는 저장하지 않고 신청 호출에만 싣는다.
 *
 * 「저장」(임시 저장)은 쓰다 만 내용도 초안으로 남기므로, 저장된 content 가 등록 요건을 갖췄는지는
 * 여기서 확인한다 — 작성·수정 저장과 같은 전체 규칙을 저장된 content 에 걸어 카카오 호출 전에
 * 항목별 422 로 알린다. content 가 아예 없는 행은 종전대로 서비스의 상태 가드가 막는다.
 */
class RequestBizppurioInspectionRequest extends FormRequest
{
    use ValidatesTemplateContent;

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
     * 검수 신청은 언제나 전체 규칙으로 검증합니다 — 본문에 draft 를 실어도 완화되지 않습니다.
     *
     * @return bool 항상 false
     */
    private function isDraftSave(): bool
    {
        return false;
    }

    /**
     * 신청 대상 행의 저장된 content 를 검증 입력으로 싣습니다.
     *
     * 요청 본문의 content 는 받지 않는다 — 카카오에 가는 것은 저장된 content 이므로 그것을 검증한다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $template = app(BizppurioTemplateService::class)->find((int) $this->route('id'));
        $content = $template?->content;

        $this->merge(['content' => is_array($content) && $content !== [] ? $content : null]);
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], $this->contentRules());
    }

    /**
     * 항목별 조건부 필수(linkType 별 링크 필드 등)를 after 훅으로 검증합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateContentConditionals($v));
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
        return array_merge([
            'comment' => __('sirsoft-message_bizppurio::messages.validation.attributes.comment'),
        ], $this->contentAttributes());
    }
}
