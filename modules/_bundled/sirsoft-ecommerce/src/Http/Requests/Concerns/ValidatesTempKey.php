<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

/**
 * 임시 이미지 키(temp_key · image_temp_key) 검증 규칙 트레이트
 *
 * 임시 키는 상품·분류가 아직 없을 때 올린 이미지를 저장 시 잇는 식별자이고, 상품 이미지는
 * 이 값을 `products/temp/{temp_key}` 저장 경로에 그대로 넣는다. 형식을 좁히지 않으면
 * `../../..` 같은 값이 임시 디렉토리 정리(deleteDirectory)를 모듈 저장소 루트로 끌어올린다
 * (KVE-2026-2349). 화면은 `$uuid()` 로, 서버는 `Str::uuid()` 로 키를 만들므로 UUID 형식만
 * 받는다 — 경로 구분자·`.`·인코딩 변형이 들어올 자리가 원천적으로 없다.
 *
 * 상품·분류 저장, 이미지 업로드, 공개 문의 Request 가 같은 규칙을 쓰도록 여기 한 곳에 둔다.
 */
trait ValidatesTempKey
{
    /**
     * 임시 키 검증 규칙을 반환합니다.
     *
     * @return array<int, string> 검증 규칙
     */
    protected function tempKeyRules(): array
    {
        return ['nullable', 'string', 'uuid'];
    }
}
