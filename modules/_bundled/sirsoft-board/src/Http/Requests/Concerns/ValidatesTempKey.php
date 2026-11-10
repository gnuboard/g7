<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

/**
 * 임시 첨부 키(temp_key) 검증 규칙 트레이트
 *
 * temp_key 는 첨부가 글보다 먼저 올라갈 때 둘을 잇는 식별자이고, 서버는 이 값을
 * `{slug}/temp/{temp_key}` 저장 경로에 그대로 넣는다. 형식을 좁히지 않으면 `../../..`
 * 같은 값이 임시 디렉토리 정리(deleteDirectory)를 모듈 저장소 루트로 끌어올린다
 * (KVE-2026-2349). 그래서 받는 꼴은 둘뿐이다 — 경로 구분자·`.`·인코딩 변형이 들어올 자리가 없다.
 *
 * - UUID: 지금 화면은 `$uuid()` 로 키를 만든다.
 * - `temp_<숫자>_<36진수 소문자>`: 7.0.x 기본 템플릿을 복사한 화면은
 *   `'temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)` 로 만든다.
 *   UUID 만 받게 좁힌 뒤 그 화면의 첨부 업로드·글 등록·수정이 모두 422 였다(템플릿 호환 대장 —
 *   결정 「호환 처리」, 2026-11-08). 숫자·소문자 영문만이라 경로 안전성은 UUID 와 같다.
 *
 * 글 작성·수정·첨부 업로드 Request 가 같은 규칙을 쓰도록 여기 한 곳에 둔다.
 */
trait ValidatesTempKey
{
    /**
     * temp_key 허용 꼴 — UUID(8-4-4-4-12 16진수) 또는 7.0.x 화면 꼴. `D` 로 끝의 개행을 막는다.
     */
    private const TEMP_KEY_PATTERN = '/^(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|temp_[0-9]{1,16}_[0-9a-z]{1,16})$/D';

    /**
     * temp_key 검증 규칙을 반환합니다.
     *
     * @return array<int, string> 검증 규칙
     */
    protected function tempKeyRules(): array
    {
        return ['nullable', 'string', 'max:64', 'regex:'.self::TEMP_KEY_PATTERN];
    }
}
