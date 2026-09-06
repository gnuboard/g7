<?php

use App\Support\EnvPriority;

/*
|--------------------------------------------------------------------------
| .env 키 단위 우선 (G7_ENV_PRIORITY)
|--------------------------------------------------------------------------
|
| G7 은 관리자 환경설정(storage/app/settings/*.json)을 운영 SSoT 로 삼고 그 값을
| config() 에 주입한다. 이 스위치를 켠 설치에서는 `.env` 에 값이 명시된 키만
| 그 주입에서 제외되어 `.env` 가 권위를 갖는다. 스위치를 켜지 않으면 아무 것도
| 달라지지 않는다 (기본 OFF — 기존 설치 무영향).
|
| explicit 는 "그 env 변수가 명시되었는가" 만 담는 불리언 맵이다. 값 자체는 이미
| 각 config/*.php 가 env() 로 읽어 갖고 있으므로 여기에 다시 담지 않는다.
|
| 판별을 여기서 캡처하는 이유: env() 직접 호출은 config:cache 환경에서 null 로
| 고정되므로 런타임 판별이 영구 미발동한다 (config/attachment.php 의 disk_explicit
| 와 동형 함정). 이 파일은 config:cache 시점에 평가되어 결과가 캐시에 박제된다.
|
| 명시 판정은 strict 다 — `KEY=` (빈 값)과 미설정만 미명시로 본다. `APP_DEBUG=false`
| 나 `REDIS_DB=0` 처럼 falsy 한 값을 명시한 경우는 명시로 취급한다 (?: 를 쓰면
| 그 값들이 미명시로 오판된다).
|
| `.env` 만 편집한 경우에는 config:cache 를 다시 실행해야 반영된다. 설정 저장 경로는
| SettingsService 가 ConfigCacheHelper 로 재빌드하므로 추가 조치가 필요 없다.
|
*/

$explicit = [];

foreach (EnvPriority::envVars() as $var) {
    if (! in_array(env($var), [null, ''], true)) {
        $explicit[$var] = true;
    }
}

return [

    'enabled' => (bool) env('G7_ENV_PRIORITY', false),

    'explicit' => $explicit,

];
