<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PlaywrightSeedDormant;
use App\Models\User;
use App\Privacy\DormantAccountService;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * playwright:seed-dormant 커맨드 — 단계마다 만든 계정이 **그 단계의 명단**에 실제로 잡히는지 검증.
 *
 * 이 커맨드는 개인정보 탭의 「대상 보기」 창을 실측하려고 쓴다. 심은 계정이 판정 쿼리와
 * 어긋나면 창이 비고, 운영자는 그것을 「대상이 없다」로 읽는다 — 시드가 틀린 것인지 기능이
 * 틀린 것인지 구분되지 않는다. 그래서 명단을 만드는 쿼리(배치와 같은 조건식)로 직접 대조한다.
 * 기간을 기본값과 다르게 둔 사이트도 같은 결과여야 하므로 설정을 바꾼 경우도 함께 본다.
 *
 * @scenario target=privacy_dormant_accounts, row_age=beyond_retention
 *
 * @effects measurement_seed_stages_land_in_their_target_lists
 */
class PlaywrightSeedDormantCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 옵트인 플래그를 지우고 테스트를 마칩니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 옵트인 플래그가 다른 테스트로 새지 않게 세 저장소에서 모두 지운다.
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');

        parent::tearDown();
    }

    /**
     * 시드 단계와 그 단계를 세는 명단 쿼리의 짝을 제공합니다.
     *
     * @return array<string, array{0: string, 1: string}> 데이터셋 이름 => [시드 단계, 명단 단계]
     */
    public static function stages(): array
    {
        return [
            '사전 안내' => ['pre_notice', 'pre_notice'],
            '휴면 전환' => ['convertible', 'convert'],
            '탈퇴 예고' => ['withdraw_notice', 'withdraw_notice'],
            '탈퇴' => ['withdraw', 'withdraw'],
            '탈퇴 회원 잔여정보' => ['withdrawn', 'withdrawn'],
        ];
    }

    /**
     * 심은 계정은 자기 단계의 명단에만 잡히고 다른 네 명단에는 나타나지 않습니다.
     *
     * @scenario target=privacy_dormant_accounts, row_age=beyond_retention
     *
     * @effects measurement_seed_stages_land_in_their_target_lists
     *
     * @param  string  $seedStage  커맨드에 넘길 단계
     * @param  string  $listStage  그 계정이 잡혀야 할 명단 단계
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('stages')]
    public function seeded_account_lands_only_in_its_own_stage_list(string $seedStage, string $listStage): void
    {
        $this->seedStage($seedStage, 2);

        foreach ($this->lists() as $stage => $query) {
            $expected = $stage === $listStage ? 2 : 0;
            $this->assertSame(
                $expected,
                $query->where('email', 'like', 'playwright_dormant_%')->count(),
                "--stage={$seedStage} 로 심은 계정의 {$stage} 명단 건수"
            );
        }
    }

    /**
     * 기간을 기본값과 다르게 둔 사이트에서도 심은 계정이 그 단계 명단에 잡힙니다.
     *
     * @scenario target=privacy_dormant_accounts, row_age=beyond_retention
     *
     * @effects measurement_seed_stages_land_in_their_target_lists
     *
     * @param  string  $seedStage  커맨드에 넘길 단계
     * @param  string  $listStage  그 계정이 잡혀야 할 명단 단계
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('stages')]
    public function seeded_account_follows_non_default_periods(string $seedStage, string $listStage): void
    {
        Config::set('g7_settings.core.privacy.dormant_days', 90);
        Config::set('g7_settings.core.privacy.dormant_notice_days', 10);
        Config::set('g7_settings.core.privacy.dormant_withdraw_days', 60);
        Config::set('g7_settings.core.privacy.withdrawn_retention_days', 30);

        $this->seedStage($seedStage, 1);

        $this->assertSame(1, $this->lists()[$listStage]->where('email', 'like', 'playwright_dormant_%')->count());
    }

    /**
     * 정리 옵션은 모든 단계에서 심은 계정을 남김없이 지웁니다.
     *
     * @scenario target=privacy_dormant_accounts, row_age=beyond_retention
     *
     * @effects measurement_seed_stages_land_in_their_target_lists
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function purge_removes_every_seeded_stage(): void
    {
        foreach (PlaywrightSeedDormant::STAGES as $stage) {
            $this->seedStage($stage, 1);
        }

        $this->artisan('playwright:seed-dormant', ['--purge-users' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->where('email', 'like', 'playwright_dormant_%')->count());
    }

    /**
     * 실측이 잔여정보를 파기해 이메일이 바뀐 시드 계정도 정리 옵션이 지웁니다.
     *
     * `withdrawn` 단계는 「지금 실행」이 이메일을 `withdrawn_{id}@removed.invalid` 로 바꾸라고 심는 계정이라,
     * 이메일 접두사만 보는 정리는 실측을 한 번 할 때마다 그만큼을 개발 DB 에 남겼다.
     *
     * @scenario target=privacy_dormant_accounts, row_age=beyond_retention
     *
     * @effects measurement_seed_stages_land_in_their_target_lists, purge_also_removes_seeds_anonymized_by_the_measured_action
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function purge_removes_seeds_anonymized_by_the_withdrawn_retention_run(): void
    {
        $this->seedStage('withdrawn', 2);
        $seededIds = User::query()->where('email', 'like', 'playwright_dormant_%')->pluck('id')->all();
        $bystander = User::factory()->create(['email' => 'bystander@example.test']);

        app(WithdrawnUserRetentionHandler::class)->process(false, true);
        $this->assertSame(
            2,
            User::query()->whereIn('id', $seededIds)->where('email', 'like', '%'.WithdrawnUserRetentionHandler::ANONYMIZED_EMAIL_DOMAIN)->count(),
            '실측과 같은 상태 — 시드 계정의 이메일이 파기 표지로 바뀌었다'
        );

        $this->artisan('playwright:seed-dormant', ['--purge-users' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->whereIn('id', $seededIds)->count());
        $this->assertTrue(User::query()->whereKey($bystander->id)->exists(), '시드가 아닌 계정은 남는다');
    }

    /**
     * 옵트인 환경변수를 켜고 커맨드로 계정을 심는다.
     *
     * @param  string  $stage  단계
     * @param  int  $count  계정 수
     * @return void 반환값 없음
     */
    private function seedStage(string $stage, int $count): void
    {
        // `env()` 는 $_SERVER·$_ENV 를 먼저 읽는다 — putenv 만으로는 커맨드 가드에 닿지 않는다.
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');

        $this->artisan('playwright:seed-dormant', ['--stage' => $stage, '--count' => $count])->assertExitCode(0);
    }

    /**
     * 화면의 「대상 보기」 다섯 개가 여는 명단 쿼리를 돌려줍니다.
     *
     * @return array<string, Builder<User>> 명단 단계 => 그 명단을 만드는 쿼리
     */
    private function lists(): array
    {
        $dormant = app(DormantAccountService::class);

        return [
            'pre_notice' => $dormant->stageQuery('pre_notice'),
            'convert' => $dormant->stageQuery('convert'),
            'withdraw_notice' => $dormant->stageQuery('withdraw_notice'),
            'withdraw' => $dormant->stageQuery('withdraw'),
            'withdrawn' => app(WithdrawnUserRetentionHandler::class)->targetQuery(),
        ];
    }
}
