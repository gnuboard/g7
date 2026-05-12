<?php

namespace App\Extension\Upgrade;

use App\Extension\UpgradeContext;

/**
 * 카탈로그 JSON 으로 표현 불가한 imperative 변환 / 단발성 핫픽스 단위.
 *
 * 각 버전이 자기 시점의 변환 로직을 본 인터페이스 구현체로
 * `upgrades/data/{version}/migrations/*.php` 에 작성한다.
 *
 * 적용 대상:
 *   - 컬럼 의미 변경 / 데이터 변환 (예: dot-path 변환)
 *   - 카탈로그 rename 의 충돌 경로 (Applier 가 처리하지 못하는 분기)
 *   - 단발성 파일시스템 핫픽스 (활성 디렉토리 복구, symlink 복구 등)
 *   - 그 버전 고유의 DB 보정 로직
 *
 * 구현 의무 (Applier 와 동일):
 *   - **idempotent**: 두 번 실행되어도 동일 결과
 *   - **V-1 안전**: 로컬 헬퍼 + `Illuminate\Support\Facades\*` 만 사용
 *   - **버전 격리**: 다른 버전 namespace 참조 금지
 *
 * 인스턴스화: `new {Migration}()` (인자 없음).
 *
 * 상세: docs/extension/upgrade-step-guide.md §12
 */
interface DataMigration
{
    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자 (예: "IdentityPermissionPivotMerge")
     */
    public function name(): string;

    /**
     * 변환 / 핫픽스 적용. idempotent 의무.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거, 현재 스텝 버전 등)
     * @return void
     *
     * @throws \Throwable 적용 실패 시 — runUpgradeSteps 가 상위에서 백업 복원 처리
     */
    public function run(UpgradeContext $context): void;
}
