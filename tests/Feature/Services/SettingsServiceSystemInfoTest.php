<?php

namespace Tests\Feature\Services;

use App\Services\SettingsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 시스템 정보 응답의 직렬화 계약 테스트.
 *
 * 배경 (gnuboard/g7#62 와 동형):
 *   `getSystemInfo()` 는 외부 프로세스 출력(powershell/wmic CPU 조회)과 환경값을
 *   한 배열에 모아 관리자 환경설정 화면으로 내보낸다. 그 안에 invalid UTF-8 이
 *   섞이면 Laravel JsonResponse 직렬화가 Malformed UTF-8 로 실패해 화면이 500 이 된다.
 *   `safeSystemProbe` 는 probe 예외만 잡고 직렬화 예외는 잡지 못한다.
 *
 * 단위 테스트(SettingsServiceCpuInfoTest)는 CPU 필드 하나의 정규화 계약만 본다.
 * 이 테스트는 **페이로드 전체**가 직렬화 가능한지를 잠가, 나중에 다른 probe 가
 * 외부 출력을 그대로 싣더라도 같은 결함이 재발하지 않게 한다.
 *
 * DB 스키마를 건드리지 않으므로 RefreshDatabase 를 쓰지 않는다.
 */
class SettingsServiceSystemInfoTest extends TestCase
{
    #[Test]
    public function system_info_payload_is_always_json_serializable(): void
    {
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertNotSame([], $info, '시스템 정보 페이로드가 비어 있으면 안 된다.');

        $encoded = json_encode($info, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse(
            $encoded,
            'getSystemInfo() 페이로드 직렬화 실패: '.json_last_error_msg()
                .' — invalid UTF-8 필드: '.implode(', ', $this->invalidUtf8Paths($info))
        );
    }

    #[Test]
    public function every_string_in_system_info_is_valid_utf8(): void
    {
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertSame(
            [],
            $this->invalidUtf8Paths($info),
            '시스템 정보에 유효하지 않은 UTF-8 문자열이 있으면 관리자 화면이 500 이 된다.'
        );
    }

    /**
     * invalid UTF-8 문자열이 있는 키 경로를 점 표기로 수집합니다.
     *
     * @param  mixed  $value  검사할 값
     * @param  string  $prefix  상위 경로
     * @return array<int, string> 점 구분 경로 목록
     */
    private function invalidUtf8Paths(mixed $value, string $prefix = ''): array
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? [] : [$prefix === '' ? '(root)' : $prefix];
        }

        if (! is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $item) {
            $label = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $paths = array_merge($paths, $this->invalidUtf8Paths($item, $label));
        }

        return $paths;
    }
}
