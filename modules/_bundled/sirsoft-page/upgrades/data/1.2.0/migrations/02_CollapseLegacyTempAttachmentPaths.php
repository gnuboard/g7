<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftPage\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 키 없이 올린 예전 첨부의 저장 경로에서 빈 조각(`//`)을 걷어냅니다.
 *
 * 1.2.0 이전에는 페이지 번호도 임시 키도 없이 첨부를 올리면 경로가 `temp//{파일}` 로 저장됐다.
 * 파일 시스템(Flysystem)은 빈 조각을 버리므로 실제 파일은 `temp/{파일}` 에 있고, DB 의 `path` 만
 * `//` 를 들고 있었다. 1.2.0 의 저장소 경로 검사(KVE-2026-2349)는 빈 조각을 거부하므로, 이 행들은 그대로
 * 두면 다운로드가 실패하고 임시 첨부 정리가 그 행에서 멈춘다.
 *
 * ── 처리 ─────────────────────────────────────────────────────────────────────
 *
 * `path` 에 `//` 가 든 행만, Flysystem 과 같은 규칙(빈 조각·`.` 제거, 앞뒤 슬래시 제거)으로 경로를 합친다.
 * 같은 규칙이라 합친 경로가 곧 실제 파일 위치다 — 파일은 옮기지 않는다(로컬·원격 디스크 공통).
 * 합친 뒤에도 `..`·역슬래시·인코딩 조각이 남는 행은 이 모듈이 만든 형식이 아니므로 고치지 않고 위치를 남긴다.
 *
 * ── 안전성 ───────────────────────────────────────────────────────────────────
 *
 *  - 멱등: 합친 경로에는 `//` 가 없어 2회차 변경 0.
 *  - 무변경 행 UPDATE 금지, `chunkById`(콜백이 path 를 바꾸므로 OFFSET 금지).
 *
 * @upgrade-path A
 */
class CollapseLegacyTempAttachmentPaths implements DataMigration
{
    /** 대상 테이블 */
    private const TABLE = 'page_attachments';

    /** 한 청크의 행 수 */
    private const CHUNK_SIZE = 500;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'CollapseLegacyTempAttachmentPaths';
    }

    /**
     * 빈 조각이 든 첨부 경로를 합칩니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'path')) {
            $context->logger->info('[page:1.2.0] '.self::TABLE.'.path 부재 — 첨부 경로 보정 스킵');

            return;
        }

        $fixed = 0;
        $skipped = 0;

        DB::table(self::TABLE)
            ->select(['id', 'path'])
            ->where('path', 'like', '%//%')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($context, &$fixed, &$skipped) {
                foreach ($rows as $row) {
                    $collapsed = $this->collapse((string) $row->path);

                    if ($collapsed === null) {
                        $skipped++;
                        $context->logger->warning('[page:1.2.0] '.self::TABLE."#{$row->id} 경로에 이 모듈이 만들지 않은 조각이 있어 보정하지 않았습니다 — 첨부를 확인하세요.");

                        continue;
                    }

                    DB::table(self::TABLE)->where('id', $row->id)->update(['path' => $collapsed]);
                    $fixed++;
                }
            });

        $context->logger->info('[page:1.2.0] 키 없이 올린 예전 첨부 경로 보정: '.$fixed.'행 (보정 불가 '.$skipped.'행)');
    }

    /**
     * Flysystem 정규화와 같은 규칙으로 경로를 합칩니다.
     *
     * @param  string  $path  저장된 경로
     * @return string|null 합친 경로 (이 모듈이 만들지 않은 조각이 있으면 null)
     */
    private function collapse(string $path): ?string
    {
        if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('/%(2e|2f|5c|00)/i', $path) === 1) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $parts[] = $segment;
        }

        return $parts === [] ? null : implode('/', $parts);
    }
}
