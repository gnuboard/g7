<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/** 기설치본에도 신규 통지 증적 테이블을 멱등 생성합니다. */
final class CreatePrivacyNoticeLogs implements DataMigration
{
    /**
     * 업그레이드 작업 식별자를 반환합니다.
     *
     * @return string 처리 결과
     */
    public function name(): string
    {
        return 'CreatePrivacyNoticeLogs';
    }

    /**
     * 통지 처리 계약을 수행합니다.
     *
     * @param  UpgradeContext  $context  발송 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (Schema::hasTable('gdpr_privacy_notice_logs')) {
            $context->logger->info('개인정보 통지 이력 테이블 존재: 생성 생략');

            return;
        }
        $migration = require dirname(__DIR__, 4).'/database/migrations/2026_09_19_000001_create_gdpr_privacy_notice_logs_table.php';
        $migration->up();
        $context->logger->info('개인정보 통지 이력 테이블 생성 완료');
    }
}
