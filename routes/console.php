<?php

use App\Services\Schedule\ScheduleRegistrar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| 모든 예약 작업은 DB `schedules` 행을 거친다 — 코어는 config/core.php 의 `schedules` 카탈로그,
| 모듈·플러그인은 getSchedules() 선언이 설치·업데이트 때 행으로 동기화되고, 운영자는 관리자 화면
| (스케줄 관리)에서 주기·실행 방식을 바꾼다. 등록기가 활성 행마다 `schedule:{id}` 이벤트를 등록한다.
|
| 시각 해석 기준은 행의 시간대, 없으면 사이트 설정 시간대(`app.schedule_timezone`)다.
|
*/

if (file_exists(base_path('.env'))) {
    app(ScheduleRegistrar::class)->register(Schedule::getFacadeRoot());
}
