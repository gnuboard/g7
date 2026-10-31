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
     * JSON 원문을 연관 배열로 디코드하되 빈 map(`{}`) 자리를 이 표식으로 되살립니다.
     *
     * `json_decode($json, true)` 는 `{}` 와 `[]` 를 모두 빈 배열로 만든다. 레이아웃 파일을 읽을 때뿐
     * 아니라 DB 에 저장된 본문(편집기 저장본·원본 본문)을 다시 조립해 저장할 때도 같은 소실이 일어나므로,
     * 판정 규칙을 한 곳에 둔다 — 같은 원문을 객체로도 디코드해 빈 객체였던 자리만 표식으로 바꾼다.
     *
     * @param  string  $json  JSON 원문
     * @return mixed 디코드 결과 (빈 map 자리는 표식). 원문이 JSON 이 아니면 null
     */
    public static function decodePreserving(string $json): mixed
    {
        return self::restore(json_decode($json, true), json_decode($json));
    }

    /**
     * 연관 배열 디코드가 잃은 빈 map 을 **객체 디코드 결과를 기준으로** 되살립니다.
     *
     * @param  mixed  $value  연관 배열로 디코드한 값
     * @param  mixed  $shaped  같은 원문을 객체로 디코드한 값 (빈 map 이 stdClass 로 남는다)
     * @return mixed 빈 map 자리가 표식으로 바뀐 값
     */
    private static function restore(mixed $value, mixed $shaped): mixed
    {
        if (is_array($value) && $value === [] && $shaped instanceof \stdClass) {
            return self::instance();
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $child = is_array($shaped)
                ? ($shaped[$key] ?? null)
                : ($shaped instanceof \stdClass ? ($shaped->{(string) $key} ?? null) : null);

            $value[$key] = self::restore($item, $child);
        }

        return $value;
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
