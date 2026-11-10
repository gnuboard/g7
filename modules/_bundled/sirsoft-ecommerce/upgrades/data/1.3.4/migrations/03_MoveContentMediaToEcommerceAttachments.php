<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_4\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 개발 중 전용 표(`ecommerce_content_media`)에 쌓인 본문 미디어를 이 모듈 범용 첨부 표(`ecommerce_attachments`, collection =
 * `content_media`)로 옮기고 전용 표를 지웁니다 (#887).
 *
 * 1.3.4 개발 중 상품 설명·공통 정보 본문 미디어를 전용 표에 두었다가 출시 전에 게시판·페이지와 같은 모양의 범용 첨부 표로 옮겼다.
 * 공개판(1.3.3 이하)에는 전용 표가 없으므로 공개 업그레이드에서는 아무것도 하지 않는다. 이미 전용 표를 만든 개발 설치본만 대상이다.
 *
 * - 해시를 그대로 옮긴다 — 저장된 상품 설명·공통 정보 본문이 `/api/modules/sirsoft-ecommerce/content-media/{hash}` 로 참조한다.
 * - 파일은 옮기지 않는다 — 두 표 모두 `path` 가 모듈 저장소 카테고리 `content-media` 기준 상대 경로(`{owner_type}/Y/m/d/{uuid}.ext`)
 *   라서 디스크·경로 값을 그대로 옮기면 서빙이 같은 파일을 읽는다.
 * - 소유 종류 → `attachmentable_type`(상품·공통 정보 모델 클래스), 소유자 → `attachmentable_id`, 임시 키·meta·올린 사람·시각·소프트
 *   삭제 시각은 그대로. 종류(kind)는 옮기지 않는다 — 새 표는 MIME 으로 판정한다.
 * - 새 표가 아직 없으면(모듈 마이그레이션 미적용) 옮기지 않고 전용 표를 남긴다(경고).
 * - 같은 해시가 새 표에 이미 있으면: 같은 본문 미디어(컬렉션·소유·경로가 같음)면 옮긴 것으로 보고 건너뛰고, 다른 행이면 충돌로 기록하고
 *   **전용 표를 지우지 않는다**(행을 잃지 않게). 소유 종류를 알 수 없는 행도 같다.
 * - 모두 옮겨졌으면 전용 표와 그 표를 만들던 마이그레이션 기록을 지운다(그 마이그레이션 파일은 삭제됐다).
 *
 * 1.3.4 시점 판정을 이 파일에 동결한다(버전별 데이터 스냅샷 규약 — 모듈 모델·서비스를 부르지 않는다).
 *
 * idempotent: 전용 표가 없으면 no-op(기록 정리만), 옮긴 행은 해시로 건너뛴다. V-1 안전: Facades\DB·Schema 만 사용.
 */
class MoveContentMediaToEcommerceAttachments implements DataMigration
{
    private const LEGACY_TABLE = 'ecommerce_content_media';

    private const TARGET_TABLE = 'ecommerce_attachments';

    private const LEGACY_MIGRATION = '2026_11_10_000001_create_ecommerce_content_media_table';

    private const COLLECTION = 'content_media';

    /** @var array<string, string> 옛 owner_type => attachmentable_type */
    private const OWNER_TYPES = [
        'product' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Product',
        'common_info' => 'Modules\\Sirsoft\\Ecommerce\\Models\\ProductCommonInfo',
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MoveContentMediaToEcommerceAttachments';
    }

    /**
     * 전용 표의 본문 미디어를 범용 첨부 표로 옮기고, 모두 옮겼으면 전용 표를 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::LEGACY_TABLE)) {
            $context->logger->info('[ecommerce:1.3.4] 본문 미디어 전용 표 부재 — 범용 첨부 표 이관 스킵(공개 업그레이드·이미 옮김)');
            $this->forgetLegacyMigration($context);

            return;
        }

        if (! Schema::hasTable(self::TARGET_TABLE)) {
            $context->logger->warning('[ecommerce:1.3.4] 범용 첨부 표(ecommerce_attachments) 없음(모듈 마이그레이션 미적용) — 본문 미디어 이관 보류, 전용 표 유지');

            return;
        }

        $moved = 0;
        $skipped = 0;
        $conflicts = [];

        DB::table(self::LEGACY_TABLE)->orderBy('id')->chunk(200, function ($rows) use (&$moved, &$skipped, &$conflicts) {
            foreach ($rows as $row) {
                $type = self::OWNER_TYPES[$row->owner_type] ?? null;
                if ($type === null) {
                    $conflicts[] = ['hash' => $row->hash, 'reason' => 'unknown_owner_type', 'owner_type' => $row->owner_type];

                    continue;
                }

                $existing = DB::table(self::TARGET_TABLE)->where('hash', $row->hash)->first();
                if ($existing !== null) {
                    if ($this->isSameMedia($existing, $row, $type)) {
                        $skipped++;
                    } else {
                        $conflicts[] = ['hash' => $row->hash, 'reason' => 'hash_conflict', 'existing_id' => $existing->id];
                    }

                    continue;
                }

                DB::table(self::TARGET_TABLE)->insert([
                    'attachmentable_type' => $type,
                    'attachmentable_id' => $row->owner_id,
                    'collection' => self::COLLECTION,
                    'temp_key' => $row->temp_key,
                    'hash' => $row->hash,
                    'original_filename' => $row->original_filename,
                    'stored_filename' => $row->stored_filename,
                    'disk' => $row->disk,
                    'path' => $row->path,
                    'mime_type' => $row->mime_type,
                    'size' => (int) $row->size,
                    'meta' => $row->meta,
                    'order' => 0,
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'deleted_at' => $row->deleted_at,
                ]);
                $moved++;
            }
        });

        if ($conflicts !== []) {
            $context->logger->warning('[ecommerce:1.3.4] 본문 미디어 일부를 옮기지 못함(해시 충돌·알 수 없는 소유 종류) — 전용 표 유지', [
                'moved' => $moved,
                'skipped' => $skipped,
                'conflicts' => $conflicts,
            ]);

            return;
        }

        Schema::drop(self::LEGACY_TABLE);
        $this->forgetLegacyMigration($context);

        $context->logger->info("[ecommerce:1.3.4] 본문 미디어 전용 표 → 범용 첨부 표 이관 완료 (옮김 {$moved}, 이미 있음 {$skipped}) — 전용 표 삭제");
    }

    /**
     * 새 표의 같은 해시 행이 이 전용 표 행을 옮긴 것인지 — 본문 미디어 컬렉션이고 소유·디스크·경로가 같을 때.
     *
     * @param  object  $existing  새 표 행
     * @param  object  $row  전용 표 행
     * @param  string  $type  옮길 소유 모델 클래스
     * @return bool 같은 본문 미디어면 true
     */
    private function isSameMedia(object $existing, object $row, string $type): bool
    {
        return $existing->collection === self::COLLECTION
            && $existing->attachmentable_type === $type
            && (string) $existing->attachmentable_id === (string) $row->owner_id
            && $existing->disk === $row->disk
            && $existing->path === $row->path;
    }

    /**
     * 지운 전용 표 마이그레이션의 실행 기록을 지웁니다 — 파일이 없는 기록이 남지 않게.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function forgetLegacyMigration(UpgradeContext $context): void
    {
        $config = config('database.migrations', 'migrations');
        $table = is_array($config) ? ($config['table'] ?? 'migrations') : (string) $config;

        if (! Schema::hasTable($table)) {
            return;
        }

        $deleted = DB::table($table)->where('migration', self::LEGACY_MIGRATION)->delete();
        if ($deleted > 0) {
            $context->logger->info('[ecommerce:1.3.4] 지운 본문 미디어 전용 표 마이그레이션 기록 삭제');
        }
    }
}
