<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 클레임 채번 시퀀스 3종(교환·반품·클레임 추가결제)을 시드합니다.
 *
 * `SequenceSeeder` 는 `SequenceType::cases()` 전수를 돌지만 **모듈 설치 시에만** 실행된다
 * (`ModuleManager::runModuleSeeders()` 는 `installModule()` 안에만 있다). 그래서 1.3.0 이
 * 새로 도입한 `exchange`/`order_return`/`claim_payment` 는 신규 설치본에만 생기고,
 * 기존 설치본이 업그레이드하면 행이 없는 채로 남는다 — 그 상태에서 교환·반품 신청과
 * 클레임 추가결제 생성은 `SequenceService::generateCode()` 가 던지는
 * `SequenceNotFoundException` 으로 **전부 실패**한다.
 *
 * 같은 결함을 이 모듈이 0.11.0 에서 이미 한 번 겪었고(`Upgrade_0_11_0` 이
 * CANCEL/REFUND 를 같은 방식으로 보충한다), 이 파일은 그 선례를 따른다.
 *
 * 설정값은 1.3.0 시점에 **동결**한다 — 이후 버전에서 기본 접두사·알고리즘이 바뀌더라도
 * 1.3.0 업그레이드를 밟는 사이트는 1.3.0 시점의 값을 받는다(스텝별 동작 동일 보장).
 *
 * idempotent: 존재하는 type 은 건드리지 않는다. `current_value` 는 운영 중 증가하는
 * counter 라 되돌리면 이미 발급된 번호를 다시 발급하게 된다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model/Seeder 를 참조하지 않는다.
 */
class SeedClaimSequences implements DataMigration
{
    private const TABLE = 'ecommerce_sequences';

    /**
     * 1.3.0 시점에 동결한 클레임 시퀀스 기본 설정.
     *
     * `SequenceType::getDefaultConfig()` 의 EXCHANGE / ORDER_RETURN / CLAIM_PAYMENT 와
     * 같은 값이며, V-1 안전을 위해 enum 을 참조하지 않고 그대로 옮겨 적었다.
     *
     * @var array<string, array{algorithm: string, prefix: string}>
     */
    private const SEQUENCES = [
        'exchange' => ['algorithm' => 'timestamp', 'prefix' => 'EX'],
        'order_return' => ['algorithm' => 'timestamp', 'prefix' => 'RT'],
        'claim_payment' => ['algorithm' => 'timestamp', 'prefix' => 'CP'],
    ];

    public function name(): string
    {
        return 'SeedClaimSequences';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->warning('[ecommerce:1.3.0] '.self::TABLE.' 테이블 미존재 — 스킵');

            return;
        }

        $now = now();
        $created = 0;

        foreach (self::SEQUENCES as $type => $config) {
            $exists = DB::table(self::TABLE)->where('type', $type)->exists();

            if ($exists) {
                $context->logger->info('[ecommerce:1.3.0] '.$type.' 시퀀스 존재 — 채번 상태 보존');

                continue;
            }

            DB::table(self::TABLE)->insert([
                'type' => $type,
                'algorithm' => $config['algorithm'],
                'prefix' => $config['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => PHP_INT_MAX,
                'cycle' => false,
                'pad_length' => 0,
                'max_history_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
            $context->logger->info(
                '[ecommerce:1.3.0] '.$type.' 시퀀스 생성 완료 (접두사: '.$config['prefix'].')'
            );
        }

        $context->logger->info('[ecommerce:1.3.0] 클레임 시퀀스 시드 완료 — 신규 '.$created.'건');
    }
}
