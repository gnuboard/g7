<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 허용 목록 밖의 검색 대상(search_field)을 기본값으로 바꿉니다.
 *
 * 사용자 목록(게시판 글 목록, 마이페이지 스크랩·반응·신고)은 모르는 검색 대상 값을 422 로 되돌려
 * 옛 링크나 손으로 고친 주소 하나로 목록 전체가 실패했다. 관리자 목록과 마이페이지 내 글·내 댓글은
 * 같은 값을 무시하고 200 으로 답한다(7.1.0 실측 O-K3-3). 그래서 검증 전에 허용 목록 밖의 값을
 * 기본 검색 대상으로 바꾼다. 조용히 「전체」로 떨어뜨리면 작성자까지 섞여 결과만 틀리므로,
 * 화면 셀렉트의 기본값과 같은 값을 쓴다. 값을 보내지 않은 요청은 건드리지 않는다(기존 링크 호환).
 */
trait FallsBackUnknownSearchField
{
    /**
     * 허용 목록 밖의 search_field 를 기본값으로 바꿉니다.
     *
     * @param  array<int, string>  $allowed  허용하는 검색 대상
     * @param  string  $default  모르는 값 대신 쓸 검색 대상
     * @return void 반환값 없음
     */
    protected function fallBackUnknownSearchField(array $allowed, string $default): void
    {
        $value = $this->input('search_field');

        if ($value !== null && ! in_array($value, $allowed, true)) {
            $this->merge(['search_field' => $default]);
        }
    }
}
