<?php

namespace App\Scheduling;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 웹 크론 호출 정책 — 운영자 설정의 단일 해석 지점.
 *
 * 설정 키를 읽는 곳이 여럿이면 기본값과 경계값이 조용히 갈라진다. 엔드포인트·미들웨어·
 * 관리자 화면 응답이 전부 이 클래스를 지난다.
 *
 * 실행 방식(`system.cron_mode`)의 뜻:
 *
 * - `auto`   — 실행 모드 판정에 동조한다. CLI 를 쓸 수 없는 환경이면 주소 호출을 열고,
 *              쓸 수 있으면 서버 크론을 전제로 주소 호출도 함께 연다(운영자가 둘 중
 *              무엇을 걸었는지 서버는 알 수 없으므로 막지 않는다).
 * - `web`    — 주소 호출만.
 * - `cron`   — 서버 크론만. 주소 호출은 인증되더라도 실행하지 않는다.
 * - `both`   — 둘 다.
 *
 * @since 7.1.0
 */
class WebCronPolicy
{
    /** 실행 방식 — 자동 (실행 모드 판정 연동). */
    public const MODE_AUTO = 'auto';

    /** 실행 방식 — 주소 호출만. */
    public const MODE_WEB = 'web';

    /** 실행 방식 — 서버 크론만. */
    public const MODE_CRON = 'cron';

    /** 실행 방식 — 둘 다. */
    public const MODE_BOTH = 'both';

    /**
     * 허용되는 실행 방식 목록 (허용값 표가 소비).
     *
     * @return list<string> 실행 방식 값 목록
     */
    public static function modes(): array
    {
        return [self::MODE_AUTO, self::MODE_WEB, self::MODE_CRON, self::MODE_BOTH];
    }

    /**
     * 저장된 실행 방식을 반환합니다 (미지원 값은 auto 폴백).
     *
     * @return string 실행 방식
     */
    public function mode(): string
    {
        $mode = (string) g7_core_settings('system.cron_mode', self::MODE_AUTO);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_AUTO;
    }

    /**
     * 주소 호출이 실행으로 이어지는 설정인지 판정합니다.
     *
     * @return bool 허용 여부
     */
    public function webCallAllowed(): bool
    {
        return in_array($this->mode(), [self::MODE_AUTO, self::MODE_WEB, self::MODE_BOTH], true);
    }

    /**
     * 최소 호출 간격(분).
     *
     * 간격 미만 호출은 실행 없이 정상 응답한다 — 호출 **횟수** 제한이지 실행 누락
     * 사유가 아니다. 다음 유효 호출의 소급 창이 그 사이 시각을 덮는다 (D17).
     *
     * @return int 분 (0 이면 제한 없음)
     */
    public function minIntervalMinutes(): int
    {
        $configured = g7_core_settings('system.cron_min_interval', null);

        $value = is_numeric($configured)
            ? (int) $configured
            : (int) config('core.cron.min_interval_minutes', 5);

        return max(0, min($value, (int) config('core.settings_limits.system_cron_min_interval_max', 1440)));
    }

    /**
     * 허용 IP 목록 (비우면 제한 없음).
     *
     * @return list<string> IP 또는 CIDR 표기 목록
     */
    public function allowedIps(): array
    {
        $raw = (string) g7_core_settings('system.cron_allowed_ips', '');

        if (trim($raw) === '') {
            return [];
        }

        // 줄바꿈·쉼표 어느 쪽으로 적어도 같게 읽는다. `\R` 은 UTF-8 모드가 아니면
        // 바이트 단위로 판정해 한글 바이트를 개행으로 오인하므로 쓰지 않는다.
        $parts = preg_split('~\r\n|\r|\n|,~', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($ip) => $ip !== ''));
    }

    /**
     * 호출자 IP 가 허용 목록에 드는지 판정합니다.
     *
     * @param  string|null  $ip  호출자 IP
     * @return bool 허용 여부 (목록이 비어 있으면 언제나 true)
     */
    public function ipAllowed(?string $ip): bool
    {
        $allowed = $this->allowedIps();

        if ($allowed === []) {
            return true;
        }

        if (! is_string($ip) || $ip === '') {
            return false;
        }

        // IpUtils 가 IPv4/IPv6 와 CIDR 표기를 함께 판정한다 — 접두사 문자열 비교로
        // 대신하면 `10.1.1.1` 규칙이 `10.1.1.10` 까지 통과시킨다.
        return IpUtils::checkIp($ip, $allowed);
    }

    /**
     * 방문 트리거 폴백이 켜져 있는지 판정합니다 (D5 — 기본 꺼짐).
     *
     * @return bool 사용 여부
     */
    public function visitFallbackEnabled(): bool
    {
        return (bool) g7_core_settings('system.cron_visit_fallback', false);
    }
}
