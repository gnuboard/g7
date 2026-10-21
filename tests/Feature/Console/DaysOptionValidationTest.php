<?php

namespace Tests\Feature\Console;

use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 정리·통계 커맨드의 일수 옵션 검증 테스트 (7.1.1 출시 전)
 *
 * `(int) 'abc'` 는 0, `-5` 는 음수라 오타가 보관 하한(1일)으로 깎여 기본값보다 더 많이 지웠다.
 * 0 이상의 정수가 아니면 오류 종료하고 아무것도 지우지 않아야 한다(`0` 은 종전대로 받는다 — 하한은 도메인 계층 소유).
 */
class DaysOptionValidationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트 준비 — 한국어 문구로 검사한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
    }

    /**
     * 일수 옵션을 받는 커맨드와 잘못된 값의 조합을 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}> [커맨드, 옵션, 값, 하한]
     */
    public static function invalidDaysProvider(): array
    {
        $commands = [
            ['notification-delivery:prune', '--days', 0],
            ['notification-log:prune', '--days', 0],
            ['schedules:prune-history', '--days', 0],
            ['seo:prune-stats', '--days', 0],
            ['seo:stats', '--days', 0],
            ['identity:prune-logs', '--days', 0],
            ['activity-log:prune', '--days', 0],
            ['analytics:prune', '--days', 0],
            ['attachments:prune-orphans', '--days', 0],
            ['storage:prune-leftovers', '--days', 0],
            ['storage:prune-leftovers', '--backup-days', 0],
        ];

        $cases = [];
        foreach ($commands as [$command, $option, $min]) {
            foreach (['abc', '-5', '1.5'] as $value) {
                $cases["{$command} {$option}={$value}"] = [$command, $option, $value, $min];
            }
        }

        return $cases;
    }

    /**
     * 정수가 아니거나 음수인 일수는 오류 종료(1)하고 무엇을 받았는지 알립니다.
     *
     * @scenario case=invalid_value_on_every_days_command
     *
     * @effects invalid_days_option_exits_with_failure, invalid_days_option_message_names_option_min_and_value
     *
     * @param  string  $command  커맨드 이름
     * @param  string  $option  옵션 이름 (`--days` 등)
     * @param  string  $value  잘못된 값
     * @param  int  $min  허용 하한
     * @return void 반환값 없음
     */
    #[DataProvider('invalidDaysProvider')]
    public function test_invalid_days_option_fails_without_processing(string $command, string $option, string $value, int $min): void
    {
        $this->artisan($command, [$option => $value])
            ->expectsOutputToContain(__('common.console.invalid_days_option', ['option' => ltrim($option, '-'), 'min' => $min, 'value' => $value]))
            ->assertExitCode(1);
    }

    /**
     * 오타(`--days=abc`)는 발송 기록을 하나도 지우지 않습니다 — 종전에는 「보관 1일」로 실행돼 기본(7일)보다 더 지웠다.
     *
     * @scenario case=typo_with_prunable_rows
     *
     * @effects invalid_days_option_exits_with_failure, invalid_days_option_prunes_nothing, valid_days_option_prunes_as_before
     *
     * @return void 반환값 없음
     */
    public function test_typo_does_not_prune_notification_deliveries(): void
    {
        $guard = app(NotificationDeliveryGuard::class);
        $this->travelTo('2026-10-01 00:00:00');
        $guard->claim('days-typo-1', 'type', 'mail', 'user:1');
        $this->travelTo('2026-10-04 00:00:00');

        $this->artisan('notification-delivery:prune', ['--days' => 'abc'])->assertExitCode(1);

        $this->assertSame(1, NotificationDelivery::query()->where('event_id', 'days-typo-1')->count());

        // 올바른 값·미지정은 종전과 같다.
        $this->artisan('notification-delivery:prune', ['--days' => '7'])->assertExitCode(0);
        $this->assertSame(1, NotificationDelivery::query()->where('event_id', 'days-typo-1')->count());
        $this->artisan('notification-delivery:prune', ['--days' => '2'])->assertExitCode(0);
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', 'days-typo-1')->count());
    }

    /**
     * 오류 문구가 옵션 이름과 무관하게 맞는 조사를 쓴다 — 「--days 은」이 아니라 「--days 값은」(7.1.1 출시 전).
     *
     * @scenario case=invalid_value_on_every_days_command
     *
     * @effects invalid_days_option_message_reads_naturally
     *
     * @return void 반환값 없음
     */
    public function test_error_message_reads_naturally(): void
    {
        $this->artisan('notification-delivery:prune', ['--days' => 'abc'])
            ->expectsOutputToContain('--days 값은 0 이상의 정수여야 합니다 (입력: abc)')
            ->assertExitCode(1);
    }

    /**
     * `--days=0` 은 최소 보관 1일로 적용됐다고 알린다 — 종전에는 「(보관 0일)」로 찍혀 전부 지운 것처럼 읽혔다(7.1.1 출시 전).
     *
     * @scenario case=zero_days_floor
     *
     * @effects zero_days_output_reports_applied_floor
     *
     * @return void 반환값 없음
     */
    public function test_zero_days_reports_the_applied_floor(): void
    {
        foreach (['notification-delivery:prune', 'notification-log:prune', 'schedules:prune-history', 'seo:prune-stats', 'identity:prune-logs'] as $command) {
            $this->artisan($command, ['--days' => '0'])
                ->expectsOutputToContain('0일은 최소 보관 1일로 적용했습니다')
                ->doesntExpectOutputToContain(' 0일)')
                ->assertExitCode(0);
        }
    }

    /**
     * `activity-log:prune` 도 하한보다 작은 `--days` 를 「최소 보관 365일로 적용」으로 알린다 — 종전에는 「(보존 365일)」만 찍혀
     * 기본값처럼 읽혔다(7.1.1 출시 전 실측, 2026-10-18).
     *
     * @scenario case=zero_days_floor
     *
     * @effects zero_days_output_reports_applied_floor
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_zero_days_reports_its_legal_floor(): void
    {
        $this->artisan('activity-log:prune', ['--days' => '0'])
            ->expectsOutputToContain('(보관 365일) 0일은 최소 보관 365일로 적용했습니다')
            ->assertExitCode(0);

        // 하한 이상이면 덧붙이지 않는다.
        $this->artisan('activity-log:prune', ['--days' => '400'])
            ->expectsOutputToContain('(보관 400일)')
            ->doesntExpectOutputToContain('최소 보관')
            ->assertExitCode(0);
    }

    /**
     * 정리 명령 6개의 완료 문구가 같은 용어(「보관」)를 쓴다 — 명령마다 「보관」·「보존」이 섞여 있었다(7.1.1 출시 전).
     *
     * @scenario case=zero_days_floor
     *
     * @effects prune_completion_uses_one_retention_term
     *
     * @return void 반환값 없음
     */
    public function test_prune_completion_messages_use_one_retention_term(): void
    {
        foreach ([
            'notification-delivery:prune' => '7',
            'notification-log:prune' => '90',
            'schedules:prune-history' => '90',
            'seo:prune-stats' => '30',
            'identity:prune-logs' => '180',
            'activity-log:prune' => '400',
        ] as $command => $days) {
            $this->artisan($command, ['--days' => $days])
                ->expectsOutputToContain("(보관 {$days}일)")
                ->doesntExpectOutputToContain('보존')
                ->assertExitCode(0);
        }
    }
}
