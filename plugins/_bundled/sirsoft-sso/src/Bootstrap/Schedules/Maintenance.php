<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Schedules;

/**
 * 스케줄 — 메타데이터 갱신, 이력 보존 정리 영역.
 *
 * `Bootstrap\Schedules::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Maintenance
{
    /**
     * 이 영역의 스케줄 목록을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 스케줄 정의
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'metadata-refresh',
                'type' => 'artisan',
                'label' => ['ko' => 'SSO IdP 메타데이터 갱신', 'en' => 'SSO IdP metadata refresh'],
                'description' => [
                    'ko' => 'SAML IdP 메타데이터를 다시 받아 인증서 만료일을 기록하고, 만료가 다가오면 알립니다.',
                    'en' => 'Re-downloads SAML IdP metadata, records certificate expiry dates and notifies when expiry is near.',
                ],
                'command' => 'sirsoft-sso:metadata-refresh',
                'frequency' => 'daily',
                'expression' => '20 4 * * *',
            ],
            [
                'name' => 'prune',
                'type' => 'artisan',
                'label' => ['ko' => 'SSO 이력 보존 정리', 'en' => 'SSO history retention cleanup'],
                'description' => [
                    'ko' => '보존일이 지난 로그인 시도 이력, SCIM 수신 이력, 끝난 SSO 세션 기록을 지웁니다.',
                    'en' => 'Deletes login attempts, SCIM request history and ended SSO session records past their retention period.',
                ],
                'command' => 'sirsoft-sso:prune',
                'frequency' => 'daily',
                'expression' => '40 4 * * *',
            ],
        ];
    }
}
