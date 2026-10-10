<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 저장된 레이아웃의 첨부 임시 키 생성식을 UUID(`{{$uuid()}}`)로 바꿉니다.
 *
 * 1.2.0 부터 글 작성·수정·첨부 업로드는 `temp_key` 로 UUID 만 받는다(KVE-2026-2349 — 요청의
 * 임시 키가 저장 경로에 들어가 `../..` 로 모듈 저장소 루트가 지워졌다). 번들 화면은 `$uuid()` 로
 * 바뀌었지만, 운영자가 레이아웃 편집기로 고쳐 둔 화면이나 업데이트 때 「편집본 유지」를 고른 화면은
 * 예전 생성식(`'temp_' + Date.now() + …`)을 그대로 들고 있어 첨부 업로드와 글 저장이 422 로 막힌다.
 *
 * ── 대상 ────────────────────────────────────────────────────────────────────
 *
 * `template_layouts` · `template_layout_extensions` 의 `content` 중 이 모듈 API
 * (`/api/modules/sirsoft-board/`)를 부르는 행에서, 문자열 값이 예전 생성식과 정확히 같은 곳만
 * 바꾼다. 템플릿 소유 행(기본 템플릿의 글쓰기 화면 등)도 포함한다 — 그 템플릿이 함께 업데이트되지
 * 않는 사이트에서도 글쓰기가 막히지 않아야 한다.
 *
 * ── 편집 여부 보존 (가장 중요한 사실) ─────────────────────────────────────────
 *
 *  - 사용자가 편집한 행(`original_content_hash` 가 현재 내용의 해시와 다름): 생성식만 고치고
 *    해시는 그대로 둔다 — 여전히 "편집본" 이다(코어 7.0.11 레이아웃 보정 스텝과 같은 규율).
 *  - 편집하지 않은 행(해시가 현재 내용과 같거나 없음): 고친 뒤 해시를 새 내용으로 맞춘다. 맞추지
 *    않으면 이 보정 자체가 "편집" 으로 보여, 다음 「편집본 유지」 업데이트가 새 번들 파일 반영에서
 *    그 행을 빼 버린다.
 *
 * 해시 식은 코어 `ComputesLayoutContentHash` 와 같다 — 정규화 JSON(UNESCAPED_UNICODE|SLASHES)의 SHA-256.
 * 스텝 실행 시점에 코어 클래스가 이전 표면일 수 있어(경로 A) 여기서 로컬로 계산한다.
 *
 * ── 안전성 ───────────────────────────────────────────────────────────────────
 *
 *  - 멱등: 이미 `{{$uuid()}}` 인 값은 일치하지 않아 2회차 변경 0.
 *  - 빈 map 보존: 본문을 코어 `EmptyMap::decodePreserving` 으로 읽어 다시 쓰므로 운영자가 고친 행의 `{}`
 *    (요청 질의 `params.query`, 폼 초기값 등)가 `[]` 로 굳지 않는다. 원본 해시·크기는 코어와 같은 표준형
 *    (빈 컨테이너를 `[]` 로 맞춘 JSON)으로 적는다.
 *  - 무변경 행 UPDATE 금지(변경 카운터 판정), `chunkById`(콜백이 content 를 바꾸므로 OFFSET 금지),
 *    soft delete 행 포함(복원 시 결함이 되살아나지 않게), 변경 행만 `lock_version` +1(업데이트 직전
 *    열어 둔 편집기의 stale 저장이 보정을 되돌리지 않게), `updated_by`·버전/미리보기 스냅샷 미개입.
 *
 * @upgrade-path A
 */
class UuidTempKeyGeneratorInLayouts implements DataMigration
{
    /** 예전 번들 화면이 쓰던 임시 키 생성식 (괄호 유무 두 형태) */
    private const LEGACY_GENERATORS = [
        "{{('temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9))}}",
        "{{'temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)}}",
    ];

    /** 바꿀 생성식 */
    private const UUID_GENERATOR = '{{$uuid()}}';

    /** 이 모듈 API 를 부르는 레이아웃만 대상으로 삼는 표지 */
    private const API_MARKER = '/api/modules/sirsoft-board/';

    /** 대상 테이블 — 버전/미리보기 스냅샷은 의도적으로 제외한다 */
    private const TARGET_TABLES = ['template_layouts', 'template_layout_extensions'];

    /** 한 청크의 행 수 */
    private const CHUNK_SIZE = 100;

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'UuidTempKeyGeneratorInLayouts';
    }

    /**
     * 대상 테이블의 예전 임시 키 생성식을 UUID 생성식으로 바꿉니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (self::TARGET_TABLES as $table) {
            $this->processTable($context, $table);
        }
    }

    /**
     * 한 테이블을 청크 단위로 순회하며 보정합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $table  대상 테이블명
     * @return void 반환값 없음
     */
    private function processTable(UpgradeContext $context, string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content')) {
            $context->logger->info("[board:1.2.0] {$table}.content 부재 — 임시 키 생성식 보정 스킵");

            return;
        }

        $hasHash = Schema::hasColumn($table, 'original_content_hash');
        $hasSize = Schema::hasColumn($table, 'original_content_size');
        $hasLock = Schema::hasColumn($table, 'lock_version');
        $columns = array_values(array_filter(['id', 'content', $hasHash ? 'original_content_hash' : null]));

        $changedRows = 0;
        $keptEdited = 0;

        DB::table($table)->select($columns)->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($context, $table, $hasHash, $hasSize, $hasLock, &$changedRows, &$keptEdited) {
            foreach ($rows as $row) {
                $content = (string) $row->content;
                if (! str_contains($content, self::API_MARKER) || ! $this->containsLegacy($content)) {
                    continue;
                }

                $decoded = $this->decode($content);
                if (! is_array($decoded)) {
                    continue;
                }

                $replaced = 0;
                $next = $this->replaceGenerators($decoded, $replaced);
                $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                // 운영자가 직접 바꾼 변형 생성식은 뜻을 알 수 없어 고치지 않는다 — 대신 위치를 남긴다
                if (str_contains($encoded, "'temp_' + Date.now()")) {
                    $context->logger->warning("[board:1.2.0] {$table}#{$row->id} 에 알 수 없는 임시 키 생성식이 남아 있습니다 — `{{\$uuid()}}` 로 직접 바꾸세요(아니면 첨부 업로드가 422).");
                }

                if ($replaced === 0) {
                    continue;
                }
                $update = ['content' => $encoded];

                $storedHash = $hasHash ? $row->original_content_hash : null;
                $wasUnedited = ! $storedHash || $storedHash === $this->contentHash($content);
                if ($hasHash && $wasUnedited) {
                    $update['original_content_hash'] = $this->contentHash($encoded);
                    if ($hasSize) {
                        $update['original_content_size'] = strlen($this->normalize($encoded));
                    }
                } else {
                    $keptEdited++;
                }

                $query = DB::table($table)->where('id', $row->id);
                if ($hasLock) {
                    $query->update($update + ['lock_version' => DB::raw('lock_version + 1')]);
                } else {
                    $query->update($update);
                }
                $changedRows++;
            }
        });

        $context->logger->info("[board:1.2.0] {$table} 임시 키 생성식 보정: {$changedRows}행 (편집본 유지 {$keptEdited}행)");
    }

    /**
     * 내용에 예전 생성식이 들어 있는지 빠르게 확인합니다.
     *
     * @param  string  $content  JSON 문자열
     * @return bool 포함 여부
     */
    private function containsLegacy(string $content): bool
    {
        foreach (self::LEGACY_GENERATORS as $legacy) {
            if (str_contains($content, $legacy)) {
                return true;
            }
        }

        return str_contains($content, "'temp_' + Date.now()");
    }

    /**
     * 값 트리를 재귀로 돌며 예전 생성식과 정확히 같은 문자열을 바꿉니다.
     *
     * @param  mixed  $value  JSON 값
     * @param  int  $replaced  바꾼 건수 누적
     * @return mixed 바꾼 값
     */
    private function replaceGenerators(mixed $value, int &$replaced): mixed
    {
        if (is_string($value)) {
            if (in_array($value, self::LEGACY_GENERATORS, true)) {
                $replaced++;

                return self::UUID_GENERATOR;
            }

            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->replaceGenerators($child, $replaced);
            }
        }

        return $value;
    }

    /**
     * 코어 레이아웃 해시(정규화 JSON 의 SHA-256)를 계산합니다.
     *
     * @param  string  $content  JSON 문자열
     * @return string 해시
     */
    private function contentHash(string $content): string
    {
        return hash('sha256', $this->normalize($content));
    }

    /**
     * 코어 레이아웃 해시의 표준형(빈 컨테이너를 `[]` 로 맞춘 JSON)을 만듭니다.
     *
     * @param  string  $content  JSON 문자열
     * @return string 표준형 JSON
     */
    private function normalize(string $content): string
    {
        return (string) json_encode(json_decode($content, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 저장된 JSON 을 연관 배열로 읽되 빈 map(`{}`) 자리는 보존합니다.
     *
     * `json_decode(…, true)` 로 읽어 다시 인코딩하면 운영자가 고친 행의 `{}` 가 `[]` 로 굳는다 — 화면은
     * 키-값 자리를 배열로 받고, 요청 질의·본문으로 실리면 서버가 대상을 찾지 못한다. 코어 EmptyMap 표식으로
     * 읽으면 `json_encode` 가 그 자리를 `{}` 로 낸다. 코어가 이 표식을 모르는 표면이면 종전 방식으로 읽는다.
     *
     * @param  string  $content  JSON 문자열
     * @return mixed 디코드 결과 (빈 map 자리는 EmptyMap 표식)
     */
    private function decode(string $content): mixed
    {
        if (class_exists(EmptyMap::class) && method_exists(EmptyMap::class, 'decodePreserving')) {
            return EmptyMap::decodePreserving($content);
        }

        return json_decode($content, true);
    }
}
