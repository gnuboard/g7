<?php

// update-schedule-row.yaml의 전 조합. UpdateScheduleRowTest가 모든 행을 검증합니다.
// [행, 사이트 시간대, 실행 요일, 행 cron(null=행 없음), 활성, 다음 실행(KST), 다음 실행(사이트), 시각 안내(사이트)]
return [
    /** @scenario schedule_row=missing, site_timezone=Asia/Seoul, run_days=weekdays */
    ['missing', 'Asia/Seoul', 'weekdays', null, true, '2026-09-14 11:30', '2026-09-14 11:30', '11:30'],
    /** @scenario schedule_row=missing, site_timezone=Asia/Seoul, run_days=empty */
    ['missing', 'Asia/Seoul', 'empty', null, true, null, null, '11:30'],
    /** @scenario schedule_row=missing, site_timezone=America/New_York, run_days=weekdays */
    ['missing', 'America/New_York', 'weekdays', null, true, '2026-09-14 11:30', '2026-09-13 22:30', '22:30'],
    /** @scenario schedule_row=missing, site_timezone=America/New_York, run_days=empty */
    ['missing', 'America/New_York', 'empty', null, true, null, null, '22:30'],
    /** @scenario schedule_row=default, site_timezone=Asia/Seoul, run_days=weekdays */
    ['default', 'Asia/Seoul', 'weekdays', '30 11 * * *', true, '2026-09-14 11:30', '2026-09-14 11:30', '11:30'],
    /** @scenario schedule_row=default, site_timezone=Asia/Seoul, run_days=empty */
    ['default', 'Asia/Seoul', 'empty', '30 11 * * *', true, null, null, '11:30'],
    /** @scenario schedule_row=default, site_timezone=America/New_York, run_days=weekdays */
    ['default', 'America/New_York', 'weekdays', '30 11 * * *', true, '2026-09-14 11:30', '2026-09-13 22:30', '22:30'],
    /** @scenario schedule_row=default, site_timezone=America/New_York, run_days=empty */
    ['default', 'America/New_York', 'empty', '30 11 * * *', true, null, null, '22:30'],
    /** @scenario schedule_row=operator_changed, site_timezone=Asia/Seoul, run_days=weekdays */
    ['operator_changed', 'Asia/Seoul', 'weekdays', '0 9 * * *', true, '2026-09-15 09:00', '2026-09-15 09:00', '09:00'],
    /** @scenario schedule_row=operator_changed, site_timezone=Asia/Seoul, run_days=empty */
    ['operator_changed', 'Asia/Seoul', 'empty', '0 9 * * *', true, null, null, '09:00'],
    /** @scenario schedule_row=operator_changed, site_timezone=America/New_York, run_days=weekdays */
    ['operator_changed', 'America/New_York', 'weekdays', '0 9 * * *', true, '2026-09-15 09:00', '2026-09-14 20:00', '20:00'],
    /** @scenario schedule_row=operator_changed, site_timezone=America/New_York, run_days=empty */
    ['operator_changed', 'America/New_York', 'empty', '0 9 * * *', true, null, null, '20:00'],
    /** @scenario schedule_row=inactive, site_timezone=Asia/Seoul, run_days=weekdays */
    ['inactive', 'Asia/Seoul', 'weekdays', '30 11 * * *', false, null, null, '11:30'],
    /** @scenario schedule_row=inactive, site_timezone=Asia/Seoul, run_days=empty */
    ['inactive', 'Asia/Seoul', 'empty', '30 11 * * *', false, null, null, '11:30'],
    /** @scenario schedule_row=inactive, site_timezone=America/New_York, run_days=weekdays */
    ['inactive', 'America/New_York', 'weekdays', '30 11 * * *', false, null, null, '22:30'],
    /** @scenario schedule_row=inactive, site_timezone=America/New_York, run_days=empty */
    ['inactive', 'America/New_York', 'empty', '30 11 * * *', false, null, null, '22:30'],
];
