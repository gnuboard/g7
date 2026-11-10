<?php

namespace Tests\Unit\Testing\SchemaCache;

use Tests\PureTestCase;
use Tests\Support\TestingSchema\Fingerprint;
use Tests\Support\TestingSchema\TestingSchemaCache;

/**
 * 마이그레이션 지문 — 같은 마이그레이션을 다시 돌리면 같은 스키마가 나온다는 근거 전부를 묶는다.
 *
 * 파일 내용 1바이트, 마이그레이션이 use 로 가져오는 클래스 파일, composer.lock, 로직 판 중 하나만 바뀌어도 지문이 달라져야
 * 다음 실행이 전체 재생으로 간다. 경로 순서처럼 결과와 무관한 차이로는 바뀌지 않아야 한다(쓸데없는 50초 재생 방지).
 */
class FingerprintTest extends PureTestCase
{
    /** 시험용 임시 디렉터리 */
    private string $dir;

    /**
     * 마이그레이션 2개·참조 클래스 파일·composer.lock 픽스처를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/g7-fingerprint-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/a.php', "<?php\nuse App\\Search\\Engines\\DatabaseFulltextEngine;\nuse Illuminate\\Support\\Str;\nreturn 1;\n");
        file_put_contents($this->dir.'/b.php', "<?php\nreturn 2;\n");
        file_put_contents($this->dir.'/Engine.php', "<?php\nclass Engine {}\n");
        file_put_contents($this->dir.'/composer.lock', '{"hash":"1"}');
    }

    /**
     * 픽스처 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * 픽스처로 지문을 계산합니다(참조 클래스는 픽스처 Engine.php 로 풀린다).
     *
     * @param  array<string, string>|null  $files  이름 → 경로(생략하면 a·b 순서)
     * @param  int|null  $logicVersion  판정 로직 판(생략하면 현재 상수)
     * @return string 지문
     */
    private function fingerprint(?array $files = null, ?int $logicVersion = null): string
    {
        return Fingerprint::of(
            $files ?? ['2026_01_01_a' => $this->dir.'/a.php', '2026_01_02_b' => $this->dir.'/b.php'],
            fn (string $class): ?string => $class === 'App\\Search\\Engines\\DatabaseFulltextEngine' ? $this->dir.'/Engine.php' : null,
            $this->dir.'/composer.lock',
            $logicVersion,
        );
    }

    /**
     * 파일 목록 순서가 달라도 지문은 같다.
     *
     * @scenario case=order_independent
     *
     * @effects fingerprint_ignores_path_order
     *
     * @return void 반환값 없음
     */
    public function test_order_of_files_does_not_change_fingerprint(): void
    {
        $this->assertSame(
            $this->fingerprint(),
            $this->fingerprint(['2026_01_02_b' => $this->dir.'/b.php', '2026_01_01_a' => $this->dir.'/a.php'])
        );
    }

    /**
     * 마이그레이션 내용 1바이트·참조 클래스 파일·composer.lock 이 바뀌면 지문이 바뀐다.
     *
     * @scenario case=input_changed
     *
     * @effects fingerprint_tracks_every_schema_input
     *
     * @return void 반환값 없음
     */
    public function test_any_input_change_changes_fingerprint(): void
    {
        $base = $this->fingerprint();

        file_put_contents($this->dir.'/b.php', "<?php\nreturn 3;\n");
        $afterMigration = $this->fingerprint();
        $this->assertNotSame($base, $afterMigration, '마이그레이션 내용 변경');

        file_put_contents($this->dir.'/Engine.php', "<?php\nclass Engine { const X = 1; }\n");
        $afterClass = $this->fingerprint();
        $this->assertNotSame($afterMigration, $afterClass, 'use 대상 클래스 파일 변경');

        file_put_contents($this->dir.'/composer.lock', '{"hash":"2"}');
        $afterLock = $this->fingerprint();
        $this->assertNotSame($afterClass, $afterLock, 'composer.lock 변경');

        $this->assertSame($afterLock, $this->fingerprint(logicVersion: TestingSchemaCache::LOGIC_VERSION), '로직 판 기본값은 현재 상수');
        $this->assertNotSame($afterLock, $this->fingerprint(logicVersion: TestingSchemaCache::LOGIC_VERSION + 1), '로직 판 변경');
    }

    /**
     * 1단계 use 중 Illuminate 가 아닌 클래스만 지문 입력으로 고르고, 로직 판은 양의 정수다.
     *
     * @scenario case=imports_and_logic
     *
     * @effects fingerprint_tracks_every_schema_input
     *
     * @return void 반환값 없음
     */
    public function test_imported_classes_exclude_illuminate_and_logic_version_is_included(): void
    {
        $this->assertSame(
            ['App\\Search\\Engines\\DatabaseFulltextEngine'],
            Fingerprint::importedClasses((string) file_get_contents($this->dir.'/a.php'))
        );
        $this->assertGreaterThan(0, TestingSchemaCache::LOGIC_VERSION);
    }
}
