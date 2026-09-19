<?php

namespace Tests\Support;

/**
 * 문서 검사 테스트의 대상 루트.
 *
 * 공개 `docs` 가 기본이고, 개발 저장소의 내부 문서 루트는 환경변수
 * `G7_DOC_SCAN_EXTRA_ROOTS`(쉼표 구분, base_path 기준 상대 경로)로만 넓힌다.
 *
 * 이 테스트들은 공개 배포본에 실린다. 내부 디렉토리를 코드에 직접 지목하면 배포본에서는
 * 그 디렉토리가 없어 검사가 조용히 비고, 제3자에게는 실행할 수 없는 내부 경로가 노출된다.
 * 넓히는 쪽은 개발 하네스(세션 종료 검사)이며, 내부 문서가 바뀐 세션에서 이 변수를 실어
 * 같은 테스트를 다시 돌린다 — 변수 이름은 그 하네스와 이 트레이트가 같은 문자열을 써야
 * 하고, 하네스 가드가 그 일치를 잠근다.
 */
trait ScansDocRoots
{
    /**
     * 검사 대상 문서 루트 목록을 돌려줍니다.
     *
     * @return array<int, string> base_path 기준 상대 경로
     */
    protected function docRoots(): array
    {
        $extra = array_filter(array_map('trim', explode(',', (string) getenv('G7_DOC_SCAN_EXTRA_ROOTS'))));

        return array_values(array_unique(array_merge(['docs'], $extra)));
    }
}
