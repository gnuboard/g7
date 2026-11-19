<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 스케줄 보안 유효 정책(읽기 전용 표시용) 응답 리소스
 *
 * 원본은 `ScheduleSecurityPolicy::describe()` 배열이다. 정책은 화면에서 바꿀 수 없다 — 서버 관리자가
 * `storage/app/schedule_security.json`(더하기만)과 `.env`(Shell)로 바꾼다. 서버 절대경로·비밀값은 싣지 않는다.
 */
class ScheduleSecurityResource extends BaseApiResource
{
    /**
     * 유효 정책을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> override_file·shell·artisan·job·url
     */
    public function toArray(Request $request): array
    {
        return [
            'override_file' => $this->getValue('override_file', []),
            'shell' => $this->getValue('shell', []),
            'artisan' => $this->getValue('artisan', []),
            'job' => $this->getValue('job', []),
            'url' => $this->getValue('url', []),
        ];
    }
}
