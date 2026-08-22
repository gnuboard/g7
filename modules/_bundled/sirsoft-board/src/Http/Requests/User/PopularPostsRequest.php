<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 인기 게시글(조회수 기준) 조회 요청을 검증합니다.
 *
 * `period=all` 은 과거 링크/북마크 호환을 위해 계속 허용하며 `year` 로 해석됩니다.
 */
class PopularPostsRequest extends FormRequest
{
    /** 조회 상한 */
    public const MAX_LIMIT = 50;

    /** 기본 조회 개수 */
    public const DEFAULT_LIMIT = 20;

    /** 기본 기간 */
    public const DEFAULT_PERIOD = 'week';

    /**
     * 저장소가 실제로 해석하는 기간 어휘 (`all` 은 여기에 없는 하위 호환 별칭이다).
     *
     * @var array<int, string>
     */
    public const RESOLVED_PERIODS = ['today', 'week', 'month', 'year'];

    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
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
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        // 상한/어휘를 규칙으로 거부하지 않는 이유: 이 엔드포인트는 북마크 가능한 공개 URL 이고,
        // 기존 계약이 "상한 초과는 상한까지, 미지원 period 는 year 로 해석" 이다. 정규화는
        // 접근자가 담당한다 (period() 가 닫힌 집합만 반환하므로 캐시 키 공간도 함께 닫힌다).
        return [
            'period' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 조회 기간을 닫힌 집합 안의 값으로 반환합니다.
     *
     * 미지정은 기본 기간, `all` 은 하위 호환 별칭이라 `year`, 그 밖의 미지원 값도 `year` 로
     * 해석합니다 — 저장소가 미지원 period 를 1년 범위로 처리해 온 기존 동작과 같습니다.
     * 반환값을 닫아 두면 이 값을 그대로 쓰는 캐시 키가 임의 문자열로 늘어나지 않습니다.
     *
     * @return string 기간 키 (today|week|month|year)
     */
    public function period(): string
    {
        $period = $this->validated('period');

        if ($period === null || $period === '') {
            return self::DEFAULT_PERIOD;
        }

        return in_array((string) $period, self::RESOLVED_PERIODS, true)
            ? (string) $period
            : 'year';
    }

    /**
     * 조회할 게시글 개수를 반환합니다.
     *
     * @return int 조회 개수 (상한 초과 요청은 상한으로 클램프)
     */
    public function limit(): int
    {
        return min((int) $this->validated('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
    }
}
