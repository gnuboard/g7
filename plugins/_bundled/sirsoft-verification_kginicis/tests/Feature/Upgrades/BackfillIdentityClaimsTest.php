<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;
use Plugins\Sirsoft\VerificationKginicis\Upgrades\Upgrade_1_1_4;
use Tests\Support\UpgradeFiles;

/**
 * 1.1.4 업그레이드 스텝 — 기존 회원의 본인확인 record 로 동일인 선점표 백필 (KVE-2026-3192 동형).
 *
 * di·ci 각각 가입 순서(users.id)대로 선점표에 넣어 먼저 가입한 계정이 선점하고, 같은 해시를 가진 회원이 둘 이상이면
 * 해시 앞 8자와 회원 ID 목록을 warning 으로 남긴다. records 는 건드리지 않고, 재실행해도 결과가 같다.
 *
 * @since 1.1.4
 */
class BackfillIdentityClaimsTest extends PluginTestCase
{
    /** 시험 전용 로그 채널 이름 */
    private const LOG_CHANNEL = 'kginicis-upgrade-test';

    /** 플러그인 경로 (프로젝트 루트 기준) */
    private const PLUGIN_PATH = 'plugins/_bundled/sirsoft-verification_kginicis';

    /** 본인확인 record 테이블 */
    private const RECORDS_TABLE = 'inicis_identity_records';

    /** 동일인 선점표 */
    private const CLAIMS_TABLE = 'inicis_identity_claims';

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
     * 같은 해시는 먼저 가입한 회원이 선점하고, 공유 해시는 warning 으로 남기며 records 는 그대로인지 확인합니다.
     *
     * @scenario upgrade=backfill, stored=shared_and_unique_hashes
     *
     * @effects backfill_first_registered_user_holds_claim, backfill_warns_shared_identity_hash
     *
     * @return void 반환값 없음
     */
    public function test_first_registered_user_holds_claim_and_shared_hash_is_warned(): void
    {
        [$first, $unique, $later] = User::factory()->count(3)->create()->all();
        $shared = hash('sha256', 'backfill-shared-di');
        $uniqueDi = hash('sha256', 'backfill-unique-di');
        $firstCi = hash('sha256', 'backfill-first-ci');

        // 삽입 순서와 가입 순서를 일부러 뒤집는다 — 선점은 records 행 순서가 아니라 users.id 순서를 따라야 한다
        $this->record((int) $later->id, $shared, null);
        $this->record((int) $unique->id, $uniqueDi, null);
        $this->record((int) $first->id, $shared, $firstCi);
        $recordsBefore = DB::table(self::RECORDS_TABLE)->orderBy('id')->get()->toArray();

        $this->runStep();

        $this->assertSame((int) $first->id, $this->holder('di', $shared), '먼저 가입한 회원이 선점해야 한다');
        $this->assertSame((int) $unique->id, $this->holder('di', $uniqueDi));
        $this->assertSame((int) $first->id, $this->holder('ci', $firstCi));
        $this->assertSame(3, DB::table(self::CLAIMS_TABLE)->count());
        $this->assertEquals($recordsBefore, DB::table(self::RECORDS_TABLE)->orderBy('id')->get()->toArray(), 'records 는 바뀌면 안 된다');

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings, '공유 해시 1건만 warning 으로 남아야 한다');
        $this->assertSame('di', $warnings[0]['hash_type']);
        $this->assertSame(substr($shared, 0, 8), $warnings[0]['hash_prefix']);
        $this->assertSame([(int) $first->id, (int) $later->id], $warnings[0]['user_ids']);
    }

    /**
     * 다시 실행해도 선점이 바뀌지 않고, 백필 전에 이미 있던 선점도 덮어쓰지 않는지 확인합니다.
     *
     * @scenario upgrade=backfill, stored=rerun_with_existing_claim
     *
     * @effects backfill_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_rerun_is_idempotent_and_keeps_existing_claims(): void
    {
        [$owner, $runtimeHolder, $other] = User::factory()->count(3)->create()->all();
        $ownerDi = hash('sha256', 'backfill-owner-di');
        $alreadyClaimed = hash('sha256', 'backfill-already-claimed-di');

        $this->record((int) $owner->id, $ownerDi, null);
        $this->record((int) $other->id, $alreadyClaimed, null);
        // 업데이트 직후 새 버전으로 들어온 가입이 먼저 선점한 상황
        DB::table(self::CLAIMS_TABLE)->insert([
            'hash_type' => 'di',
            'hash' => $alreadyClaimed,
            'user_id' => (int) $runtimeHolder->id,
            'created_at' => now(),
        ]);

        $this->runStep();
        $afterFirst = DB::table(self::CLAIMS_TABLE)->orderBy('id')->get(['hash_type', 'hash', 'user_id'])->toArray();

        $this->runStep();
        $afterSecond = DB::table(self::CLAIMS_TABLE)->orderBy('id')->get(['hash_type', 'hash', 'user_id'])->toArray();

        $this->assertEquals($afterFirst, $afterSecond, '재실행해도 선점표가 같아야 한다');
        $this->assertSame(2, DB::table(self::CLAIMS_TABLE)->count());
        $this->assertSame((int) $owner->id, $this->holder('di', $ownerDi));
        $this->assertSame((int) $runtimeHolder->id, $this->holder('di', $alreadyClaimed), '이미 있던 선점을 덮어쓰면 안 된다');
    }

    /**
     * 업그레이드 스텝을 실제 실행 경로(스텝 → data/1.1.4/migrations)로 돌립니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        $stepFile = base_path(self::PLUGIN_PATH.'/upgrades/Upgrade_1_1_4.php');
        $this->assertFileExists($stepFile, '1.1.4 업그레이드 스텝이 없다.');

        // 업그레이드 스텝은 업데이트 실행기가 파일로 불러온다 — 일반 autoload 대상이 아니다.
        UpgradeFiles::requireOnce($stepFile);

        (new Upgrade_1_1_4)->run(new UpgradeContext('1.1.3', '1.1.4', '1.1.4', self::LOG_CHANNEL));
    }

    /**
     * 본인확인 record 를 직접 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $diHash  DI 해시
     * @param  string|null  $ciHash  CI 해시
     * @return void 반환값 없음
     */
    private function record(int $userId, ?string $diHash, ?string $ciHash): void
    {
        DB::table(self::RECORDS_TABLE)->insert([
            'user_id' => $userId,
            'di_hash' => $diHash,
            'ci_hash' => $ciHash,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 해시를 쥔 회원 ID 를 읽습니다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @return int|null 회원 ID (없으면 null)
     */
    private function holder(string $type, string $hash): ?int
    {
        $userId = DB::table(self::CLAIMS_TABLE)->where('hash_type', $type)->where('hash', $hash)->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * 시험 채널에 남은 warning 의 문맥을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> warning 문맥 목록
     */
    private function warnings(): array
    {
        $handler = Log::channel(self::LOG_CHANNEL)->getLogger()->getHandlers()[0];

        return array_values(array_map(
            static fn ($record) => $record->context,
            array_filter($handler->getRecords(), static fn ($record) => $record->level->name === 'Warning'),
        ));
    }
}
