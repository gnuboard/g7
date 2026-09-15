<?php

declare(strict_types=1);

namespace App\Support\LayoutMarkup;

use JsonSerializable;

/**
 * 빈 **map**(이름표 붙은 값 묶음)을 뜻하는 표식 값 (50차).
 *
 * ## 왜 필요한가
 *
 * PHP 배열은 list 와 map 을 구분하지 않는다. 그래서 값이 비면 작성자가 `{}` 를 적었는지 `[]` 를
 * 적었는지가 컴파일 산출에서 소실되고, `json_encode` 는 둘 다 `[]` 로 낸다. 프론트는 JSON 을
 * 그대로 받으므로 `{}`(속성 접근 대상)와 `[]`(순서 목록)를 **구분**하며, 그 값이 API 요청 본문
 * (`params.body`)이나 URL 질의(`params.query`)로 실려 가면 서버측 검증이 대상을 찾지 못해
 * 422 또는 침묵 미적용이 된다. 화면도 안 깨지고 로그도 안 남는 형태로 틀리는 결함이다.
 *
 * ## 왜 "허용 키 목록" 이 아닌가
 *
 * "어느 키가 map 이어야 하는가" 의 목록을 두면 문법이 늘 때 그 목록만 낡아 같은 결함이 다시
 * 새는 자리가 생긴다(계획서 ④ "문법 SSoT 가 4벌로 흩어져 있다" 와 같은 자리). 그래서 목록을
 * 만들지 않고 **map 을 만드는 지점**({@see StructuredValueCompiler::compileMap()} ·
 * {@see MarkupValue::decodeTypeLiteral()})이 비었을 때 이 표식을 돌려주게 한다. 목록이 없으면
 * 낡을 것도 없다.
 *
 * ## 왜 `stdClass` 를 직접 쓰지 않는가
 *
 * 컴파일 산출 트리는 곳곳에서 `is_array()` 로 재귀를 결정한다. `stdClass` 를 그대로 흘리면 그
 * 가드들이 조용히 갈라진다. 이 클래스는 **자기 자신은 재귀 대상이 아니라는 사실**(빈 값이므로
 * 내려갈 것이 없다)을 타입으로 표현하고, 직렬화 순간에만 `stdClass` 로 바뀐다.
 */
final class EmptyMap implements JsonSerializable
{
    /**
     * 단일 인스턴스 — 값이 없는 표식이라 동일성을 나눌 이유가 없다.
     */
    private static ?self $instance = null;

    /**
     * 외부 생성을 막습니다 ({@see instance()} 로만 얻습니다).
     */
    private function __construct() {}

    /**
     * 표식 인스턴스를 돌려줍니다.
     *
     * @return self 빈 map 표식
     */
    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * 주어진 값이 빈 map 표식인지 판정합니다.
     *
     * @param  mixed  $value  판정할 값
     * @return bool 빈 map 표식이면 true
     */
    public static function is(mixed $value): bool
    {
        return $value instanceof self;
    }

    /**
     * `json_encode` 가 `{}` 를 내도록 빈 객체로 직렬화합니다.
     *
     * @return object 빈 객체
     */
    public function jsonSerialize(): object
    {
        return new \stdClass;
    }
}
