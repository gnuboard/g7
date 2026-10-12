<?php

namespace Tests\Unit\Rules;

use App\Rules\SafeModulePath;
use App\Rules\SafePluginPath;
use App\Rules\SafeTemplatePath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 정적 파일 경로 규칙 3종이 기준 폴더 경계를 지키는지 봉인한다 (#144 같은 계열).
 *
 * 종전 판정은 문자열 접두(`Str::startsWith`)라 기준 폴더 `…/base` 와 이름이 비슷한 형제 `…/base-x` 를 구분하지 못했다.
 * 경로 문자열에는 `..` 를 쓸 수 없으므로(패턴 차단) 형제 폴더로 가는 길은 링크뿐이다 — 기준 폴더 안의 링크가 형제 폴더를
 * 가리키게 만들어 잰다(Windows 는 정션, 그 밖은 심볼릭 링크). 또 아직 없는 파일은 원 경로를 정리하지 않고 비교해,
 * 기준 경로를 `/` 로 적은 Windows 에서 정상 경로가 "밖" 으로 오판됐다.
 */
class SafeExtensionPathBoundaryTest extends TestCase
{
    /** 임시 트리 최상위 */
    private string $tree;

    /** 기준 폴더 */
    private string $base;

    /** 형제 폴더를 가리키는 링크 */
    private string $link;

    /**
     * 기준 폴더·형제 폴더·링크를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = storage_path('framework/testing/safe_path_'.uniqid());
        $this->base = $this->tree.'/acme-ext';
        $this->link = $this->base.'/dist/shared';

        File::makeDirectory($this->base.'/dist/js', 0777, true);
        File::makeDirectory($this->tree.'/acme-ext-x/dist', 0777, true);
        File::put($this->base.'/dist/js/app.js', 'ok');
        File::put($this->tree.'/acme-ext-x/dist/secret.js', 'secret');

        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $this->link)).' '.escapeshellarg(str_replace('/', '\\', $this->tree.'/acme-ext-x/dist')), $output, $code);
            $this->assertSame(0, $code, '정션을 만들지 못했습니다.');
        } else {
            $this->assertTrue(symlink($this->tree.'/acme-ext-x/dist', $this->link), '심볼릭 링크를 만들지 못했습니다.');
        }
    }

    /**
     * 링크를 먼저 떼고 임시 트리를 지웁니다 (링크를 따라 형제 폴더를 지우지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @rmdir($this->link);
        } else {
            @unlink($this->link);
        }

        File::deleteDirectory($this->tree);

        parent::tearDown();
    }

    /**
     * 규칙 클래스와 그 메시지 키 접두를 제공합니다.
     *
     * @return array<string, array{0: class-string, 1: string}> 데이터셋 이름 → [규칙 클래스, 메시지 키 접두]
     */
    public static function ruleProvider(): array
    {
        return [
            '모듈' => [SafeModulePath::class, 'validation.module_path'],
            '플러그인' => [SafePluginPath::class, 'validation.plugin_path'],
            '템플릿' => [SafeTemplatePath::class, 'validation.template_path'],
        ];
    }

    /**
     * 기준 폴더 안 링크가 이름이 비슷한 형제 폴더를 가리키면 거부한다.
     *
     * @scenario case=safe_path_sibling_prefix_rejected
     *
     * @effects extension_asset_path_rejects_sibling_prefix_directory
     *
     * @param  string  $ruleClass  규칙 클래스
     * @param  string  $keyPrefix  메시지 키 접두
     * @return void 반환값 없음
     */
    #[DataProvider('ruleProvider')]
    public function test_link_to_sibling_prefix_directory_is_rejected(string $ruleClass, string $keyPrefix): void
    {
        $validator = Validator::make(['path' => 'dist/shared/secret.js'], ['path' => [new $ruleClass($this->base)]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(__($keyPrefix.'.outside_base_directory'), $validator->errors()->first('path'));
    }

    /**
     * 기준 경로를 `/` 로 적어도 안쪽 경로(실재·미실재 모두)는 통과한다.
     *
     * @scenario case=safe_path_inside_accepted
     *
     * @effects extension_asset_path_accepts_inside_path_regardless_of_separator
     *
     * @param  string  $ruleClass  규칙 클래스
     * @param  string  $keyPrefix  메시지 키 접두
     * @return void 반환값 없음
     */
    #[DataProvider('ruleProvider')]
    public function test_inside_paths_pass_with_forward_slash_base(string $ruleClass, string $keyPrefix): void
    {
        $base = str_replace('\\', '/', $this->base);

        foreach (['dist/js/app.js', 'dist/js/not-built-yet.js'] as $path) {
            $validator = Validator::make(['path' => $path], ['path' => [new $ruleClass($base)]]);

            $this->assertFalse($validator->fails(), "{$path}: ".$validator->errors()->first('path').' ('.$keyPrefix.')');
        }
    }
}
