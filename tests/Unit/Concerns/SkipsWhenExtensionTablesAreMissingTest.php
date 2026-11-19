<?php

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\SkipsWhenExtensionTablesAreMissing;

/**
 * 건너뛰기 안전장치 자신의 계약.
 *
 * 이 트레이트가 잘못 판정하면 코어 실행 모드 축의 클래스들이 **통째로 건너뛰어지고
 * 종료 코드는 0** 이다 — 회귀가 있어도 초록으로 보인다. 그런데 트레이트 자신에게는
 * 어떤 검사도 없었다(발견). 가장 깨지기 쉬운 부분인 `.env` 파싱을 여기서 고정한다.
 *
 * 접속 판정 자체는 실 DB 에 묶여 있어 단위로 재현할 수 없다 — 그 축은 실행 결과의
 * `skip 0` 수치가 계속 근거다.
 *
 * 커버 축: env_parse plain·quoted·value_with_equals·comment·inline_comment·quoted_hash·blank·no_separator
 */
class SkipsWhenExtensionTablesAreMissingTest extends TestCase
{
    /**
     * 트레이트의 보호 멤버에 닿기 위한 노출용 클래스.
     *
     * @return object 파싱을 공개로 여는 익명 클래스
     */
    private function subject(): object
    {
        return new class
        {
            use SkipsWhenExtensionTablesAreMissing;

            /**
             * 트레이트의 .env 줄 해석기를 그대로 부른다.
             *
             * @param  array<int, string>  $lines  파일 줄 목록
             * @return array<string, string> 키-값
             */
            public function parse(array $lines): array
            {
                return self::parseEnvLines($lines);
            }

            /**
             * 트레이트가 부팅 전에 요구하는 확장 테이블 목록을 드러낸다.
             *
             * @return array<int, string> 부팅이 요구하는 확장 테이블
             */
            public function requiredTables(): array
            {
                return $this->requiredExtensionTables;
            }
        };
    }

    /**
     * 따옴표 없는 값, 큰따옴표 값, 작은따옴표 값이 모두 따옴표를 벗긴 원문으로 읽히는지 확인한다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_parses_plain_and_quoted_values(): void
    {
        $config = $this->subject()->parse([
            'DB_WRITE_DATABASE=g7_testing',
            'DB_PREFIX="g7_"',
            "DB_WRITE_USERNAME='root'",
        ]);

        $this->assertSame('g7_testing', $config['DB_WRITE_DATABASE']);
        $this->assertSame('g7_', $config['DB_PREFIX']);
        $this->assertSame('root', $config['DB_WRITE_USERNAME']);
    }

    /**
     * 값 안의 `=` 를 잘라 먹지 않는다.
     *
     * 비밀번호에 `=` 가 들어가는 것은 흔하다 — 첫 `=` 뒤 전부가 값이다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_keeps_equals_signs_inside_values(): void
    {
        $config = $this->subject()->parse(['DB_WRITE_PASSWORD=a=b=c']);

        $this->assertSame('a=b=c', $config['DB_WRITE_PASSWORD']);
    }

    /**
     * ini 문법이 아니어도 파싱이 통째로 실패하지 않는다.
     *
     * `parse_ini_file` 을 쓰지 않는 이유가 이것이다 — 괄호가 든 값 하나에 전체 파싱이
     * 실패하면 판정 불가로 떨어져 가드가 조용히 통과하고, 그 다음이 부팅 실패다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_survives_values_that_would_break_ini_parsing(): void
    {
        $config = $this->subject()->parse([
            'APP_NAME=G7 (Beta)',
            'DB_WRITE_DATABASE=g7_testing',
        ]);

        $this->assertSame('G7 (Beta)', $config['APP_NAME']);
        $this->assertSame('g7_testing', $config['DB_WRITE_DATABASE'], '앞 줄이 이상해도 뒤 줄이 살아야 한다');
    }

    /**
     * 인라인 주석은 값이 아니다.
     *
     * `DB_PREFIX=g7_ # 접두사` 를 값으로 읽으면 표 이름이 `g7_ # 접두사…` 가 되어 트레이트가
     * 「표가 없다」로 판정하고 그 축의 클래스를 통째로 skip 한다 — 종료 코드는 0 이라 초록으로
     * 보인다. 커버 축 선언에 `comment` 가 있었지만 픽스처는 **줄 전체 주석**만 태우고 있었다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_inline_comment_is_not_part_of_the_value(): void
    {
        $config = $this->subject()->parse([
            'DB_PREFIX=g7_ # 접두사',
            'DB_WRITE_DATABASE=g7_testing   # 테스트 DB',
        ]);

        $this->assertSame('g7_', $config['DB_PREFIX']);
        $this->assertSame('g7_testing', $config['DB_WRITE_DATABASE']);
    }

    /**
     * 따옴표로 감싼 값 안의 `#` 는 값의 일부다 — 비밀번호에 흔하다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_hash_inside_a_quoted_value_is_kept(): void
    {
        $config = $this->subject()->parse([
            'DB_WRITE_PASSWORD="p#ss w0rd"',
        ]);

        $this->assertSame('p#ss w0rd', $config['DB_WRITE_PASSWORD']);
    }

    /**
     * 주석 줄, 빈 줄, 구분자가 없는 줄, 키가 빈 줄은 건너뛰고 올바른 키 값 쌍만 남기는지 확인한다.
     *
     * @effects skip_guard_parses_env_without_ini_semantics
     *
     * @return void 반환값 없음
     */
    public function test_skips_comments_blanks_and_lines_without_a_separator(): void
    {
        $config = $this->subject()->parse([
            '# 주석',
            '',
            'NOT_A_PAIR',
            '=값만',
            'DB_PREFIX=g7_',
        ]);

        $this->assertSame(['DB_PREFIX' => 'g7_'], $config);
    }

    /**
     * 부팅이 읽는 표 목록을 고정한다.
     *
     * 이 목록이 비면 가드가 **한 번도 건너뛰지 않게** 되어(fail-open) 확장 표가 비워진 직후의
     * 부팅 실패가 다시 진짜 회귀처럼 보인다. 늘어나면 반대로 과잉 건너뜀이 된다.
     *
     * @effects skip_guard_required_tables_are_fixed
     *
     * @return void 반환값 없음
     */
    public function test_required_extension_tables_are_fixed(): void
    {
        $this->assertSame(['modules', 'plugins'], $this->subject()->requiredTables());
    }

    /**
     * 트레이트를 쓰는 클래스가 **`parent::setUp()` 보다 먼저** 부르는가.
     *
     * 트레이트 자신의 계약은 8차 #22 가 잠갔지만, 그것을 **누가 어떻게 부르는가**는 아무도
     * 보지 않았다. 배선 한 줄이 빠지거나 순서가 뒤집히면 정상 DB 에서는 초록이라 드러나지
     * 않고, 확장 표가 비워진 직후에만 「Table 'g7_modules' doesn't exist」가 진짜 회귀와 섞여 나타난다.
     * 판정은 앱 부팅 **전**에 해야 한다 — 부팅이 바로 그 실패 지점이기 때문이다.
     *
     * @effects skip_guard_runs_before_the_app_boots
     *
     * @return void 반환값 없음
     */
    public function test_consumers_call_the_guard_before_booting_the_app(): void
    {
        $root = dirname(__DIR__, 2);
        $consumers = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            // 스캐너 자신은 모집단이 아니다 — 검사 대상 문자열을 본문에 들고 있을 뿐이다
            // (형제 InstallerTestIsolationCoverageTest 와 같은 규칙).
            if ($file->getPathname() === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'use SkipsWhenExtensionTablesAreMissing;')) {
                continue;
            }

            $consumers[] = $file->getFilename();

            $guardAt = strpos($source, '$this->skipWhenExtensionTablesAreMissing()');
            $parentAt = strpos($source, 'parent::setUp()');

            $this->assertNotFalse($guardAt, $file->getFilename().' 이 트레이트를 쓰면서 가드를 부르지 않는다');
            $this->assertNotFalse($parentAt, $file->getFilename().' 에 setUp 이 없다');
            $this->assertLessThan(
                $parentAt,
                $guardAt,
                $file->getFilename().' 은 앱 부팅 전에 가드를 불러야 한다 — 부팅이 바로 그 실패 지점이다'
            );
        }

        $this->assertNotEmpty($consumers, '트레이트를 쓰는 클래스를 하나도 찾지 못했다 — 배선이 통째로 사라졌다');
    }
}
