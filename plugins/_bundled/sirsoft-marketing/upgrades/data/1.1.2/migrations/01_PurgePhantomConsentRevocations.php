<?php

namespace App\Upgrades\Data\Ext\Plugins\SirsoftMarketing\V1_1_2\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 동의한 적 없는 항목에 남은 「철회」 기록을 지웁니다.
 *
 * 배경:
 *   1.1.1 까지는 동의 기록이 없는 회원이 동의 칸을 끈 채 저장하면(회원가입, 정보 수정, 관리자 회원 추가와 수정)
 *   항목마다 상태 행(동의 안 함, 철회 시각 있음)과 철회 이력이 생겼습니다. 철회할 동의가 없었으므로 이 기록은
 *   일어나지 않은 철회이며, 관리자 회원 상세의 동의 이력에 그대로 보였습니다. 1.1.2 부터는 이런 저장이 기록을
 *   남기지 않으므로, 이미 쌓인 기록만 정리하면 됩니다.
 *
 * 대상 (회원, 항목) 쌍:
 *   - 상태 행이 동의 안 함(`is_consented = 0`)이고 동의한 적이 없다(`consent_count = 0`, `consented_at` 없음)
 *   - 그 쌍에 동의(`granted`) 이력이 한 건도 없다
 *   대상 쌍의 철회 이력과 상태 행을 지웁니다. 상태 행이 없어도 화면 값은 같은 「동의 안 함」입니다.
 *
 * 보존:
 *   - 동의했다가 철회한 쌍(동의 이력 있음, 횟수 1 이상), 지금 동의 중인 쌍
 *   - 판별이 애매한 쌍 — 횟수는 0 인데 동의 이력이 있는 쌍, 철회 이력만 있는데 동의 횟수가 남은 쌍
 *
 * 멱등: 지운 뒤에는 조건에 맞는 쌍이 없으므로 다시 실행해도 더 지우지 않습니다(「대상 없음」으로 기록).
 *
 * 기록: 지운 쌍, 이력, 상태 행 건수를 업그레이드 로그에 남깁니다. 이력 테이블은 원래 지우지 않는 기록이므로
 *   이 정리가 있었다는 사실을 로그로 남겨 둡니다.
 *
 * V-1 안전: `Illuminate\Support\Facades\*` 와 로컬 private 헬퍼만 사용합니다(플러그인 모델·서비스 미참조).
 */
class PurgePhantomConsentRevocations implements DataMigration
{
    /** 상태 테이블 (1.1.2 시점 동결) */
    private const STATE_TABLE = 'user_marketing_consents';

    /** 이력 테이블 (1.1.2 시점 동결) */
    private const HISTORY_TABLE = 'user_marketing_consent_histories';

    /** 한 번에 처리할 쌍 수 */
    private const CHUNK = 500;

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'PurgePhantomConsentRevocations';
    }

    /**
     * 동의한 적 없는 (회원, 항목) 쌍의 철회 이력과 상태 행을 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::STATE_TABLE) || ! Schema::hasTable(self::HISTORY_TABLE)) {
            $context->logger->info('[1.1.2] 동의한 적 없는 항목의 철회 기록 정리 — 동의 테이블이 없어 건너뜁니다');

            return;
        }

        $pairs = 0;
        $histories = 0;
        $states = 0;

        $this->phantomPairQuery()->chunkById(self::CHUNK, function ($rows) use (&$pairs, &$histories, &$states) {
            DB::transaction(function () use ($rows, &$pairs, &$histories, &$states) {
                foreach ($rows as $row) {
                    $histories += DB::table(self::HISTORY_TABLE)
                        ->where('user_id', $row->user_id)
                        ->where('channel_key', $row->consent_key)
                        ->where('action', 'revoked')
                        ->delete();

                    $deleted = DB::table(self::STATE_TABLE)
                        ->where('id', $row->id)
                        ->where('is_consented', false)
                        ->where('consent_count', 0)
                        ->whereNull('consented_at')
                        ->delete();

                    $states += $deleted;
                    $pairs += $deleted > 0 ? 1 : 0;
                }
            });
        });

        if ($pairs === 0 && $histories === 0 && $states === 0) {
            $context->logger->info('[1.1.2] 동의한 적 없는 항목의 철회 기록 정리 — 대상 없음');

            return;
        }

        $context->logger->info(sprintf(
            '[1.1.2] 동의한 적 없는 항목의 철회 기록 정리 — 쌍 %d건 / 이력 %d건 / 상태 행 %d건 삭제',
            $pairs,
            $histories,
            $states,
        ));
    }

    /**
     * 동의한 적 없는 (회원, 항목) 쌍의 상태 행을 고르는 질의를 만듭니다.
     *
     * @return Builder 상태 행 질의 (id, user_id, consent_key)
     */
    private function phantomPairQuery(): Builder
    {
        return DB::table(self::STATE_TABLE)
            ->select(['id', 'user_id', 'consent_key'])
            ->where('is_consented', false)
            ->where('consent_count', 0)
            ->whereNull('consented_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from(self::HISTORY_TABLE)
                    ->whereColumn(self::HISTORY_TABLE.'.user_id', self::STATE_TABLE.'.user_id')
                    ->whereColumn(self::HISTORY_TABLE.'.channel_key', self::STATE_TABLE.'.consent_key')
                    ->where(self::HISTORY_TABLE.'.action', 'granted');
            });
    }
}
