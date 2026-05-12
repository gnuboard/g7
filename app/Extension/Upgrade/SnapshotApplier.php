<?php

namespace App\Extension\Upgrade;

use App\Extension\UpgradeContext;

/**
 * 카탈로그 delta JSON 한 파일을 DB 에 idempotent 하게 적용하는 단위.
 *
 * 각 버전이 자기가 사용하는 kind 별로 본 인터페이스 구현체를
 * `upgrades/data/{version}/appliers/{Kind}Applier.php` 에 작성한다.
 *
 * 구현 의무:
 *   - **raw JSON 만 read**: 시더 클래스(`Database\Seeders\*`) 호출 금지
 *   - **idempotent**: `Schema::hasColumn` / `where->exists()` 가드 동반
 *   - **V-1 안전**: 로컬 SQL/Eloquent 만 사용, `app(*Service|*Manager|*Repository::class)` 금지
 *   - **버전 격리**: 다른 버전 namespace 의 클래스 참조 금지
 *
 * 인스턴스화: `new {Kind}Applier($absoluteJsonPath)`.
 * 생성자는 delta JSON 파일의 절대 경로를 받아 `apply()` 시 read 한다.
 *
 * 상세: docs/extension/upgrade-step-guide.md §12
 */
interface SnapshotApplier
{
    /**
     * delta JSON 을 read 하여 DB 에 적용.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거, 현재 스텝 버전 등)
     * @return void
     *
     * @throws \Throwable 적용 실패 시 — runUpgradeSteps 가 상위에서 백업 복원 처리
     */
    public function apply(UpgradeContext $context): void;
}
