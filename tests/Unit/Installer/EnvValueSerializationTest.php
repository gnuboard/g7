<?php

namespace Tests\Unit\Installer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 .env 값 직렬화 테스트 (KVE-2026-2042).
 *
 * 회귀 배경: `.env` 는 줄 단위 형식이라 사용자 입력에 개행이 섞이면 그 뒤가 새로운
 * 환경변수 줄로 해석된다. 기존 escapeEnvValue() 는 개행을 조용히 지웠고 그마저도
 * 일부 값(호스트·DB명·앱 이름·업데이트 URL 등)에는 적용되지 않아, 설치 창이 열려 있는
 * 동안 임의 환경변수를 주입할 수 있었다.
 *
 * 정책: 직렬화기는 CR/LF/NUL 을 만나면 예외를 던진다(조용한 삭제 금지 — 삭제하면
 * 운영자가 자기가 입력한 값과 다른 값이 저장된 것을 알 수 없다). 값 전달 경로가
 * 하나이므로 새 필드가 추가돼도 같은 관문을 지난다.
 *
 * 정책 파일은 의존성이 없어야 한다 — 인스톨러 본 흐름과 워커 양쪽에서 단독으로
 * require 되므로, 이 테스트도 BASE_PATH·lang 스텁 없이 파일만 로드한다.
 */
class EnvValueSerializationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/env-value.php';
    }

    /**
     * 정상 값은 큰따옴표로 감싸 반환된다.
     */
    #[Test]
    public function it_quotes_a_normal_value(): void
    {
        $this->assertSame('"g7"', serializeEnvValue('g7'));
        $this->assertSame('"127.0.0.1"', serializeEnvValue('127.0.0.1'));
    }

    /**
     * 빈 값은 빈 따옴표로 반환된다 (기존 동작 보존).
     */
    #[Test]
    public function it_returns_empty_quotes_for_an_empty_value(): void
    {
        $this->assertSame('""', serializeEnvValue(''));
    }

    /**
     * 큰따옴표와 백슬래시는 이스케이프된다 (기존 동작 보존).
     */
    #[Test]
    public function it_escapes_quotes_and_backslashes(): void
    {
        $this->assertSame('"a\\\\b"', serializeEnvValue('a\\b'));
        $this->assertSame('"say \\"hi\\""', serializeEnvValue('say "hi"'));
    }

    /**
     * 줄바꿈·NUL 이 섞인 값은 예외로 거부된다 (조용한 삭제 금지).
     *
     * @param  string  $value  주입 시도 값
     * @param  string  $reason  거부 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('lineBreakInjectionProvider')]
    public function it_rejects_values_containing_line_breaks_or_nul(string $value, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        serializeEnvValue($value);
    }

    /**
     * 개행 주입 벡터.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lineBreakInjectionProvider(): array
    {
        return [
            'LF 로 새 변수 줄 주입' => ["g7\nAPP_DEBUG=true", 'LF'],
            'CR 로 새 변수 줄 주입' => ["g7\rAPP_DEBUG=true", 'CR'],
            'CRLF 로 새 변수 줄 주입' => ["g7\r\nAPP_DEBUG=true", 'CRLF'],
            'NUL 바이트' => ["g7\0APP_DEBUG=true", 'NUL'],
            '선두 개행' => ["\nAPP_DEBUG=true", 'leading LF'],
        ];
    }

    /**
     * 개행 검사는 값 전체를 본다 — 따옴표 안에 숨겨도 거부된다.
     */
    #[Test]
    public function it_rejects_line_breaks_even_inside_quotes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        serializeEnvValue("\"g7\"\nAPP_DEBUG=true");
    }

    /**
     * 하위호환 별칭 `escapeEnvValue` 의 두 정의가 모두 단일 관문에 위임한다.
     *
     * 종전 구현은 개행을 **조용히 지웠다**. 그 동작이 어느 한쪽에라도 남아 있으면 다음
     * 호출자가 그 이름을 불러 같은 결함을 다시 들여온다 — 실제로 과거 KVE 수정들이
     * "한쪽만 고쳐서" 재수정된 이력이 있다.
     *
     * 함수를 실행해 대조하지 않고 소스를 검사하는 이유: 두 정의는 BASE_PATH 상수를
     * 요구하는 파일에 있고, 그 상수를 여기서 박으면 인접 Installer 테스트의 안전 가드가
     * 걸려 그 테스트들이 조용히 skip 된다(초록인데 검사는 멈춘 상태가 된다).
     */
    #[Test]
    public function both_legacy_alias_definitions_delegate_to_the_single_gate(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        $definitions = [
            'includes/functions.php' => $projectRoot.'/public/install/includes/functions.php',
            'includes/installer-runtime.php' => $projectRoot.'/public/install/includes/installer-runtime.php',
        ];

        foreach ($definitions as $label => $path) {
            $source = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                '/function escapeEnvValue\(string \$value\): string\s*\{[^}]*return serializeEnvValue\(\$value\);/s',
                $source,
                "{$label} 의 escapeEnvValue 가 단일 관문에 위임하지 않는다"
            );

            $this->assertStringNotContainsString(
                'str_replace(["\r", "\n"], \'\', $value)',
                $source,
                "{$label} 에 개행을 조용히 지우는 경로가 남아 있다 — 그 이름을 부르면 결함이 재유입된다"
            );
        }
    }

    /**
     * 개행이 없는 값은 검사기가 통과시킨다.
     */
    #[Test]
    public function the_line_break_checker_accepts_clean_values(): void
    {
        $this->assertTrue(installer_env_value_is_single_line('https://github.com/gnuboard/g7'));
        $this->assertTrue(installer_env_value_is_single_line(''));
        $this->assertFalse(installer_env_value_is_single_line("a\nb"));
        $this->assertFalse(installer_env_value_is_single_line("a\rb"));
        $this->assertFalse(installer_env_value_is_single_line("a\0b"));
    }
}
