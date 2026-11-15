<?php

namespace App\Rules;

use App\Support\OutboundUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 서버 연결 대상의 주소(URL) 칸을 검증하는 Custom Rule.
 *
 * 관리자 환경설정의 주소 칸(S3 엔드포인트·공개 URL)에 쓴다. 형식 자체는 `url` 규칙이 보고, 이 규칙은 그 위에
 * - 호스트가 링크로컬·클라우드 메타데이터 주소가 아닌지,
 * - 계정 정보(`user:pass@`)가 섞이지 않았는지,
 * - (경로 불허 모드) 스킴·호스트·포트 뒤에 경로·쿼리·프래그먼트가 붙지 않았는지
 * 를 본다. 서버가 직접 연결하는 엔드포인트는 경로를 받지 않는다 — 경로가 붙으면 연결 테스트가 그 경로로
 * 요청을 보낸다. 파일 주소를 만드는 데만 쓰는 공개 URL(CDN 주소)은 경로를 받는다.
 *
 * localhost·사내 IP 는 정상 구성(자체 호스팅 객체 저장소)이라 막지 않는다. 빈 값은 통과시킨다.
 *
 * @since 7.1.6
 */
class ConnectionEndpointUrl implements ValidationRule
{
    /**
     * ConnectionEndpointUrl 생성자
     *
     * @param  bool  $allowPath  경로를 받을지 (공개 URL 칸)
     */
    public function __construct(
        private readonly bool $allowPath = false,
    ) {}

    /**
     * 값이 연결 대상 주소로 쓸 수 있는 형태인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts) || empty($parts['host'])) {
            // 형식 오류는 `url` 규칙이 알린다.
            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail(__('validation.settings.host_format_invalid'));

            return;
        }

        // 링크로컬·메타데이터 주소와, 숫자로 감춘 IP 표기(연결 계층이 IP 로 풀어 버린다).
        if (OutboundUrlValidator::isRefusedConnectionUrl(trim($value))) {
            $fail(__('validation.settings.host_blocked'));

            return;
        }

        if ($this->allowPath) {
            return;
        }

        $path = (string) ($parts['path'] ?? '');

        if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment'])) {
            $fail(__('validation.settings.endpoint_path_not_allowed'));
        }
    }
}
