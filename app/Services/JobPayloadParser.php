<?php

namespace App\Services;

use App\Jobs\RunScheduledTaskJob;

/**
 * 큐 작업 payload(jobs·failed_jobs.payload JSON) 파서
 *
 * 화면 표시와 처리 기록에 쓰는 메타데이터(표시 이름·작업 클래스·UUID·시도 횟수·예약 작업 ID)만
 * 꺼낸다. 직렬화된 작업 객체는 **역직렬화하지 않는다** — 목록 한 페이지마다 수십 개의 객체를
 * 깨우면 그 생성자·의존성이 조회 요청 안에서 돌고, 클래스가 사라진 작업은 오류가 된다.
 * 예약 작업 ID 는 직렬화 문자열에서 공개 속성 `scheduleId` 의 정수만 읽는다(RunScheduledTaskJob 의 공개 readonly 속성).
 */
class JobPayloadParser
{
    /** 표시 이름을 모를 때 쓰는 값 */
    public const UNKNOWN = 'Unknown';

    /**
     * payload 문자열에서 표시용 메타데이터를 꺼냅니다.
     *
     * `attempts` 는 payload 에 시도 수가 실려 있을 때만 값이 있다(redis 처럼 payload 안에서 세는 드라이버). database 드라이버는
     * 시도 수를 `jobs.attempts` 열에 두고 payload 에 쓰지 않으므로 null(미상)이다 — 0 으로 채우면 「한 번도 시도하지 않음」으로 읽힌다.
     *
     * @param  string|null  $payload  payload JSON 문자열
     * @return array{display_name: string, job_class: string, uuid: string|null, attempts: int|null, max_tries: int|null, schedule_id: int|null} 메타데이터 (해석 불가면 기본값)
     */
    public static function parse(?string $payload): array
    {
        $decoded = is_string($payload) && $payload !== '' ? json_decode($payload, true) : null;

        return is_array($decoded) ? self::fromDecoded($decoded) : self::empty();
    }

    /**
     * 이미 디코드된 payload 배열에서 표시용 메타데이터를 꺼냅니다.
     *
     * 워커 이벤트(`$job->payload()`)는 디코드된 배열을 주므로 다시 인코딩하지 않고 이 경로를 쓴다.
     *
     * @param  array<string, mixed>  $decoded  디코드된 payload
     * @return array{display_name: string, job_class: string, uuid: string|null, attempts: int|null, max_tries: int|null, schedule_id: int|null} 메타데이터
     */
    public static function fromDecoded(array $decoded): array
    {
        $displayName = is_string($decoded['displayName'] ?? null) && $decoded['displayName'] !== ''
            ? $decoded['displayName']
            : self::UNKNOWN;
        $commandName = $decoded['data']['commandName'] ?? null;
        $jobClass = is_string($commandName) && $commandName !== ''
            ? $commandName
            : (is_string($decoded['job'] ?? null) ? $decoded['job'] : $displayName);

        return [
            'display_name' => $displayName,
            'job_class' => $jobClass,
            'uuid' => is_string($decoded['uuid'] ?? null) ? $decoded['uuid'] : null,
            'attempts' => isset($decoded['attempts']) && is_numeric($decoded['attempts']) ? (int) $decoded['attempts'] : null,
            'max_tries' => isset($decoded['maxTries']) && is_numeric($decoded['maxTries']) ? (int) $decoded['maxTries'] : null,
            'schedule_id' => self::scheduleId($jobClass, $decoded['data']['command'] ?? null),
        ];
    }

    /**
     * 예약 작업 Job 이면 그 스케줄 ID 를 꺼냅니다.
     *
     * 직렬화 문자열(`O:…`)의 `s:10:"scheduleId";i:N;` 만 읽는다. 암호화된 작업(ShouldBeEncrypted)은
     * 복호화하지 않고 null 을 돌려준다.
     *
     * @param  string  $jobClass  작업 클래스
     * @param  mixed  $command  payload data.command (직렬화 문자열)
     * @return int|null 스케줄 ID (예약 작업 Job 이 아니거나 읽을 수 없으면 null)
     */
    public static function scheduleId(string $jobClass, mixed $command): ?int
    {
        if ($jobClass !== RunScheduledTaskJob::class || ! is_string($command) || ! str_starts_with($command, 'O:')) {
            return null;
        }

        return preg_match('/s:10:"scheduleId";i:(\d+);/', $command, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * 해석할 수 없는 payload 의 기본 메타데이터를 반환합니다.
     *
     * @return array{display_name: string, job_class: string, uuid: null, attempts: null, max_tries: null, schedule_id: null} 기본값
     */
    private static function empty(): array
    {
        return [
            'display_name' => self::UNKNOWN,
            'job_class' => self::UNKNOWN,
            'uuid' => null,
            'attempts' => null,
            'max_tries' => null,
            'schedule_id' => null,
        ];
    }
}
