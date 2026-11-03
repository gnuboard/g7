<?php

namespace App\Http\Requests\System;

use App\Extension\HookManager;
use App\Scheduling\WebCronTokenStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 웹 크론 호출 요청 (A-5).
 *
 * 이 주소는 인증 없이 도달할 수 있는 실행 표면이라, "요청에서 무엇을 읽는가" 를 요청
 * 클래스가 소유한다 — 호출 번호를 어디에서 꺼내는지가 컨트롤러마다 흩어지면 통로가
 * 하나 늘 때 그 통로만 다른 규칙을 갖는다.
 *
 * **번호의 유효성은 여기서 판정하지 않는다.** 검증 규칙으로 막으면 실패가 422 가 되는데,
 * 번호 불일치는 검증 오류가 아니라 **인증 실패(401)** 다. 형태만 제한하고 대조는
 * 컨트롤러가 상수 시간 비교로 수행한다.
 *
 * @since 7.1.0
 */
class WebCronRunRequest extends FormRequest
{
    /**
     * @var int 받아들이는 호출 번호의 최대 길이 (바이트)
     *
     * 발급되는 번호보다 넉넉하되, 비교 비용을 키우려는 호출을 흡수할 만큼은 좁다.
     * 이 상한을 **검증 규칙이 아니라** `presentedToken()` 이 쓰는 이유는 위 주석과 같다 —
     * 상한 초과는 422 가 아니라 401 로 수렴해야 한다.
     */
    public const MAX_TOKEN_LENGTH = 512;

    /**
     * 요청 권한을 판정합니다.
     *
     * @return bool 이 엔드포인트는 토큰으로 인증하며 그 판정은 컨트롤러가 수행하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * 번호에는 **어떤 형식 규칙도 걸지 않는다.** 이 면의 계약은 「인증 실패만 401/403」
     * 이라 형식 위반이 검증 오류(422)로 나가면 그 계약이 깨진다 — 외부 크론 서비스는
     * 401 과 422 를 다르게 다루고, 무엇보다 「번호가 틀렸다」는 사실이 두 가지 다른
     * 응답으로 갈린다. 길이 상한과 형태(배열로 실린 `?token[]=…` 등)는 검증이 아니라
     * `presentedToken()` 이 흡수한다 — 문자열이 아니면 없는 것으로 다뤄 401 로 수렴한다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'token' => ['nullable'],
        ];

        // 확장이 이 호출에 자기 파라미터를 더할 수 있게 둔다 — 외부 크론 서비스가
        // 실어 보내는 서명·식별자처럼, 코어가 미리 알 수 없는 값이 있다.
        return HookManager::applyFilters('core.web_cron.run_validation_rules', $rules, $this);
    }

    /**
     * 요청에서 호출 번호를 꺼냅니다 (헤더 우선, 없으면 쿼리스트링).
     *
     * 둘 다 받는 이유는 외부 크론 서비스 중 헤더를 설정할 수 없는 곳이 많기 때문이다.
     * 설정할 수 있는 곳은 접근 로그에 남지 않는 헤더를 쓰는 편이 낫다.
     *
     * 상한을 넘는 값은 **없는 것으로 다룬다** — 비교 비용을 키우는 호출을 막으면서도
     * 결과는 「번호가 맞지 않는다」(401)로 수렴시킨다. 검증 규칙으로 막으면 같은 사실이
     * 422 로 나가 이 면의 응답 계약이 갈린다.
     *
     * @return string|null 제시된 번호 (없거나 상한 초과면 null)
     */
    public function presentedToken(): ?string
    {
        $header = $this->header(WebCronTokenStore::TOKEN_HEADER);

        if (is_string($header) && $header !== '') {
            return $this->withinLengthCap($header);
        }

        $query = $this->query('token');

        return (is_string($query) && $query !== '') ? $this->withinLengthCap($query) : null;
    }

    /**
     * 상한 이내의 번호만 돌려줍니다.
     *
     * @param  string  $token  제시된 번호
     * @return string|null 상한 이내면 그대로, 넘으면 null
     */
    private function withinLengthCap(string $token): ?string
    {
        return strlen($token) <= self::MAX_TOKEN_LENGTH ? $token : null;
    }
}
