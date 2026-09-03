<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;

/**
 * 비즈뿌리오 알림 템플릿 DB 목록 조회 검증 (#597 §3.6 관리 화면).
 */
class BizppurioTemplateListRequest extends FormRequest
{
    /** 목록 기본 페이지 크기 */
    private const DEFAULT_PER_PAGE = 20;

    /** 목록 최대 페이지 크기 */
    private const MAX_PER_PAGE = 100;

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
            'status' => ['nullable', Rule::enum(BizppurioTemplateStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * 저장소 필터 배열을 반환합니다.
     *
     * @return array<string, mixed> status / search
     */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'search' => $this->validated('search'),
        ];
    }

    /**
     * 요청 페이지 번호를 반환합니다.
     *
     * @return int 페이지 번호 (기본 1)
     */
    public function page(): int
    {
        return max(1, (int) ($this->validated('page') ?? 1));
    }

    /**
     * 요청 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기 (기본 20, 최대 100)
     */
    public function perPage(): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE)));
    }

    /**
     * 검증 오류 문구에 쓰일 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    public function attributes(): array
    {
        $label = static fn (string $key): string => __("sirsoft-message_bizppurio::messages.validation.attributes.{$key}");

        return [
            'status' => $label('status'),
            'search' => $label('search'),
            'page' => $label('page'),
            'per_page' => $label('per_page'),
        ];
    }
}
