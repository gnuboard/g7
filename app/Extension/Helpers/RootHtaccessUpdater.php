<?php

namespace App\Extension\Helpers;

/**
 * 루트 `.htaccess` 의 코어 관리 블록(`# BEGIN G7` ~ `# END G7`)만 교체하는 갱신기 (D25).
 *
 * 판정 로직은 `AbstractRootMarkerFileUpdater` 가 소유한다 — 이 클래스는 Apache 주석 문법의 마커와
 * 안내 문구 키만 정한다. 형제 `RootWebConfigUpdater`(IIS · XML 주석 마커)와 같은 케이스 표를 지난다.
 *
 * 임시 파일 이름 규칙(`TEMP_INFIX`·`TEMP_SUFFIX_PATTERN`)과 결과 코드(`RESULT_*`)는 추상화의 것을
 * 상속한다 — 정리 주체(`StorageLeftoverPruneService::pruneRootEntryTemp`)가 이 클래스 이름으로 그
 * 상수를 읽는다.
 */
class RootHtaccessUpdater extends AbstractRootMarkerFileUpdater
{
    /**
     * 코어 관리 블록 시작 마커 (줄 단위 정확 일치가 아니라 줄 시작 매칭).
     */
    public const BEGIN_MARKER = '# BEGIN G7';

    /**
     * 코어 관리 블록 종료 마커.
     */
    public const END_MARKER = '# END G7';

    protected const LOG_TAG = 'root-htaccess';

    protected const MESSAGE_KEY_PREFIX = 'root_htaccess_';
}
