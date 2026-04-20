<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| 여기에서 애플리케이션이 지원하는 모든 이벤트 브로드캐스팅 채널을 등록합니다.
| 주어진 채널 인증 콜백은 현재 인증된 사용자가 채널을 청취할 수 있는지
| 여부를 결정하는 데 사용됩니다.
|
| 코어 채널 네이밍: core.{역할}.{리소스}.{파라미터}
| 모듈 채널: module.{identifier}.* (ModuleManager 자동 등록)
| 플러그인 채널: plugin.{identifier}.* (PluginManager 자동 등록)
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 관리자 대시보드 채널 - core.dashboard.read 권한 필요
Broadcast::channel('core.admin.dashboard', function ($user) {
    return $user->hasPermission('core.dashboard.read');
});

// 사용자별 알림 채널 - UUID 기반 (User ID 노출 방지)
// core.user-notifications.read (type=user) 권한 필요
Broadcast::channel('core.user.notifications.{uuid}', function ($user, $uuid) {
    return $user->uuid === $uuid
        && $user->hasPermission('core.user-notifications.read', \App\Enums\PermissionType::User);
});
