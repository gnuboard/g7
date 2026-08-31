<?php

namespace Tests\Unit\Listeners;

use App\Listeners\NotificationHookListener;
use Tests\TestCase;

/**
 * 알림 발송 건수 관측 (A-6 · D6-a · §7.4 R21).
 *
 * 큐 연결이 `sync` 인 사이트에서는 수신자마다의 발송이 **요청 안에서** 순차 실행된다.
 * 실행 시간 상한에 걸리면 그 지점 이후 수신자가 누락되는데, 관측이 없으면 그 누락은
 * 아무 흔적도 남기지 않는다 — 알림이 그냥 오지 않을 뿐이다(수신자 1,174명 × 2채널이
 * 적재된 실사례가 있다).
 *
 * 계획서는 이 축을 **로그로만** 확보하기로 했다(정책은 바꾸지 않고 관측만). 그래서
 * 이 테스트가 재는 것은 「그 관측이 코드에 남아 있는가」다 — 지워도 어떤 게이트도
 * red 가 되지 않으면, 조치를 취하고도 사라진 것을 알 방법이 없다.
 *
 * 로그 호출을 실행 경로로 재려면 정의·템플릿·수신자를 모두 세워야 하는데 그 준비물이
 * 이 축과 무관하게 커서, 여기서는 **관측 지점의 존재와 형태**를 소스에서 판정한다.
 * 발송 로직을 돌리는 통합 축은 형제 테스트(NotificationDispatchIntegrationTest)가 맡는다.
 *
 * @scenario caller=panel
 */
class NotificationSendCountLogTest extends TestCase
{
    /**
     * 리스너 소스를 읽습니다.
     *
     * @return string 소스 본문
     */
    private function source(): string
    {
        $path = (new \ReflectionClass(NotificationHookListener::class))->getFileName();

        $this->assertIsString($path);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 발송 **시작** 줄이 대상 인원을 남긴다.
     *
     * 완료 줄만 있으면 중간에 끊긴 경우 「몇 명에게 보내려 했는지」를 알 수 없어,
     * 남은 건수가 전부인지 잘린 것인지 구분되지 않는다.
     *
     * @effects notification_send_logs_the_intended_total
     */
    public function test_send_start_is_logged_with_the_intended_total(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("Log::info('알림 발송 시작'", $source);
        $this->assertMatchesRegularExpression(
            '/Log::info\(\'알림 발송 시작\'.*?\'recipients\' => \$total/s',
            $source,
            '발송 시작 줄이 대상 인원을 남기지 않는다 — 중간에 끊긴 발송의 누락 규모를 알 수 없다.',
        );
    }

    /**
     * 발송 **완료** 줄이 대상·성공·실패 건수를 남긴다.
     *
     * @effects notification_send_logs_sent_and_failed_counts
     */
    public function test_send_completion_is_logged_with_counts(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("Log::info('알림 발송 완료'", $source);

        foreach (["'recipients' => \$total", "'sent' => \$sent", "'failed' => \$failed"] as $field) {
            $this->assertMatchesRegularExpression(
                "/Log::info\('알림 발송 완료'.*?".preg_quote($field, '/').'/s',
                $source,
                '발송 완료 줄에 '.$field.' 가 없다.',
            );
        }
    }

    /**
     * 성공·실패 카운터가 실제로 증가한다.
     *
     * 로그 줄만 있고 카운터가 고정값이면 관측은 언제나 0 을 보고한다.
     *
     * @effects notification_send_counters_are_incremented
     */
    public function test_counters_are_incremented_in_the_send_loop(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('$sent++;', $source);
        $this->assertStringContainsString('$failed++;', $source);
    }
}
