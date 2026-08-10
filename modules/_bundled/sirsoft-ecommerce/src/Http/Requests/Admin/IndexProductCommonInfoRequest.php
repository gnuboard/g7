<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 공통정보 목록 조회 요청을 검증합니다.
 *
 * `per_page` 는 정수 외에 `all` 을 허용합니다 (전체 조회 — Select 옵션 채우기 용도).
 */
class IndexProductCommonInfoRequest extends FormRequest
{
    /** 기본 페이지 크기 */
    public const DEFAULT_PER_PAGE = 20;

    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 쿼리 문자열로 도착하는 불리언을 검증 전에 정규화합니다.
     *
     * 쿼리는 `?active_only=true` 형태의 문자열로 도착하는데 `boolean` 규칙은 `"true"`/`"false"`
     * 를 받지 않는다. 해석 가능한 값만 캐스팅하고, 해석 불가한 값은 그대로 두어 422 로 드러낸다.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['active_only', 'default_only'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'active_only' => ['nullable', 'boolean'],
            'default_only' => ['nullable', 'boolean'],
            // 정수 또는 `all`. 0 이하는 전체 조회로 해석된다.
            'per_page' => ['nullable', 'regex:/^(all|-?\d+)$/'],
        ];
    }

    /**
     * 목록 필터를 반환합니다 (미지정 필터는 키 자체가 빠집니다).
     *
     * @return array<string, mixed> 필터 배열
     */
    public function filters(): array
    {
        $filters = [
            'search' => $this->validated('search'),
            'is_active' => $this->boolean('active_only') ? true : null,
            'is_default' => $this->has('default_only') && $this->boolean('default_only') ? true : null,
        ];

        return array_filter($filters, fn ($value) => $value !== null);
    }

    /**
     * 전체 조회 여부를 반환합니다.
     *
     * @return bool `per_page=all` 이거나 0 이하이면 true
     */
    public function wantsAll(): bool
    {
        $perPage = $this->validated('per_page');

        return $perPage === 'all' || (int) ($perPage ?? self::DEFAULT_PER_PAGE) <= 0;
    }

    /**
     * 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
