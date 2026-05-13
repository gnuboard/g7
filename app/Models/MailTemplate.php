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
 * 본 shim 은 autoload 통과와 최소 Eloquent 쿼리 성공만 제공하며, 업그레이드 종료 시점에
 * `Upgrade_7_0_0_beta_2::run()` 이 파일과 `mail_templates` 테이블을 함께 제거한다.
 * beta.3 에서는 저장소에서도 완전히 삭제 예정.
 *
 * ※ 본 파일은 비즈니스 로직을 포함하지 않으며, 확장이나 재사용 대상이 아니다.
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
