<?php

namespace App\Contracts\Seeder;

/**
 * 다국어 데이터를 시드하는 시더가 구현해야 할 인터페이스.
 *
 * 활성 언어팩의 seed/{entity}.json 자동 머지 인프라와 결선되어,
 * `seed.{vendor-extension}.{entity}.translations` 필터를 자동 호출.
 *
 * @since 7.0.0-beta.5
 */
interface TranslatableSeederInterface
{
    /**
     * 확장 식별자 — `seed.{vendor-extension}.{entity}.translations` 필터 키 구성.
     *
     * 예: 'sirsoft-board', 'sirsoft-ecommerce'.
     * 코어 시더는 빈 문자열 반환 (필터 키: `seed.{entity}.translations`).
     */
    public function getExtensionIdentifier(): string;

    /**
     * Entity 이름 — `seed/{entity}.json` 파일명 (확장자 제외).
     *
     * 예: 'board_types', 'shipping_carriers'.
     */
    public function getTranslatableEntity(): string;

    /**
     * 시드 entry 매칭 키 컬럼명.
     *
     * 우선순위: code > slug > key > identifier > id.
     */
    public function getMatchKey(): string;

    /**
     * 기본 데이터 (활성 언어팩 머지 전 원본).
     *
     * `applyFilters` 호출 후 ja 등 활성 locale 키가 자동 보강된 결과를 시더가 사용.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefaults(): array;
}
