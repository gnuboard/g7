<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 탈퇴 회원에게 남은 마일리지를 탈퇴 소멸 계약대로 소멸 기록합니다.
 *
 * 회원 탈퇴는 남은 마일리지를 「탈퇴로 소멸」 거래로 기록해 0 으로 만들고 거래 행은 남긴다
 * (`UserMileageCleanupListener` → `UserMileageService::forfeitForWithdrawal`). 1.3.0 의 관리자 수동 지급·유효기간 연장은
 * 탈퇴 회원을 거르지 않아 탈퇴 뒤 지급된 잔액이 쓸 수 없는 채 남을 수 있었다. 1.3.1 은 그 경로를 막고, 이미 남은 잔액은
 * 여기서 탈퇴 때와 같은 모양으로 소멸 기록한다.
 *
 * 1.3.1 시점 `forfeitForWithdrawal` 의 원장 동작을 이 파일에 동결한다(버전별 데이터 스냅샷 규약 — 런타임 서비스를 부르지 않는다):
 *   - 활성 적립건(남은 금액 > 0, 미소멸, 유효기간 안)마다 `expired` 거래 한 행: 금액 = −남은 금액, 남은 금액 0,
 *     `source_transaction_id` = 적립건, `expired_at` = 지금, `metadata.reason` = `withdrawal`, 거래 뒤 잔액 = 그 통화 활성 합 − 남은 금액
 *   - 적립건은 남은 금액 0·`expired_at` = 지금
 *   - 잔액 캐시는 소멸이 바꾸는 칸(사용 가능 잔액)만 다시 맞춘다 — 누적 적립·사용은 소멸 거래를 세지 않는다
 * 활동 로그는 남기지 않는다(업그레이드 로그에 회원·적립건 수를 남긴다). 원장 거래 행이 정본 기록이다.
 *
 * 대상: 상태가 `withdrawn` 인 회원의 활성 적립건. 유효기간이 지났는데 아직 소멸 배치가 돌지 않은 적립건은 활성이 아니다 —
 * 소멸 배치가 종전대로 정리한다.
 *
 * idempotent: 소멸한 적립건은 활성이 아니므로 다시 돌리면 대상 0명이다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 클래스를 참조하지 않는다(금액 표기 도우미는 있을 때만 쓴다).
 */
class ForfeitWithdrawnMemberMileage implements DataMigration
{
    /** 1.3.1 시점 탈퇴 상태 값 — 코어 UserStatus::Withdrawn 동결본 */
    private const WITHDRAWN = 'withdrawn';

    /** 1.3.1 시점 소멸 거래 유형 값 — MileageTransactionTypeEnum::EXPIRED 동결본 */
    private const TYPE_EXPIRED = 'expired';

    /** 원장 테이블 */
    private const LEDGER = 'ecommerce_mileage_transactions';

    /** 잔액 캐시 테이블 */
    private const BALANCES = 'ecommerce_mileage_balances';

    /** 탈퇴 소멸 거래 설명 키 — 런타임 탈퇴 소멸과 같은 문구 */
    private const DESCRIPTION_KEY = 'sirsoft-ecommerce::activity_log.description.mileage_withdrawal_expire';

    /** 한 번에 읽을 회원 수 */
    private const CHUNK = 200;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ForfeitWithdrawnMemberMileage';
    }

    /**
     * 탈퇴 회원의 활성 적립건을 소멸 기록합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable(self::LEDGER)) {
            $context->logger->warning('[ecommerce:1.3.1] users 또는 마일리지 원장 테이블 미존재 — 탈퇴 회원 마일리지 소멸 스킵');

            return;
        }

        $users = 0;
        $lots = 0;
        $failed = 0;

        DB::table('users')
            ->select('id')
            ->where('status', self::WITHDRAWN)
            ->whereExists(function ($query) {
                $this->activeLots($query->select(DB::raw(1))->from(self::LEDGER)->whereColumn(self::LEDGER.'.user_id', 'users.id'));
            })
            ->chunkById(self::CHUNK, function ($rows) use ($context, &$users, &$lots, &$failed) {
                foreach ($rows as $row) {
                    $userId = (int) $row->id;

                    try {
                        $count = $this->forfeitUser($userId);
                    } catch (\Throwable $e) {
                        $failed++;
                        $context->logger->warning('[ecommerce:1.3.1] 탈퇴 회원 마일리지 소멸 실패 — 건너뜀 (관리자 수동 차감으로 정리 가능)', [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    if ($count > 0) {
                        $users++;
                        $lots += $count;
                        $context->logger->info('[ecommerce:1.3.1] 탈퇴 회원 마일리지 소멸 기록', ['user_id' => $userId, 'lots' => $count]);
                    }
                }
            }, 'id');

        if ($users === 0 && $failed === 0) {
            $context->logger->info('[ecommerce:1.3.1] 잔액이 남은 탈퇴 회원 없음 — 소멸 대상 0명 (idempotent)');

            return;
        }

        $context->logger->info('[ecommerce:1.3.1] 탈퇴 회원 마일리지 소멸 완료', [
            'users' => $users,
            'lots' => $lots,
            'failed' => $failed,
        ]);
    }

    /**
     * 한 회원의 활성 적립건을 잠그고 소멸 기록한 뒤 잔액 캐시의 사용 가능 잔액을 맞춥니다.
     *
     * @param  int  $userId  탈퇴 회원 ID
     * @return int 소멸 기록한 적립건 수
     */
    private function forfeitUser(int $userId): int
    {
        return DB::transaction(function () use ($userId) {
            $now = now();
            $lots = $this->activeLots(DB::table(self::LEDGER)->where('user_id', $userId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'currency', 'remaining_amount']);

            $currencies = [];
            $count = 0;

            foreach ($lots as $lot) {
                $remaining = (float) $lot->remaining_amount;
                if ($remaining <= 0) {
                    continue;
                }

                $balance = (float) $this->activeLots(DB::table(self::LEDGER)->where('user_id', $userId)->where('currency', $lot->currency))
                    ->sum('remaining_amount');

                DB::table(self::LEDGER)->insert([
                    'user_id' => $userId,
                    'currency' => $lot->currency,
                    'type' => self::TYPE_EXPIRED,
                    'amount' => -$remaining,
                    'remaining_amount' => 0,
                    'balance_after' => $balance - $remaining,
                    'source_transaction_id' => $lot->id,
                    'expired_at' => $now,
                    'metadata' => json_encode(['reason' => 'withdrawal']),
                    'description' => mb_substr(__(self::DESCRIPTION_KEY, ['amount' => $this->formatAmount($remaining, (string) $lot->currency)]), 0, 500),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table(self::LEDGER)->where('id', $lot->id)->update([
                    'remaining_amount' => 0,
                    'expired_at' => $now,
                    'updated_at' => $now,
                ]);

                $currencies[(string) $lot->currency] = true;
                $count++;
            }

            if (Schema::hasTable(self::BALANCES)) {
                foreach (array_keys($currencies) as $currency) {
                    $available = (float) $this->activeLots(DB::table(self::LEDGER)->where('user_id', $userId)->where('currency', $currency))
                        ->sum('remaining_amount');

                    DB::table(self::BALANCES)
                        ->where('user_id', $userId)
                        ->where('currency', $currency)
                        ->update(['available' => $available, 'recalculated_at' => $now, 'updated_at' => $now]);
                }
            }

            return $count;
        });
    }

    /**
     * 활성 적립건 조건(남은 금액 > 0, 미소멸, 유효기간 안)을 붙입니다 — 1.3.1 MileageTransaction::scopeActive 동결본.
     *
     * @param  Builder  $query  원장 질의
     * @return Builder 조건을 붙인 질의
     */
    private function activeLots($query)
    {
        $now = now();

        return $query->where(self::LEDGER.'.remaining_amount', '>', 0)
            ->whereNull(self::LEDGER.'.expired_at')
            ->where(function ($q) use ($now) {
                $q->whereNull(self::LEDGER.'.expires_at')
                    ->orWhere(self::LEDGER.'.expires_at', '>', $now);
            });
    }

    /**
     * 설명 문구의 금액을 표기합니다 — 런타임과 같은 도우미가 있으면 쓰고, 없으면 숫자와 통화 코드로 적습니다.
     *
     * @param  float  $amount  금액
     * @param  string  $currency  통화 코드
     * @return string 표기
     */
    private function formatAmount(float $amount, string $currency): string
    {
        if (function_exists('ecommerce_format_price')) {
            try {
                return (string) ecommerce_format_price($amount, $currency);
            } catch (\Throwable) {
                // 통화 설정을 읽지 못하면 아래 표기로 둔다
            }
        }

        return number_format($amount, floor($amount) === $amount ? 0 : 2).' '.$currency;
    }
}
