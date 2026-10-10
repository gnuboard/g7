<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [한시적 호환 Shim — 7.0.0-beta.1 → 7.0.0-beta.2 업그레이드 전용]
 *
 * beta.2 에서 알림 시스템 통합(#256)으로 MailTemplate 은 공식 제거되었으나,
 * 업데이트 중 in-memory 로 실행되는 beta.1 의 `CoreUpdateService::syncCoreMailTemplates()`
 * 가 `MailTemplate::where()` / `::create()` 를 호출하여 autoload 가 필요하다.
 *
 * 본 shim 은 autoload 통과만 제공한다. 7.1.0 부터 `mail_templates` 테이블은 생성 마이그레이션이 없고
 * 기존 설치의 빈 테이블도 업그레이드 스텝 `08_DropLegacyMailTables` 가 지우므로, 쿼리하면 실패한다 —
 * 옛 업그레이드 코드가 클래스를 찾다 죽지 않게만 남겨 둔다(알림 시스템 「예외」 표 등재).
 *
 * ※ 본 파일은 비즈니스 로직을 포함하지 않으며, 확장이나 재사용 대상이 아니다. 발송 문구는 알림 정의를 쓴다.
 *
 * @deprecated 7.0.0-beta.2 알림 시스템(notification_definitions·notification_templates)으로 대체됨
 */
class MailTemplate extends Model
{
    protected $table = 'mail_templates';

    protected $guarded = [];

    protected $casts = [
        'subject' => 'array',
        'body' => 'array',
        'variables' => 'array',
        'user_overrides' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];
}
