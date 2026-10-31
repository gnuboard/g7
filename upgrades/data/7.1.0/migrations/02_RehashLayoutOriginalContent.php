<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 빈 객체 때문에 거짓 "수정됨"이 된 레이아웃 원본 해시를 7.1.0 정규화로 다시 적습니다.
 *
 * 7.0.x 는 레이아웃 파일을 컴파일한 본문(빈 객체를 `{}` 로 유지)으로 원본 해시를 저장했지만, 수정 여부를 볼 때는
 * DB 에서 배열로 읽은 본문(빈 객체가 `[]`)으로 해시를 계산했습니다. 그래서 빈 객체가 하나라도 있는 레이아웃은
 * 아무도 고치지 않았는데 "사용자 수정본"이 되어, `keep` 업데이트가 새 판을 반영하지 않았습니다. 7.1.0 은 두 쪽을
 * 모두 배열 모양으로 정규화하므로, 저장된 해시도 같은 방식으로 맞춰야 합니다.
 *
 * DB 의 JSON 원문은 빈 객체를 `{}` 로 보존하므로, 원문을 객체 그대로 다시 인코딩하면 7.0.x 방식 해시를 정확히
 * 재현할 수 있습니다. 그 값이 저장된 해시와 같은 행 = 설치 뒤 아무도 고치지 않은 행만 새 방식 해시·크기로
 * 바꿉니다. 운영자가 실제로 고친 행은 재현값이 달라 그대로 두므로 계속 보존 대상입니다.
 *
 * 대상: `template_layouts`(템플릿·모듈·플러그인 레이아웃), `template_layout_extensions`(확장 조각).
 *
 * idempotent: 이미 새 방식인 행은 새 방식 해시가 저장값과 같아 건너뜁니다.
 *
 * V-1 안전: 모델·트레이트를 참조하지 않고 DB 파사드와 리터럴만 씁니다(정규화 규칙을 여기에 동결).
 */
class RehashLayoutOriginalContent implements DataMigration
{
    /** 해시 대상 테이블 */
    private const TABLES = ['template_layouts', 'template_layout_extensions'];

    /** json_encode 플래그 — 7.0.x·7.1.0 정규화 공통 */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RehashLayoutOriginalContent';
    }

    /**
     * 설치 뒤 고치지 않은 레이아웃·확장 조각의 원본 해시를 새 정규화로 다시 적습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'original_content_hash')) {
                $context->logger->info("[core:7.1.0] {$table} 원본 해시 컬럼 부재 — 스킵");

                continue;
            }

            $rewritten = 0;
            $kept = 0;

            DB::table($table)
                ->whereNotNull('original_content_hash')
                ->orderBy('id')
                ->select(['id', 'content', 'original_content_hash'])
                ->chunkById(200, function ($rows) use ($table, &$rewritten, &$kept) {
                    foreach ($rows as $row) {
                        $result = $this->rehash((string) $row->content, (string) $row->original_content_hash);

                        if ($result === null) {
                            continue;
                        }

                        if ($result === false) {
                            $kept++;

                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update([
                            'original_content_hash' => $result['hash'],
                            'original_content_size' => $result['size'],
                        ]);
                        $rewritten++;
                    }
                });

            $context->logger->info(
                "[core:7.1.0] {$table} 원본 해시 재계산 — 새 방식으로 바꿈 {$rewritten}건, 운영자 수정본 보존 {$kept}건"
            );
        }
    }

    /**
     * 한 행의 새 방식 해시를 판정합니다.
     *
     * @param  string  $raw  DB 의 JSON 원문
     * @param  string  $stored  저장된 원본 해시
     * @return array{hash: string, size: int}|false|null 바꿀 값 / false = 운영자 수정본(그대로 둠) / null = 바꿀 필요 없음
     */
    private function rehash(string $raw, string $stored): array|false|null
    {
        $assoc = json_decode($raw, true);
        if (! is_array($assoc)) {
            return null;
        }

        $normalized = json_encode($assoc, self::FLAGS);
        $newHash = hash('sha256', $normalized);
        if ($newHash === $stored) {
            return null; // 이미 새 방식이거나 빈 객체가 없는 행
        }

        // 7.0.x 방식 재현 — 원문을 객체 그대로 디코드해 다시 인코딩하면 빈 객체가 `{}` 로 남는다
        $legacyHash = hash('sha256', json_encode(json_decode($raw), self::FLAGS));
        if ($legacyHash !== $stored) {
            return false; // 설치 뒤 내용이 바뀐 행 — 운영자 수정본
        }

        return ['hash' => $newHash, 'size' => strlen($normalized)];
    }
}
