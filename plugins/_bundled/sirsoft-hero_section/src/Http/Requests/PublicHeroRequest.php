<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Rules\RegisteredPosition;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 공개 노출 요청 — 위치 키(확장점 이름)와, 동적 위치면 화면이 아는 대상 값(`target` — 게시판 slug·상품 코드·카테고리 slug 또는 id).
 *
 * 등록되지 않은 위치는 422 다. 대상 값을 풀지 못하면(모르는 slug·지워진 대상) 422 가 아니라 빈 목록이다 — 사이트를 깨지 않는다(D3·D7).
 * 정적 위치에 보낸 대상 값은 쓰지 않는다.
 */
class PublicHeroRequest extends FormRequest
{
    /**
     * 누구나 부를 수 있는 공개 API 입니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $position = (string) $this->input('position');

        return [
            'position' => ['required', 'string', 'max:50', new RegisteredPosition],
            'target' => [Rule::requiredIf(fn () => app(PositionRegistry::class)->isDynamic($position)), 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'position' => __('sirsoft-hero_section::validation.attributes.position'),
            'target' => __('sirsoft-hero_section::validation.attributes.target'),
        ];
    }
}
