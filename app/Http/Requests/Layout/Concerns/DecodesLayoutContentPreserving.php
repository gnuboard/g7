<?php

namespace App\Http\Requests\Layout\Concerns;

use App\Support\LayoutEmptyObjects;
use App\Support\LayoutMarkup\EmptyMap;

/**
 * 레이아웃·확장 저장/미리보기 요청의 `content` 를 빈 객체(`{}`)를 보존해 읽는 FormRequest 공용 트레이트 (#758).
 *
 * 편집기는 본문을 두 형태로 보낸다 — 위지윅 레이아웃 저장은 `content: JSON.stringify(…)`(문자열), 확장 저장은
 * `content` 를 객체 그대로. 문자열은 `json_decode(true)` 가, 객체 본문은 Laravel 의 요청 해석이 `{}` 를 `[]` 로
 * 만들어, 그대로 저장하면 DB 원문의 `{}` 가 영구히 `[]` 로 굳는다(위지윅으로 `shop/show` 를 한 번 저장하면 상품 상세
 * 옵션 선택·상품평 필터 상태가 목록으로 시작). 두 형태 모두 원문을 {@see EmptyMap::decodePreserving()} 로 다시 읽는다.
 *
 * 검증은 종전과 같은 배열로 한다 — 빈 객체 표식은 배열이 아니라서 `content.meta` 의 `array` 규칙 같은 검사에서
 * `"meta": {}` 가 거부된다. 그래서 검증기에는 표식을 빈 배열로 낮춘 사본({@see validationData()})을 넘기고,
 * 검증 통과분에는 원문의 빈 객체 자리를 다시 채운다({@see restoreEmptyMaps()}).
 *
 * `format=markup` 분기({@see CompilesMarkupContent})는 컴파일러가 이미 빈 map 을 표식으로 내므로 디코드는 하지 않고,
 * 검증 사본·검증 통과분 복원만 같이 적용된다.
 */
trait DecodesLayoutContentPreserving
{
    /**
     * 요청 `content` 를 빈 객체를 보존해 디코드합니다.
     *
     * @return mixed 문자열이면 원문 보존 디코드 결과(JSON 이 아니면 원래 문자열), JSON 본문의 배열이면 원문 본문에서
     *               다시 꺼낸 `content`, 그 밖이면 원래 값
     */
    protected function decodeContentPreserving(): mixed
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $decoded = EmptyMap::decodePreserving($content);

            return is_array($decoded) ? $decoded : $content;
        }

        if (is_array($content) && $this->isJson()) {
            $raw = (string) $this->getContent();
            $body = $raw !== '' ? EmptyMap::decodePreserving($raw) : null;

            if (is_array($body) && is_array($body['content'] ?? null)) {
                return $body['content'];
            }
        }

        return $content;
    }

    /**
     * 검증기가 볼 데이터 — `content` 의 빈 객체 표식을 빈 배열로 낮춘 사본입니다.
     *
     * 규칙(`array`·`ValidLayoutStructure` 등)은 #758 이전처럼 `{}` 를 `[]` 로 본다. 요청 입력 자체는 표식을 그대로 들고 있다.
     *
     * @return array<string, mixed> 검증 대상 데이터
     */
    public function validationData(): array
    {
        $data = $this->all();

        if (isset($data['content']) && is_array($data['content'])) {
            $data['content'] = LayoutEmptyObjects::toArrays($data['content']);
        }

        return $data;
    }

    /**
     * 검증 통과분을 돌려주되 `content` 의 빈 객체 자리를 원문대로 되살립니다.
     *
     * @param  string|null  $key  점 표기 경로 (전체면 null)
     * @param  mixed  $default  경로가 없을 때 값
     * @return mixed 검증 통과분
     */
    public function validated($key = null, $default = null): mixed
    {
        return $this->restoreValidatedContent(parent::validated($key, $default), $key);
    }

    /**
     * 검증 통과분(전체 또는 `content` 경로)에 원문의 빈 객체 자리를 되살립니다.
     *
     * @param  mixed  $validated  검증 통과분
     * @param  string|null  $key  `validated()` 에 넘긴 경로
     * @return mixed 빈 객체가 되살아난 검증 통과분
     */
    protected function restoreValidatedContent(mixed $validated, ?string $key): mixed
    {
        $original = $this->input('content');

        if ($key === 'content') {
            return $this->restoreEmptyMaps($validated, $original);
        }

        if ($key === null && is_array($validated) && array_key_exists('content', $validated)) {
            $validated['content'] = $this->restoreEmptyMaps($validated['content'], $original);
        }

        return $validated;
    }

    /**
     * 원문이 빈 객체 표식이고 검증 통과분의 같은 자리가 빈 배열이면 표식으로 되돌립니다 (같은 키로 함께 내려간다).
     *
     * @param  mixed  $validated  검증 통과분 노드
     * @param  mixed  $original  요청 원문 노드
     * @return mixed 되살린 노드
     */
    protected function restoreEmptyMaps(mixed $validated, mixed $original): mixed
    {
        if ($validated === [] && EmptyMap::is($original)) {
            return $original;
        }

        if (is_array($validated) && is_array($original)) {
            foreach ($validated as $k => $v) {
                if (array_key_exists($k, $original)) {
                    $validated[$k] = $this->restoreEmptyMaps($v, $original[$k]);
                }
            }
        }

        return $validated;
    }
}
