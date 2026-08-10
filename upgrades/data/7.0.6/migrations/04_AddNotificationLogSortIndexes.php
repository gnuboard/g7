<?php

namespace App\Upgrades\Data\V7_0_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 발송 이력 목록의 정렬 인덱스를 추가합니다.
 *
 * 목록은 지연 조인으로 페이지네이션되는데, 그 전제는 "정렬은 인덱스가 있는 닫힌 집합" 입니다.
 * 인덱스가 없으면 inner 쿼리도 전체 스캔이 되어 깊은 OFFSET 개선 폭이 사라집니다. 화면 정렬
 * 셀렉트가 수신자명순·제목순을 제공하므로 두 컬럼에 인덱스를 둡니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드 시점에
 * 동일 인덱스를 추가합니다.
 *
 * 발송 이력이 많은 사이트에서는 ALTER TABLE 이 수 분 걸릴 수 있고 그동안 알림 발송 기록
 * 쓰기가 대기합니다. 진행 상황을 로그로 남깁니다.
 *
 * idempotent: 이미 존재하는 인덱스는 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddNotificationLogSortIndexes implements DataMigration
{
    private const TABLE = 'notification_logs';

    /**
     * 추가할 인덱스 정의 (이름 => 컬럼 목록)
     *
     * @var array<string, array<int, string>>
     */
    private const INDEXES = [
        'idx_notification_logs_recipient_name' => ['recipient_name'],
        'idx_notification_logs_subject' => ['subject'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddNotificationLogSortIndexes';
    }

    /**
     * 정렬 인덱스를 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[core:7.0.6] 알림 발송 이력 테이블 부재 — 정렬 인덱스 추가 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        foreach (self::INDEXES as $name => $columns) {
            if (in_array($name, $existing, true)) {
                $context->logger->info("[core:7.0.6] 이미 존재하는 인덱스 — 스킵: {$name}");

                continue;
            }

            $context->logger->info("[core:7.0.6] 정렬 인덱스 추가 시작: {$name} (발송 이력이 많으면 수 분 걸릴 수 있고 그동안 알림 발송 기록 쓰기가 대기합니다)");

            Schema::table(self::TABLE, function ($table) use ($columns, $name) {
                $table->index($columns, $name);
            });

            $context->logger->info("[core:7.0.6] 정렬 인덱스 추가 완료: {$name}");
        }
    }
}
