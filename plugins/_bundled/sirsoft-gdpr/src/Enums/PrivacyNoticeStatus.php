<?php

namespace Plugins\Sirsoft\Gdpr\Enums;

/** 개인정보 통지의 불변 이벤트 상태. */
enum PrivacyNoticeStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
