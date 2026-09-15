<?php

// failure-notification.yaml의 전 조합. 각 행은 실제 실행 결과를 단언합니다.
return [
    /** @scenario status=auth_error, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['auth_error', 'on', 'yes', 'yes'],
    /** @scenario status=auth_error, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['auth_error', 'on', 'yes', 'no'],
    /** @scenario status=auth_error, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['auth_error', 'on', 'no', 'yes'],
    /** @scenario status=auth_error, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['auth_error', 'on', 'no', 'no'],
    /** @scenario status=auth_error, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['auth_error', 'off', 'yes', 'yes'],
    /** @scenario status=auth_error, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['auth_error', 'off', 'yes', 'no'],
    /** @scenario status=auth_error, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['auth_error', 'off', 'no', 'yes'],
    /** @scenario status=auth_error, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['auth_error', 'off', 'no', 'no'],
    /** @scenario status=network_error, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['network_error', 'on', 'yes', 'yes'],
    /** @scenario status=network_error, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['network_error', 'on', 'yes', 'no'],
    /** @scenario status=network_error, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['network_error', 'on', 'no', 'yes'],
    /** @scenario status=network_error, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['network_error', 'on', 'no', 'no'],
    /** @scenario status=network_error, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['network_error', 'off', 'yes', 'yes'],
    /** @scenario status=network_error, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['network_error', 'off', 'yes', 'no'],
    /** @scenario status=network_error, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['network_error', 'off', 'no', 'yes'],
    /** @scenario status=network_error, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['network_error', 'off', 'no', 'no'],
    /** @scenario status=no_data, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['no_data', 'on', 'yes', 'yes'],
    /** @scenario status=no_data, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['no_data', 'on', 'yes', 'no'],
    /** @scenario status=no_data, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['no_data', 'on', 'no', 'yes'],
    /** @scenario status=no_data, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['no_data', 'on', 'no', 'no'],
    /** @scenario status=no_data, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['no_data', 'off', 'yes', 'yes'],
    /** @scenario status=no_data, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['no_data', 'off', 'yes', 'no'],
    /** @scenario status=no_data, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['no_data', 'off', 'no', 'yes'],
    /** @scenario status=no_data, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['no_data', 'off', 'no', 'no'],
    /** @scenario status=quota_exceeded, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['quota_exceeded', 'on', 'yes', 'yes'],
    /** @scenario status=quota_exceeded, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['quota_exceeded', 'on', 'yes', 'no'],
    /** @scenario status=quota_exceeded, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['quota_exceeded', 'on', 'no', 'yes'],
    /** @scenario status=quota_exceeded, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['quota_exceeded', 'on', 'no', 'no'],
    /** @scenario status=quota_exceeded, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['quota_exceeded', 'off', 'yes', 'yes'],
    /** @scenario status=quota_exceeded, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['quota_exceeded', 'off', 'yes', 'no'],
    /** @scenario status=quota_exceeded, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['quota_exceeded', 'off', 'no', 'yes'],
    /** @scenario status=quota_exceeded, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['quota_exceeded', 'off', 'no', 'no'],
    /** @scenario status=bad_response, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['bad_response', 'on', 'yes', 'yes'],
    /** @scenario status=bad_response, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['bad_response', 'on', 'yes', 'no'],
    /** @scenario status=bad_response, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['bad_response', 'on', 'no', 'yes'],
    /** @scenario status=bad_response, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['bad_response', 'on', 'no', 'no'],
    /** @scenario status=bad_response, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['bad_response', 'off', 'yes', 'yes'],
    /** @scenario status=bad_response, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['bad_response', 'off', 'yes', 'no'],
    /** @scenario status=bad_response, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['bad_response', 'off', 'no', 'yes'],
    /** @scenario status=bad_response, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['bad_response', 'off', 'no', 'no'],
    /** @scenario status=success, notify_on_failure=on, already_notified_today=yes, retry_exhausted=yes */
    ['success', 'on', 'yes', 'yes'],
    /** @scenario status=success, notify_on_failure=on, already_notified_today=yes, retry_exhausted=no */
    ['success', 'on', 'yes', 'no'],
    /** @scenario status=success, notify_on_failure=on, already_notified_today=no, retry_exhausted=yes */
    ['success', 'on', 'no', 'yes'],
    /** @scenario status=success, notify_on_failure=on, already_notified_today=no, retry_exhausted=no */
    ['success', 'on', 'no', 'no'],
    /** @scenario status=success, notify_on_failure=off, already_notified_today=yes, retry_exhausted=yes */
    ['success', 'off', 'yes', 'yes'],
    /** @scenario status=success, notify_on_failure=off, already_notified_today=yes, retry_exhausted=no */
    ['success', 'off', 'yes', 'no'],
    /** @scenario status=success, notify_on_failure=off, already_notified_today=no, retry_exhausted=yes */
    ['success', 'off', 'no', 'yes'],
    /** @scenario status=success, notify_on_failure=off, already_notified_today=no, retry_exhausted=no */
    ['success', 'off', 'no', 'no'],
];
