<?php

namespace App\Upgrades\Data\Ext\Plugins\SirsoftVerificationKginicis\V1_1_4\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 회원의 본인확인 record 로 동일인 선점표를 채웁니다.
 *
 * 배경:
 *   1.1.4 부터 가입 트랜잭션 안에서 `inicis_identity_claims` 의 UNIQUE(hash_type, hash) 로 동일인을 선점한다
 *   (KVE-2026-3192 동형). 기설치본은 선점표가 비어 있으므로, 이미 가입한 회원의 DI/CI 해시를 records 에서 옮겨
 *   둔다 — 그래야 같은 명의의 신규 가입이 가입 직전 조기 판정·가입 트랜잭션 양쪽에서 막힌다.
 *
 * 규칙:
 *   - di·ci 각각 `records JOIN users ORDER BY users.id` 순서로 청크 `insertOrIgnore` — 먼저 가입한 계정이 선점한다.
 *   - 같은 해시를 가진 회원이 둘 이상이면(차단을 꺼 두었던 기간의 가입 등) 해시 앞 8자와 회원 ID 목록을 warning 으로
 *     남긴다 — 운영자가 확인할 몫이고, 이 스텝은 어느 쪽도 지우지 않는다.
 *   - records 는 읽기만 한다.
 *
 * 멱등: `insertOrIgnore` 라 재실행해도 이미 있는 선점은 그대로다(먼저 들어간 행 유지).
 * 선점표가 아직 없으면(마이그레이션 전) 건너뛴다.
 *
 * V-1 안전: `DB`·`Schema` 파사드만 사용하고 플러그인 Model·Repository·Service 를 해석하지 않는다
 * (업데이트 프로세스에는 옛 버전 프로바이더만 등록돼 있다 — 업그레이드 가이드 원칙 5). 테이블명은 1.1.4 시점 값으로 동결.
 */
class BackfillIdentityClaims implements DataMigration
{
    /** 본인확인 record 테이블 (1.1.4 시점 동결) */
    private const RECORDS_TABLE = 'inicis_identity_records';

    /** 동일인 선점표 (1.1.4 시점 동결) */
    private const CLAIMS_TABLE = 'inicis_identity_claims';

    /** 식별값 종류 → records 해시 컬럼 */
    private const HASH_COLUMNS = [
        'di' => 'di_hash',
        'ci' => 'ci_hash',
    ];

    /** 청크 크기 */
    private const CHUNK = 500;

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'BackfillIdentityClaims';
    }

    /**
     * 선점표를 records 로 채웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::CLAIMS_TABLE)) {
            $context->logger->info('[sirsoft-verification_kginicis 1.1.4] 선점표가 아직 없음(마이그레이션 전) — 백필 skip');

            return;
        }

        if (! Schema::hasTable(self::RECORDS_TABLE)) {
            $context->logger->info('[sirsoft-verification_kginicis 1.1.4] 본인확인 record 테이블 없음 — 백필 skip');

            return;
        }

        foreach (self::HASH_COLUMNS as $type => $column) {
            $inserted = $this->backfillType($type, $column);

            $context->logger->info('[sirsoft-verification_kginicis 1.1.4] 동일인 선점 백필', [
                'hash_type' => $type,
                'inserted' => $inserted,
            ]);

            $this->warnSharedHashes($context, $type, $column);
        }
    }

    /**
     * 한 식별값 종류를 가입 순서(users.id)대로 선점표에 넣습니다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $column  records 해시 컬럼
     * @return int 새로 넣은 행 수
     */
    private function backfillType(string $type, string $column): int
    {
        $inserted = 0;
        $now = Carbon::now();

        DB::table(self::RECORDS_TABLE.' as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereNotNull('r.'.$column)
            ->where('r.'.$column, '!=', '')
            ->orderBy('u.id')
            ->select(['r.user_id as user_id', 'r.'.$column.' as hash'])
            ->chunk(self::CHUNK, function ($rows) use ($type, $now, &$inserted): void {
                $payload = [];
                foreach ($rows as $row) {
                    $payload[] = [
                        'hash_type' => $type,
                        'hash' => (string) $row->hash,
                        'user_id' => (int) $row->user_id,
                        'created_at' => $now,
                    ];
                }

                // 한 청크 안에서도 같은 해시가 여러 번 나오면 먼저 나온(가입이 빠른) 행만 남는다
                $inserted += DB::table(self::CLAIMS_TABLE)->insertOrIgnore($payload);
            });

        return $inserted;
    }

    /**
     * 같은 해시를 가진 회원이 둘 이상인 경우를 warning 으로 남깁니다 (해시 앞 8자 + 회원 ID 목록).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $column  records 해시 컬럼
     * @return void 반환값 없음
     */
    private function warnSharedHashes(UpgradeContext $context, string $type, string $column): void
    {
        $shared = DB::table(self::RECORDS_TABLE.' as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->whereNotNull('r.'.$column)
            ->where('r.'.$column, '!=', '')
            ->groupBy('r.'.$column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck('r.'.$column);

        foreach ($shared as $hash) {
            $userIds = DB::table(self::RECORDS_TABLE.' as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.'.$column, $hash)
                ->orderBy('u.id')
                ->pluck('u.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $context->logger->warning('[sirsoft-verification_kginicis 1.1.4] 같은 식별값을 가진 회원이 둘 이상 — 먼저 가입한 회원이 선점', [
                'hash_type' => $type,
                'hash_prefix' => substr((string) $hash, 0, 8),
                'user_ids' => $userIds,
            ]);
        }
    }
}
