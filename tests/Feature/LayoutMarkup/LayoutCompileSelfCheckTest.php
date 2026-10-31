<?php

namespace Tests\Feature\LayoutMarkup;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `layout:compile` 기본 모드의 자기검사 — 산출 `.g7.html` 을 기록하기 전에 프로덕션 진입점으로 되읽는다.
 *
 * 종전에는 역변환 산출을 컴파일 없이 그대로 썼다. 그래서 설치·편집기가 거부할 소스(주석 귀속 위반·
 * 강검증 위반)를 커맨드가 만들어 두어도 `--check` 가 돌기 전까지 아무도 몰랐다. 자기검사는 그 소스를
 * **쓰지 않고** 실패로 세운다.
 *
 * 픽스처는 임시 디렉터리에만 만든다 — 추적 파일을 고치는 테스트는 잔여물로 무관한 검사를 red 로 만든다.
 */
class LayoutCompileSelfCheckTest extends TestCase
{
    private string $dir;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-layout-compile-selfcheck-'.uniqid();
        File::makeDirectory($this->dir, 0777, true);
    }

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * 올바른 레이아웃은 자기검사를 통과한 뒤 파일로 쓴다.
     *
     * @scenario compile_unit=command,source_health=valid
     *
     * @effects compile_command_writes_markup_that_passes_production_compile
     *
     * @return void 반환값 없음
     */
    public function test_valid_layout_is_written_after_self_check(): void
    {
        $json = $this->dir.DIRECTORY_SEPARATOR.'ok.json';
        $out = $this->dir.DIRECTORY_SEPARATOR.'ok.g7.html';

        File::put($json, json_encode([
            'layout_name' => 'ok',
            'version' => '1.0.0',
            'named_actions' => [
                'probe' => ['comment' => '한 번만 찍히는 주석', 'handler' => 'setState', 'params' => ['target' => 'local', 'a' => 1]],
            ],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ]));

        $this->artisan('layout:compile', ['path' => $json, '--out' => $out])
            ->assertExitCode(0);

        $this->assertFileExists($out);
        $this->assertSame(1, substr_count(File::get($out), '한 번만 찍히는 주석'));
    }

    /**
     * 프로덕션 컴파일이 거부하는 레이아웃은 파일로 쓰지 않는다.
     *
     * @scenario compile_unit=command,source_health=invalid
     *
     * @effects compile_command_refuses_to_write_markup_that_production_compile_rejects
     *
     * @return void 반환값 없음
     */
    public function test_layout_rejected_by_production_compile_is_not_written(): void
    {
        $json = $this->dir.DIRECTORY_SEPARATOR.'bad.json';
        $out = $this->dir.DIRECTORY_SEPARATOR.'bad.g7.html';

        // 역변환은 되지만 강검증(허용 목록 밖 엔드포인트)이 거부하는 문서 — 종전 커맨드는 이 소스를 그대로 썼다.
        File::put($json, json_encode([
            'layout_name' => 'bad',
            'version' => '1.0.0',
            'data_sources' => [['id' => 'd', 'endpoint' => '/nope/x']],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ]));

        $this->artisan('layout:compile', ['path' => $json, '--out' => $out])
            ->expectsOutputToContain('자기검사 실패')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($out);
    }
}
