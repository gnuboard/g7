<?php

namespace Tests\Unit\Extension;

use App\Extension\ListenerQueueOptions;
use App\Support\Queue\ThrottledQueueWarning;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 훅 리스너 큐 옵션 정규화 테스트 (#139)
 *
 * `getSubscribedHooks()` 의 `'queue' => [...]` 덩어리에서 유효한 키만 남기고, 잘못된 키·값은 그 키만 버리며
 * 경고를 남기는지 검증한다.
 */
class ListenerQueueOptionsTest extends TestCase
{
    /**
     * 경고 스로틀 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        ThrottledQueueWarning::reset();
    }

    /**
     * 허용 범위 안의 10개 키는 모두 그대로 남는다.
     *
     * @scenario declaration=all_keys
     *
     * @effects options_normalized
     *
     * @return void 반환값 없음
     */
    public function test_normalize_keeps_all_ten_valid_options(): void
    {
        $raw = [
            'connection' => 'database',
            'queue' => 'notifications',
            'delay' => 5,
            'after_commit' => false,
            'tries' => 5,
            'timeout' => 60,
            'backoff' => 10,
            'retry_until' => 600,
            'max_exceptions' => 2,
            'fail_on_timeout' => true,
        ];

        $this->assertSame($raw, ListenerQueueOptions::normalize($raw, 'Listener', 'hook'));
        $this->assertSame(ListenerQueueOptions::KEYS, array_keys($raw));
    }

    /**
     * 모르는 키는 버리고 경고를 남긴다.
     *
     * @scenario declaration=unknown_key
     *
     * @effects invalid_option_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_normalize_drops_unknown_key_with_warning(): void
    {
        Log::spy();

        $result = ListenerQueueOptions::normalize(['tries' => 2, 'priority' => 1], 'Listener', 'hook');

        $this->assertSame(['tries' => 2], $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => ($context['key'] ?? null) === 'priority');
    }

    /**
     * 값이 범위를 벗어난 키는 하나씩 따로 버리고 나머지는 살린다.
     *
     * @scenario declaration=invalid_values
     *
     * @effects invalid_option_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_normalize_drops_each_invalid_value_individually(): void
    {
        Log::spy();

        $result = ListenerQueueOptions::normalize([
            'connection' => '',
            'queue' => 'bad name!',
            'delay' => -1,
            'after_commit' => 'yes',
            'tries' => 0,
            'timeout' => 0,
            'backoff' => -5,
            'retry_until' => '600',
            'max_exceptions' => 0,
            'fail_on_timeout' => 1,
            'tries_ok' => 1,
        ], 'Listener', 'hook');

        $this->assertSame([], $result);
        Log::shouldHaveReceived('warning')->times(11);

        // 경계값 — tries 25 는 허용, 26 은 버린다. 큐 이름은 영문·숫자·_ . : - 만
        $this->assertSame(['tries' => 25, 'queue' => 'a:b.c-d_e'], ListenerQueueOptions::normalize(['tries' => 25, 'queue' => 'a:b.c-d_e'], 'L3', 'h3'));
        $this->assertSame([], ListenerQueueOptions::normalize(['tries' => 26], 'L4', 'h4'));
    }

    /**
     * backoff 는 정수 목록도 받는다(목록 순서 그대로).
     *
     * @scenario declaration=backoff_list
     *
     * @effects options_normalized
     *
     * @return void 반환값 없음
     */
    public function test_normalize_accepts_backoff_array(): void
    {
        $this->assertSame(['backoff' => [10, 60, 300]], ListenerQueueOptions::normalize(['backoff' => [10, 60, 300]], 'L', 'h'));
        $this->assertSame([], ListenerQueueOptions::normalize(['backoff' => []], 'L', 'h2'));
        $this->assertSame([], ListenerQueueOptions::normalize(['backoff' => [10, -1]], 'L', 'h3'));
        $this->assertSame([], ListenerQueueOptions::normalize(['backoff' => ['a' => 10]], 'L', 'h4'));
    }

    /**
     * 배열이 아닌 'queue' 선언은 통째로 무시하고 경고한다.
     *
     * @scenario declaration=non_array
     *
     * @effects invalid_option_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_non_array_queue_config_is_ignored_with_warning(): void
    {
        Log::spy();

        $this->assertSame([], ListenerQueueOptions::normalize('notifications', 'Listener', 'hook'));
        $this->assertSame([], ListenerQueueOptions::normalize(null, 'Listener', 'hook2'));
        Log::shouldHaveReceived('warning')->once();
    }
}
