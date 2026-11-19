<?php

namespace Tests\Unit\Services;

use App\Enums\ScheduleTriggerType;
use App\Jobs\GenerateSitemapJob;
use App\Jobs\RunScheduledTaskJob;
use App\Services\JobPayloadParser;
use PHPUnit\Framework\TestCase;

/**
 * 큐 payload 파서 테스트 (#17 — 예약 작업 Job 의 scheduleId 를 역직렬화 없이 읽는다)
 */
class JobPayloadParserTest extends TestCase
{
    /**
     * 표시 이름·작업 클래스·UUID·시도 횟수를 꺼낸다.
     *
     * @scenario payload=regular
     *
     * @effects parser_reads_display_meta
     *
     * @return void 반환값 없음
     */
    public function test_표시_메타를_꺼낸다(): void
    {
        $parsed = JobPayloadParser::parse(json_encode([
            'uuid' => 'u-1', 'displayName' => 'App\\Jobs\\Foo', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3, 'attempts' => 2,
            'data' => ['commandName' => 'App\\Jobs\\Foo', 'command' => 'O:12:"App\\Jobs\\Foo":0:{}'],
        ]));

        $this->assertSame('App\\Jobs\\Foo', $parsed['display_name']);
        $this->assertSame('App\\Jobs\\Foo', $parsed['job_class']);
        $this->assertSame('u-1', $parsed['uuid']);
        $this->assertSame(2, $parsed['attempts']);
        $this->assertSame(3, $parsed['max_tries']);
        $this->assertNull($parsed['schedule_id']);
    }

    /**
     * 예약 작업 Job 이면 직렬화 문자열의 scheduleId 를 읽고, 다른 클래스·암호화 payload 는 null 이다.
     *
     * @scenario payload=scheduled_job|encrypted|other_class
     *
     * @effects parser_reads_schedule_id
     *
     * @return void 반환값 없음
     */
    public function test_예약작업_job_의_schedule_id_를_읽는다(): void
    {
        $command = 'O:28:"App\\Jobs\\RunScheduledTaskJob":3:{s:10:"scheduleId";i:42;s:7:"trigger";O:8:"stdClass":0:{}s:6:"userId";N;}';

        $this->assertSame(42, JobPayloadParser::scheduleId(RunScheduledTaskJob::class, $command));
        $this->assertNull(JobPayloadParser::scheduleId(RunScheduledTaskJob::class, 'eyJpdiI6ImVuY3J5cHRlZCJ9'));
        $this->assertNull(JobPayloadParser::scheduleId('App\\Jobs\\Other', $command));

        $parsed = JobPayloadParser::parse(json_encode([
            'displayName' => 'schedule:42 sitemap-generate',
            'data' => ['commandName' => RunScheduledTaskJob::class, 'command' => $command],
        ]));
        $this->assertSame(42, $parsed['schedule_id']);
    }

    /**
     * 실제 RunScheduledTaskJob 직렬화 문자열에서도 scheduleId 를 읽는다(실제 직렬화 실측).
     *
     * @scenario payload=scheduled_job_real
     *
     * @effects parser_reads_schedule_id
     *
     * @return void 반환값 없음
     */
    public function test_실제_예약작업_job_직렬화에서_schedule_id_를_읽는다(): void
    {
        $command = serialize(new RunScheduledTaskJob(1234, ScheduleTriggerType::Manual, 7));

        $this->assertSame(1234, JobPayloadParser::scheduleId(RunScheduledTaskJob::class, $command));
    }

    /**
     * Job 유형 스케줄의 큐 모드는 그 Job 자체가 큐에 들어간다(Job 유형 큐 모드는 Job 자체를 큐에 넣는 설계) — schedule_id 가 비는 것이 정상이고 오류가 아니다.
     *
     * @scenario payload=job_type_queue_mode
     *
     * @effects parser_reads_schedule_id
     *
     * @return void 반환값 없음
     */
    public function test_job_유형_큐모드는_schedule_id_가_비어도_정상이다(): void
    {
        $parsed = JobPayloadParser::parse(json_encode([
            'uuid' => 'u-sitemap',
            'displayName' => GenerateSitemapJob::class,
            'data' => ['commandName' => GenerateSitemapJob::class, 'command' => 'O:26:"App\\Jobs\\GenerateSitemapJob":0:{}'],
        ]));

        $this->assertSame(GenerateSitemapJob::class, $parsed['job_class']);
        $this->assertSame('u-sitemap', $parsed['uuid']);
        $this->assertNull($parsed['schedule_id']);
    }

    /**
     * payload 에 시도 수가 없으면(database 드라이버의 jobs·failed_jobs payload) 0 이 아니라 미상(null)이다.
     *
     * @scenario payload=regular
     *
     * @effects failed_attempts_from_log_or_unknown
     *
     * @return void 반환값 없음
     */
    public function test_시도_수가_없는_payload_는_미상이다(): void
    {
        $parsed = JobPayloadParser::parse(json_encode([
            'uuid' => 'u-db', 'displayName' => 'App\Jobs\Foo',
            'data' => ['commandName' => 'App\Jobs\Foo', 'command' => 'O:12:"App\Jobs\Foo":0:{}'],
        ]));

        $this->assertNull($parsed['attempts']);
        $this->assertNull(JobPayloadParser::parse(null)['attempts']);
    }

    /**
     * 해석할 수 없는 payload 는 기본값을 돌려준다.
     *
     * @scenario payload=null|empty|broken_json
     *
     * @effects parser_tolerates_broken_payload
     *
     * @return void 반환값 없음
     */
    public function test_깨진_payload_는_기본값이다(): void
    {
        foreach ([null, '', '{not json', '"string"'] as $payload) {
            $parsed = JobPayloadParser::parse($payload);
            $this->assertSame(JobPayloadParser::UNKNOWN, $parsed['display_name']);
            $this->assertNull($parsed['uuid']);
            $this->assertNull($parsed['schedule_id']);
        }
    }
}
