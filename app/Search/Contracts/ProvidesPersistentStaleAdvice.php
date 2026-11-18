<?php

namespace App\Search\Contracts;

use App\Search\DTO\SearchIndexHealth;

/**
 * 재생성 뒤에도 남는 색인 누락에 다음 조치를 안내하는 선택 계약
 *
 * 인덱스 재생성(`SearchIndexMaintainer::rebuild()`)으로 고쳐지지 않는 손상이 있는 엔진이 구현합니다.
 * 예: MySQL InnoDB 는 숨은 문서 번호(FTS_DOC_ID) 매핑이 어긋난 표를 FULLTEXT 인덱스 DROP/ADD 로 고치지
 * 못하고 표를 다시 만들어야(`ALTER TABLE … ENGINE=InnoDB, ALGORITHM=COPY`) 회복됩니다.
 *
 * 코어는 안내를 **실행하지 않고** 명령 출력·로그·관리자 응답에 싣기만 합니다 — 표 재구축은 표 전체를
 * 복사해 오래 걸리고 쓰기를 잠그므로 운영자가 유지보수 시간에 직접 실행합니다.
 * 이 계약을 구현하지 않은 엔진은 잔존 보고만 하고 안내를 붙이지 않습니다(기존 동작).
 */
interface ProvidesPersistentStaleAdvice
{
    /**
     * 재생성 뒤에도 색인 누락으로 남은 인덱스들에 대한 조치 안내를 반환합니다.
     *
     * 같은 대상(표 등)에 인덱스가 여럿 남아도 안내는 대상마다 하나입니다.
     *
     * @param  array<int, SearchIndexHealth>  $remaining  재생성 뒤 재점검에서 여전히 색인 누락인 판정
     * @return array<int, array{target: string, command: string}> 대상(표 이름 등)과 운영자가 실행할 정확한 명령
     */
    public function persistentStaleAdvice(array $remaining): array;
}
