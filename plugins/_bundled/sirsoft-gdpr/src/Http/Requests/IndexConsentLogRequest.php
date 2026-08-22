<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Gdpr\Enums\ConsentAction;
use Plugins\Sirsoft\Gdpr\Enums\ConsentSource;

/**
 * 관리자 동의 로그 목록 조회 요청을 검증합니다.
 *
 * 행위(`actions`)·출처(`sources`) 허용 어휘는 ConsentAction / ConsentSource enum 단일 출처에서
 * 파생합니다 — 여기에 리터럴을 다시 적으면 화면 필터가 실제 기록 어휘의 부분집합이 되어
 * 일부 행이 도달 불가해집니다(거부 이력이 그 형태로 조회 불가였습니다).
 */
class IndexConsentLogRequest extends FormRequest
{
    /** 기본 페이지 크기 */
    public const DEFAULT_PER_PAGE = 20;

    /** 페이지 크기 상한 */
    public const MAX_PER_PAGE = 100;

    /**
     * 배열로 받는 필터 키 목록.
     *
     * @var array<int, string>
     */
    private const ARRAY_FILTERS = ['consent_keys', 'actions', 'sources'];

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
     * 단일 값으로 도착한 배열 필터를 검증 전에 배열로 정규화합니다.
     *
     * `?sources[]=banner` 와 `?sources=banner` 를 모두 허용하기 위한 것으로, 정규화하지
     * 않으면 후자가 `array` 규칙에 걸려 목록 전체가 422 가 됩니다.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::ARRAY_FILTERS as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_array($value)) {
                continue;
            }

            $normalized[$key] = ($value === null || $value === '') ? [] : [(string) $value];
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
            'email' => ['nullable', 'string', 'max:255'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'consent_keys' => ['nullable', 'array'],
            'consent_keys.*' => ['string', 'max:100'],
            'actions' => ['nullable', 'array'],
            'actions.*' => ['string', Rule::in(ConsentAction::allValues())],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', Rule::in(ConsentSource::allValues())],
            // 상·하한은 규칙이 아니라 접근자에서 클램프한다 — 기존 컨트롤러가 범위를 벗어난
            // per_page 를 거부하지 않고 조정해 응답하던 계약을 유지한다.
            'per_page' => ['nullable', 'integer'],
        ];
    }

    /**
     * 목록 필터를 반환합니다.
     *
     * @return array<string, mixed> 필터 배열 (배열 필터는 항상 배열)
     */
    public function filters(): array
    {
        return [
            'email' => $this->validated('email'),
            'session_id' => $this->validated('session_id'),
            'consent_keys' => $this->arrayFilter('consent_keys'),
            'actions' => $this->arrayFilter('actions'),
            'sources' => $this->arrayFilter('sources'),
        ];
    }

    /**
     * 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기 (1 ~ 상한으로 클램프)
     */
    public function perPage(): int
    {
        $perPage = (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }

    /**
     * 배열 필터에서 빈 값을 제거해 반환합니다.
     *
     * @param  string  $key  필터 키
     * @return array<int, string> 정규화된 값 목록
     */
    private function arrayFilter(string $key): array
    {
        /** @var array<int, string> $values */
        $values = $this->validated($key) ?? [];

        return array_values(array_filter(
            array_map('strval', $values),
            fn (string $value) => $value !== ''
        ));
    }
}
