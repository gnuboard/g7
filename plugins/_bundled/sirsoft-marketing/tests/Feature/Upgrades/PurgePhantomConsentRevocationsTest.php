<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Models\MarketingConsentHistory;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;
use Plugins\Sirsoft\Marketing\Upgrades\Upgrade_1_1_2;

/**
 * 1.1.2 업그레이드 스텝 — 동의한 적 없는 항목에 남은 「철회」 기록 정리
 *
 * 1.1.1 까지는 동의한 적 없는 회원이 동의 칸을 끈 채 저장하면 항목마다 상태 행(동의 안 함, 철회 시각)과 철회 이력이
 * 생겼다. 스텝은 「동의 이력이 없고, 상태 행이 동의 안 함이며 동의한 적도(횟수 0, 동의 시각 없음) 없는」 (회원, 항목)
 * 쌍만 골라 그 쌍의 이력과 상태 행을 지운다. 실제로 동의했다가 철회한 쌍, 지금 동의 중인 쌍은 건드리지 않는다.
 */
class PurgePhantomConsentRevocationsTest extends PluginTestCase
{
    /**
     * 시험 전용 로그 채널 이름
     */
    private const LOG_CHANNEL = 'marketing-upgrade-test';

    /**
     * 동의 테이블이 있으면 테스트 DB 재생성 없이 트랜잭션으로 격리합니다.
     *
     * 테이블이 없는 새 테스트 DB 에서는 기반 클래스의 재생성을 한 번 거친다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        if (! Schema::hasTable('user_marketing_consents') || ! Schema::hasTable('user_marketing_consent_histories')) {
            parent::refreshDatabase();

            return;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * 로그를 붙잡을 시험 전용 채널을 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.'.self::LOG_CHANNEL => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    }

    /**
     * 업그레이드 스텝을 실제 실행 경로(스텝 → data/1.1.2/migrations)로 돌립니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        $stepFile = base_path('plugins/_bundled/sirsoft-marketing/upgrades/Upgrade_1_1_2.php');
        $this->assertFileExists($stepFile, '1.1.2 업그레이드 스텝이 없다.');

        // 업그레이드 스텝은 업데이트 실행기가 파일로 불러온다 — 일반 autoload 대상이 아니다.
        require_once $stepFile;

        (new Upgrade_1_1_2)->run(new UpgradeContext('1.1.1', '1.1.2', '1.1.2', self::LOG_CHANNEL));
    }

    /**
     * 시험 채널에 남은 로그 문구를 돌려줍니다.
     *
     * @return array<int, string> 로그 문구
     */
    private function loggedMessages(): array
    {
        $handler = Log::channel(self::LOG_CHANNEL)->getLogger()->getHandlers()[0];

        return array_map(static fn ($record) => (string) $record->message, $handler->getRecords());
    }

    /**
     * 상태 행을 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $key  동의 항목 키
     * @param  array<string, mixed>  $state  상태 값
     * @return void 반환값 없음
     */
    private function state(int $userId, string $key, array $state): void
    {
        MarketingConsent::create(['user_id' => $userId, 'consent_key' => $key, 'last_source' => 'profile', ...$state]);
    }

    /**
     * 이력 행을 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $key  동의 항목 키
     * @param  string  $action  동작(granted 또는 revoked)
     * @return void 반환값 없음
     */
    private function history(int $userId, string $key, string $action): void
    {
        MarketingConsentHistory::create([
            'user_id' => $userId,
            'channel_key' => $key,
            'action' => $action,
            'source' => 'profile',
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 회원의 남은 (항목 → 이력 행위 목록) 과 상태 행 키 목록을 읽습니다.
     *
     * @param  int  $userId  회원 ID
     * @return array{histories: array<string, array<int, string>>, states: array<int, string>} 남은 기록
     */
    private function remaining(int $userId): array
    {
        $histories = [];
        foreach (MarketingConsentHistory::where('user_id', $userId)->orderBy('id')->get() as $history) {
            $histories[$history->channel_key][] = $history->action;
        }
        ksort($histories);

        $states = MarketingConsent::where('user_id', $userId)->orderBy('consent_key')->pluck('consent_key')->all();

        return ['histories' => $histories, 'states' => $states];
    }

    /**
     * 동의한 적 없는 쌍의 철회 기록만 지우고, 실제 동의·철회와 판별이 애매한 쌍은 남긴다. (실패-먼저)
     *
     * @scenario stored=phantom_and_real
     *
     * @effects phantom_pairs_purged, real_pairs_preserved, purge_counts_logged
     *
     * @return void 반환값 없음
     */
    public function test_purges_only_never_consented_revocations(): void
    {
        $phantomOnly = User::factory()->create();
        $mixed = User::factory()->create();
        $revokedAt = now()->subDays(3);

        // 회원 1: 첫 저장으로 생긴 가짜 철회 2쌍
        foreach (['marketing_consent', 'third_party_consent'] as $key) {
            $this->state($phantomOnly->id, $key, ['is_consented' => false, 'consent_count' => 0, 'consented_at' => null, 'revoked_at' => $revokedAt]);
            $this->history($phantomOnly->id, $key, 'revoked');
        }

        // 회원 2: 같은 회원 안에 가짜 쌍과 실제 쌍이 섞여 있다
        // 가짜 — 동의한 적 없는 채널
        $this->state($mixed->id, 'sms_subscription', ['is_consented' => false, 'consent_count' => 0, 'consented_at' => null, 'revoked_at' => $revokedAt]);
        $this->history($mixed->id, 'sms_subscription', 'revoked');
        // 실제 — 동의했다가 철회(철회하면 동의 시각은 비지만 횟수는 1)
        $this->state($mixed->id, 'email_subscription', ['is_consented' => false, 'consent_count' => 1, 'consented_at' => null, 'revoked_at' => $revokedAt]);
        $this->history($mixed->id, 'email_subscription', 'granted');
        $this->history($mixed->id, 'email_subscription', 'revoked');
        // 실제 — 지금 동의 중
        $this->state($mixed->id, 'marketing_consent', ['is_consented' => true, 'consent_count' => 1, 'consented_at' => now()->subDays(5), 'revoked_at' => null]);
        $this->history($mixed->id, 'marketing_consent', 'granted');
        // 애매 — 횟수는 0 인데 동의 이력이 있다(남긴다)
        $this->state($mixed->id, 'third_party_consent', ['is_consented' => false, 'consent_count' => 0, 'consented_at' => null, 'revoked_at' => $revokedAt]);
        $this->history($mixed->id, 'third_party_consent', 'granted');
        $this->history($mixed->id, 'third_party_consent', 'revoked');
        // 애매 — 철회 이력만 있지만 동의 횟수가 남아 있다(이력 이전의 동의, 남긴다)
        $this->state($mixed->id, 'info_disclosure', ['is_consented' => false, 'consent_count' => 2, 'consented_at' => null, 'revoked_at' => $revokedAt]);
        $this->history($mixed->id, 'info_disclosure', 'revoked');

        $this->runStep();

        $this->assertSame(['histories' => [], 'states' => []], $this->remaining($phantomOnly->id), '가짜 철회 쌍이 남았다.');

        $this->assertSame([
            'histories' => [
                'email_subscription' => ['granted', 'revoked'],
                'info_disclosure' => ['revoked'],
                'marketing_consent' => ['granted'],
                'third_party_consent' => ['granted', 'revoked'],
            ],
            'states' => ['email_subscription', 'info_disclosure', 'marketing_consent', 'third_party_consent'],
        ], $this->remaining($mixed->id), '실제 동의·철회 쌍이나 판별이 애매한 쌍이 지워졌다(또는 가짜 쌍이 남았다).');

        $this->assertContains('[1.1.2] 동의한 적 없는 항목의 철회 기록 정리 — 쌍 3건 / 이력 3건 / 상태 행 3건 삭제', $this->loggedMessages());
    }

    /**
     * 다시 돌려도 더 지우지 않는다(멱등). 지울 것이 없으면 0건으로 남긴다.
     *
     * @scenario stored=already_clean
     *
     * @effects purge_idempotent, purge_counts_logged
     *
     * @return void 반환값 없음
     */
    public function test_rerun_deletes_nothing_more(): void
    {
        $user = User::factory()->create();
        $this->state($user->id, 'marketing_consent', ['is_consented' => false, 'consent_count' => 0, 'consented_at' => null, 'revoked_at' => now()]);
        $this->history($user->id, 'marketing_consent', 'revoked');
        $this->state($user->id, 'email_subscription', ['is_consented' => false, 'consent_count' => 1, 'consented_at' => null, 'revoked_at' => now()]);
        $this->history($user->id, 'email_subscription', 'granted');
        $this->history($user->id, 'email_subscription', 'revoked');

        $this->runStep();
        $afterFirst = $this->remaining($user->id);
        $this->runStep();

        $this->assertSame(['histories' => ['email_subscription' => ['granted', 'revoked']], 'states' => ['email_subscription']], $afterFirst);
        $this->assertSame($afterFirst, $this->remaining($user->id), '두 번째 실행이 기록을 더 지웠다.');
        $this->assertContains('[1.1.2] 동의한 적 없는 항목의 철회 기록 정리 — 대상 없음', $this->loggedMessages());
    }
}
