<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Schedules;

/**
 * 스케줄 — 주기 점검, 퇴사 후 탈퇴 영역.
 *
 * `Bootstrap\Schedules::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Provisioning
{
    /**
     * 이 영역의 스케줄 목록을 반환합니다.
     *
     * 주기 점검은 5분마다 돌지만 IdP 마다 설정한 간격(`periodic_minutes`)이 지난 연결만 본다. 주기 점검, 탈퇴를 켠 IdP 가 없으면
     * 명령이 아무 일도 하지 않고 끝난다.
     *
     * @return array<int, array<string, mixed>> 스케줄 정의
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'deprovision-check',
                'type' => 'artisan',
                'label' => ['ko' => 'SSO 주기 점검', 'en' => 'SSO periodic deprovisioning check'],
                'description' => [
                    'ko' => 'IdP 에 재직 여부를 다시 물어 퇴사한 회원을 차단하고 복귀한 회원을 다시 켭니다(주기 점검을 켠 IdP 만).',
                    'en' => 'Asks each IdP again whether members are still employed, blocks those who left and reactivates those who returned (only IdPs with periodic checks on).',
                ],
                'command' => 'sirsoft-sso:deprovision-check',
                'frequency' => 'custom',
                'expression' => '*/5 * * * *',
                'without_overlapping' => 30,
                'history_mode' => 'failures_only',
            ],
            [
                'name' => 'withdraw-deprovisioned',
                'type' => 'artisan',
                'label' => ['ko' => 'SSO 퇴사 후 탈퇴', 'en' => 'SSO withdraw after deprovisioning'],
                'description' => [
                    'ko' => '퇴사 처리 뒤 정한 날이 지난 회원을 탈퇴(익명화)시킵니다(퇴사 후 탈퇴를 켠 IdP 만, 관리자 계정은 건너뜀).',
                    'en' => 'Withdraws (anonymizes) members whose deprovisioning is older than the configured days (only IdPs with it on; administrator accounts are skipped).',
                ],
                'command' => 'sirsoft-sso:withdraw-deprovisioned',
                'frequency' => 'daily',
                'expression' => '10 4 * * *',
            ],
        ];
    }
}
