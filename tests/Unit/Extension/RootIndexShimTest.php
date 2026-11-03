<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\RootIndexShim;
use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * 루트 `index.php` shim 판별의 SSoT 계약 (14차 G3 라운드).
 *
 * 판별 기준이 두 곳에 **다른 엄격도**로 적혀 있었다 — 런타임
 * (`CoreUpdateService::recordRootIndexReplacement`)은 공백까지 고정한 리터럴을
 * `str_contains` 로 찾았고, 계약 테스트(`RootEntrypointContractTest`)는 공백을 허용하는
 * 정규식으로 봤다. 그래서 PSR-12 스타일로 띄어 쓴 판본은 게이트를 통과하면서 런타임에서는
 * 「코어 shim 이 아니다」로 읽힌다.
 *
 * 그 상태의 증상은 조용하다: 매 코어 업데이트마다 거짓 경고가 뜨고
 * `storage/app/core-update/` 에 쓸모없는 사본이 쌓이는데, 게이트는 언제나 초록이다.
 *
 * @scenario shim_detection=literal
 *          / ssot runtime_uses_helper
 */
class RootIndexShimTest extends TestCase
{
    /**
     * 표기가 달라도 코어 shim 으로 판정한다.
     *
     * 이 형태들은 전부 「`public/index.php` 로 위임하는 한 줄」이며 운영자가 되찾을 내용이
     * 없다. 하나라도 놓치면 그 표기의 사이트에서 거짓 경고와 사본이 쌓인다.
     *
     * @effects root_index_shim_detection_has_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_코어_shim_은_표기가_달라도_판별된다(): void
    {
        $variants = [
            '릴리즈 표기' => "<?php\n\nrequire __DIR__.'/public/index.php';\n",
            'PSR-12 공백' => "<?php\n\nrequire __DIR__ . '/public/index.php';\n",
            '겹따옴표' => "<?php require __DIR__ . \"/public/index.php\";\n",
            'require_once' => "<?php require_once __DIR__.'/public/index.php';\n",
            '괄호 표기' => "<?php require(__DIR__ . '/public/index.php');\n",
            // 대상 부재 가드가 붙은 릴리즈 판본 (G3 회차 15) — 가드의 `is_file` 은 위임이 아니다.
            '부재 가드 + 위임' => "<?php\nif (! is_file(__DIR__.'/public/index.php')) {\n    http_response_code(503);\n    exit;\n}\n\nrequire __DIR__.'/public/index.php';\n",
        ];

        foreach ($variants as $label => $contents) {
            $this->assertTrue(
                RootIndexShim::matches($contents),
                $label.' 판본이 코어 shim 으로 인식되지 않는다 — 그 사이트는 매 업데이트마다 '
                .'거짓 경고와 쓸모없는 사본을 받는다'
            );
        }
    }

    /**
     * 운영자 파일은 shim 이 아니다 — 이 축이 무너지면 사본 보존이 통째로 사라진다.
     *
     * @scenario shim_detection=literal, entry_file=root_index
     *
     * @effects root_index_shim_detection_has_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_운영자_파일은_shim_이_아니다(): void
    {
        $notShims = [
            '레이아웃 ③ 랜딩' => "<?php\nheader('Location: /shop');\nexit;\n",
            '다른 대상 위임' => "<?php require __DIR__.'/legacy/index.php';\n",
            '설명만 있는 파일' => "<?php\n// require __DIR__ 로 위임하는 파일이 아니다\necho 'hi';\n",
            '빈 파일' => '',
        ];

        foreach ($notShims as $label => $contents) {
            $this->assertFalse(
                RootIndexShim::matches($contents),
                $label.' 이 코어 shim 으로 오판된다 — 교체 직전 사본이 남지 않아 되찾을 수단이 사라진다'
            );
        }
    }

    /**
     * 저장소의 실제 루트 진입 파일은 이 판정을 통과한다.
     *
     * @scenario shim_detection=literal, entry_file=root_index
     *
     * @effects root_index_shim_detection_has_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_저장소의_루트_진입_파일은_shim_으로_판별된다(): void
    {
        $this->assertTrue(
            RootIndexShim::matches((string) file_get_contents(base_path('index.php'))),
            '배포되는 루트 shim 자신이 판정을 통과하지 못한다'
        );
    }

    /**
     * 런타임이 자기 사본을 갖지 않고 이 SSoT 를 쓴다.
     *
     * 사본이 남아 있으면 헬퍼를 쓰면서도 그 사본을 부르는 상태가 성립하고, 그것이 정확히
     * 이 결함의 형태였다.
     *
     * @effects root_index_shim_detection_has_one_owner
     *
     * @return void 반환값 없음
     */
    public function test_런타임_판정은_사본_대신_공유_지점을_쓴다(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/CoreUpdateService.php'));

        $this->assertStringContainsString(
            'RootIndexShim::matches(',
            $source,
            CoreUpdateService::class.' 가 shim 판정 SSoT 를 쓰지 않는다'
        );

        // 실행 코드에 리터럴 사본이 남아 있는지 본다 — 주석의 설명은 정상이다.
        $executable = implode("\n", array_filter(
            // 개행 분리에 `\R` 을 쓰지 않는다 — UTF-8 모드가 아니면 그 이스케이프가
            // **바이트 0x85**(NEL)도 개행으로 본다. 한국어 주석의 흔한 글자가 그 바이트로
            // 끝나(`종` = EC A2 85) 한 줄이 글자 중간에서 쪼개지고, 뒷조각은 주석 표식을
            // 잃어 아래 필터를 그대로 지나간다 — 「주석은 보지 않는다」는 이 판정의 전제가
            // 깨진다. 실제 줄 종결자만 본다.
            preg_split('~\r\n|\r|\n~', $source) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
        ));

        $this->assertStringNotContainsString(
            'str_contains($existing, "require __DIR__',
            $executable,
            '공백까지 고정한 리터럴 사본이 남아 있다 — 표기가 갈리면 계약 테스트와 다른 판정을 낸다'
        );
    }
}
