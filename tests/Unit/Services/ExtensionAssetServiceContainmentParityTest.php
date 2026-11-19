<?php

namespace Tests\Unit\Services;

use App\Services\Concerns\ResolvesExtensionAssetType;
use App\Services\Concerns\SecuresExtensionAssetPath;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 확장 자산 서빙 세 통로가 **같은 2차 봉쇄**를 쓴다.
 *
 * 요청 규칙(`Serve*AssetRequest`)이 1차, 서비스가 2차다. 2차가 없는 통로는 규칙 한 줄이
 * 무력해지는 순간 남는 방어선이 없고, 그 상태는 오류도 로그도 남기지 않는다 — 심볼릭
 * 링크를 경유해 기준 밖을 가리키는 경로가 **정상 200** 으로 나가는 것이 유일한 증상이다.
 *
 * 이 검사가 필요한 이유는 실제로 갈려 있었기 때문이다: trait 독블록은 「2차 봉쇄를 가진
 * 서비스는 템플릿뿐」이라고 적었는데 실측 결과 템플릿이야말로 realpath 재확인이 없었고,
 * 모듈·플러그인만 trait 을 쓰고 있었다. 문서와 구현이 서로를 근거로
 * 삼는 동안 어느 게이트도 그 어긋남을 보지 못했다.
 *
 * 같은 규약이 **유형 판정**(`ResolvesExtensionAssetType`)에도 걸린다. 확장자 화이트리스트
 * 2차 확인은 템플릿에만 있어 세 컨트롤러가 공통으로 갖는 `file_type_not_allowed` 403 분기가
 * 모듈·플러그인에서 도달 불가였고, MIME 표의 소스맵 항목(`map`)도 템플릿 사본에만 있어 그 두
 * 통로에서는 `local` 완화가 사문이었다 — 404 도 403 도 아니라 「200 인데 devtools 가 조용히
 * 무시」라 드러나지 않았다.
 *
 * @scenario asset_serving=template, defense_layer=second_containment, mime_table=sourcemap
 */
class ExtensionAssetServiceContainmentParityTest extends TestCase
{
    /** 확장 자산을 파일시스템에서 직접 여는 서비스 전부. */
    private const ASSET_SERVICES = [
        TemplateService::class,
        ModuleService::class,
        PluginService::class,
    ];

    /**
     * 템플릿, 모듈, 플러그인 자산 서비스가 모두 2차 봉쇄 trait(SecuresExtensionAssetPath)을 쓰는지 확인한다.
     *
     * @effects extension_asset_services_share_one_containment
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_extension_asset_service_uses_the_shared_containment_trait(): void
    {
        foreach (self::ASSET_SERVICES as $service) {
            $this->assertContains(
                SecuresExtensionAssetPath::class,
                class_uses($service) ?: [],
                $service.' 가 2차 봉쇄 trait 을 쓰지 않는다 — 그 통로는 요청 규칙이 무력해지면 '
                .'남는 방어선이 없다(심볼릭 링크 경유 탈출이 정상 200 으로 나간다)'
            );
        }
    }

    /**
     * 어느 자산 서비스에도 경로 정제 사본(sanitizePath)이 남아 공유 trait 과 판정이 갈리지 않는지 확인한다.
     *
     * @effects extension_asset_services_share_one_containment
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_asset_service_keeps_a_private_copy_of_the_sanitizer(): void
    {
        // 사본이 남아 있으면 trait 을 쓰면서도 그 사본을 부르는 상태가 성립한다 —
        // 실제로 템플릿이 `sanitizePath()` 사본 하나로만 서빙하고 있었다.
        foreach (self::ASSET_SERVICES as $service) {
            $methods = array_map(
                static fn ($m) => $m->getName(),
                (new ReflectionClass($service))->getMethods()
            );

            $this->assertNotContains(
                'sanitizePath',
                $methods,
                $service.' 에 정제 사본 `sanitizePath()` 가 남아 있다 — 공유 trait 과 갈리면 '
                .'같은 입력이 통로마다 다른 판정을 받는다'
            );
        }
    }

    /**
     * 세 자산 서비스가 모두 유형 판정 trait(ResolvesExtensionAssetType)을 써서 확장자 확인과 MIME 표를 공유하는지 확인한다.
     *
     * @effects extension_asset_services_share_one_type_resolution
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_extension_asset_service_uses_the_shared_type_resolution_trait(): void
    {
        foreach (self::ASSET_SERVICES as $service) {
            $this->assertContains(
                ResolvesExtensionAssetType::class,
                class_uses($service) ?: [],
                $service.' 가 유형 판정 trait 을 쓰지 않는다 — 확장자 화이트리스트 2차 확인과 '
                .'MIME 표가 통로마다 갈린다(모듈·플러그인이 실제로 그 상태였다)'
            );
        }
    }

    /**
     * 어느 자산 서비스에도 MIME 표 사본(getMimeType)이 남아 같은 파일이 통로마다 다른 유형으로 나가지 않는지 확인한다.
     *
     * @effects extension_asset_services_share_one_type_resolution
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function no_asset_service_keeps_a_private_copy_of_the_mime_table(): void
    {
        // 사본이 남아 있으면 trait 을 쓰면서도 그 사본을 부르는 상태가 성립한다. 그리고
        // 그 어긋남은 오류로 나타나지 않는다 — 소스맵이 `application/octet-stream` 으로
        // 200 을 받고 devtools 가 조용히 무시할 뿐이다.
        foreach (self::ASSET_SERVICES as $service) {
            $methods = array_map(
                static fn ($m) => $m->getName(),
                (new ReflectionClass($service))->getMethods()
            );

            $this->assertNotContains(
                'getMimeType',
                $methods,
                $service.' 에 MIME 표 사본 `getMimeType()` 이 남아 있다 — 공유 표와 갈리면 '
                .'같은 파일이 통로마다 다른 유형으로 나간다'
            );

            $this->assertNotContains(
                'isAllowedExtension',
                $methods,
                $service.' 에 확장자 판정 사본 `isAllowedExtension()` 이 남아 있다'
            );
        }
    }

    /**
     * 공유 유형 표가 소스맵을 application/json 으로, 번들 JS 를 application/javascript 로 판정하는지 확인한다.
     *
     * @scenario mime_table=sourcemap
     *
     * @effects extension_asset_services_share_one_type_resolution
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_shared_mime_table_types_source_maps_as_json(): void
    {
        // 되돌리면(공유 표에서 `map` 항목을 빼면) 이 단언이 red 다. 소스맵은 `local` 에서만
        // 화이트리스트에 오르는데, 표에 없으면 통과해도 `application/octet-stream` 이라
        // 브라우저 devtools 가 읽지 못해 그 완화가 목적을 달성하지 못한다.
        $resolver = new class
        {
            use ResolvesExtensionAssetType {
                extensionAssetMimeType as public mimeType;
                extensionAssetExtensionIsAllowed as public allowed;
            }
        };

        $this->assertSame('application/json', $resolver->mimeType('dist/js/app.iife.js.map'));
        $this->assertSame('application/javascript', $resolver->mimeType('dist/js/app.iife.js'));

        // 표에 없는 확장자는 스니핑하지 않는다 — HTML 형태 업로드물이 `text/html` 로
        // 승격되면 브라우저가 그것을 실행한다.
        $this->assertSame('application/octet-stream', $resolver->mimeType('dist/x.unknown'));

        $this->assertTrue($resolver->allowed('a/b.JS', ['js', 'css']), '확장자 판정은 대소문자를 가리지 않는다');
        $this->assertFalse($resolver->allowed('a/b.php', ['js', 'css']));
    }

    /**
     * 세 자산 서비스 소스가 모두 확장자 허용 판정을 불러 file_type_not_allowed 분기가 어느 통로에서도 죽은 코드가 되지 않는지 확인한다.
     *
     * @effects every_asset_serving_service_gates_the_file_extension
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_asset_serving_service_gates_the_file_extension(): void
    {
        // 컨트롤러 셋은 모두 `file_type_not_allowed` 를 403 으로 옮기는 분기를 갖는다.
        // 그 분기를 **발생시키는** 쪽이 한 통로에만 있으면 나머지 둘에서 그 분기는 도달
        // 불가한 죽은 코드이고, 그 사실은 어떤 응답에도 드러나지 않는다.
        $sources = [
            TemplateService::class => 'app/Services/TemplateService.php',
            ModuleService::class => 'app/Services/ModuleService.php',
            PluginService::class => 'app/Services/PluginService.php',
        ];

        foreach ($sources as $service => $relative) {
            $source = (string) file_get_contents(base_path($relative));

            $this->assertStringContainsString(
                'extensionAssetExtensionIsAllowed(',
                $source,
                $service.' 가 확장자 화이트리스트를 2차로 확인하지 않는다 — 요청 규칙 한 줄이 '
                .'무력해지면 그 통로에는 남는 방어선이 없다'
            );

            $this->assertStringContainsString(
                "'error' => 'file_type_not_allowed',",
                $source,
                $service.' 가 `file_type_not_allowed` 를 낼 수 없다 — 컨트롤러의 403 분기가 도달 불가다'
            );
        }
    }

    /**
     * 세 통로의 **1차 봉쇄 기준선**이 각자의 서빙 루트와 같다.
     *
     * 요청 규칙(`Serve*AssetRequest`)이 재는 기준선과 서비스가 실제로 여는 루트가 갈리면,
     * 1차층은 **서빙되지 않는 트리**를 재게 된다. 그 상태는 오류를 내지 않는다 — 정상
     * 자산 경로에서 `realpath()` 가 언제나 false 로 떨어져 문자열 폴백으로 흘러 봉쇄
     * 단언이 사실상 무조건 참이 되고, 부재 파일은 어차피 서비스가 404 로 끝내므로 응답도
     * 그대로다. 즉 방어 한 겹이 조용히 사라진다.
     *
     * 개별 열거가 아니라 **양쪽 소스에서 도출**해 맞춘다 — 한쪽만 고치면 그 사실 자체가
     * red 다. 실제로 이 브랜치가 템플릿 통로에서만 `/dist` 를 떨어뜨렸고(형제 둘은
     * 일치), 관측 동작이 같아 어떤 테스트도 그것을 보지 못했다.
     *
     * @scenario baseline_source=request_rule, baseline_source=service, extension_type=template, extension_type=module, extension_type=plugin
     *
     * @effects extension_asset_request_baseline_matches_the_serving_root
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_request_rule_measures_the_same_baseline_as_its_service(): void
    {
        $pairs = [
            'template' => [
                'app/Http/Requests/Public/Template/ServeTemplateAssetRequest.php',
                'app/Services/TemplateService.php',
            ],
            'module' => [
                'app/Http/Requests/Public/Module/ServeModuleAssetRequest.php',
                'app/Services/ModuleService.php',
            ],
            'plugin' => [
                'app/Http/Requests/Public/Plugin/ServePluginAssetRequest.php',
                'app/Services/PluginService.php',
            ],
        ];

        // 모집단이 줄면 이 검사는 「보지 않음」이 된다 — 그 상태를 통과로 두지 않는다.
        $this->assertCount(3, $pairs, '자산 서빙 통로 모집단이 셋이 아니다');

        foreach ($pairs as $type => [$requestFile, $serviceFile]) {
            $requestBase = $this->extractBasePathExpression($requestFile, 'function rules(');
            $serviceBase = $this->extractBasePathExpression($serviceFile, 'function getAssetFilePath(');

            $this->assertNotSame('', $requestBase, $type.' 요청 규칙에서 기준선을 도출하지 못했다 — 도출 규칙이 실물과 어긋났다');
            $this->assertNotSame('', $serviceBase, $type.' 서비스에서 서빙 루트를 도출하지 못했다 — 도출 규칙이 실물과 어긋났다');
            $this->assertStringContainsString('{id}', $requestBase, $type.' 요청 기준선에 식별자가 없다 — 도출이 엉뚱한 표현을 잡았다');

            $this->assertSame(
                $serviceBase,
                $requestBase,
                $type.' 통로의 1차 봉쇄 기준선("'.$requestBase.'")이 실제 서빙 루트("'.$serviceBase.'")와 다르다 — '
                .'그 층은 서빙되지 않는 트리를 재고 있어 방어 한 겹이 조용히 사라진다'
            );
        }
    }

    /**
     * 소스에서 `$basePath = …` 가 고르는 `base_path(...)` 인자 표현식(들)을 도출해 정규화합니다.
     *
     * 기준선은 갈래를 가질 수 있다 — 템플릿 통로는 운영자 디렉토리(`custom/`)면 확장 루트,
     * 아니면 `dist` 를 쓴다(7.2.0). 그래서 대입문 안의 `base_path(...)` 를 **전부** 순서대로 모아
     * `|` 로 잇는다. 대입이 같은 클래스의 메서드 호출(`$this->basePathFor(…)`)이면 그 메서드의
     * `return` 문에서 같은 방식으로 모은다 — 기준선을 한 메서드로 뽑아 둔 형태도 같은 값을 잰다.
     *
     * @param  string  $relative  프로젝트 루트 기준 파일 경로
     * @param  string  $methodNeedle  탐색 시작 지점이 되는 메서드 선언 문자열
     * @return string 정규화된 기준선 (도출 실패 시 빈 문자열)
     */
    private function extractBasePathExpression(string $relative, string $methodNeedle): string
    {
        $source = (string) file_get_contents(base_path($relative));

        $methodAt = strpos($source, $methodNeedle);
        if ($methodAt === false) {
            return '';
        }

        $assignAt = strpos($source, '$basePath = ', $methodAt);
        if ($assignAt === false) {
            return '';
        }

        $statement = $this->statementFrom($source, $assignAt);

        if (! str_contains($statement, 'base_path(')
            && preg_match('/\$this->(\w+)\(/', $statement, $call) === 1) {
            $helperAt = strpos($source, 'function '.$call[1].'(');
            $returnAt = $helperAt === false ? false : strpos($source, 'return ', $helperAt);
            if ($returnAt === false) {
                return '';
            }
            $statement = $this->statementFrom($source, $returnAt);
        }

        $expressions = [];
        $offset = 0;
        while (($at = strpos($statement, 'base_path(', $offset)) !== false) {
            $open = $at + strlen('base_path');
            $close = $this->matchingParenAt($statement, $open);
            if ($close === null) {
                return '';
            }

            $expression = substr($statement, $open + 1, $close - $open - 1);

            // 표기 차이를 접는다 — `"templates/{$identifier}/dist"` 와
            // `'templates/'.$identifier.'/dist'` 는 같은 경로다.
            $expression = str_replace(['{$identifier}', '$identifier'], '{id}', $expression);
            $expression = str_replace(['"', "'", '.', ' ', "\t", "\n", "\r"], '', $expression);
            $expressions[] = trim($expression, '/');

            $offset = $close + 1;
        }

        return implode('|', $expressions);
    }

    /**
     * `$offset` 에서 시작하는 문장을 첫 `;` 까지 잘라 돌려줍니다.
     *
     * @param  string  $source  소스
     * @param  int  $offset  문장 시작 위치
     * @return string 문장 (끝 `;` 제외)
     */
    private function statementFrom(string $source, int $offset): string
    {
        $end = strpos($source, ';', $offset);

        return $end === false ? '' : substr($source, $offset, $end - $offset);
    }

    /**
     * `$open` 위치의 `(` 와 짝이 맞는 `)` 의 위치.
     *
     * @param  string  $source  소스
     * @param  int  $open  여는 괄호 위치
     * @return int|null 닫는 괄호 위치 (짝이 없으면 null)
     */
    private function matchingParenAt(string $source, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 봉쇄 판정이 기준 디렉토리 안의 경로만 통과시키고, 이름이 기준으로 시작하는 형제 디렉토리처럼 밖으로 풀리는 경로는 거부하는지 확인한다.
     *
     * @scenario defense_layer=second_containment
     *
     * @effects containment_check_rejects_paths_resolving_outside_the_base
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_containment_check_rejects_a_path_that_resolves_outside_the_base(): void
    {
        // trait 자신의 성질 — 되돌리면(접두 비교에서 구분자를 빼거나 realpath 를 생략하면)
        // 이 단언이 red 다.
        $trait = new class
        {
            use SecuresExtensionAssetPath {
                extensionAssetPathIsContained as public contained;
            }
        };

        $base = storage_path('framework/testing/containment-base');
        $sibling = storage_path('framework/testing/containment-base-other');

        @mkdir($base, 0755, true);
        @mkdir($sibling, 0755, true);
        file_put_contents($sibling.DIRECTORY_SEPARATOR.'secret.js', 'x');

        try {
            $this->assertFalse(
                $trait->contained($sibling.DIRECTORY_SEPARATOR.'secret.js', $base),
                '형제 디렉토리가 접두 일치로 통과한다 — 기준 비교에 구분자가 빠졌다'
            );
        } finally {
            @unlink($sibling.DIRECTORY_SEPARATOR.'secret.js');
            @rmdir($sibling);
            @rmdir($base);
        }
    }

    /**
     * 2차 봉쇄가 널바이트와 숨김 세그먼트를 **자기 계층에서** 막는다.
     *
     * 이 trait 은 요청 규칙(1차층)이 무력해졌을 때 남는 마지막 방어선인데, 그 두 축은
     * 1차층에만 있었다. 서비스는 확장에 열려 있어 「현재 호출부가 FormRequest 를
     * 거친다」는 방어가 되지 못한다. 형제 통로 `PublicStorageService` 는 같은 두 축을
     * 이미 서비스 계층에 갖고 있었다 — 한쪽에만 있는 성질이 곧 결함이다.
     *
     * @effects extension_asset_second_barrier_rejects_null_byte_and_hidden_segments
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_sanitizer_rejects_null_bytes_and_hidden_segments(): void
    {
        $trait = new class
        {
            use SecuresExtensionAssetPath {
                sanitizeExtensionAssetPath as public sanitize;
            }
        };

        // 널바이트 — `file_exists()` 가 PHP 8 에서 ValueError 를 던져 무인증 표면이 500 이 된다.
        $this->assertSame('', $trait->sanitize("js/app.js\0.png"));

        // 탈출 정제 **후** 남는 숨김 세그먼트 — 확장 트리 안의 점 파일이 확장자
        // 화이트리스트(`json`)를 통과하던 형태다.
        $this->assertSame('', $trait->sanitize('dist/../../../.secrets.json'));
        $this->assertSame('', $trait->sanitize('.git/config'));
        // 구분자를 통일하지 않으면 이 형태의 숨김 세그먼트를 보지 못한다.
        $this->assertSame('', $trait->sanitize('dist\.hidden/app.js'));

        // 정상 경로는 그대로 통과해야 한다 — 과잉 거부 대조.
        $this->assertSame('js/app.min.js', $trait->sanitize('js/app.min.js'));
        $this->assertSame('css/components.css', $trait->sanitize('/css/components.css'));
        $this->assertSame('js/app.js', $trait->sanitize('../js/app.js'));
    }
}
