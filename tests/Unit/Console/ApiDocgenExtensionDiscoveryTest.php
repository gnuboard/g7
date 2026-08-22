<?php

namespace Tests\Unit\Console;

use App\Console\Commands\ApiDocgenCommand;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ApiDocgenCommand 확장 지원 로직 단위 테스트.
 *
 * 확장 컨트롤러 FQCN 에서 베이스 네임스페이스 추출, 도메인-파라미터 매칭
 * (미바인딩 path param 폴백 판정)의 순수 로직을 HTTP 의존 없이 검증한다.
 */
class ApiDocgenExtensionDiscoveryTest extends TestCase
{
    /**
     * 커맨드의 private 메서드를 리플렉션으로 호출합니다.
     *
     * @param  string  $method  메서드명
     * @param  array<int, mixed>  $args  인자
     * @return mixed 반환값
     */
    private function invoke(string $method, array $args): mixed
    {
        $command = app(ApiDocgenCommand::class);
        $ref = new ReflectionMethod($command, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($command, $args);
    }

    #[Test]
    public function 확장_컨트롤러_fqc_n에서_베이스_네임스페이스를_추출한다(): void
    {
        $base = $this->invoke('extensionBaseNamespace', [
            'Modules\\Sirsoft\\Page\\Http\\Controllers\\Admin\\PageController',
        ]);

        $this->assertSame('Modules\\Sirsoft\\Page', $base);
    }

    #[Test]
    public function http_controllers_없이_controllers_규약을_쓰는_확장도_베이스를_추출한다(): void
    {
        // pay_kginicis 는 `\Http\Controllers\` 대신 `\Controllers\` 배치를 쓴다.
        // 이 경우도 시더 발견을 위해 베이스 네임스페이스를 추출해야 한다.
        $base = $this->invoke('extensionBaseNamespace', [
            'Plugins\\Sirsoft\\PayKginicis\\Controllers\\PaymentSignatureController',
        ]);

        $this->assertSame('Plugins\\Sirsoft\\PayKginicis', $base);
    }

    #[Test]
    public function http_controllers_규약이_controllers_보다_우선한다(): void
    {
        // 두 세그먼트가 공존할 수 있는 경우 `\Http\Controllers\` 를 우선 판정한다.
        $base = $this->invoke('extensionBaseNamespace', [
            'Modules\\Sirsoft\\Board\\Http\\Controllers\\Admin\\BoardController',
        ]);

        $this->assertSame('Modules\\Sirsoft\\Board', $base);
    }

    #[Test]
    public function 클로저_또는_컨트롤러_규약_밖이면_null을_반환한다(): void
    {
        $this->assertNull($this->invoke('extensionBaseNamespace', [null]));
        $this->assertNull($this->invoke('extensionBaseNamespace', ['SomeRandomClass']));
    }

    #[Test]
    public function 파라미터명이_도메인_단수형과_일치하면_매칭이다(): void
    {
        // pages/{page} → 도메인 단수 리소스 폴백 대상
        $this->assertTrue($this->invoke('paramMatchesDomain', ['page', 'pages']));
        $this->assertTrue($this->invoke('paramMatchesDomain', ['pages', 'pages']));
    }

    #[Test]
    public function 보조_문자열_파라미터는_도메인과_매칭되지_않는다(): void
    {
        // {slug}/{hash}/{versionId} 는 route key 가 달라 폴백 금지 (잘못된 404 회피)
        $this->assertFalse($this->invoke('paramMatchesDomain', ['slug', 'pages']));
        $this->assertFalse($this->invoke('paramMatchesDomain', ['hash', 'pages']));
        $this->assertFalse($this->invoke('paramMatchesDomain', ['versionId', 'pages']));
    }

    #[Test]
    public function 도메인_샘플의_path_params_맵으로_문자열_파라미터를_치환한다(): void
    {
        // board 처럼 route-model binding 없는 slug/id 문자열 param 을
        // 도메인 대표 샘플의 path_params 맵(param 명 => 실제 값)으로 정확 일치 치환.
        $route = [
            'uri' => 'api/modules/sirsoft-board/boards/{slug}/posts/{id}',
            'method' => 'GET',
            'domain_group' => 'boards',
            'path_params' => ['slug', 'id'],
            'path_bindings' => [],
        ];
        $sampleMap = [
            'boards' => [
                'model' => User::class,
                'key' => 'id',
                'value' => '1',
                'path_params' => ['slug' => 'qna', 'id' => '123'],
            ],
        ];

        $uri = $this->invoke('resolvePathParams', [$route, $sampleMap]);

        // slug/id 치환 후 중괄호가 모두 사라진 GET 은 목록 대표 샘플 확보용 per_page 가 부착됨
        $this->assertSame('api/modules/sirsoft-board/boards/qna/posts/123?per_page=25', $uri);
    }

    #[Test]
    public function path_params에_없는_파라미터는_치환하지_않고_원본을_유지한다(): void
    {
        // path_params 맵에 없는 param(hash) 은 미치환 → URI 에 남아 프로브가 실측 제외.
        $route = [
            'uri' => 'api/modules/sirsoft-board/boards/{slug}/attachment/{hash}',
            'method' => 'GET',
            'domain_group' => 'boards',
            'path_params' => ['slug', 'hash'],
            'path_bindings' => [],
        ];
        $sampleMap = [
            'boards' => [
                'model' => User::class,
                'key' => 'id',
                'value' => '1',
                'path_params' => ['slug' => 'qna'],
            ],
        ];

        $uri = $this->invoke('resolvePathParams', [$route, $sampleMap]);

        // slug 는 치환되고 hash 는 미치환 → 중괄호가 남아 실측 대상에서 제외됨
        $this->assertStringContainsString('boards/qna/attachment/{hash}', $uri);
    }

    #[Test]
    public function path_params가_없는_기존_확장은_route_key_치환만_적용한다(): void
    {
        // page/gdpr 등 path_params 미제공 확장은 기존 동작 유지(회귀 방지):
        // 문자열 param 은 미치환 → 원본 중괄호 유지.
        $route = [
            'uri' => 'api/modules/sirsoft-page/pages/{slug}',
            'method' => 'GET',
            'domain_group' => 'pages',
            'path_params' => ['slug'],
            'path_bindings' => [],
        ];
        $sampleMap = [
            'pages' => [
                'model' => User::class,
                'key' => 'slug',
                'value' => 'sample',
            ],
        ];

        $uri = $this->invoke('resolvePathParams', [$route, $sampleMap]);

        // path_params 미제공 + paramMatchesDomain('slug','pages')=false → 미치환
        $this->assertStringContainsString('pages/{slug}', $uri);
    }
}
