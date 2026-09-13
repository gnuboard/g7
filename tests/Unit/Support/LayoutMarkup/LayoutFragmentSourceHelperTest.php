<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Extension\Helpers\LayoutFragmentSourceHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장이 자기 fragment 정의를 직접 읽을 때의 소스 SSoT 우선 규약 테스트 (S18 P1).
 *
 * 코어가 디렉토리째 수집하는 경로와 달리, 확장 Listener 는 **특정 fragment 파일 하나**를 읽어
 * 호스트 레이아웃에 자기 확장이 주입돼 있는지 확인한다. 그 자리들이 각자 `file_get_contents`
 * +`json_decode` 사본을 갖고 있어 소스(`.g7.html`)을 보지 못했고, 사본이 넷이라 규약을 넓힐 때
 * 한 곳만 고치면 나머지가 남는 구조였다.
 *
 * 여기서는 **코어 진입점의 동작**을 고정한다 — 파일 하나(`read()`)와 디렉토리 전체
 * (`readDirectory()`) 둘 다. 사본이 다시 생기지 않는지를 디스크 도출 모집단으로 보는 가드는
 * {@see FragmentSourceCopyGuardTest} 에 있다.
 *
 * **이 파일이 `Tests\Unit\Support\LayoutMarkup` 에 사는 이유**(30차 이전): 최초 판본은
 * `Tests\Unit\Extension\Helpers` 에 있어 종료 게이트 `--filter=LayoutMarkup` **밖**이었다.
 * 같은 회차에 가드는 게이트 안으로 옮기고 그 사유를 독블록에 적었으면서 **가드가 지키는 대상의
 * 계약 테스트**(이 파일)는 옮기지 않았다 — P1 코어 진입점의 유일한 단위 계약이 게이트 밖에서
 * 여덟 건 돌고 있었다. 소속이 이름의 우연에 걸리지 않도록
 * {@see GateMembershipGuardTest} 가 매니페스트↔게이트 양방향으로 잠근다.
 */
class LayoutFragmentSourceHelperTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/fragment_helper_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_prefers_markup_sibling
     */
    public function test_read_prefers_markup_sibling(): void
    {
        $this->writeMarkup('ext', 'markup_point');
        $this->writeJson('ext', 'json_point');

        $this->assertSame('markup_point', LayoutFragmentSourceHelper::read($this->tmp.'/ext.json')['extension_point'] ?? null,
            '병존하면 소스 SSoT 가 이긴다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_reads_markup_only_definition
     */
    public function test_read_resolves_markup_only_definition(): void
    {
        $this->writeMarkup('ext', 'markup_only');

        $this->assertSame('markup_only', LayoutFragmentSourceHelper::read($this->tmp.'/ext.json')['extension_point'] ?? null,
            '짝 .json 을 굽지 않아도 확장점 정의가 읽힌다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_reads_json_only_definition
     */
    public function test_read_resolves_json_only_definition(): void
    {
        $this->writeJson('ext', 'json_only');

        $this->assertSame('json_only', LayoutFragmentSourceHelper::read($this->tmp.'/ext.json')['extension_point'] ?? null,
            '소스가 없으면 종전대로 .json 을 읽는다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_honors_json_forced_source
     */
    public function test_read_honors_json_forced_source(): void
    {
        $this->writeMarkup('ext', 'markup_point');
        $this->writeJson('ext', 'json_point');

        config(['template.layout_markup.install_source' => 'json']);

        $this->assertSame('json_point', LayoutFragmentSourceHelper::read($this->tmp.'/ext.json')['extension_point'] ?? null,
            '--layout-source=json 이면 코어 설치 경로와 같은 방향으로 뒤집힌다');
    }

    /**
     * 읽을 소스가 없으면 빈 배열 — 호출처는 "있으면 쓰고 없으면 아무것도 하지 않는" 자리다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fragment_helper_returns_empty_when_absent
     */
    public function test_read_returns_empty_when_no_source_exists(): void
    {
        $this->assertSame([], LayoutFragmentSourceHelper::read($this->tmp.'/absent.json'));
    }

    /**
     * 디렉토리 단위 읽기도 소스 SSoT 를 우선한다 — 마크업 전용 정의가 잡힌다.
     *
     * 확장이 "자기 확장점 **전부**" 를 읽어야 하는 자리가 있다(업데이트 후 overlay 복원). 그 자리가
     * 스스로 `glob('*.json')` 하면 소스가 통째로 보이지 않으므로, 디렉토리 수집도 코어가 맡는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_directory_read_includes_markup_only
     */
    public function test_read_directory_includes_markup_only_definitions(): void
    {
        $this->writeMarkup('markup_only_point', 'markup_only');
        $this->writeJson('json_only_point', 'json_only');

        $points = array_map(
            static fn (array $definition): mixed => $definition['extension_point'] ?? null,
            LayoutFragmentSourceHelper::readDirectory($this->tmp)
        );

        $this->assertEqualsCanonicalizing(['markup_only', 'json_only'], $points,
            '마크업 전용 정의도 디렉토리 수집에 잡힌다');
    }

    /**
     * 짝이 병존하면 소스 SSoT 하나만 남는다 (중복 계상 금지).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_directory_read_dedupes_pairs
     */
    public function test_read_directory_does_not_double_count_pairs(): void
    {
        $this->writeMarkup('ext', 'markup_point');
        $this->writeJson('ext', 'json_point');

        $definitions = LayoutFragmentSourceHelper::readDirectory($this->tmp);

        $this->assertCount(1, $definitions, '같은 논리 fragment 는 1건이다');
        $this->assertSame('markup_point', $definitions[0]['extension_point'] ?? null);
    }

    /**
     * 디렉토리 수집은 조각 식별자(`fragment_key`)를 함께 돌려준다 — 같은 대상에 조각이 둘일 때
     * 확장이 키 없이 등록하면 어느 행인지 가릴 수 없어 아무것도 하지 않기 때문이다(#476 C13).
     *
     * 짝(`x.g7.html`·`x.json`)은 같은 논리 조각이라 같은 키다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_directory_read_carries_fragment_key
     */
    public function test_read_directory_keyed_carries_fragment_key(): void
    {
        $this->writeMarkup('admin_order_payment', 'order_payment');
        $this->writeJson('admin_order_query', 'order_query');

        $keyed = LayoutFragmentSourceHelper::readDirectoryKeyed($this->tmp);

        $byKey = [];
        foreach ($keyed as $fragment) {
            $byKey[$fragment['key']] = $fragment['data']['extension_point'] ?? null;
        }

        $this->assertSame(
            ['admin_order_payment' => 'order_payment', 'admin_order_query' => 'order_query'],
            $byKey,
            '키는 확장자를 뗀 기본 이름이고, 소스·짝 어느 쪽이든 같은 키다',
        );

        // 기대값은 **이 테스트가 디스크에 쓴 것**에서 직접 적는다. 종전에는
        // `array_column($keyed, 'data')` 와 비교했는데, `readDirectory()` 가 바로 그 식으로
        // 구현돼 있어 **어느 소스가 이겨도 양변이 같아지는 항등식**이었다 — 실패할 수 없는 단언이다
        // (`OracleIndependenceGuardTest` 가 잡았다).
        $this->assertSame(
            ['order_payment', 'order_query'],
            array_map(
                fn (array $definition): ?string => $definition['extension_point'] ?? null,
                LayoutFragmentSourceHelper::readDirectory($this->tmp),
            ),
            'readDirectory 는 키 없이 정의만, 수집 순서 그대로 돌려준다',
        );
    }

    /**
     * 디렉토리가 없으면 빈 배열 — 호출처가 존재 확인을 중복하지 않게 한다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fragment_helper_directory_read_returns_empty_when_absent
     */
    public function test_read_directory_returns_empty_when_directory_absent(): void
    {
        $this->assertSame([], LayoutFragmentSourceHelper::readDirectory($this->tmp.'/absent'));
    }

    /**
     * 읽기 캐시의 **키 계약**(경로|mtime|크기)을 관측합니다.
     *
     * 37차 신설. 34차가 조각 컴파일 캐시에는 이 관측을 붙였는데(`MarkupPartialResolutionTest`)
     * **동형 계약 둘이 무관측**으로 남아 있었다 — 여기(`LayoutFragmentSourceHelper::$readCache`)와
     * `CollectsFragmentSources::$fragmentCompileCache`. 캐시는 틀려도 초록인 종류의 코드다:
     * 키에서 mtime·크기를 빼면 **낡은 정의가 무한히 살아남고**, 캐시 조회를 지우면 성능만 나빠져
     * 아무 단언도 깨지지 않는다. 그래서 두 방향을 각각 관측한다.
     *
     * 변이검증: 캐시 조회(`isset(self::$readCache[...])`)를 지우면 축ⓐ가, 키에서 mtime·크기를
     * 빼면 축ⓑ가 red.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_helper_read_cache_is_keyed_by_path_mtime_size
     */
    public function test_read_cache_is_keyed_by_path_mtime_and_size(): void
    {
        $path = "{$this->tmp}/cached.g7.html";
        $reference = "{$this->tmp}/cached.json";

        $write = function (string $point) use ($path): void {
            File::put($path, "<extension extension-point=\"{$point}\" mode=\"replace\" priority=\"100\">\n  <div/>\n</extension>\n");
        };

        $write('cache_v1');
        $this->assertSame('cache_v1', LayoutFragmentSourceHelper::read($reference)['extension_point'] ?? null,
            '첫 읽기가 실패하면 이후 축을 관측할 수 없다');

        // ⓐ 적중 — mtime·크기가 같으면 내용이 바뀌어도 캐시가 이긴다.
        //    길이를 맞추고(`cache_v1` ↔ `cache_v2`) mtime 을 되돌려 두 성분을 동일하게 만든다.
        $stamp = filemtime($path);
        $write('cache_v2');
        touch($path, (int) $stamp);
        clearstatcache(true, $path);

        $this->assertSame('cache_v1', LayoutFragmentSourceHelper::read($reference)['extension_point'] ?? null,
            '동일 키인데 새 내용이 나온다 — 캐시 조회가 죽었다는 뜻이고, 매 요청이 재컴파일된다');

        // ⓑ 무효화 — 크기가 달라지면 키가 갈려 새로 읽는다.
        $write('cache_v3_longer_point_name');
        clearstatcache(true, $path);

        $this->assertSame('cache_v3_longer_point_name', LayoutFragmentSourceHelper::read($reference)['extension_point'] ?? null,
            '크기가 바뀌었는데도 옛 값이 나온다 — 키에 크기가 빠지면 낡은 정의가 무한히 산다');
    }

    /**
     * fragment 소스를 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  string  $point  확장점 이름
     */
    private function writeMarkup(string $name, string $point): void
    {
        File::put("{$this->tmp}/{$name}.g7.html",
            "<extension extension-point=\"{$point}\" mode=\"replace\" priority=\"100\">\n  <div/>\n</extension>\n");
    }

    /**
     * fragment 짝 `.json` 을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  string  $point  확장점 이름
     */
    private function writeJson(string $name, string $point): void
    {
        File::put("{$this->tmp}/{$name}.json", json_encode([
            'extension_point' => $point,
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
