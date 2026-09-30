<?php

namespace Tests\Feature\LayoutMarkup;

use App\Console\Commands\Layout\LayoutDecompileCommand;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutJsonWriter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `layout:decompile --sync` / `--check` 계약 테스트 (S18.5 축 A — 쌍둥이 기계화).
 *
 * S10 이후 소스 SSoT 는 `.g7.html` 인데 짝 `.json` 은 **손유지**였다. 소스만 고치면 코퍼스
 * 게이트가 사후에 red 를 낼 뿐 고치는 방법은 알려 주지 않았고, 주석 하나를 다는 데 편집이 두 벌
 * 필요했다(그래서 S21 이 S18.5 를 선행 조건으로 둔다). 굽기는 그 손유지를 기계로 바꾼다.
 *
 * 여기서 고정하는 것은 다섯이다:
 *
 *  - `--sync` 가 짝을 **생성**한다 (마크업 전용 신규 확장).
 *  - `--sync` 가 낡은 짝을 **갱신**하고, 이미 같으면 **쓰지 않는다**(mtime 보존 — 조각 컴파일
 *    캐시가 `경로｜mtime｜크기` 로 키를 잡으므로 무의미한 재기록이 캐시를 통째로 무효화한다).
 *  - `--check` 는 쓰지 않고 대조만 하며 불일치 시 **non-zero + 파일 목록**을 낸다.
 *  - 산출 개행은 **LF 고정**이다 (㊼ N4 — CRLF 가 섞이면 인덱스 생성기가 제목을 퇴화시킨다).
 *  - **설치·서빙 경로는 굽지 않는다** (D38). 이 축만 합성 표본이 아니라 코어 전량 스캔이다 —
 *    나머지 넷은 커맨드의 계약이지만 이것은 "커맨드 밖에서 아무도 부르지 않는다" 는 여집합
 *    명제라, 표본으로는 원리적으로 증명되지 않는다.
 */
class LayoutDecompileSyncTest extends TestCase
{
    /**
     * 합성 소스가 놓이는 디렉토리 (git 미추적, {@see LayoutCorpus} 스캔 밖).
     */
    private const SCRATCH = 'storage/framework/testing/layout-decompile-sync';

    /**
     * 굽기 계약을 시험하기에 충분한 최소 소스 — 컴파일이 결정론적이어야 하므로 단순하게 둔다.
     */
    private const MARKUP = <<<'HTML'
    <layout name="decompile_sync_probe" version="1.0.0">
      <div class="p-4">
        <h2>굽기 표본</h2>
      </div>
    </layout>
    HTML;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(base_path(self::SCRATCH));
    }

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path(self::SCRATCH));

        parent::tearDown();
    }

    /**
     * 짝이 없으면 `--sync` 가 생성한다 (마크업 전용 신규 확장).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_sync_creates_missing_json_twin
     *
     * @return void 반환값 없음
     */
    public function test_sync_creates_the_missing_json_twin(): void
    {
        $markup = $this->writeMarkup('create_probe');
        $json = LayoutJsonWriter::jsonSiblingOf($markup);

        $this->assertFileDoesNotExist($json, '전제: 짝이 이미 있으면 이 테스트는 생성을 관측하지 못한다');

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])
            ->assertExitCode(0)
            ->run();

        $this->assertFileExists($json, '--sync 가 짝 .json 을 생성하지 않았다');

        $decoded = json_decode(File::get($json), true);

        $this->assertIsArray($decoded, '생성된 짝이 JSON 으로 파싱되지 않는다');
        $this->assertSame('decompile_sync_probe', $decoded['layout_name'] ?? null,
            '생성된 짝의 내용이 컴파일 산출이 아니다');
    }

    /**
     * 낡은 짝은 `--sync` 가 갱신한다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_sync_rewrites_stale_json_twin
     *
     * @return void 반환값 없음
     */
    public function test_sync_rewrites_a_stale_twin(): void
    {
        $markup = $this->writeMarkup('stale_probe');
        $json = LayoutJsonWriter::jsonSiblingOf($markup);

        File::put($json, "{\n    \"layout_name\": \"낡은 손유지 값\"\n}\n");

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])
            ->assertExitCode(0)
            ->run();

        $decoded = json_decode(File::get($json), true);

        $this->assertSame('decompile_sync_probe', $decoded['layout_name'] ?? null,
            '낡은 짝이 컴파일 산출로 갱신되지 않았다');
    }

    /**
     * 이미 최신인 짝은 다시 쓰지 않는다 (mtime 보존 — 조각 컴파일 캐시 키가 mtime 을 쓴다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_sync_does_not_rewrite_an_up_to_date_twin
     *
     * @return void 반환값 없음
     */
    public function test_sync_leaves_an_up_to_date_twin_untouched(): void
    {
        $markup = $this->writeMarkup('idempotent_probe');
        $json = LayoutJsonWriter::jsonSiblingOf($markup);

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])->run();

        $before = File::get($json);
        touch($json, time() - 120);
        $mtime = filemtime($json);

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])
            ->expectsOutputToContain('무변경 1개')
            ->assertExitCode(0)
            ->run();

        clearstatcache(true, $json);

        $this->assertSame($before, File::get($json), '무변경인데 내용이 바뀌었다');
        $this->assertSame($mtime, filemtime($json),
            '내용이 같은데 다시 썼다 — mtime 이 바뀌면 조각 컴파일 캐시(경로｜mtime｜크기)가 통째로 무효화된다');
    }

    /**
     * `--check` 는 최신 짝에서 초록이다.
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_check_passes_when_twin_is_fresh
     *
     * @return void 반환값 없음
     */
    public function test_check_passes_when_the_twin_is_fresh(): void
    {
        $markup = $this->writeMarkup('fresh_probe');

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])->run();

        $this->artisan('layout:decompile', ['path' => $markup, '--check' => true])
            ->assertExitCode(0)
            ->run();
    }

    /**
     * `--check` 는 불일치를 **쓰지 않고** non-zero + 파일 목록으로 알린다 (`prettier --check` 패턴).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_check_reports_stale_twins_without_writing
     *
     * @return void 반환값 없음
     */
    public function test_check_reports_stale_twins_and_does_not_write(): void
    {
        $markup = $this->writeMarkup('check_probe');
        $json = LayoutJsonWriter::jsonSiblingOf($markup);

        $stale = "{\n    \"layout_name\": \"낡은 손유지 값\"\n}\n";
        File::put($json, $stale);

        $this->artisan('layout:decompile', ['path' => $markup, '--check' => true])
            ->expectsOutputToContain('check_probe.json')
            ->assertExitCode(1)
            ->run();

        $this->assertSame($stale, File::get($json), '--check 가 파일을 수정했다 — 대조만 해야 한다');
    }

    /**
     * `--check` 는 짝이 아예 없을 때도 불일치로 다룬다 (부재를 통과로 세면 굽기 누락이 조용히 지난다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_check_treats_a_missing_twin_as_stale
     *
     * @return void 반환값 없음
     */
    public function test_check_treats_a_missing_twin_as_stale(): void
    {
        $markup = $this->writeMarkup('missing_probe');

        $this->assertFileDoesNotExist(LayoutJsonWriter::jsonSiblingOf($markup));

        $this->artisan('layout:decompile', ['path' => $markup, '--check' => true])
            ->assertExitCode(1)
            ->run();
    }

    /**
     * `--sync` 와 `--check` 는 배타다 (함께 주면 "쓰지 않는다" 는 계약이 깨진다).
     *
     * @scenario compile_unit=decompile,source_health=invalid
     *
     * @effects decompile_sync_and_check_are_mutually_exclusive
     *
     * @return void 반환값 없음
     */
    public function test_sync_and_check_cannot_be_combined(): void
    {
        $markup = $this->writeMarkup('exclusive_probe');

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true, '--check' => true])
            ->assertExitCode(1)
            ->run();

        $this->assertFileDoesNotExist(LayoutJsonWriter::jsonSiblingOf($markup),
            '배타 검사보다 굽기가 먼저 일어났다 — 검사는 handle() 최상단이어야 한다');
    }

    /**
     * 굽기 산출은 LF 고정이다 (㊼ N4 — CRLF 가 섞이면 인덱스 생성기가 제목을 basename 으로 퇴화시킨다).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_sync_output_is_line_feed_only_with_one_trailing_newline
     *
     * @return void 반환값 없음
     */
    public function test_sync_output_uses_line_feed_only(): void
    {
        // CRLF 로 작성된 소스에서도 산출이 LF 여야 한다 — 토크나이저가 CRLF 를 정규화하므로
        // 성립하지만, 그 성질에 기대지 않고 여기서 관측한다.
        $markup = $this->writeMarkup('crlf_probe', str_replace("\n", "\r\n", self::MARKUP));
        $json = LayoutJsonWriter::jsonSiblingOf($markup);

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])->run();

        $contents = File::get($json);

        $this->assertStringNotContainsString("\r", $contents, '굽기 산출에 CR 이 섞였다');
        $this->assertTrue(LayoutJsonWriter::assertLineFeedOnly($contents));
        $this->assertStringEndsWith("\n", $contents, '끝 개행이 없다');
        $this->assertStringEndsNotWith("\n\n", $contents, '끝 개행이 둘 이상이다');
    }

    /**
     * `--out` 덤프와 짝 굽기가 **같은 직렬화 관례**를 쓴다 (값을 두 곳에 적지 않는다 — 34차 규율).
     *
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects decompile_out_dump_shares_the_bake_serialization
     *
     * @return void 반환값 없음
     */
    public function test_out_dump_and_sync_produce_identical_bytes(): void
    {
        $markup = $this->writeMarkup('out_probe');
        $dump = base_path(self::SCRATCH.'/out_dump.json');

        $this->artisan('layout:decompile', ['path' => $markup, '--sync' => true])->run();
        $this->artisan('layout:decompile', ['path' => $markup, '--out' => $dump])->run();

        $this->assertSame(
            File::get(LayoutJsonWriter::jsonSiblingOf($markup)),
            File::get($dump),
            '--out 덤프와 짝 굽기의 인코딩이 갈렸다 — 관례가 두 벌이면 한쪽이 낡는다',
        );
    }

    /**
     * 설치·서빙 경로는 굽지 않는다 (D38) — 코어 전량에서 도출한 여집합 명제.
     *
     * 설치가 소스를 **인메모리로** 컴파일해 DB 에 등록하고 디스크에 쓰지 않는다는 것이 S10
     * 재아키텍처의 결정이다. 굽기가 설치에 끼어들면 프로덕션 배포가 저장소 파일을 수정한다.
     *
     * 모집단은 `app/` 전량이고, 면제도 신호도 **손으로 열거하지 않는다** — 둘 다 리플렉션으로
     * 도출한다. 열거의 여집합이 곧 사각이 되는 것이 이 트랙이 여러 회차에 걸쳐 확인한 형태이고,
     * 낡은 면제 경로 하나면 이 검사는 조용히 초록이 된다.
     *
     *  - 면제 = 굽기 커맨드 자신과 직렬화기 자신의 **실제 파일 경로**(`ReflectionClass::getFileName`).
     *  - 굽기 신호 = 직렬화기 **클래스 이름**. 짝을 쓰는 코어 표면은 그 클래스 하나뿐이므로
     *    이름만 바뀌어도 신호가 자동으로 따라온다.
     *  - 호출 신호 = 커맨드의 **등록된 이름**(`getName()`)을 Artisan 프로그램 호출 형태로 찾는다.
     *    **문자열로 이름을 언급하는 것은 호출이 아니다** — 작성 안내 문구에 커맨드를 적어 주는
     *    (지금은 삭제된) `layout:add-permissions` 가 정확히 그 형태였고, 단순 부분문자열 판정이면 그 안내가
     *    위반으로 잡혀 저자는 안내를 지우게 된다(19차 "설명을 지우게 만드는 가드" 형태).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects install_and_serving_paths_never_bake_json_twins
     *
     * @return void 반환값 없음
     */
    public function test_install_and_serving_paths_do_not_bake_json(): void
    {
        $bakeApi = class_basename(LayoutJsonWriter::class);
        $commandName = (string) (new LayoutDecompileCommand)->getName();

        $this->assertNotSame('', $commandName, '굽기 커맨드 이름 도출이 죽었다 — 호출 신호가 사라진다');

        $owners = [];

        foreach ([LayoutJsonWriter::class, LayoutDecompileCommand::class] as $owner) {
            $owners[] = $this->relative((string) (new \ReflectionClass($owner))->getFileName());
        }

        sort($owners);

        $this->assertCount(2, array_unique($owners), '굽기 소유자 도출이 겹쳤다');

        $scanned = 0;
        $referencing = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            $code = $this->codeWithoutComments(File::get($file->getPathname()));
            $invokes = preg_match('/(?:Artisan::(?:call|queue)|->(?:call|callSilent))\s*\(\s*[\'"]'.preg_quote($commandName, '/').'/', $code) === 1;

            if (str_contains($code, $bakeApi) || $invokes) {
                $referencing[] = $this->relative($file->getPathname());
            }
        }

        sort($referencing);

        $this->assertGreaterThan(0, $scanned,
            '코어 스캔이 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다 ("위반 0" 과 "검사 0" 은 다른 명제다)');

        // 면제로 건너뛰지 않고 **두 도출 집합의 상등**으로 판정한다. 파일 단위 면제는 그 파일의
        // 미래 위반까지 덮으므로(35차 근본 조치 J 의 C2), 여기서는 "굽기 표면을 참조하는 파일" 과
        // "굽기 소유자" 가 같다는 등식을 세운다 — 새 소비처가 생기면 좌변이 커져 red 이고, 소유자
        // 파일이 굽기를 그만두면 우변이 남아 red 다. 어느 방향이든 침묵하지 않는다.
        $this->assertSame($owners, $referencing,
            '굽기는 개발자 커맨드 전용이다 (D38) — 설치·서빙 경로가 짝 .json 을 쓰면 프로덕션 배포가 '
            .'저장소 파일을 수정하게 된다. 새 굽기 소비처가 정당하다면 이 테스트의 소유자 도출에 '
            .'그 클래스를 더해야 한다.');
    }

    /**
     * 위 여집합 명제의 판정식이 살아 있음을 합성 표본으로 시험합니다.
     *
     * 실물이 전건 준수인 검사는 "위반을 잡는다" 를 스스로 증명하지 못한다 — 신호 도출이 죽어도
     * 초록이기 때문이다. 판정에 쓰는 두 형태를 그대로 태워 확인한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects bake_boundary_predicate_detects_both_violation_forms
     *
     * @return void 반환값 없음
     */
    public function test_bake_boundary_predicate_detects_both_forms(): void
    {
        $bakeApi = class_basename(LayoutJsonWriter::class);
        $commandName = (string) (new LayoutDecompileCommand)->getName();
        $invocation = '/(?:Artisan::(?:call|queue)|->(?:call|callSilent))\s*\(\s*[\'"]'.preg_quote($commandName, '/').'/';

        $this->assertStringContainsString($bakeApi, 'LayoutJsonWriter::write($path, $json);',
            '굽기 API 신호가 직접 호출 형태를 잡지 못한다');

        $this->assertSame(1, preg_match($invocation, "Artisan::call('{$commandName}', ['--sync' => true]);"),
            'Artisan 프로그램 호출을 잡지 못한다');
        $this->assertSame(1, preg_match($invocation, "\$this->call('{$commandName}');"),
            '커맨드 내부 호출(->call)을 잡지 못한다');

        // 안내 문구에 이름을 적는 것은 호출이 아니다 (과탐하는 가드는 무시당한다).
        $this->assertSame(0, preg_match($invocation, "\$this->warn('짝을 다시 구우세요: php artisan {$commandName} --all --sync');"),
            '안내 문구의 커맨드 이름을 호출로 잡습니다 — 그러면 저자가 안내를 지우게 된다');
    }

    /**
     * 합성 소스를 스크래치 디렉토리에 씁니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @param  string|null  $markup  소스 (기본: {@see MARKUP})
     * @return string `.g7.html` 절대 경로
     */
    private function writeMarkup(string $name, ?string $markup = null): string
    {
        $path = base_path(self::SCRATCH."/{$name}.g7.html");

        File::put($path, $markup ?? self::MARKUP);

        return $path;
    }

    /**
     * 절대 경로를 저장소 상대 경로로 바꿉니다 (슬래시 통일).
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }

    /**
     * PHP 소스에서 주석만 제거합니다 (문자열 리터럴 보존 — 19차 정규식 사고 회피).
     *
     * 규약을 **설명하는** 독블록이 위반으로 잡히면 저자는 설명을 지우게 되고, 그러면 다음 사람이
     * 왜 이렇게 쓰는지 모른 채 사본을 되살린다.
     *
     * @param  string  $source  PHP 소스
     * @return string 주석이 제거된 소스
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
