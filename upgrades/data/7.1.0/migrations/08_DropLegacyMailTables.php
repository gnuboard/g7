<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 쓰이지 않는 옛 메일 표(`mail_templates`, `mail_send_logs`)를 드랍합니다 (#699 B1).
 *
 * 7.0.0-beta.2 에서 메일 템플릿·발송 이력이 알림 시스템(`notification_templates` · `notification_logs`)으로
 * 일원화된 뒤에도 생성 마이그레이션이 남아 신규 설치에 빈 표가 생겼습니다. 7.1.0 은 생성 마이그레이션을
 * 지웠고(신규 설치에는 아예 없다), 기존 설치에서는 이 스텝이 **안전할 때만** 드랍합니다:
 *  - `mail_templates` : 행이 0건이면 드랍. 행이 있으면 경고 후 보존(운영자가 직접 넣은 데이터일 수 있다).
 *  - `mail_send_logs` : 0건이거나, beta.2 이관이 남긴 `notification_logs.source='migrated_from_mail_send_logs'`
 *    건수가 원본 이상이면 드랍. 아니면 경고 후 보존(이관되지 않은 발송 이력을 지우지 않는다).
 *
 * 마이그레이션 파일로 drop 하지 않는 이유: 업데이트는 마이그레이션 → 재시딩 → 업그레이드 스텝 순서라,
 * 판정(건수 비교) 없이 지우게 된다.
 *
 * V-1 판단: DB·Schema 파사드만 씁니다. idempotent: 표가 없으면 건너뜁니다.
 */
class DropLegacyMailTables implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'DropLegacyMailTables';
    }

    /**
     * 조건을 만족하는 옛 메일 표를 드랍합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $this->dropMailTemplates($context);
        $this->dropMailSendLogs($context);
    }

    /**
     * 비어 있는 mail_templates 를 드랍합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function dropMailTemplates(UpgradeContext $context): void
    {
        if (! Schema::hasTable('mail_templates')) {
            $context->logger->info('[core:7.1.0] mail_templates 부재 — 스킵');

            return;
        }

        $rows = DB::table('mail_templates')->count();
        if ($rows > 0) {
            $context->logger->warning('[core:7.1.0] mail_templates 에 행이 있어 보존합니다 — 7.0.0-beta.2 부터 쓰이지 않는 표입니다. 필요한 내용을 알림 템플릿으로 옮긴 뒤 직접 지우세요.', [
                'rows' => $rows,
            ]);

            return;
        }

        Schema::dropIfExists('mail_templates');
        $context->logger->info('[core:7.1.0] 빈 mail_templates 드랍');
    }

    /**
     * 이관이 끝났거나 비어 있는 mail_send_logs 를 드랍합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function dropMailSendLogs(UpgradeContext $context): void
    {
        if (! Schema::hasTable('mail_send_logs')) {
            $context->logger->info('[core:7.1.0] mail_send_logs 부재 — 스킵');

            return;
        }

        $rows = DB::table('mail_send_logs')->count();
        $migrated = Schema::hasTable('notification_logs')
            ? DB::table('notification_logs')->where('source', 'migrated_from_mail_send_logs')->count()
            : 0;

        if ($rows > 0 && $migrated < $rows) {
            $context->logger->warning('[core:7.1.0] mail_send_logs 에 알림 발송 로그로 옮겨지지 않은 행이 있어 보존합니다.', [
                'rows' => $rows,
                'migrated' => $migrated,
            ]);

            return;
        }

        Schema::dropIfExists('mail_send_logs');
        $context->logger->info('[core:7.1.0] mail_send_logs 드랍', ['rows' => $rows, 'migrated' => $migrated]);
    }
}
