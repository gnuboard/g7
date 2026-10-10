<?php

// exchange-rate-update.yaml의 pairwise 조합을 실제 실행 테스트가 소비합니다.
return [
    /** @scenario trigger=scheduled, auto_manage=on, key=set, base=KRW, day=run_day, observed_at=before_11, success_today=yes, attempts=zero */
    ['scheduled', 'on', 'set', 'KRW', 'run_day', 'before_11', 'yes', 'zero'],
    /** @scenario trigger=retry, auto_manage=off, key=unset, base=USD, day=off_day, observed_at=after_11, success_today=no, attempts=lt_max */
    ['retry', 'off', 'unset', 'USD', 'off_day', 'after_11', 'no', 'lt_max'],
    /** @scenario trigger=manual, auto_manage=on, key=set, base=JPY, day=off_day, observed_at=after_11, success_today=yes, attempts=ge_max */
    ['manual', 'on', 'set', 'JPY', 'off_day', 'after_11', 'yes', 'ge_max'],
    /** @scenario trigger=manual, auto_manage=off, key=unset, base=unsupported, day=run_day, observed_at=before_11, success_today=no, attempts=zero */
    ['manual', 'off', 'unset', 'unsupported', 'run_day', 'before_11', 'no', 'zero'],
    /** @scenario trigger=retry, auto_manage=on, key=set, base=unsupported, day=run_day, observed_at=after_11, success_today=yes, attempts=lt_max */
    ['retry', 'on', 'set', 'unsupported', 'run_day', 'after_11', 'yes', 'lt_max'],
    /** @scenario trigger=scheduled, auto_manage=off, key=unset, base=KRW, day=off_day, observed_at=before_11, success_today=no, attempts=ge_max */
    ['scheduled', 'off', 'unset', 'KRW', 'off_day', 'before_11', 'no', 'ge_max'],
    /** @scenario trigger=retry, auto_manage=on, key=set, base=USD, day=run_day, observed_at=before_11, success_today=no, attempts=zero */
    ['retry', 'on', 'set', 'USD', 'run_day', 'before_11', 'no', 'zero'],
    /** @scenario trigger=scheduled, auto_manage=off, key=unset, base=JPY, day=run_day, observed_at=before_11, success_today=yes, attempts=lt_max */
    ['scheduled', 'off', 'unset', 'JPY', 'run_day', 'before_11', 'yes', 'lt_max'],
    /** @scenario trigger=scheduled, auto_manage=on, key=unset, base=USD, day=run_day, observed_at=after_11, success_today=yes, attempts=ge_max */
    ['scheduled', 'on', 'unset', 'USD', 'run_day', 'after_11', 'yes', 'ge_max'],
    /** @scenario trigger=retry, auto_manage=off, key=set, base=JPY, day=off_day, observed_at=after_11, success_today=no, attempts=zero */
    ['retry', 'off', 'set', 'JPY', 'off_day', 'after_11', 'no', 'zero'],
    /** @scenario trigger=manual, auto_manage=on, key=set, base=KRW, day=off_day, observed_at=after_11, success_today=yes, attempts=lt_max */
    ['manual', 'on', 'set', 'KRW', 'off_day', 'after_11', 'yes', 'lt_max'],
    /** @scenario trigger=retry, auto_manage=on, key=set, base=unsupported, day=off_day, observed_at=before_11, success_today=yes, attempts=ge_max */
    ['retry', 'on', 'set', 'unsupported', 'off_day', 'before_11', 'yes', 'ge_max'],
    /** @scenario trigger=retry, auto_manage=on, key=set, base=KRW, day=run_day, observed_at=before_11, success_today=yes, attempts=zero */
    ['retry', 'on', 'set', 'KRW', 'run_day', 'before_11', 'yes', 'zero'],
    /** @scenario trigger=manual, auto_manage=on, key=set, base=USD, day=run_day, observed_at=before_11, success_today=yes, attempts=zero */
    ['manual', 'on', 'set', 'USD', 'run_day', 'before_11', 'yes', 'zero'],
    /** @scenario trigger=scheduled, auto_manage=on, key=set, base=unsupported, day=run_day, observed_at=before_11, success_today=yes, attempts=zero */
    ['scheduled', 'on', 'set', 'unsupported', 'run_day', 'before_11', 'yes', 'zero'],
];
