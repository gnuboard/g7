<?php

/**
 * 프론트엔드 노출 설정
 *
 * 백엔드의 config() 값 중 프론트엔드에 노출할 항목을 정의합니다.
 * G7Config.appConfig으로 주입되어 _global.appConfig에서 접근 가능합니다.
 *
 * 영속 설정(defaults.json)과 분리: 이 파일은 정적 시스템 설정만 관리합니다.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | 프론트엔드에 노출할 config() 값
    |--------------------------------------------------------------------------
    |
    | 각 항목: 프론트엔드 키 => ['config_key' => config() 경로, 'type' => 타입]
    | 레이아웃에서 {{_global.appConfig.supportedTimezones}} 형태로 접근
    |
    | 향후 프론트에 노출할 config 값이 추가되면 여기에 추가만 하면 됩니다.
    |
    */
    'app_config' => [
        'supportedTimezones' => [
            'config_key' => 'app.supported_timezones',
            'type' => 'array',
        ],
        'supportedLocales' => [
            'config_key' => 'app.supported_locales',
            'type' => 'array',
        ],
        'releaseYear' => [
            'config_key' => 'app.release_year',
            'type' => 'string',
        ],
        'version' => [
            'config_key' => 'app.version',
            'type' => 'string',
        ],
    ],
];
