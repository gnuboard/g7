<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\BaseApiResource;
use App\Http\Resources\LayoutExtensionResource;
use App\Http\Resources\LayoutListResource;
use App\Http\Resources\LayoutResource;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * BaseApiResource 의 공용 헬퍼를 자식이 더 좁은 가시성으로 재선언하지 못하게 막는다.
 *
 * PHP 는 오버라이드에서 가시성 축소를 금지한다. 부모에 `protected` 헬퍼를 새로 추가했는데
 * 자식이 같은 이름을 이미 `private` 로 갖고 있으면 **그 리소스를 쓰는 엔드포인트가 통째로 500** 이 된다.
 *
 * 이 검사는 **반드시 소스 텍스트로** 해야 한다 — 리플렉션으로 자식 클래스를 로드하는 순간
 * `Access level to ...::x() must be protected ... or weaker` **FatalError** 가 나면서
 * PHPUnit 프로세스 자체가 죽고, 실패한 단언이 아니라 **아무 결과도 남지 않는다.**
 * (S3-D11 red 대조에서 실제로 러너가 즉사하는 것을 확인했다. 그래서 클래스를 로드하지 않는다.)
 *
 * 회귀(S3-D11): 커밋 6a071b2ec 가 `BaseApiResource::formatFileSize()` 를 추가했는데
 * `LayoutListResource`·`LayoutExtensionResource`·`LayoutResource` 세 곳이 이미 같은 이름을
 * `private` 로 갖고 있어 `GET /api/admin/templates/{t}/layouts` 가 무조건 500 이 됐다
 * (레이아웃 편집기가 목록을 아예 못 불러옴 → 3-K 실측 불가).
 *
 * 헬퍼 이름을 나열하지 않고 **부모 헬퍼 전체 × 리소스 소스 전체**를 도는 이유는, 다음에
 * 부모에 헬퍼가 하나 더 붙어도 이 테스트가 자동으로 잡게 하기 위해서다.
 */
class BaseApiResourceHelperVisibilityTest extends TestCase
{
    /**
     * @scenario child_declaration=narrowed
     *
     * @effects child_must_not_narrow_parent_helper_visibility
     */
    #[Test]
    public function 자식_리소스는_부모_헬퍼를_더_좁은_가시성으로_재선언하지_않는다(): void
    {
        $parentHelpers = $this->parentHelperVisibilities();

        $this->assertNotEmpty(
            $parentHelpers,
            '부모에서 헬퍼를 하나도 못 읽었다면 이 테스트는 아무것도 지키지 못한다.'
        );

        $violations = [];

        foreach ($this->resourceSources() as $file => $source) {
            foreach ($parentHelpers as $name => $parentVisibility) {
                // 소스 텍스트로만 본다 — 클래스를 로드하면 위반 파일에서 러너가 죽는다.
                if (preg_match('/^\s*private\s+(?:static\s+)?function\s+'.preg_quote($name, '/').'\s*\(/m', $source)) {
                    $violations[] = sprintf(
                        '%s::%s() 가 private — 부모 BaseApiResource 는 %s (가시성 축소는 FatalError)',
                        basename($file, '.php'), $name, $parentVisibility
                    );

                    continue;
                }

                if ($parentVisibility === 'public'
                    && preg_match('/^\s*protected\s+(?:static\s+)?function\s+'.preg_quote($name, '/').'\s*\(/m', $source)) {
                    $violations[] = sprintf(
                        '%s::%s() 가 protected — 부모 BaseApiResource 는 public (가시성 축소는 FatalError)',
                        basename($file, '.php'), $name
                    );
                }
            }
        }

        $this->assertSame([], $violations, "가시성 축소 오버라이드:\n".implode("\n", $violations));
    }

    /**
     * 레이아웃 리소스 3종이 실제로 **로드되고** 부모 헬퍼로 크기를 포맷하는지 본다.
     *
     * 위 스캔은 소스 텍스트만 보므로 "선언이 없다" 까지만 증명한다. 이 테스트는 한 걸음 더 나아가
     * **클래스를 실제로 로드해** FatalError 가 나지 않음을 보인다 — S3-D11 에서 무너진 것이
     * 정확히 이 지점(`GET /api/admin/templates/{t}/layouts` 가 무조건 500)이었다.
     *
     * 포맷 결과까지 단언하는 이유: 자식 선언을 지우기만 하고 부모에 헬퍼가 없으면
     * `Call to undefined method` 로 바뀔 뿐 증상이 그대로다.
     *
     * @scenario child_declaration=inherited
     *
     * @effects layout_resources_inherit_shared_file_size_formatter
     */
    #[Test]
    public function 레이아웃_리소스_3종은_로드되고_부모의_크기_포맷터를_상속한다(): void
    {
        $classes = [
            LayoutListResource::class,
            LayoutExtensionResource::class,
            LayoutResource::class,
        ];

        foreach ($classes as $class) {
            // class_exists 가 로드를 강제한다 — 가시성이 어긋나 있으면 여기서 FatalError 다.
            $this->assertTrue(class_exists($class), $class.' 를 로드하지 못했다.');

            $method = new ReflectionMethod($class, 'formatFileSize');

            $this->assertSame(
                BaseApiResource::class,
                $method->getDeclaringClass()->getName(),
                $class.' 가 공용 포맷터를 자체 선언으로 가리고 있다 — 단위·자릿수 규칙이 화면마다 갈린다.'
            );
        }

        // 부모 포맷터가 실제로 동작하는지 (지우기만 하고 부모에 없으면 증상이 같다)
        $formatter = new ReflectionMethod(BaseApiResource::class, 'formatFileSize');
        $formatter->setAccessible(true);
        $resource = new LayoutResource(null);

        $this->assertSame('0 B', $formatter->invoke($resource, 0));
        $this->assertSame('1 KB', $formatter->invoke($resource, 1024));
    }

    /**
     * BaseApiResource 가 스스로 선언한 non-private 메서드와 그 가시성.
     *
     * 부모는 위반 대상이 아니므로 로드해도 안전하다.
     *
     * @return array<string, string> 메서드명 => 'protected'|'public'
     */
    private function parentHelperVisibilities(): array
    {
        $parent = new ReflectionClass(BaseApiResource::class);
        $helpers = [];

        foreach ($parent->getMethods(ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== BaseApiResource::class) {
                continue;
            }

            // 매직 메서드와 생성자는 오버라이드 관례가 달라 제외한다.
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            $helpers[$method->getName()] = $method->isPublic() ? 'public' : 'protected';
        }

        return $helpers;
    }

    /**
     * `app/Http/Resources` 아래 모든 PHP 파일의 소스.
     *
     * @return array<string, string> 경로 => 소스
     */
    private function resourceSources(): array
    {
        $dir = app_path('Http/Resources');
        $sources = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // 부모 자신은 기준이지 위반 대상이 아니다.
            if (basename($path) === 'BaseApiResource.php') {
                continue;
            }

            $source = file_get_contents($path);

            // BaseApiResource 를 상속하는 파일만 본다.
            if (! str_contains($source, 'extends BaseApiResource')) {
                continue;
            }

            $sources[$path] = $source;
        }

        return $sources;
    }
}
