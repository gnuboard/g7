<?php

namespace App\Http\Requests\Concerns;

use App\Fields\FieldContext;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;

/**
 * 회원 폼 요청(가입, 관리자 추가·수정, 내 정보 수정)이 회원 항목 규칙을 팩토리에서 받아 쓰는 공통부.
 *
 * 요청 클래스는 「고정 규칙(이메일, 비밀번호, 약관, 역할 등) + 회원 항목 규칙」을 만든 뒤 자기 필터 훅을 그대로 태운다.
 * 회원 항목의 필수 여부와 사용 여부는 운영자 설정(환경설정 > 회원)에서 오므로 요청 클래스에 적지 않는다.
 *
 * @since 7.2.0
 */
trait UsesMemberFields
{
    /**
     * 이 요청의 회원 항목 문맥(표면, 대상 회원, 요청자)을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    abstract protected function memberFieldContext(): FieldContext;

    /**
     * 고정 규칙에 회원 항목 규칙을 합칩니다.
     *
     * 규칙 순서는 종전 요청 클래스의 순서를 따른다 — 오류가 여럿일 때 응답 `message` 에 실리는 첫 문구가
     * 종전과 달라지지 않게 하려는 것이다. 종전에 없던 항목(운영자 추가 항목 등)은 뒤에 붙는다.
     *
     * @param  array<string, mixed>  $fixed  요청이 직접 정한 고정 규칙
     * @param  array<int, string>  $legacyOrder  종전 규칙의 키 순서
     * @return array<string, mixed> 합친 규칙
     */
    protected function withMemberFieldRules(array $fixed, array $legacyOrder): array
    {
        // 고정 규칙이 같은 키를 가지면 고정 규칙이 이긴다(이메일처럼 항목이 아닌 칸을 항목 선언이 덮지 못하게).
        $merged = $fixed + app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, $this->memberFieldContext());

        $ordered = [];
        foreach ($legacyOrder as $key) {
            if (array_key_exists($key, $merged)) {
                $ordered[$key] = $merged[$key];
            }
        }

        return $ordered + $merged;
    }

    /**
     * 회원 항목의 오류 문구용 이름을 반환합니다 (요청의 attributes() 에 합친다).
     *
     * 이름은 항목 정의의 이름(운영자가 고친 이름 포함)이다. 언어 파일이 그 요청 키의 검증 라벨을 따로 가진 칸은
     * 넣지 않는다 — 종전 문구가 그 라벨로 나가던 칸의 문구를 바꾸지 않기 위해서다. 요청이 직접 정한 이름이 있으면 그것이 이긴다.
     *
     * @param  array<string, string>  $own  요청이 직접 정한 속성 이름
     * @return array<string, string> 합친 속성 이름
     */
    protected function withMemberFieldAttributes(array $own = []): array
    {
        $fromFields = array_filter(
            app(FieldRuleFactory::class)->attributes(FieldSet::CORE_USER, $this->memberFieldContext()),
            fn (string $path) => ! trans()->has('validation.attributes.'.$path),
            ARRAY_FILTER_USE_KEY,
        );

        return $own + $fromFields;
    }

    /**
     * 운영자가 적은 형식 오류 문구를 요청의 문구에 합칩니다.
     *
     * @param  array<string, string>  $own  요청이 직접 정한 문구
     * @return array<string, string> 합친 문구
     */
    protected function withMemberFieldMessages(array $own = []): array
    {
        return $own + app(FieldRuleFactory::class)->messages(FieldSet::CORE_USER, $this->memberFieldContext());
    }
}
