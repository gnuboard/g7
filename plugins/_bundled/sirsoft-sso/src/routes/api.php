<?php

/*
|--------------------------------------------------------------------------
| SSO 연동 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| URL 프리픽스 자동 적용: /api/plugins/sirsoft-sso/
| 이름 프리픽스 자동 적용: api.plugins.sirsoft-sso.
|
| permission 미들웨어 형식: permission:admin,sirsoft-sso.{category}.{action}
| 영역별 파일로 나눈다 — 흐름, 마이페이지, 관리자 화면, SCIM.
|
*/

foreach (['flow', 'me', 'admin_idps', 'admin_policy', 'admin_accounts', 'admin_logs', 'scim'] as $area) {
    require __DIR__.'/api/'.$area.'.php';
}
