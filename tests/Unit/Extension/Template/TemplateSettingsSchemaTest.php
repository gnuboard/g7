<?php

namespace Tests\Unit\Extension\Template;

use App\Extension\Template\TemplateSettingsSchema;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 템플릿 환경설정 선언 해석기 테스트
 *
 * 선언 파일 한 벌이 섹션·기본값·검증·노출을 정하므로(모듈 설정과 같은 섹션 구조), 깨진 선언은 설치 단계에서 막혀야 한다.
 */
class TemplateSettingsSchemaTest extends TestCase
{
    private string $root;

    /**
     * 임시 템플릿 루트를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/qa434-schema-'.uniqid());
        File::ensureDirectoryExists($this->root.'/config/settings');
    }

    /**
     * 임시 템플릿 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 선언 파일을 씁니다.
     *
     * @param  mixed  $data  선언 (문자열이면 원문 그대로)
     * @return void 반환값 없음
     */
    private function declare(mixed $data): void
    {
        File::put($this->root.'/config/settings/defaults.json', is_string($data) ? $data : json_encode($data));
    }

    /**
     * 섹션 하나짜리 선언을 만듭니다.
     *
     * @param  array<string, mixed>  $fields  칸 선언
     * @param  array<string, mixed>  $extra  덧붙일 최상위 블록
     * @param  string  $section  섹션 이름
     * @return array<string, mixed> 선언
     */
    private function section(array $fields, array $extra = [], string $section = 'branding'): array
    {
        return array_merge(['_meta' => ['categories' => [$section]], 'schema' => [$section => $fields]], $extra);
    }

    /**
     * 칸 종류 13가지를 해석하고 섹션 순서·기본값 채우기·첨부/이미지/계산 경로를 돌려준다.
     *
     * @scenario case=schema
     *
     * @effects schema_declaration_parsed
     *
     * @return void 반환값 없음
     */
    public function test_parses_all_field_types_by_section(): void
    {
        $this->declare([
            '_meta' => ['categories' => ['look', 'content']],
            'defaults' => ['look' => ['title' => 'x'], 'content' => ['count' => 3]],
            'schema' => [
                'look' => [
                    'title' => ['type' => 'string', 'max' => 50],
                    'flag' => ['type' => 'boolean'],
                    'mode' => ['type' => 'enum', 'options' => [['value' => 'a', 'label' => 'A'], 'b']],
                    'color' => ['type' => 'color'],
                    'banner' => ['type' => 'attachments', 'max_files' => 3],
                    'hero' => ['type' => 'image', 'max_size_kb' => 512],
                ],
                'content' => [
                    'count' => ['type' => 'integer', 'min' => 1, 'step' => 2],
                    'body' => ['type' => 'text', 'max_length' => 500],
                    'site' => ['type' => 'url'],
                    'path' => ['type' => 'link'],
                    'mail' => ['type' => 'email'],
                    'notice' => ['type' => 'multilingual'],
                    'links' => ['type' => 'list', 'max_items' => 3, 'item_fields' => ['label' => ['type' => 'multilingual'], 'url' => ['type' => 'link']]],
                ],
            ],
            'frontend_schema' => ['look' => ['expose' => true, 'fields' => ['title' => ['expose' => true], 'hero_url' => ['expose' => true]]]],
        ]);

        $schema = TemplateSettingsSchema::load('qa434-x', $this->root);

        $this->assertNotNull($schema);
        $this->assertSame(['look', 'content'], $schema->categories);
        $this->assertSame(['look.banner', 'look.hero'], $schema->attachmentPaths());
        $this->assertSame(['look.hero'], $schema->imagePaths());
        $this->assertSame(['look.hero_url'], $schema->computedPaths());
        $this->assertSame('template_settings.qa434-x.look.banner', $schema->collection('look.banner'));
        $this->assertSame(3, $schema->maxFiles('look.banner'));
        $this->assertSame(TemplateSettingsSchema::IMAGE_EXTENSIONS, $schema->acceptedExtensions('look.hero'));
        // 선언된 기본값은 그대로, 선언되지 않은 칸은 종류별 빈 값으로 채운다
        $this->assertSame('x', $schema->defaults['look']['title']);
        $this->assertFalse($schema->defaults['look']['flag']);
        $this->assertSame(3, $schema->defaults['content']['count']);
        $this->assertSame([], $schema->defaults['content']['links']);
        $this->assertSame(['a', 'b'], TemplateSettingsSchema::optionValues($schema->field('look.mode')));
        $this->assertFalse($schema->declaresFavicon());
    }

    /**
     * 선언 파일이 없으면 null(설정 기능 없음)이다.
     *
     * @scenario case=schema
     *
     * @effects schema_declaration_parsed
     *
     * @return void 반환값 없음
     */
    public function test_missing_declaration_returns_null(): void
    {
        File::delete($this->root.'/config/settings/defaults.json');

        $this->assertNull(TemplateSettingsSchema::load('qa434-x', $this->root));
        $this->assertFalse(TemplateSettingsSchema::isDeclared('qa434-x', $this->root));
    }

    /**
     * 규약 위반 선언은 거부한다 — 평면(v1) 형식·모르는 종류·노출 스키마의 선언 밖 키·비밀값 노출 등.
     *
     * @scenario case=schema
     *
     * @effects invalid_schema_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_declarations_are_rejected(): void
    {
        foreach ([
            'json' => '{not json',
            'v1 flat' => ['schema' => ['favicon' => ['type' => 'string']]],
            'section not listed' => ['_meta' => ['categories' => ['a']], 'schema' => ['a' => ['x' => ['type' => 'string']], 'b' => ['y' => ['type' => 'string']]]],
            'unknown type' => $this->section(['a' => ['type' => 'unknown']]),
            'enum no options' => $this->section(['a' => ['type' => 'enum']]),
            'computed collision' => $this->section(['logo' => ['type' => 'image'], 'logo_url' => ['type' => 'string']]),
            'bad key' => $this->section(['Bad-Key' => ['type' => 'string']]),
            'list without items' => $this->section(['links' => ['type' => 'list']]),
            'nested file in list' => $this->section(['links' => ['type' => 'list', 'item_fields' => ['file' => ['type' => 'attachments']]]]),
            'image non-image accept' => $this->section(['logo' => ['type' => 'image', 'accept' => ['pdf']]]),
            'sensitive on boolean' => $this->section(['a' => ['type' => 'boolean', 'sensitive' => true]]),
            'expose undeclared' => $this->section(['a' => ['type' => 'string']], ['frontend_schema' => ['branding' => ['expose' => true, 'fields' => ['nope' => ['expose' => true]]]]]),
            'expose sensitive' => $this->section(['token' => ['type' => 'string', 'sensitive' => true]], ['frontend_schema' => ['branding' => ['expose' => true, 'fields' => ['token' => ['expose' => true]]]]]),
            'expose sensitive by section' => $this->section(['token' => ['type' => 'string', 'sensitive' => true]], ['frontend_schema' => ['branding' => ['expose' => true]]]),
            'default type' => $this->section(['count' => ['type' => 'integer']], ['defaults' => ['branding' => ['count' => 'many']]]),
            'bad rename' => $this->section(['a' => ['type' => 'string', 'renamed_from' => ['flat_key']]]),
        ] as $name => $case) {
            $this->declare($case);

            try {
                TemplateSettingsSchema::load('qa434-x', $this->root);
                $this->fail("거부되어야 할 선언이 통과했다: {$name}");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * 표준 키 규격 — 파비콘은 attachments·1개·파비콘 7형식, 포인트 색은 안전한 enum 값, 기본 화면 모드는 system|light|dark 부분집합.
     *
     * @scenario case=schema
     *
     * @effects favicon_reserved_key_contract
     *
     * @return void 반환값 없음
     */
    public function test_standard_key_contracts(): void
    {
        $favicon = ['type' => 'attachments', 'max_files' => 1, 'accept' => ['webp', 'ico', 'png', 'svg', 'gif', 'jpg', 'jpeg']];

        $this->declare($this->section(['favicon' => $favicon, 'accent' => ['type' => 'enum', 'options' => ['blue', 'green-2']], 'color_scheme_default' => ['type' => 'enum', 'options' => ['system', 'dark']]]));
        $this->assertTrue(TemplateSettingsSchema::load('qa434-x', $this->root)->declaresFavicon());

        foreach ([
            ['favicon' => array_merge($favicon, ['max_files' => 2])],
            ['favicon' => array_merge($favicon, ['accept' => ['png']])],
            ['favicon' => array_merge($favicon, ['type' => 'string'])],
            ['accent' => ['type' => 'enum', 'options' => ['blue"><script>']]],
            ['accent' => ['type' => 'color']],
            ['color_scheme_default' => ['type' => 'enum', 'options' => ['sepia']]],
        ] as $fields) {
            $this->declare($this->section($fields));

            try {
                TemplateSettingsSchema::load('qa434-x', $this->root);
                $this->fail('표준 키 규격 위반이 통과했다: '.json_encode($fields));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('branding.', $e->getMessage());
            }
        }
    }

    /**
     * 비밀값·이름 바꾸기 목록과, 다른 주인이 가진 값과 이름이 겹치는 칸 경고를 돌려준다.
     *
     * @scenario case=schema
     *
     * @effects renamed_key_migrated
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_renames_and_owned_elsewhere_warnings(): void
    {
        $data = $this->section([
            'token' => ['type' => 'string', 'sensitive' => true],
            'title' => ['type' => 'string', 'renamed_from' => ['old.heading']],
            'site_name' => ['type' => 'string'],
        ]);
        $this->declare($data);

        $schema = TemplateSettingsSchema::load('qa434-x', $this->root);

        $this->assertSame(['branding.token'], $schema->sensitivePaths());
        $this->assertSame(['old.heading' => 'branding.title'], $schema->renames());
        $this->assertSame(['branding.site_name'], TemplateSettingsSchema::warnings($data));
        $this->assertSame(['branding.site_name'], TemplateSettingsSchema::warningsFor('qa434-x', $this->root));
    }

    /**
     * 번들 템플릿 4종의 선언은 규약을 지키고 다른 주인과 겹치는 이름이 없다.
     *
     * @scenario case=schema
     *
     * @effects schema_declaration_parsed
     *
     * @return void 반환값 없음
     */
    public function test_bundled_declarations_are_valid(): void
    {
        foreach (['sirsoft-basic', 'sirsoft-admin_basic', 'gnuboard7-hello_user_template', 'gnuboard7-hello_admin_template'] as $identifier) {
            $root = base_path('templates/_bundled/'.$identifier);
            $data = json_decode((string) File::get(TemplateSettingsSchema::path($identifier, $root)), true);

            $this->assertSame([], TemplateSettingsSchema::validate($data), $identifier);
            $this->assertSame([], TemplateSettingsSchema::warnings($data), $identifier);
            $this->assertTrue(TemplateSettingsSchema::load($identifier, $root)->declaresFavicon(), $identifier);
        }
    }
}
