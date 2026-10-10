<?php

namespace App\Listeners\Analytics;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\Analytics\ArchiveRunType;

/**
 * 방문자 통계 알림 데이터 필터 리스너
 *
 * 알림 정의가 본문에 쓴 `{visits}` 같은 자리를 실제 숫자로 바꿉니다. 이 리스너가
 * 없으면 메일 본문에 중괄호가 그대로 적힌 채로 발송됩니다.
 *
 * 수신자는 알림 정의의 `recipients` 가 정합니다 — 여기서는 값만 만듭니다.
 */
class AnalyticsNotificationDataListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 훅 이름 → 메서드/우선순위/타입 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.analytics.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다 (HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 없음 — 이 리스너는 필터로만 동작한다
     */
    public function handle(...$args): void {}

    /**
     * 알림 유형에 따라 데이터를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array} 추출 결과
     */
    public function extractData(array $default, string $type, array $args): array
    {
        $payload = is_array($args[0] ?? null) ? $args[0] : [];

        return match ($type) {
            'analytics_archive_failed' => $this->wrap($this->archiveFailed($payload)),
            'analytics_ledger_limit_exceeded' => $this->wrap($this->ledgerLimit($payload)),
            'analytics_visit_limit_exceeded' => $this->wrap($this->visitLimit($payload)),
            'analytics_weekly_report', 'analytics_monthly_report' => $this->wrap($this->report($payload)),
            default => $default,
        };
    }

    /**
     * 집계 실패 알림 값을 만듭니다.
     *
     * @param  array<string, mixed>  $payload  훅 인수
     * @return array<string, string> 치환 값
     */
    private function archiveFailed(array $payload): array
    {
        return [
            // 원시값(`day`)은 운영자가 읽는 말이 아니다 — 모르는 값이면 그대로 둔다.
            'run_type' => ArchiveRunType::tryFrom((string) ($payload['run_type'] ?? ''))?->label()
                ?? (string) ($payload['run_type'] ?? ''),
            'period' => (string) ($payload['period'] ?? ''),
            'error' => (string) ($payload['error'] ?? ''),
            'diagnostics_url' => $this->adminUrl('/admin/analytics/diagnostics'),
        ];
    }

    /**
     * 원장 한계 초과 알림 값을 만듭니다.
     *
     * @param  array<string, mixed>  $payload  훅 인수
     * @return array<string, string> 치환 값
     */
    private function ledgerLimit(array $payload): array
    {
        return [
            'visits' => (string) ($payload['visits'] ?? 0),
            'actions' => (string) ($payload['actions'] ?? 0),
            'threshold_visits' => (string) ($payload['threshold_visits'] ?? 0),
            'threshold_actions' => (string) ($payload['threshold_actions'] ?? 0),
            'retention_days' => (string) ($payload['retention_days'] ?? 0),
            'settings_url' => $this->adminUrl('/admin/analytics/settings?tab=retention'),
        ];
    }

    /**
     * 방문당 액션 한계 초과 알림 값을 만듭니다.
     *
     * @param  array<string, mixed>  $payload  훅 인수
     * @return array<string, string> 치환 값
     */
    private function visitLimit(array $payload): array
    {
        return [
            'visitor_key' => mb_substr((string) ($payload['visitor_key'] ?? ''), 0, 8),
            'actions' => (string) ($payload['actions'] ?? 0),
            'limit' => (string) ($payload['limit'] ?? 0),
            // 방문 행에 저장된 주소를 그대로 싣는다 — 수집 모드가 이미 가린 값이라 원래 주소보다
            // 많은 것을 드러내지 않는다. 알림 템플릿이 {ip_display} 를 쓸 때만 보인다.
            'ip_display' => (string) ($payload['ip_display'] ?? ''),
        ];
    }

    /**
     * 증감률을 부호와 단위를 붙인 문자열로 바꿉니다.
     *
     * @param  float  $value  증감률 (%)
     * @return string 예: `+12.5%` · `-3.0%` · `0.0%`
     */
    private static function signedPercent(float $value): string
    {
        $rounded = round($value, 1);

        return ($rounded > 0 ? '+' : '').number_format($rounded, 1, '.', '').'%';
    }

    /**
     * 정기 리포트 알림 값을 만듭니다.
     *
     * @param  array<string, mixed>  $payload  훅 인수
     * @return array<string, string> 치환 값
     */
    private function report(array $payload): array
    {
        $changePct = $payload['change_pct'] ?? null;

        return [
            'period_label' => (string) ($payload['period_label'] ?? ''),
            'visits' => (string) ($payload['visits'] ?? 0),
            'visitors' => (string) ($payload['visitors'] ?? 0),
            'pageviews' => (string) ($payload['pageviews'] ?? 0),
            // 직전 기간이 0 이면 비율이 성립하지 않는다 — 「무한 % 증가」 대신 줄표를 쓴다.
            // 값이 있으면 부호와 단위를 붙인다 — 「12.5」만으로는 늘었는지 줄었는지 알 수 없다.
            'change_pct' => $changePct === null ? '-' : self::signedPercent((float) $changePct),
            'top_pages' => $this->rowsAsHtml($payload['top_pages'] ?? []),
            'top_referrers' => $this->rowsAsHtml($payload['top_referrers'] ?? []),
            'report_url' => $this->adminUrl('/admin/analytics'),
        ];
    }

    /**
     * 상위 목록을 메일 본문에 넣을 표로 만듭니다.
     *
     * @param  mixed  $rows  상위 목록
     * @return string HTML 표 (비었으면 빈 문자열)
     */
    private function rowsAsHtml(mixed $rows): string
    {
        if (! is_array($rows) || $rows === []) {
            return '';
        }

        $cells = '';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells .= '<tr><td>'.e((string) ($row['label'] ?? ''))
                .'</td><td>'.e((string) ($row['visits'] ?? 0)).'</td></tr>';
        }

        return $cells === '' ? '' : '<table>'.$cells.'</table>';
    }

    /**
     * 관리자 화면 주소를 만듭니다.
     *
     * @param  string  $path  경로
     * @return string 전체 주소
     */
    private function adminUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * 치환 값에 공통 항목을 붙여 알림 체계가 기대하는 모양으로 감쌉니다.
     *
     * @param  array<string, string>  $data  치환 값
     * @return array{notifiable: null, notifiables: null, data: array<string, string>, context: array<string, mixed>} 추출 결과
     */
    private function wrap(array $data): array
    {
        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => array_merge([
                'app_name' => (string) config('app.name'),
                'name' => '{recipient_name}',
                'site_url' => (string) config('app.url'),
            ], $data),
            'context' => [],
        ];
    }
}
