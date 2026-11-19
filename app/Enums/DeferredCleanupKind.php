<?php

namespace App\Enums;

/**
 * 검수 서버가 미뤄 둔 정리의 종류.
 *
 * @since 7.2.0
 */
enum DeferredCleanupKind: string
{
    /** 확장(코어)의 선언 항목(메뉴·권한·역할·알림·본인확인 정책·스케줄·회원 항목)을 이 서버 선언으로 다시 동기화 */
    case Declarations = 'declarations';

    /** 시드 행 정리를 맡은 시더를 다시 실행 — 사용 중 판정을 실행 순간에 다시 한다 */
    case Seeder = 'seeder';
}
