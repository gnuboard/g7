<?php

namespace Modules\Sirsoft\Page\Http\Requests\Concerns;

/**
 * 임시 첨부 키(temp_key) 검증 규칙 트레이트
 *
 * temp_key 는 페이지가 아직 없을 때 올린 첨부를 저장 시 잇는 식별자이고, 서버는 이 값을
 * `temp/{temp_key}` 저장 경로에 그대로 넣는다. 형식을 좁히지 않으면 `../..` 같은 값이
 * 임시 디렉토리 정리(deleteDirectory)를 모듈 저장소 루트로 끌어올린다(KVE-2026-2349).
 * 화면은 `$uuid()` 로 키를 만들므로 UUID 형식만 받는다 — 경로 구분자·`.`·인코딩 변형이
 * 들어올 자리가 원천적으로 없다.
 *
 * 페이지 생성·수정·첨부 업로드 Request 가 같은 규칙을 쓰도록 여기 한 곳에 둔다.
 */
trait ValidatesTempKey
{
    /**
     * temp_key 검증 규칙을 반환합니다.
     *
     * @return array<int, string> 검증 규칙
     */
    protected function tempKeyRules(): array
    {
        return ['nullable', 'string', 'uuid'];
    }

    /**
     * temp_key 검증 오류 문구에 쓸 항목 이름을 반환합니다.
     *
     * 지정하지 않으면 Laravel 이 필드명을 풀어 「temp key」를 그대로 문구에 넣는다.
     *
     * @return array<string, string> 필드명 → 표시 이름
     */
    protected function tempKeyAttributes(): array
    {
        return ['temp_key' => __('sirsoft-page::validation.attributes.temp_key')];
    }
}
