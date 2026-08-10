<?php

/*
|--------------------------------------------------------------------------
| Hello Module Web Routes
|--------------------------------------------------------------------------
|
| ModuleRouteServiceProvider 가 자동으로 prefix 를 적용합니다.
| - URL prefix: 'modules/gnuboard7-hello_module'
| - Name prefix: 'web.modules.gnuboard7-hello_module.'
| - 미들웨어 그룹: 'web' (세션/CSRF)
|
| 이 모듈은 화면을 레이아웃 JSON 으로 그리고 데이터는 JSON API 로만 받으므로
| 웹(세션) 라우트가 필요 없다. 관리자 CRUD 는 routes/api.php 의
| 'api/modules/gnuboard7-hello_module/admin/memos' 그룹에 있다.
|
| 레이아웃 data_sources 가 호출하는 엔드포인트를 이 파일에 두면 prefix 가
| 'api/modules/...' 가 아니라 'modules/...' 가 되어 화면에서 404 가 된다.
|
*/
