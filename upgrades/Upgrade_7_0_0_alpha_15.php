<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 코어 7.0.0-alpha.15 업그레이드 스텝
 *
 * 기존 사용자 레코드에 UUID v7을 백필하고 NOT NULL 제약을 추가합니다.
 * 신규 설치 시에는 User 모델 boot() creating 이벤트에서 UUID가 자동 생성되므로
 * 이 스텝은 기존 운영 DB 업그레이드 시에만 실질적으로 동작합니다.
 */
class Upgrade_7_0_0_alpha_15 implements UpgradeStepInterface
{
    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasColumn('users', 'uuid')) {
            $context->logger->warning('users 테이블에 uuid 컬럼이 없습니다. 마이그레이션을 먼저 실행하세요.');

            return;
        }

        // 1. UUID가 없는 기존 레코드에 백필
        $nullCount = DB::table('users')->whereNull('uuid')->count();

        if ($nullCount > 0) {
            $context->logger->info("UUID 백필 시작: {$nullCount}건의 기존 레코드");

            DB::table('users')->whereNull('uuid')->orderBy('id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['uuid' => Str::orderedUuid()->toString()]);
                }
            });

            $context->logger->info("UUID 백필 완료: {$nullCount}건 처리");
        } else {
            $context->logger->info('모든 사용자 레코드에 UUID가 이미 존재합니다.');
        }

        // 2. NOT NULL 제약 추가 (아직 nullable인 경우)
        $columnType = DB::selectOne("SHOW COLUMNS FROM {$context->table('users')} WHERE Field = 'uuid'");

        if ($columnType && $columnType->Null === 'YES') {
            Schema::table('users', function ($table) {
                $table->uuid('uuid')->nullable(false)->unique()->change();
            });

            $context->logger->info('uuid 컬럼에 NOT NULL 제약을 추가했습니다.');
        }
    }
}
