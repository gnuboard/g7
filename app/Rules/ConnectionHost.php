<?php

namespace App\Rules;

use App\Support\OutboundUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 서버 연결 대상의 호스트 칸이 호스트명 또는 IP 인지 검증하는 Custom Rule.
 *
 * 관리자 환경설정의 호스트 칸(웹소켓·캐시 서버·메일 서버 등)은 서버가 그 값으로 주소를 조립해 연결한다.
 * 칸에 경로·쿼리·계정 정보가 섞이면 조립된 주소가 화면에 보인 것과 다른 곳을 가리킨다 — 예를 들어
 * `169.254.169.254/latest/meta-data/?` 뒤에 포트가 붙으면 포트가 쿼리로 밀려난다.
 *
 * 허용:
 * - 호스트명 (`localhost`, `redis`, `ws.example.com` — 연결 계층과 같은 규칙으로 정규화해 판정)
 * - IPv4 (`127.0.0.1`, `192.168.0.10` — 사설 대역은 정상 구성이라 막지 않는다)
 * - 대괄호로 감싼 IPv6 (`[::1]`)
 * - 소켓·TLS 허용 모드에서만: `/` 로 시작하는 유닉스 소켓 절대 경로, `tls://`·`rediss://` 접두,
 *   대괄호 없는 IPv6 (`::1`)
 *
 * 거부:
 * - 경로·쿼리·프래그먼트·계정 정보·역슬래시·퍼센트 인코딩·공백·제어문자, 대괄호 밖의 `:`(포트·맨 IPv6)
 * - 숫자만으로 된 최상위 라벨·`0x` 접두(10진·16진으로 감춘 IP)
 * - 링크로컬·클라우드 메타데이터 주소 (`OutboundUrlValidator::isMetadataHost()`)
 *
 * 빈 값은 통과시킨다 — 필수 여부는 FormRequest 의 다른 규칙이 정한다.
 *
 * @since 7.1.6
 */
class ConnectionHost implements ValidationRule
{
    /**
     * 소켓·TLS 허용 모드에서 받는 접두
     *
     * @var array<int, string>
     */
    private const TLS_PREFIXES = ['tls://', 'rediss://'];

    /**
     * ConnectionHost 생성자
     *
     * @param  bool  $allowSocketAndTls  유닉스 소켓 절대 경로·TLS 접두·대괄호 없는 IPv6 를 받을지 (캐시 서버 칸)
     */
    public function __construct(
        private readonly bool $allowSocketAndTls = false,
    ) {}

    /**
     * 값이 연결 대상 호스트로 쓸 수 있는 형태인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $violation = is_string($value) ? self::violation($value, $this->allowSocketAndTls) : 'format';

        if ($violation === 'blocked') {
            $fail(__('validation.settings.host_blocked'));
        } elseif ($violation === 'format') {
            $fail(__('validation.settings.host_format_invalid'));
        }
    }

    /**
     * 값이 규칙을 어기는 사유를 반환합니다.
     *
     * 요청 검증과 연결 실행 직전의 이중 확인이 같은 판정을 쓴다.
     *
     * @param  string  $value  호스트 칸 값
     * @param  bool  $allowSocketAndTls  유닉스 소켓 절대 경로와 TLS 접두를 받을지
     * @return string|null `format`(형식 오류) · `blocked`(허용되지 않는 주소) · null(통과)
     */
    public static function violation(string $value, bool $allowSocketAndTls = false): ?string
    {
        // 공백·제어문자는 어디에 있든 받지 않는다 (헤더·요청 분리 시도, 눈에 안 보이는 차이).
        if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            return 'format';
        }

        $host = $value;

        if ($allowSocketAndTls) {
            // 유닉스 소켓 절대 경로 — 네트워크 연결이 아니므로 주소 판정 대상이 아니다.
            if (str_starts_with($host, '/')) {
                // 점으로 시작하는 폴더(숨김 폴더)는 정상 경로다. 점만으로 된 조각(`.`·`..`)은 받지 않는다.
                return preg_match('#^(/(?!\.+(?:/|$))[A-Za-z0-9._\-]+)+$#', $host) === 1 ? null : 'format';
            }

            foreach (self::TLS_PREFIXES as $prefix) {
                if (stripos($host, $prefix) === 0) {
                    $host = substr($host, strlen($prefix));

                    break;
                }
            }

            if ($host === '') {
                return 'format';
            }

            // 대괄호 없는 IPv6 — 캐시 서버 연결 클라이언트가 쓰는 표기라 이 칸에서는 받는다.
            // 다른 칸에서는 포트 구분(`:`)과 섞이므로 아래 형식 검사에서 걸린다.
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return OutboundUrlValidator::isMetadataHost('['.$host.']') ? 'blocked' : null;
            }
        }

        // 대괄호로 감싼 IPv6
        if (str_starts_with($host, '[')) {
            if (! str_ends_with($host, ']')
                || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return 'format';
            }

            return OutboundUrlValidator::isMetadataHost($host) ? 'blocked' : null;
        }

        // 경로·쿼리·프래그먼트·계정 정보·역슬래시·퍼센트 인코딩·포트(맨 IPv6 포함)
        if (preg_match('#[/?\#@\\\\%:\[\]]#', $host) === 1) {
            return 'format';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return OutboundUrlValidator::isMetadataHost($host) ? 'blocked' : null;
        }

        $normalized = OutboundUrlValidator::normalizeHost($host);

        if ($normalized === null) {
            return 'format';
        }

        // 10진·8진·16진으로 감춘 IP — 호스트명의 최상위 라벨은 숫자만이거나 16진 표기(0x…)일 수 없다.
        // 맨 앞 라벨이 0x 로 시작하는 정상 도메인(0xcafe.example.com)은 막지 않도록 최상위 라벨만 본다.
        // 정규화하고서야 IP 가 되는 값(후행 점을 붙인 IP)도 같은 부류로 본다.
        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false || OutboundUrlValidator::isNumericDisguisedHost($normalized)) {
            return 'format';
        }

        return OutboundUrlValidator::isMetadataHost($normalized) ? 'blocked' : null;
    }
}
