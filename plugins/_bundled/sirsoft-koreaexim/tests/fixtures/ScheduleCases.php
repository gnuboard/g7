<?php

// schedule-and-settings.yaml의 전 조합. PluginScheduleTest가 모든 행을 검증합니다.
return [
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=set */
    ['Asia/Seoul', 'weekdays', 'on', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['Asia/Seoul', 'weekdays', 'on', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['Asia/Seoul', 'weekdays', 'on', 'masked'],
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=set */
    ['Asia/Seoul', 'weekdays', 'off', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['Asia/Seoul', 'weekdays', 'off', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['Asia/Seoul', 'weekdays', 'off', 'masked'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=set */
    ['Asia/Seoul', 'all', 'on', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=unset */
    ['Asia/Seoul', 'all', 'on', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=masked */
    ['Asia/Seoul', 'all', 'on', 'masked'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=set */
    ['Asia/Seoul', 'all', 'off', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=unset */
    ['Asia/Seoul', 'all', 'off', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=masked */
    ['Asia/Seoul', 'all', 'off', 'masked'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=set */
    ['Asia/Seoul', 'empty', 'on', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=unset */
    ['Asia/Seoul', 'empty', 'on', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=masked */
    ['Asia/Seoul', 'empty', 'on', 'masked'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=set */
    ['Asia/Seoul', 'empty', 'off', 'set'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=unset */
    ['Asia/Seoul', 'empty', 'off', 'unset'],
    /** @scenario site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=masked */
    ['Asia/Seoul', 'empty', 'off', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=set */
    ['America/New_York', 'weekdays', 'on', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['America/New_York', 'weekdays', 'on', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['America/New_York', 'weekdays', 'on', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=set */
    ['America/New_York', 'weekdays', 'off', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['America/New_York', 'weekdays', 'off', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['America/New_York', 'weekdays', 'off', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=set */
    ['America/New_York', 'all', 'on', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=unset */
    ['America/New_York', 'all', 'on', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=masked */
    ['America/New_York', 'all', 'on', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=set */
    ['America/New_York', 'all', 'off', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=unset */
    ['America/New_York', 'all', 'off', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=masked */
    ['America/New_York', 'all', 'off', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=set */
    ['America/New_York', 'empty', 'on', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=unset */
    ['America/New_York', 'empty', 'on', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=masked */
    ['America/New_York', 'empty', 'on', 'masked'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=set */
    ['America/New_York', 'empty', 'off', 'set'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=unset */
    ['America/New_York', 'empty', 'off', 'unset'],
    /** @scenario site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=masked */
    ['America/New_York', 'empty', 'off', 'masked'],
];
