<?php

// schedule-and-settings.yaml의 전 조합. PluginScheduleTest가 모든 행을 검증합니다.
return [
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=set */
    ['valid', 'Asia/Seoul', 'weekdays', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['valid', 'Asia/Seoul', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['valid', 'Asia/Seoul', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=set */
    ['valid', 'Asia/Seoul', 'weekdays', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['valid', 'Asia/Seoul', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['valid', 'Asia/Seoul', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=set */
    ['valid', 'Asia/Seoul', 'all', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=unset */
    ['valid', 'Asia/Seoul', 'all', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=masked */
    ['valid', 'Asia/Seoul', 'all', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=set */
    ['valid', 'Asia/Seoul', 'all', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=unset */
    ['valid', 'Asia/Seoul', 'all', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=masked */
    ['valid', 'Asia/Seoul', 'all', 'off', 'masked'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=set */
    ['valid', 'Asia/Seoul', 'empty', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=unset */
    ['valid', 'Asia/Seoul', 'empty', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=masked */
    ['valid', 'Asia/Seoul', 'empty', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=set */
    ['valid', 'Asia/Seoul', 'empty', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=unset */
    ['valid', 'Asia/Seoul', 'empty', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=masked */
    ['valid', 'Asia/Seoul', 'empty', 'off', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=set */
    ['valid', 'America/New_York', 'weekdays', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['valid', 'America/New_York', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['valid', 'America/New_York', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=set */
    ['valid', 'America/New_York', 'weekdays', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['valid', 'America/New_York', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['valid', 'America/New_York', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=set */
    ['valid', 'America/New_York', 'all', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=unset */
    ['valid', 'America/New_York', 'all', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=masked */
    ['valid', 'America/New_York', 'all', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=set */
    ['valid', 'America/New_York', 'all', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=unset */
    ['valid', 'America/New_York', 'all', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=masked */
    ['valid', 'America/New_York', 'all', 'off', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=set */
    ['valid', 'America/New_York', 'empty', 'on', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=unset */
    ['valid', 'America/New_York', 'empty', 'on', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=masked */
    ['valid', 'America/New_York', 'empty', 'on', 'masked'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=set */
    ['valid', 'America/New_York', 'empty', 'off', 'set'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=unset */
    ['valid', 'America/New_York', 'empty', 'off', 'unset'],
    /** @scenario update_time=valid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=masked */
    ['valid', 'America/New_York', 'empty', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=set */
    ['invalid', 'Asia/Seoul', 'weekdays', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=set */
    ['invalid', 'Asia/Seoul', 'weekdays', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=set */
    ['invalid', 'Asia/Seoul', 'all', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'all', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'all', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=set */
    ['invalid', 'Asia/Seoul', 'all', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'all', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'all', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=set */
    ['invalid', 'Asia/Seoul', 'empty', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'empty', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'empty', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=set */
    ['invalid', 'Asia/Seoul', 'empty', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=unset */
    ['invalid', 'Asia/Seoul', 'empty', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=masked */
    ['invalid', 'Asia/Seoul', 'empty', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=set */
    ['invalid', 'America/New_York', 'weekdays', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['invalid', 'America/New_York', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['invalid', 'America/New_York', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=set */
    ['invalid', 'America/New_York', 'weekdays', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['invalid', 'America/New_York', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['invalid', 'America/New_York', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=set */
    ['invalid', 'America/New_York', 'all', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=unset */
    ['invalid', 'America/New_York', 'all', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=masked */
    ['invalid', 'America/New_York', 'all', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=set */
    ['invalid', 'America/New_York', 'all', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=unset */
    ['invalid', 'America/New_York', 'all', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=masked */
    ['invalid', 'America/New_York', 'all', 'off', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=set */
    ['invalid', 'America/New_York', 'empty', 'on', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=unset */
    ['invalid', 'America/New_York', 'empty', 'on', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=masked */
    ['invalid', 'America/New_York', 'empty', 'on', 'masked'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=set */
    ['invalid', 'America/New_York', 'empty', 'off', 'set'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=unset */
    ['invalid', 'America/New_York', 'empty', 'off', 'unset'],
    /** @scenario update_time=invalid, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=masked */
    ['invalid', 'America/New_York', 'empty', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=set */
    ['missing', 'Asia/Seoul', 'weekdays', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['missing', 'Asia/Seoul', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['missing', 'Asia/Seoul', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=set */
    ['missing', 'Asia/Seoul', 'weekdays', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['missing', 'Asia/Seoul', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['missing', 'Asia/Seoul', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=set */
    ['missing', 'Asia/Seoul', 'all', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=unset */
    ['missing', 'Asia/Seoul', 'all', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=on, auth_key=masked */
    ['missing', 'Asia/Seoul', 'all', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=set */
    ['missing', 'Asia/Seoul', 'all', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=unset */
    ['missing', 'Asia/Seoul', 'all', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=all, auto_manage=off, auth_key=masked */
    ['missing', 'Asia/Seoul', 'all', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=set */
    ['missing', 'Asia/Seoul', 'empty', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=unset */
    ['missing', 'Asia/Seoul', 'empty', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=on, auth_key=masked */
    ['missing', 'Asia/Seoul', 'empty', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=set */
    ['missing', 'Asia/Seoul', 'empty', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=unset */
    ['missing', 'Asia/Seoul', 'empty', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=Asia/Seoul, run_days=empty, auto_manage=off, auth_key=masked */
    ['missing', 'Asia/Seoul', 'empty', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=set */
    ['missing', 'America/New_York', 'weekdays', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=unset */
    ['missing', 'America/New_York', 'weekdays', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=on, auth_key=masked */
    ['missing', 'America/New_York', 'weekdays', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=set */
    ['missing', 'America/New_York', 'weekdays', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=unset */
    ['missing', 'America/New_York', 'weekdays', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=weekdays, auto_manage=off, auth_key=masked */
    ['missing', 'America/New_York', 'weekdays', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=set */
    ['missing', 'America/New_York', 'all', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=unset */
    ['missing', 'America/New_York', 'all', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=on, auth_key=masked */
    ['missing', 'America/New_York', 'all', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=set */
    ['missing', 'America/New_York', 'all', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=unset */
    ['missing', 'America/New_York', 'all', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=all, auto_manage=off, auth_key=masked */
    ['missing', 'America/New_York', 'all', 'off', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=set */
    ['missing', 'America/New_York', 'empty', 'on', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=unset */
    ['missing', 'America/New_York', 'empty', 'on', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=on, auth_key=masked */
    ['missing', 'America/New_York', 'empty', 'on', 'masked'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=set */
    ['missing', 'America/New_York', 'empty', 'off', 'set'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=unset */
    ['missing', 'America/New_York', 'empty', 'off', 'unset'],
    /** @scenario update_time=missing, site_timezone=America/New_York, run_days=empty, auto_manage=off, auth_key=masked */
    ['missing', 'America/New_York', 'empty', 'off', 'masked'],
];
