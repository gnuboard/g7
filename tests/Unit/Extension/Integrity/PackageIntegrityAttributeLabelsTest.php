<?php

namespace Tests\Unit\Extension\Integrity;

use App\Http\Requests\Concerns\HasPackageIntegrityRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\App;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * 무결성 트레잇이 준 검증 키에 칸 이름 라벨이 붙는지 확인합니다 (docs/backend/validation.md 「트레이트로 규칙을 나눠 쓸 때」).
 *
 * 트레잇 키(`allow_unsigned`·`integrity_aware`·`signature`·`signature_url`)는 요청 파일에 글자로 적히지 않아
 * 라벨 감사 룰이 보지 못한다. 라벨이 없으면 메시지를 따로 두지 않은 규칙(`signature_url.string`·`.max` 등)의
 * 오류 문구에 원시 키가 나간다. 대상 요청은 손으로 적지 않고 트레잇 사용처를 파일에서 뽑는다.
 */
class PackageIntegrityAttributeLabelsTest extends TestCase
{
    /**
     * 트레잇을 쓰는 요청마다, 그 요청의 규칙에 실린 트레잇 키 전부가 로케일별로 사람이 읽는 라벨을 갖는다.
     *
     * @scenario case=integrity_attribute_labels
     *
     * @effects integrity_rule_keys_have_field_labels
     *
     * @return void 반환값 없음
     */
    public function test_trait_provided_rule_keys_have_labels(): void
    {
        $classes = $this->requestClassesUsingTrait();
        $this->assertNotEmpty($classes, '무결성 트레잇을 쓰는 요청을 하나도 찾지 못했다 — 모집단 파생이 깨졌다');

        $traitKeys = ['allow_unsigned', 'integrity_aware', 'signature', 'signature_url'];
        $checked = 0;

        foreach (['ko', 'en'] as $locale) {
            App::setLocale($locale);

            foreach ($classes as $class) {
                /** @var FormRequest $request */
                $request = $class::create('/', 'POST');
                $request->setContainer($this->app);

                $keys = array_values(array_intersect(array_keys($request->rules()), $traitKeys));
                $this->assertNotEmpty($keys, "{$class}: 규칙에 트레잇 키가 없다");

                $labels = $request->attributes();
                foreach ($keys as $key) {
                    $label = $labels[$key] ?? null;
                    $this->assertIsString($label, "{$class} [{$locale}]: '{$key}' 라벨이 없다");
                    $this->assertNotSame($key, $label, "{$class} [{$locale}]: '{$key}' 라벨이 원시 키다");
                    $this->assertStringNotContainsString('extension_integrity.', $label, "{$class} [{$locale}]: '{$key}' 라벨 번역 키를 찾지 못했다");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * `app/Http/Requests` 에서 무결성 트레잇을 쓰는 요청 클래스를 뽑습니다.
     *
     * @return array<int, class-string<FormRequest>> 요청 클래스 목록
     */
    private function requestClassesUsingTrait(): array
    {
        $classes = [];
        $finder = Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php');

        foreach ($finder as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Http\\Requests\\'.$relative;

            if (! class_exists($class) || ! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            if (in_array(HasPackageIntegrityRules::class, class_uses_recursive($class), true)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
