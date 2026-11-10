<?php

namespace Tests\Unit\Concerns\Seeder;

use App\Concerns\Seeder\HasTranslatableSeeder;
use App\Contracts\Seeder\TranslatableSeederInterface;
use App\Extension\HookManager;
use Tests\PureTestCase;

/**
 * HasTranslatableSeeder Trait 단위 테스트.
 *
 * 검증 시나리오:
 *  1. 확장 식별자가 있을 때 필터명이 `seed.{ext}.{entity}.translations` 로 조립됨
 *  2. 확장 식별자가 빈 문자열일 때 필터명이 `seed.{entity}.translations` 로 조립됨
 *  3. 등록된 필터가 없으면 getDefaults() 결과가 그대로 반환됨
 *  4. 등록된 필터가 있으면 활성 lang pack 키가 머지된 결과가 반환됨
 *  5. 매칭 키 (code/slug/key) 가 entry 보강 시 사용되는지 — 필터 콜백 인자로 정확히 전달됨
 */
class HasTranslatableSeederTest extends PureTestCase
{
    /**
     * 테스트용 anonymous 시더 — interface + trait 사용.
     *
     * @param  string  $extension  확장 식별자(빈 문자열이면 코어 범위)
     * @param  string  $entity  번역 대상 엔티티 이름
     * @param  string  $matchKey  항목 매칭 키(code·slug·key 등)
     * @param  array  $defaults  시더 기본 항목 목록
     * @return TranslatableSeederInterface 트레이트 내부 메서드를 노출하는 익명 시더
     */
    private function makeSeeder(string $extension, string $entity, string $matchKey, array $defaults): TranslatableSeederInterface
    {
        return new class($extension, $entity, $matchKey, $defaults) implements TranslatableSeederInterface
        {
            use HasTranslatableSeeder;

            /**
             * 시더 계약 값들을 그대로 보관한다.
             *
             * @param  string  $extension  확장 식별자(빈 문자열이면 코어 범위)
             * @param  string  $entity  번역 대상 엔티티 이름
             * @param  string  $matchKey  항목 매칭 키
             * @param  array  $defaults  시더 기본 항목 목록
             */
            public function __construct(
                private string $extension,
                private string $entity,
                private string $matchKey,
                private array $defaults,
            ) {}

            /**
             * 생성자로 받은 확장 식별자를 돌려준다.
             *
             * @return string 확장 식별자
             */
            public function getExtensionIdentifier(): string
            {
                return $this->extension;
            }

            /**
             * 생성자로 받은 번역 대상 엔티티 이름을 돌려준다.
             *
             * @return string 엔티티 이름
             */
            public function getTranslatableEntity(): string
            {
                return $this->entity;
            }

            /**
             * 생성자로 받은 항목 매칭 키를 돌려준다.
             *
             * @return string 매칭 키
             */
            public function getMatchKey(): string
            {
                return $this->matchKey;
            }

            /**
             * 생성자로 받은 기본 항목 목록을 돌려준다.
             *
             * @return array 기본 항목 목록
             */
            public function getDefaults(): array
            {
                return $this->defaults;
            }

            /**
             * 트레이트가 조립한 번역 필터 이름을 노출한다.
             *
             * @return string 필터 훅 이름
             */
            public function exposeFilterName(): string
            {
                return $this->resolveTranslationFilterName();
            }

            /**
             * 트레이트가 필터를 거쳐 만든 번역 보강 기본 항목을 노출한다.
             *
             * @return array 필터 적용 후 항목 목록
             */
            public function exposeResolved(): array
            {
                return $this->resolveTranslatedDefaults();
            }
        };
    }

    /**
     * 확장 식별자가 있으면 필터 이름을 seed.{ext}.{entity}.translations 로 조립한다.
     *
     * @scenario case=filter_name
     *
     * @effects extension_scoped_filter_name
     *
     * @return void 반환값 없음
     */
    public function test_filter_name_for_extension_scope(): void
    {
        $seeder = $this->makeSeeder('sirsoft-ecommerce', 'shipping_carriers', 'code', []);

        $this->assertSame(
            'seed.sirsoft-ecommerce.shipping_carriers.translations',
            $seeder->exposeFilterName(),
        );
    }

    /**
     * 확장 식별자가 빈 문자열이면 필터 이름을 seed.{entity}.translations 로 조립한다.
     *
     * @scenario case=filter_name
     *
     * @effects core_scoped_filter_name
     *
     * @return void 반환값 없음
     */
    public function test_filter_name_for_core_scope_when_extension_empty(): void
    {
        $seeder = $this->makeSeeder('', 'notifications', 'type', []);

        $this->assertSame(
            'seed.notifications.translations',
            $seeder->exposeFilterName(),
        );
    }

    /**
     * 등록된 필터가 없으면 getDefaults() 결과를 그대로 돌려준다.
     *
     * @scenario case=defaults_resolution
     *
     * @effects defaults_returned_without_filter
     *
     * @return void 반환값 없음
     */
    public function test_returns_defaults_when_no_filter_registered(): void
    {
        $defaults = [
            ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics']],
        ];

        $seeder = $this->makeSeeder('sirsoft-ecommerce', 'shipping_carriers_unfiltered_'.uniqid(), 'code', $defaults);

        $this->assertSame($defaults, $seeder->exposeResolved());
    }

    /**
     * 필터가 등록돼 있으면 활성 언어팩 키(ja)가 기존 ko·en 값을 유지한 채 병합된 결과를 돌려준다.
     *
     * @scenario case=defaults_resolution
     *
     * @effects lang_pack_keys_merged_via_filter
     *
     * @return void 반환값 없음
     */
    public function test_returns_filtered_defaults_with_lang_pack_merge(): void
    {
        $entity = 'shipping_carriers_filtered_'.uniqid();
        $filterName = "seed.sirsoft-ecommerce.{$entity}.translations";

        $defaults = [
            ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics']],
            ['code' => 'hanjin', 'name' => ['ko' => '한진택배', 'en' => 'Hanjin Express']],
        ];

        // 활성 ja lang pack 의 seed/{entity}.json 머지를 모사 — code 매칭으로 ja 키 보강
        $jaSeed = [
            ['code' => 'cj', 'name' => ['ja' => 'CJ大韓通運']],
            ['code' => 'hanjin', 'name' => ['ja' => 'ハンジン宅配']],
        ];

        HookManager::addFilter($filterName, function (array $entries) use ($jaSeed): array {
            foreach ($entries as &$entry) {
                foreach ($jaSeed as $patch) {
                    if ($entry['code'] === $patch['code']) {
                        $entry['name'] = array_merge($entry['name'], $patch['name']);
                    }
                }
            }

            return $entries;
        });

        $seeder = $this->makeSeeder('sirsoft-ecommerce', $entity, 'code', $defaults);
        $resolved = $seeder->exposeResolved();

        $this->assertCount(2, $resolved);
        $this->assertSame('CJ대한통운', $resolved[0]['name']['ko']);
        $this->assertSame('CJ Logistics', $resolved[0]['name']['en']);
        $this->assertSame('CJ大韓通運', $resolved[0]['name']['ja']);
        $this->assertSame('ハンジン宅配', $resolved[1]['name']['ja']);
    }

    /**
     * 필터 콜백은 기본 항목을 그대로 받고, 시더는 지정한 매칭 키(slug)를 노출한다.
     *
     * @scenario case=defaults_resolution
     *
     * @effects filter_receives_defaults_with_match_key
     *
     * @return void 반환값 없음
     */
    public function test_match_key_passes_through_to_filter_callback(): void
    {
        $entity = 'board_types_'.uniqid();
        $filterName = "seed.sirsoft-board.{$entity}.translations";

        $defaults = [
            ['slug' => 'basic', 'name' => ['ko' => '기본형']],
        ];

        $captured = null;
        HookManager::addFilter($filterName, function (array $entries) use (&$captured): array {
            $captured = $entries;

            return $entries;
        });

        $seeder = $this->makeSeeder('sirsoft-board', $entity, 'slug', $defaults);
        $seeder->exposeResolved();

        $this->assertSame($defaults, $captured);
        $this->assertSame('slug', $seeder->getMatchKey());
    }
}
