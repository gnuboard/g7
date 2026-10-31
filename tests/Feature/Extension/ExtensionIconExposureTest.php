<?php

namespace Tests\Feature\Extension;

use App\Http\Resources\LanguagePackResource;
use App\Http\Resources\ModuleResource;
use App\Http\Resources\PluginResource;
use App\Http\Resources\TemplateResource;
use App\Models\LanguagePack;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 아이콘의 API 노출 테스트 — 목록과 상세 **양쪽**
 *
 * 상세 창은 목록 행을 전역에 담아 두고 열리지만, 창이 뜨는 즉시 상세 응답이
 * 그 자리를 **통째로 덮는다**(레이아웃의 `initGlobal`). 그래서 목록에만 아이콘을
 * 실으면 창이 열린 뒤 아이콘만 사라진다 — 눈에는 "떴다가 없어지는" 것으로 보인다.
 * 실제로 그렇게 새어 나간 적이 있어(플러그인·템플릿의 `toDetailArray`) 이 축을 잠근다.
 *
 * 모집단을 손으로 적지 않는다 — 리소스 클래스 목록에서 파생하고, 그 목록이 비면
 * 하한 가드가 red 가 된다. 유형이 하나 늘어도 케이스 없이 통과하지 않는다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects icon_fields_exposed_on_list_and_detail, language_pack_icon_source_derivation
 */
class ExtensionIconExposureTest extends TestCase
{
    /** @var int 아이콘을 실어야 하는 리소스 유형의 하한(module·plugin·template·language-pack) */
    private const MINIMUM_RESOURCES = 4;

    /**
     * 아이콘 두 필드를 실어야 하는 리소스와 그 직렬화 메서드를 돌려줍니다.
     *
     * 목록(`toArray`)과 상세(`toDetailArray`)를 **각각 한 건으로** 센다 — 한쪽만
     * 채우고 닫은 것이 이 축이 열린 이유다.
     *
     * @return array<string, array{0: class-string, 1: string}> 케이스 이름 => [리소스 클래스, 메서드]
     */
    public static function iconBearingSerializerProvider(): array
    {
        $cases = [];

        foreach (self::iconBearingResources() as $label => $class) {
            $cases[$label.' 목록'] = [$class, 'toArray'];
            $cases[$label.' 상세'] = [$class, 'toDetailArray'];
        }

        return $cases;
    }

    /**
     * 아이콘을 노출해야 하는 리소스 클래스 목록입니다.
     *
     * @return array<string, class-string> 표시 이름 => 클래스
     */
    private static function iconBearingResources(): array
    {
        return [
            '모듈' => ModuleResource::class,
            '플러그인' => PluginResource::class,
            '템플릿' => TemplateResource::class,
            '언어팩' => LanguagePackResource::class,
        ];
    }

    /**
     * 모집단이 하한 아래로 내려가지 않았습니다.
     *
     * 리소스 목록이 비면 아래 DataProvider 기반 케이스가 0회 실행되어 조용히
     * green 이 되므로, 그 함정을 이 단언이 막는다.
     *
     * @scenario surface=list, surface=detail
     *
     * @effects icon_fields_exposed_on_list_and_detail
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function icon_bearing_resource_population_meets_the_lower_bound(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_RESOURCES,
            count(self::iconBearingResources()),
            '아이콘 노출 리소스 모집단이 하한 아래입니다 — 리소스가 이름을 바꿨는지 확인하세요.'
        );
    }

    /**
     * 목록과 상세 응답 **양쪽**이 icon_url·icon_source 를 싣습니다.
     *
     * @scenario surface=list, surface=detail
     *
     * @effects icon_fields_exposed_on_list_and_detail
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  string  $method  직렬화 메서드
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('iconBearingSerializerProvider')]
    public function icon_fields_are_exposed(string $resourceClass, string $method): void
    {
        $payload = $this->serialize($resourceClass, $method, [
            'icon_url' => '/api/extensions/module/acme-demo/icon?v=1.0.0',
            'icon_source' => 'convention',
        ]);

        $this->assertArrayHasKey(
            'icon_url',
            $payload,
            $resourceClass.'::'.$method.'() 가 icon_url 을 싣지 않습니다 — 상세 응답이 목록 행을 덮으면 아이콘이 사라집니다.'
        );
        $this->assertSame('/api/extensions/module/acme-demo/icon?v=1.0.0', $payload['icon_url']);

        $this->assertArrayHasKey(
            'icon_source',
            $payload,
            $resourceClass.'::'.$method.'() 가 icon_source 를 싣지 않습니다.'
        );
        $this->assertSame('convention', $payload['icon_source']);
    }

    /**
     * 아이콘이 없는 행은 icon_url=null · icon_source='none' 으로 나갑니다.
     *
     * 화면은 이 두 값으로 유형 글리프 폴백을 판정하므로 기본값이 어긋나면
     * 아이콘 자리가 비어 버린다.
     *
     * @scenario declaration=none
     *
     * @effects icon_absent_row_defaults_to_none
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  string  $method  직렬화 메서드
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('iconBearingSerializerProvider')]
    public function missing_icon_falls_back_to_none(string $resourceClass, string $method): void
    {
        $payload = $this->serialize($resourceClass, $method, []);

        $this->assertArrayHasKey(
            'icon_url',
            $payload,
            $resourceClass.'::'.$method.'() 가 icon_url 을 싣지 않습니다.'
        );
        $this->assertNull(
            $payload['icon_url'],
            $resourceClass.'::'.$method.'() 의 icon_url 기본값이 null 이 아닙니다.'
        );
        $this->assertSame(
            'none',
            $payload['icon_source'] ?? null,
            $resourceClass.'::'.$method.'() 의 icon_source 기본값이 none 이 아닙니다.'
        );
    }

    /**
     * 언어팩은 합성 출처(target·core·manifest)를 그대로 내보냅니다.
     *
     * 화면이 이 값으로 로케일 배지를 얹을지 정하므로(manifest 면 배지 없음)
     * 값이 뭉개지면 배지가 사라지거나 엉뚱한 곳에 붙는다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects language_pack_icon_source_derivation
     *
     * @param  string  $source  아이콘 출처
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('languagePackIconSourceProvider')]
    public function language_pack_icon_source_is_preserved(string $source): void
    {
        foreach (['toArray', 'toDetailArray'] as $method) {
            $payload = $this->serialize(LanguagePackResource::class, $method, [
                'icon_url' => '/assets/icons/core/g7-core.svg',
                'icon_source' => $source,
            ]);

            $this->assertSame(
                $source,
                $payload['icon_source'] ?? null,
                'LanguagePackResource::'.$method.'() 가 icon_source='.$source.' 를 보존하지 않습니다.'
            );
        }
    }

    /**
     * 언어팩 아이콘 출처 4갈래입니다.
     *
     * @return array<string, array{0: string}> 케이스 이름 => [출처]
     */
    public static function languagePackIconSourceProvider(): array
    {
        return [
            '자기 선언' => ['manifest'],
            '대상 확장' => ['target'],
            '코어' => ['core'],
            '없음' => ['none'],
        ];
    }

    /**
     * 리소스를 배열로 직렬화합니다.
     *
     * 메서드마다 시그니처가 갈려(`toArray(Request)` · `toDetailArray()` ·
     * `LanguagePackResource::toDetailArray(Request)`) 리플렉션으로 인자를 맞춘다.
     *
     * @param  class-string  $resourceClass  리소스 클래스
     * @param  string  $method  직렬화 메서드
     * @param  array<string, mixed>  $attributes  리소스 속성
     * @return array<string, mixed> 직렬화 결과
     */
    private function serialize(string $resourceClass, string $method, array $attributes): array
    {
        $attributes += ['identifier' => 'acme-demo', 'name' => 'Acme Demo'];

        // 언어팩 상세는 모델 메서드(resolveDirectory)를 불러 배열로는 직렬화되지 않는다.
        // 저장하지 않은 인스턴스로 충분하므로 DB 는 건드리지 않는다.
        $payloadSource = $resourceClass === LanguagePackResource::class
            ? tap(new LanguagePack, static function (LanguagePack $pack) use ($attributes): void {
                foreach ($attributes as $key => $value) {
                    $pack->setAttribute($key, $value);
                }
            })
            : $attributes;

        $resource = new $resourceClass($payloadSource);

        $reflection = new \ReflectionMethod($resourceClass, $method);
        $arguments = $reflection->getNumberOfParameters() > 0 ? [Request::create('/')] : [];

        /** @var array<string, mixed> $payload */
        $payload = $reflection->invokeArgs($resource, $arguments);

        return $payload;
    }
}
