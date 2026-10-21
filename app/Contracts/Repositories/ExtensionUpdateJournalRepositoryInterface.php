<?php

namespace App\Contracts\Repositories;

use App\Extension\Helpers\ExtensionUpdateJournal;

/**
 * 확장 업데이트 진행 기록 Repository 인터페이스
 *
 * 업데이트 하나가 어디까지 진행됐는지({@see ExtensionUpdateJournal}) 저장·조회·삭제합니다. 기록은 DB 가 아니라 업데이트 백업과
 * 같은 저장소(`storage/app`) 의 파일에 둔다(결정 — 새 컬럼·마이그레이션 없음). 읽거나 쓰지 못해도 예외를 던지지 않는다 —
 * 업데이트 자체를 막지 않고 종전 동작(기록 없음)으로 돌아간다.
 */
interface ExtensionUpdateJournalRepositoryInterface
{
    /**
     * 남아 있는(끝나지 않은) 기록을 읽습니다.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return ExtensionUpdateJournal|null 기록 (없거나 읽을 수 없거나 모양이 맞지 않으면 null)
     */
    public function find(string $type, string $identifier): ?ExtensionUpdateJournal;

    /**
     * 기록을 저장합니다 — 실패해도 예외를 던지지 않습니다(경고 로그).
     *
     * @param  ExtensionUpdateJournal  $journal  저장할 기록
     * @return void 반환값 없음
     */
    public function save(ExtensionUpdateJournal $journal): void;

    /**
     * 기록을 지웁니다 — 없으면 아무 일도 하지 않고, 실패해도 예외를 던지지 않습니다(경고 로그).
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    public function delete(string $type, string $identifier): void;
}
