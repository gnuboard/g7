<?php

namespace Tests\Unit\Support;

use App\Support\CoreVersionDiskReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 디스크 `config/app.php` 의 코어 버전 기본값(이 코드가 출시된 버전)을 읽는 헬퍼 테스트 (#145 W3).
 *
 * 메모리의 `config('app.version')` 은 옛 부모가 물려준 env 나 오염된 config 캐시를 따라갈 수 있어, 자동 비활성화
 * 안전망과 beta.7 전용 복구 가드는 디스크 파일을 직접 읽는다. 옛 업데이터와 버전 동기 검사도 같은 줄을 정규식으로
 * 읽으므로 `'version' => env('APP_VERSION', 'X'),` 모양은 바뀌면 안 된다.
 */
class CoreVersionDiskReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $files = [];

    /**
     * 테스트가 만든 임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * 임시 config 파일을 만듭니다.
     *
     * @param  string  $content  파일 본문
     * @return string 파일 경로
     */
    private function configFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'g7cfg');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /**
     * 저장소 config/app.php 에서 env 기본값을 읽는다.
     *
     * @scenario case=repository_config
     *
     * @effects disk_reader_returns_env_default_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_reads_repository_default_version(): void
    {
        preg_match("/'version'\s*=>\s*env\('APP_VERSION',\s*'([^']+)'\)/", (string) file_get_contents(config_path('app.php')), $m);

        $this->assertNotEmpty($m[1] ?? null, '전제 — config/app.php 의 version 줄 모양');
        $this->assertSame($m[1], CoreVersionDiskReader::shippedVersion());
    }

    /**
     * env 기본값 형식과 리터럴 형식을 모두 읽는다.
     *
     * @scenario case=env_default_and_literal
     *
     * @effects disk_reader_returns_env_default_version
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_reads_env_default_and_literal_forms(): void
    {
        $this->assertSame('7.1.1', CoreVersionDiskReader::shippedVersion($this->configFile("<?php return [\n    'version' => env('APP_VERSION', '7.1.1'),\n];")));
        $this->assertSame('7.0.0-beta.7', CoreVersionDiskReader::shippedVersion($this->configFile("<?php return ['version' => '7.0.0-beta.7'];")));
    }

    /**
     * 파일이 없거나 형식이 다르면 null 을 돌려준다(안전망은 아무것도 하지 않는 쪽으로 기운다).
     *
     * @scenario case=unrecognized
     *
     * @effects disk_reader_returns_null_on_mismatch
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_returns_null_on_missing_or_mismatched_file(): void
    {
        $this->assertNull(CoreVersionDiskReader::shippedVersion(sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7_no_such_config_'.uniqid().'.php'));
        $this->assertNull(CoreVersionDiskReader::shippedVersion($this->configFile("<?php return ['name' => env('APP_NAME', 'G7')];")));
    }
}
