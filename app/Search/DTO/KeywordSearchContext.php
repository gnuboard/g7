<?php

namespace App\Search\DTO;

/**
 * 키워드 술어를 만들 때 엔진에게 전달되는 조건
 *
 * 엔진마다 술어를 만드는 방식이 다르고, 그중 일부는 **비용이 규모에 비례**합니다.
 * 외부 검색 서버를 쓰는 엔진은 자기 서버에서 키 집합을 받아 조건으로 붙이는데, 매칭이
 * 크면 그 키 집합 자체가 메모리 폭발이 됩니다 — 이 프로젝트가 한 번 겪은 결함입니다.
 *
 * 그래서 코어는 엔진에게 "얼마까지 가져와도 되는가" 를 함께 넘깁니다. 규정만으로는
 * 강제할 수 없으므로(외부 엔진 코드는 이 저장소 밖입니다) **값을 손에 쥐어 주는 것**까지가
 * 코어가 할 수 있는 최선입니다.
 *
 * 값 추가가 필요해질 때 계약 시그니처를 다시 깨지 않도록 객체로 감쌉니다.
 */
final class KeywordSearchContext
{
    /**
     * @param  int|null  $keyCap  엔진이 돌려줄 수 있는 최대 키 개수 (null = 무제한)
     */
    public function __construct(
        public readonly ?int $keyCap = null,
    ) {}

    /**
     * 상한이 정해져 있는지 반환합니다.
     *
     * @return bool 상한이 있으면 true
     */
    public function hasKeyCap(): bool
    {
        return $this->keyCap !== null && $this->keyCap > 0;
    }
}
