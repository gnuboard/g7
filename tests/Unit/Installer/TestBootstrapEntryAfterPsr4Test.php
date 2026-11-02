<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 테스트 부트스트랩은 `_bundled` 확장의 PSR-4 를 등록한 뒤에 진입 파일(module.php·plugin.php)을 읽는다 (#159).
 *
 * 진입 클래스가 자기 PSR-4 네임스페이스의 클래스를 상속하는 확장은, PSR-4 등록 전에 진입 파일을 읽으면 부모를 못 찾아
 * 부트스트랩이 치명 오류로 죽는다(테스트 스위트 전체가 요약 줄 없이 중단). 설치 흐름과 같은 순서를 지킨다.
 */
class TestBootstrapEntryAfterPsr4Test extends TestCase
{
    /**
     * `_bundled` 루프 구간 정의.
     *
     * @return array<string, array{string, string, string}> 구간 시작 표지, 구간 끝 표지, 진입 파일 require
     */
    public static function bundledLoops(): array
    {
        return [
            'modules' => ["\$bundledModulesDir = __DIR__.'/../modules/_bundled';", "\$bundledPluginsDir = __DIR__.'/../plugins/_bundled';", 'require_once $modulePhp;'],
            'plugins' => ["\$bundledPluginsDir = __DIR__.'/../plugins/_bundled';", '// 확장(모듈/플러그인) 오토로드 등록', 'require_once $pluginPhp;'],
        ];
    }

    /**
     * 각 `_bundled` 루프에서 PSR-4 prepend 등록이 진입 파일 require 보다 앞에 있다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects test_bootstrap_registers_psr4_before_entry_file
     *
     * @param  string  $startMarker  구간 시작 표지
     * @param  string  $endMarker  구간 끝 표지
     * @param  string  $entryRequire  진입 파일 require 문
     * @return void 반환값 없음
     */
    #[DataProvider('bundledLoops')]
    public function test_bundled_loop_registers_psr4_before_requiring_entry_file(string $startMarker, string $endMarker, string $entryRequire): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap.php');

        $start = strpos($source, $startMarker);
        $this->assertNotFalse($start, '구간 시작 표지를 찾지 못했습니다');
        $end = strpos($source, $endMarker, $start + strlen($startMarker));
        $this->assertNotFalse($end, '구간 끝 표지를 찾지 못했습니다');
        $loop = substr($source, $start, $end - $start);

        $psr4Pos = strpos($loop, '$loader->addPsr4($namespace, $absolutePath, true);');
        $requirePos = strpos($loop, $entryRequire);

        $this->assertNotFalse($psr4Pos);
        $this->assertNotFalse($requirePos);
        $this->assertLessThan($requirePos, $psr4Pos, '진입 파일을 PSR-4 등록 전에 읽습니다 — 자기 소스 클래스를 상속하는 확장에서 부트스트랩이 죽습니다.');
    }
}
