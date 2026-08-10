<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.0.6/migrations/01_NullifyUntouchedSeoCacheOverrides.php';

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_6\Migrations\NullifyUntouchedSeoCacheOverrides;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 7.0.6 업그레이드 스텝 — SEO 캐시 오버라이드 이행 (결정 D19)
 *
 * 판정 규칙:
 *   옛 기본값과 같으면 "안 건드린 것" → null(미설정)로 비움
 *   옛 기본값과 다르면 "의도적으로 바꾼 것" → 보존 (이제부터 오버라이드로 발동)
 *
 * 이 판정이 틀리면 기존 사이트의 캐시 동작이 의도치 않게 바뀐다.
 */
class NullifyUntouchedSeoCacheOverridesTest extends TestCase
{
    private NullifyUntouchedSeoCacheOverrides $migration;

    /**
     * 테스트 초기화 - settings 디스크를 가짜로 대체합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('settings');
        $this->migration = new NullifyUntouchedSeoCacheOverrides;
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.0.5', '7.0.6', '7.0.6');
    }

    /**
     * seo.json 을 기록합니다.
     *
     * @param  array<string, mixed>  $settings  설정 배열
     */
    private function writeSeoJson(array $settings): void
    {
        Storage::disk('settings')->put('seo.json', json_encode($settings));
    }

    /**
     * seo.json 을 읽습니다.
     *
     * @return array<string, mixed> 설정 배열
     */
    private function readSeoJson(): array
    {
        return json_decode(Storage::disk('settings')->get('seo.json'), true);
    }

    /**
     * name: 로그용 식별자를 반환한다
     */
    public function test_name_returns_identifier(): void
    {
        $this->assertSame('NullifyUntouchedSeoCacheOverrides', $this->migration->name());
    }

    /**
     * 옛 기본값 그대로인 키는 null 로 비운다 (안 건드린 것으로 추정)
     */
    public function test_clears_keys_left_at_legacy_defaults(): void
    {
        $this->writeSeoJson([
            'cache_enabled' => true,
            'cache_ttl' => 7200,
            'sitemap_cache_ttl' => 86400,
            'sitemap_enabled' => true,
        ]);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertNull($result['cache_enabled']);
        $this->assertNull($result['cache_ttl']);
        $this->assertNull($result['sitemap_cache_ttl']);
    }

    /**
     * 옛 기본값과 다른 값은 보존한다 (의도적으로 바꾼 것 → 오버라이드로 발동)
     *
     * 실제 사례: seo.cache_ttl=3600 을 설정해 뒀으나 그동안 무시되던 사이트.
     */
    public function test_preserves_values_that_differ_from_legacy_defaults(): void
    {
        $this->writeSeoJson([
            'cache_enabled' => false,
            'cache_ttl' => 3600,
            'sitemap_cache_ttl' => 604800,
        ]);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertFalse($result['cache_enabled'], 'false 는 기본값(true)과 다르므로 보존돼야 합니다.');
        $this->assertSame(3600, $result['cache_ttl']);
        $this->assertSame(604800, $result['sitemap_cache_ttl']);
    }

    /**
     * 일부만 바뀐 경우 — 바뀐 것만 보존하고 나머지는 비운다
     */
    public function test_clears_untouched_and_preserves_changed_in_same_file(): void
    {
        $this->writeSeoJson([
            'cache_enabled' => true,      // 기본값 → 비움
            'cache_ttl' => 3600,          // 변경됨 → 보존
            'sitemap_cache_ttl' => 86400, // 기본값 → 비움
        ]);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertNull($result['cache_enabled']);
        $this->assertSame(3600, $result['cache_ttl']);
        $this->assertNull($result['sitemap_cache_ttl']);
    }

    /**
     * seo 카테고리의 다른 키는 건드리지 않는다
     */
    public function test_does_not_touch_unrelated_keys(): void
    {
        $this->writeSeoJson([
            'cache_ttl' => 7200,
            'sitemap_enabled' => true,
            'sitemap_schedule' => 'hourly',
            'sitemap_schedule_time' => '03:00',
            'meta_title_suffix' => ' | G7',
        ]);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertTrue($result['sitemap_enabled']);
        $this->assertSame('hourly', $result['sitemap_schedule'], '재생성 주기는 이 이행과 무관합니다.');
        $this->assertSame('03:00', $result['sitemap_schedule_time']);
        $this->assertSame(' | G7', $result['meta_title_suffix']);
    }

    /**
     * 멱등: 두 번 실행해도 결과가 같다
     */
    public function test_is_idempotent(): void
    {
        $this->writeSeoJson([
            'cache_ttl' => 7200,
            'sitemap_cache_ttl' => 3600,
        ]);

        $this->migration->run($this->context());
        $first = $this->readSeoJson();

        $this->migration->run($this->context());
        $second = $this->readSeoJson();

        $this->assertSame($first, $second);
        $this->assertNull($second['cache_ttl']);
        $this->assertSame(3600, $second['sitemap_cache_ttl']);
    }

    /**
     * 이미 null 인 키는 그대로 둔다 (재실행 안전)
     */
    public function test_leaves_already_null_keys_untouched(): void
    {
        $this->writeSeoJson(['cache_ttl' => null, 'sitemap_cache_ttl' => null]);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertNull($result['cache_ttl']);
        $this->assertNull($result['sitemap_cache_ttl']);
    }

    /**
     * 키가 아예 없으면 새로 만들지 않는다
     */
    public function test_does_not_add_absent_keys(): void
    {
        $this->writeSeoJson(['meta_title_suffix' => ' | G7']);

        $this->migration->run($this->context());
        $result = $this->readSeoJson();

        $this->assertArrayNotHasKey('cache_ttl', $result);
        $this->assertArrayNotHasKey('sitemap_cache_ttl', $result);
    }

    /**
     * seo.json 이 없으면 no-op (예외 없음)
     */
    public function test_no_op_when_settings_file_is_absent(): void
    {
        $this->migration->run($this->context());

        $this->assertFalse(Storage::disk('settings')->exists('seo.json'));
    }

    /**
     * 손상된 JSON 은 덮어쓰지 않고 보존한다 (파괴 금지)
     */
    public function test_preserves_corrupted_json_without_overwriting(): void
    {
        Storage::disk('settings')->put('seo.json', '{ this is not json');

        $this->migration->run($this->context());

        $this->assertSame('{ this is not json', Storage::disk('settings')->get('seo.json'));
    }

    /**
     * 타입이 다른 동일 값은 오버라이드로 본다 (느슨한 비교 금지)
     *
     * 문자열 "7200" 은 기본값 7200(int)과 == 로는 같지만, 운영자가 폼으로 입력한
     * 흔적이므로 === 비교로 보존해야 한다.
     */
    public function test_treats_type_mismatched_value_as_override(): void
    {
        $this->writeSeoJson(['cache_ttl' => '7200']);

        $this->migration->run($this->context());

        $this->assertSame('7200', $this->readSeoJson()['cache_ttl']);
    }
}
