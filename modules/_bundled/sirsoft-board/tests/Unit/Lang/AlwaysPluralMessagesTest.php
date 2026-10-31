<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Lang;

use Carbon\Carbon;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Rules\DailyReportLimitRule;
use Modules\Sirsoft\Board\Rules\RejectionLimitRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * 괄호 없이 늘 복수로 적혀 있던 게시판 서버 영어 문구의 단·복수 테스트.
 *
 * 「1 minutes ago」·「(1 reports)」 처럼 늘 복수였던 문구를 `단수|복수` + `trans_choice` 로 1 이면 단수로 고른다.
 * 한국어 값은 그대로다. 번역을 직접 부르는 호출부(검증 메시지 등)는 코어 `PluralKeyDirectTranslationScanTest` 가
 * `__()` 로 남지 않았음을 지킨다.
 */
class AlwaysPluralMessagesTest extends ModuleTestCase
{
    /**
     * 사전을 작업 원본(_bundled)에서 읽도록 네임스페이스를 다시 잡습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('sirsoft-board', base_path('modules/_bundled/sirsoft-board/src/lang'));
    }

    /**
     * 시각 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 사전 키별 [다국어 키, 수 외 치환값, 수 자리표시자, 1일 때 영어, 2일 때 영어].
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}> 데이터 이름 => [키, 치환값, 수 자리표시자, 1일 때, 2일 때]
     */
    public static function boardKeys(): array
    {
        $p = 'sirsoft-board::';

        return [
            'report_bulk_update_status' => [$p.'activity_log.description.report_bulk_update_status', [], 'count',
                'Report statuses bulk changed (1 item)', 'Report statuses bulk changed (2 items)'],
            'settings.bulk_apply_success' => [$p.'messages.settings.bulk_apply_success', [], 'count',
                'Settings applied to 1 board.', 'Settings applied to 2 boards.'],
            'relative_time.minutes_ago' => [$p.'messages.common.relative_time.minutes_ago', [], 'count', '1 minute ago', '2 minutes ago'],
            'relative_time.hours_ago' => [$p.'messages.common.relative_time.hours_ago', [], 'count', '1 hour ago', '2 hours ago'],
            'relative_time.days_ago' => [$p.'messages.common.relative_time.days_ago', [], 'count', '1 day ago', '2 days ago'],
            'relative_time.months_ago' => [$p.'messages.common.relative_time.months_ago', [], 'count', '1 month ago', '2 months ago'],
            'relative_time.years_ago' => [$p.'messages.common.relative_time.years_ago', [], 'count', '1 year ago', '2 years ago'],
            'cooldown_duration.seconds' => [$p.'validation.cooldown_duration.seconds', [], 'seconds', '1 second', '2 seconds'],
            'cooldown_duration.minutes' => [$p.'validation.cooldown_duration.minutes', [], 'minutes', '1 minute', '2 minutes'],
            'cooldown_duration.hours' => [$p.'validation.cooldown_duration.hours', [], 'hours', '1 hour', '2 hours'],
            'post.files.max' => [$p.'validation.post.files.max', [], 'max',
                'You can upload up to 1 file only.', 'You can upload up to 2 files only.'],
            'report.daily_limit_exceeded' => [$p.'validation.report.daily_limit_exceeded', [], 'limit',
                'You have exceeded the daily report limit (1 report).', 'You have exceeded the daily report limit (2 reports).'],
            'report.rejection_limit_exceeded' => [$p.'validation.report.rejection_limit_exceeded', ['days' => 7], 'count',
                'Reporting is restricted due to 1 rejection in the last 7-day period. Please try again later.',
                'Reporting is restricted due to 2 rejections in the last 7-day period. Please try again later.'],
        ];
    }

    /**
     * 각 영어 메시지는 1 이면 단수, 0·2 이면 복수로 나온다.
     *
     * @scenario surface=dictionary
     *
     * @effects english_message_singular_for_one_plural_otherwise
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @param  string  $one  1 일 때 기대 문구
     * @param  string  $two  2 일 때 기대 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('boardKeys')]
    public function english_message_is_singular_for_one_and_plural_otherwise(string $key, array $params, string $numberParam, string $one, string $two): void
    {
        $this->app->setLocale('en');

        $this->assertSame($one, trans_choice($key, 1, $params + [$numberParam => 1]));
        $this->assertSame($two, trans_choice($key, 2, $params + [$numberParam => 2]));
        $this->assertSame(
            preg_replace('/(?<![\w.])2(?![\w.])/', '0', $two),
            trans_choice($key, 0, $params + [$numberParam => 0]),
            "{$key}: 0 은 복수"
        );
    }

    /**
     * 한국어 문구는 복수 구분 기호가 없고, 복수 고르기 호출로 바꿔도 결과가 `__()` 와 같다.
     *
     * @scenario surface=dictionary
     *
     * @effects korean_message_unchanged
     *
     * @param  string  $key  다국어 키
     * @param  array<string, mixed>  $params  수 외 치환값
     * @param  string  $numberParam  수 자리표시자 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('boardKeys')]
    public function korean_message_is_unchanged(string $key, array $params, string $numberParam): void
    {
        $this->app->setLocale('ko');

        foreach ([1, 2] as $n) {
            $replace = $params + [$numberParam => $n];
            $legacy = __($key, $replace);

            $this->assertStringNotContainsString('|', $legacy, "{$key}: 한국어 값에 복수 구분 기호가 있다");
            $this->assertSame($legacy, trans_choice($key, $n, $replace));
        }
    }

    /**
     * 게시글·댓글 작성일의 상대 시각은 1 이면 단수(「1 minute ago」)다.
     *
     * @scenario surface=relative_time
     *
     * @effects relative_time_follows_count
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function relative_time_label_is_singular_for_one(): void
    {
        $formatter = new class
        {
            use FormatsBoardDate;

            /**
             * 상대 시각 문구를 만듭니다.
             *
             * @param  Carbon  $at  작성 시각
             * @return string 문구
             */
            public function label(Carbon $at): string
            {
                return $this->formatCreatedAtFormat($at, 'relative');
            }
        };

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $now = Carbon::now();

        $this->app->setLocale('en');
        $this->assertSame('1 minute ago', $formatter->label($now->copy()->subMinute()));
        $this->assertSame('2 minutes ago', $formatter->label($now->copy()->subMinutes(2)));
        $this->assertSame('1 hour ago', $formatter->label($now->copy()->subHour()));
        $this->assertSame('1 day ago', $formatter->label($now->copy()->subDay()));
        $this->assertSame('1 month ago', $formatter->label($now->copy()->subMonth()));
        $this->assertSame('1 year ago', $formatter->label($now->copy()->subYear()));
        $this->assertSame('3 years ago', $formatter->label($now->copy()->subYears(3)));

        $this->app->setLocale('ko');
        $this->assertSame('1분 전', $formatter->label($now->copy()->subMinute()));
    }

    /**
     * 연속 작성 제한 시간 문구는 단위마다 단·복수를 고른다(「1 minute 1 second」). 한국어는 종전 그대로다.
     *
     * @scenario surface=validation_rule
     *
     * @effects cooldown_duration_pluralises_each_unit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function cooldown_duration_pluralises_each_unit(): void
    {
        $rule = new CooldownRule('post', 60, 'probe');
        $format = fn (int $seconds) => (new \ReflectionMethod($rule, 'formatDuration'))->invoke($rule, $seconds);

        $this->app->setLocale('en');
        $this->assertSame('1 second', $format(1));
        $this->assertSame('2 seconds', $format(2));
        $this->assertSame('1 minute', $format(60));
        $this->assertSame('1 minute 1 second', $format(61));
        $this->assertSame('2 minutes 30 seconds', $format(150));
        $this->assertSame('1 hour', $format(3600));
        $this->assertSame('1 hour 1 minute', $format(3660));
        $this->assertSame('2 hours 2 minutes', $format(7320));

        $this->app->setLocale('ko');
        $this->assertSame('1분 1초', $format(61));
        $this->assertSame('2시간 2분', $format(7320));
    }

    /**
     * 신고 하루 한도·반려 누적 제한 검증 문구는 한도가 1 이면 단수다.
     *
     * @scenario surface=validation_rule
     *
     * @effects report_limit_message_follows_limit
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function report_limit_messages_are_singular_for_one(): void
    {
        $this->app->setLocale('en');

        $repository = \Mockery::mock(ReportRepositoryInterface::class);
        $repository->shouldReceive('countTodayReportsByUser')->andReturn(5);
        $repository->shouldReceive('countRejectedReportsByUser')->andReturn(5);

        $messages = [];
        $fail = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        (new DailyReportLimitRule($repository, 1, 1))->validate('reason', 'x', $fail);
        (new DailyReportLimitRule($repository, 1, 3))->validate('reason', 'x', $fail);
        (new RejectionLimitRule($repository, 1, 1, 30))->validate('reason', 'x', $fail);
        (new RejectionLimitRule($repository, 1, 3, 1))->validate('reason', 'x', $fail);

        $this->assertSame([
            'You have exceeded the daily report limit (1 report).',
            'You have exceeded the daily report limit (3 reports).',
            'Reporting is restricted due to 1 rejection in the last 30-day period. Please try again later.',
            'Reporting is restricted due to 3 rejections in the last 1-day period. Please try again later.',
        ], $messages);
    }
}
