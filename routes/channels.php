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
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 관리자 대시보드 채널 - core.dashboard.read 권한 필요
Broadcast::channel('admin.dashboard', function ($user) {
    return $user->hasPermission('core.dashboard.read');
});
