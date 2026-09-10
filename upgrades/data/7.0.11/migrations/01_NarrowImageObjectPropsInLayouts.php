<?php

namespace App\Upgrades\Data\V7_0_11\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 저장된 레이아웃의 prop 자리에 통째로 들어간 이미지 값 객체를 url 문자열로 축약합니다.
 *
 * 레이아웃 편집기의 `image` 위젯은 배경 이미지용으로 설계되어
 * `{url, size, repeat, position}` **객체**를 내보낸다. 그 값을 노드에 기록하는 경로 중
 * `styleProp` 의 다중 속성 묶음만 객체를 CSS 4속성으로 분해했고, `propValue` 는 객체를
 * 그대로 `props[key]` 에 기록했다. 소비 컴포넌트가 `<Img src={객체}>` 로 받으면 브라우저가
 * `[object Object]` 를 URL 로 해석해 이미지가 깨진다(공개 #135).
 *
 * 이 결함은 예외도 콘솔 오류도 서버 로그도 남기지 않는다 — 깨진 이미지 요청은 SPA
 * catch-all 때문에 404 조차 아니라 200(HTML)을 받고, 편집기 위젯의 미리보기는 정상이라
 * 조작 중에는 이상이 보이지 않는다. 소스 교정만으로는 이미 저장된 설치본이 낫지 않으므로
 * 여기서 보정한다.
 *
 * ── 판정식 엄격도 (이 마이그레이션에서 가장 중요한 사실) ─────────────────────────
 *
 * 코어 편집기 엔진의 `isImageValueObject` 는 4키 중 **하나라도** 있으면 참이다. 그것을
 * 여기에 이식하면 저장된 레이아웃이 대량 파괴된다 — 레이아웃 JSON 전수 실측에서 느슨한
 * 판정식은 2,219건을 매치했고 그중 정상 props 가 대부분이었다(`{className,name,size}`
 * 674건 · `{name,size}` 552건 · `{items,position}` 26건). 엄격 판정식(키 집합 ⊆ 4키
 * **AND** `url` 키 존재)의 매치는 0건이었다.
 *
 * 두 판정식이 다른 것은 실수가 아니라 의도다 — 엔진은 "방금 위젯이 만든 값"을 보므로
 * 느슨해도 안전하고, 백필은 "임의 사용자 레이아웃 전수"를 보므로 정밀도 요구가 다르다.
 *
 * ── 순회 범위 ────────────────────────────────────────────────────────────────
 *
 * "prop 자리" 는 키 이름 allowlist 로 정의할 수 없다 — 실측상 `props` 안에 컴포넌트 노드가
 * 1,150건 산다(`props.cardColumns[0].cellChildren[0]`, `props.itemTemplate` …). 임의의
 * prop 키가 노드를 품을 수 있으므로 **모드 플래그 전역 재귀**를 쓴다: `props` 키를 만나면
 * 모드 ON, `style` 키를 만나면 OFF(배경 4속성 분해값은 정상), 그 외는 현재 모드 승계.
 * 이 한 규칙으로 `node.props.*` · `responsive.{bp}.props.*` · 중첩 노드의 props ·
 * 확장 content 의 `injections[].props` 가 전부 포섭되고, `children`/`slots`/`modals`
 * 등은 그냥 통과 지점이라 열거할 필요가 없다.
 *
 * ── 안전성 ───────────────────────────────────────────────────────────────────
 *
 *  - **멱등**: 이미 문자열인 값은 `is_array` 에서 탈락하므로 2회차 변경 건수는 구조적으로 0.
 *  - **무변경 행 UPDATE 금지**: 변경 카운터로 판정한다. 재직렬화 결과 비교로 판정하면
 *    키 순서·escape 차이로 전 행을 오탐 UPDATE 시킨다.
 *  - **`chunkById`**: 콜백이 `content` 를 update 하므로 OFFSET 방식은 커서가 밀려
 *    미처리 행을 조용히 건너뛴다.
 *  - **사전 필터 없음**: `content LIKE '%"repeat"%'` 는 인덱스를 못 타 어차피 풀스캔이고,
 *    escape·공백 변형에 따라 오탐이 아니라 **누락**을 만든다 — 누락은 흔적이 없다.
 *  - **soft delete 행 포함**: 삭제된 레이아웃은 복원 가능하므로 고치지 않으면 복원 시
 *    결함이 되살아난다. `DB::table()` 은 SoftDeletes 스코프를 타지 않아 기본 동작이 곧
 *    원하는 동작이다.
 *  - **`lock_version` 은 변경된 행만 +1**: 업데이트 직전에 편집기 탭을 열어 둔 운영자가
 *    업데이트 후 저장하면, 올리지 않은 경우 그의 stale content(= 객체값 그대로)가 조용히
 *    백필을 되돌린다. 올리면 "다른 곳에서 수정됨" 으로 정확히 거부된다.
 *  - **`updated_by` 미변경**: 시스템 보정이지 사람의 편집이 아니다.
 *  - **`original_content_hash` 미변경**: 재계산하면 사용자 편집본이 "원본 그대로" 로
 *    위장되어 다음 `template:update --preserve-modified` 가 그 레이아웃을 덮어쓴다.
 *  - **버전·미리보기 스냅샷 미개입**: `template_layout_versions` ·
 *    `template_layout_previews` 는 대상이 아니다. 과거 스냅샷을 고치면 「이전 버전으로
 *    되돌리기」가 원본과 달라진다. 그리고 그 미개입이 곧 본 마이그레이션의 안전망이다 —
 *    되돌리기는 스텝 규격에 없으므로(`DataMigration` 은 `name()`+`run()` 뿐) 운영자의
 *    개별 복구 경로는 관리자 UI 의 레이아웃 이력이다.
 *
 * V-1 안전: `Illuminate\Support\Facades\{DB,Schema}` + 로컬 private 헬퍼만 사용한다
 * (Service/Manager/Repository 컨테이너 해석 금지 — 그 클래스들은 스텝 실행 시점에 이전
 * 버전 표면일 수 있다).
 */
class NarrowImageObjectPropsInLayouts implements DataMigration
{
    /** 이미지 값 객체가 가질 수 있는 키의 전부 */
    private const IMAGE_VALUE_KEYS = ['url', 'size', 'repeat', 'position'];

    /**
     * 모드 전이 키 → 그 아래의 prop 자리 여부.
     *
     * 이 목록에 있는 키는 **컨테이너지 값이 아니다** — 두 사실(모드를 어떻게 바꾸는가,
     * 값 판정에서 제외해야 하는가)이 같은 목록에서 나와야 한다. 따로 적어 두면 나중에
     * 전이 키가 하나 늘 때 한쪽만 갱신되어, 그 컨테이너가 값으로 평가된다.
     */
    private const CONTAINER_MODE = ['props' => true, 'style' => false];

    /** 대상 테이블 — 버전/미리보기 스냅샷은 의도적으로 제외한다 */
    private const TARGET_TABLES = ['template_layouts', 'template_layout_extensions'];

    /** 한 청크의 행 수 */
    private const CHUNK_SIZE = 100;

    /** 손상 url 로 prop 키를 제거한 건수(로그용 누적) */
    private int $droppedCount = 0;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'NarrowImageObjectPropsInLayouts';
    }

    /**
     * 대상 테이블의 `content` JSON 을 순회하며 prop 자리의 이미지 값 객체를 축약합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
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
     * @return void
     */
    private function processTable(UpgradeContext $context, string $table): void
    {
        if (! Schema::hasTable($table)) {
            $context->logger->info("[core:7.0.11] {$table} 테이블 부재 — 이미지 값 객체 축약 스킵");

            return;
        }

        if (! Schema::hasColumn($table, 'content')) {
            $context->logger->info("[core:7.0.11] {$table}.content 컬럼 부재 — 이미지 값 객체 축약 스킵");

            return;
        }

        $hasLockVersion = Schema::hasColumn($table, 'lock_version');
        $updatedRows = 0;
        $changedProps = 0;
        $failedRows = 0;
        $this->droppedCount = 0;

        DB::table($table)
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                $context,
                $table,
                $hasLockVersion,
                &$updatedRows,
                &$changedProps,
                &$failedRows
            ) {
                foreach ($rows as $row) {
                    try {
                        $changed = $this->processRow($context, $table, $row, $hasLockVersion);
                        if ($changed > 0) {
                            $updatedRows++;
                            $changedProps += $changed;
                        }
                    } catch (Throwable $e) {
                        // 행 단위 격리 — 예외를 전파하면 CoreUpdateCommand 가 백업 복원(롤백)을
                        // 트리거한다. 손상 레이아웃 1건 때문에 코어 업데이트 전체를 되돌리는 것은
                        // 비례하지 않는다.
                        $failedRows++;
                        $context->logger->warning(
                            "[core:7.0.11] {$table} id={$row->id} 이미지 값 객체 축약 실패(건너뜀) — ".$e->getMessage()
                        );
                    }
                }
            });

        if ($failedRows > 0) {
            $context->logger->warning(
                "[core:7.0.11] {$table} 처리 실패 행 {$failedRows}건 — 위 경고의 id 를 확인하세요"
            );
        }

        if ($updatedRows === 0) {
            $context->logger->info("[core:7.0.11] {$table} 보정 대상 없음 — 이미 정상");

            return;
        }

        $context->logger->info(
            "[core:7.0.11] {$table} 이미지 값 객체 축약 완료 — 행 {$updatedRows}건 / prop {$changedProps}건"
            .($this->droppedCount > 0 ? " / 손상 url 로 제거한 prop {$this->droppedCount}건" : '')
        );
    }

    /**
     * 한 행의 content JSON 을 보정하고, 변경이 있을 때만 UPDATE 합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $table  대상 테이블명
     * @param  object  $row  `id` + `content` 를 가진 행
     * @param  bool  $hasLockVersion  lock_version 컬럼 보유 여부
     * @return int 변경된 prop 개수 (0이면 UPDATE 미수행)
     */
    private function processRow(UpgradeContext $context, string $table, object $row, bool $hasLockVersion): int
    {
        if (! is_string($row->content) || $row->content === '') {
            return 0;
        }

        $decoded = json_decode($row->content, true);
        if (! is_array($decoded)) {
            $context->logger->warning(
                "[core:7.0.11] {$table} id={$row->id} content JSON 파싱 실패(건너뜀)"
            );

            return 0;
        }

        $changed = 0;
        $next = $this->transform($decoded, false, $changed, $context, $table, (string) $row->id, '');

        if ($changed === 0) {
            // 무변경 행은 UPDATE 하지 않는다 — updated_at·lock_version 을 건드리면
            // 「수정됨」 판정과 낙관적 잠금이 이유 없이 흔들린다.
            return 0;
        }

        $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $context->logger->warning(
                "[core:7.0.11] {$table} id={$row->id} content 재직렬화 실패(건너뜀)"
            );

            return 0;
        }

        $update = ['content' => $encoded];
        if ($hasLockVersion) {
            // 편집기 탭을 열어 둔 운영자의 stale 저장이 백필을 되돌리지 않도록 올린다.
            $update['lock_version'] = DB::raw('lock_version + 1');
        }

        DB::table($table)->where('id', $row->id)->update($update);

        return $changed;
    }

    /**
     * content 트리를 재귀 순회하며 prop 자리의 이미지 값 객체를 축약합니다.
     *
     * `props` 키 아래로 들어가면 모드 ON, `style` 키 아래로 들어가면 OFF, 그 외는 현재
     * 모드를 승계한다. 이 한 규칙이 임의 깊이의 중첩 노드까지 포섭한다.
     *
     * @param  mixed  $value  현재 노드(맵/리스트/스칼라)
     * @param  bool  $inPropSlot  현재 위치가 prop 자리인지
     * @param  int  $changed  변경 카운터 (참조 누적)
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $table  대상 테이블명 (로그용)
     * @param  string  $rowId  행 id (로그용)
     * @param  string  $path  JSON 경로 (로그용)
     * @return mixed 보정된 값
     */
    private function transform(
        mixed $value,
        bool $inPropSlot,
        int &$changed,
        UpgradeContext $context,
        string $table,
        string $rowId,
        string $path
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        // 리스트 — 모드를 그대로 승계하며 각 원소를 내려간다.
        if (array_is_list($value)) {
            foreach ($value as $i => $item) {
                $value[$i] = $this->transform(
                    $item, $inPropSlot, $changed, $context, $table, $rowId, $path.'['.$i.']'
                );
            }

            return $value;
        }

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            // 모드 전이 — `props` 는 prop 자리 진입, `style` 은 CSS 자리(배경 4속성 분해값이
            // 정상적으로 사는 곳)라 이탈. 그 외 키는 현재 모드를 그대로 승계한다.
            $isContainerKey = array_key_exists($key, self::CONTAINER_MODE);
            $childMode = $isContainerKey ? self::CONTAINER_MODE[$key] : $inPropSlot;

            // 값 판정은 **자식이 놓인 슬롯**($inPropSlot) 기준이다. $childMode 는 자식 *안으로*
            // 내려갈 때의 모드라, 그것으로 게이트하면 `props` 컨테이너 자신이 값으로 평가된다 —
            // `url` prop 을 받는 컴포넌트(`{"url":"/embed/x","size":"lg"}`)는 props 맵 자체가
            // 「4키 부분집합 + url 보유」라 판정식을 통과해, props 가 통째로 문자열로 붕괴하거나
            // (손상 url 이면) 키째 삭제된다. 컨테이너 키는 값이 아니므로 판정에서 제외한다.
            if ($inPropSlot && ! $isContainerKey && $this->isImageValueObject($child)) {
                $url = $child['url'] ?? null;

                if (is_string($url) && $url !== '') {
                    $value[$key] = $url;
                    $changed++;

                    continue;
                }

                // 손상값 — 스칼라가 아니거나 빈 문자열이면 어떤 렌더 경로에서도 유효한 src 가
                // 될 수 없다. prop 키를 제거해 컴포넌트의 폴백(사이트명 텍스트 등)을 살린다.
                // 파괴적 동작이므로 원본을 로그에 남긴다.
                $context->logger->warning(
                    "[core:7.0.11] {$table} id={$rowId} {$childPath} — url 이 유효하지 않아 prop 제거: "
                    .json_encode($child, JSON_UNESCAPED_UNICODE)
                );
                unset($value[$key]);
                $changed++;
                $this->droppedCount++;

                continue;
            }

            $value[$key] = $this->transform(
                $child, $childMode, $changed, $context, $table, $rowId, $childPath
            );
        }

        return $value;
    }

    /**
     * 값이 이미지 값 객체인지 **엄격하게** 판정합니다.
     *
     * 키 집합이 4키의 부분집합이고 `url` 키를 실제로 보유해야 한다. 코어 엔진의 느슨한
     * 판정식(4키 중 하나라도)을 여기에 쓰면 정상 props 를 대량 파괴한다 — 클래스 docblock
     * 의 실측 수치 참조.
     *
     * @param  mixed  $v  판정 대상
     * @return bool 이미지 값 객체이면 true
     */
    private function isImageValueObject(mixed $v): bool
    {
        if (! is_array($v) || $v === []) {
            return false;
        }

        if (array_is_list($v)) {
            return false;
        }

        foreach (array_keys($v) as $k) {
            if (! in_array($k, self::IMAGE_VALUE_KEYS, true)) {
                return false;
            }
        }

        return array_key_exists('url', $v);
    }
}
