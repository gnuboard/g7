<?php

namespace Tests\Unit\Support;

use App\Support\ProcessOutputEncoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 외부 프로세스 출력 인코딩 정규화 테스트.
 *
 * 배경 (gnuboard/g7#62, sir.kr 566085):
 *   한국어 Windows 에서 `whoami` 는 OEM 코드페이지(CP949) 로 출력한다.
 *   계정명에 한글이 포함되면 그 바이트가 invalid UTF-8 이라
 *   json_encode() 가 false 를 반환하고 `echo false` = 빈 본문(HTTP 200)이 나간다.
 *   예외도 로그도 남지 않아 운영자가 원인을 특정할 수 없다.
 *
 * 이 클래스는 그 출력을 "항상 유효한 UTF-8" 로 만드는 단일 출처다.
 * 인스톨러(프레임워크 없음)와 코어가 함께 쓴다.
 */
class ProcessOutputEncodingTest extends TestCase
{
    /**
     * 한국어 Windows 의 whoami 출력을 재현한 CP949 바이트.
     *
     * hex 를 손으로 적지 않는다 — 오타로 다른 글자가 되는 사고가 실제로 있었다.
     */
    private static function cp949(string $utf8): string
    {
        return (string) mb_convert_encoding($utf8, 'CP949', 'UTF-8');
    }

    /**
     * 유효 UTF-8 입력은 한 바이트도 변형되지 않아야 한다.
     *
     * @return array<string, array{string}>
     */
    public static function validUtf8Provider(): array
    {
        return [
            'empty' => [''],
            'ascii' => ['www-data'],
            'korean' => ['it-manager\\티아모라'],
            'emoji' => ['user 🎉 name'],
            'mixed' => ['서버-01 / nginx / 그룹'],
        ];
    }

    #[Test]
    #[DataProvider('validUtf8Provider')]
    public function valid_utf8_passes_through_unchanged(string $value): void
    {
        $this->assertSame(
            $value,
            ProcessOutputEncoding::normalize($value),
            '유효 UTF-8 입력은 정규화 대상이 아니므로 변형되면 안 된다.'
        );
    }

    #[Test]
    public function cp949_korean_account_name_is_restored_exactly(): void
    {
        $expected = 'it-manager\\티아모라';
        $raw = self::cp949($expected);

        // 사전 조건: 표본이 실제로 결함을 재현하는 바이트여야 의미가 있다.
        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'), '표본은 invalid UTF-8 이어야 한다.');
        $this->assertFalse(json_encode(['u' => $raw]), '표본은 json_encode 를 false 로 만들어야 한다.');

        $this->assertSame(
            $expected,
            ProcessOutputEncoding::normalize($raw),
            'CP949 한글 계정명은 원문 그대로 복원되어야 한다 (U+FFFD 훼손 금지).'
        );
    }

    #[Test]
    public function cp949_extended_hangul_is_restored(): void
    {
        // KS X 1001 밖의 확장 한글 — mb_detect_encoding 이 EUC-KR 이 아닌 CP949 로 답하는 영역
        $expected = '똠방각하';
        $raw = self::cp949($expected);

        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'), '표본은 invalid UTF-8 이어야 한다.');
        $this->assertSame($expected, ProcessOutputEncoding::normalize($raw));
    }

    /**
     * 복원 불가능한 손상 바이트 표본.
     *
     * AddLogUtf8ScrubTest::invalidUtf8Provider 와 같은 표본 — composer 진행바(\r 갱신)가
     * 임의 바이트 경계에서 잘릴 때 생기는 형태다. 여기서는 "정확한 복원" 이 아니라
     * "응답이 살아남는다" 만 요구한다.
     *
     * @return array<string, array{string}>
     */
    public static function unrecoverableProvider(): array
    {
        return [
            'truncated hangul lead bytes' => ["Downloading \xEC\x99 package"],
            'lone 0xFF byte' => ["progress: abc\xFFdef 100%"],
            'truncated 4-byte sequence' => ["emoji \xF0\x9F head"],
            'lone continuation byte' => ["tail\x80end"],
        ];
    }

    #[Test]
    #[DataProvider('unrecoverableProvider')]
    public function damaged_bytes_still_yield_encodable_utf8(string $raw): void
    {
        $normalized = ProcessOutputEncoding::normalize($raw);

        $this->assertTrue(
            mb_check_encoding($normalized, 'UTF-8'),
            '복원 불가능한 바이트라도 결과는 반드시 유효 UTF-8 이어야 한다.'
        );
        $this->assertNotFalse(
            json_encode(['v' => $normalized]),
            '정규화 결과는 json_encode 를 false 로 만들면 안 된다 (빈 본문 방지).'
        );
    }

    #[Test]
    public function normalize_deep_covers_nested_values_and_keys(): void
    {
        $cp949 = self::cp949('티아모라');

        $input = [
            'directories' => [
                'web_server_user' => $cp949,
                'nested' => [['owner' => $cp949], 42, true, null, 1.5],
            ],
            $cp949 => 'key-is-invalid-too',
        ];

        $out = ProcessOutputEncoding::normalizeDeep($input);

        $this->assertNotFalse(
            json_encode($out),
            '배열 트리 전체가 정규화되어야 json_encode 가 살아남는다.'
        );
        $this->assertSame('티아모라', $out['directories']['web_server_user']);
        $this->assertSame('티아모라', $out['directories']['nested'][0]['owner']);
        $this->assertArrayHasKey('티아모라', $out, '배열 키도 정규화 대상이다.');

        // 문자열 외 스칼라는 타입·값이 보존되어야 한다.
        $this->assertSame(42, $out['directories']['nested'][1]);
        $this->assertTrue($out['directories']['nested'][2]);
        $this->assertNull($out['directories']['nested'][3]);
        $this->assertSame(1.5, $out['directories']['nested'][4]);
    }

    #[Test]
    public function invalid_paths_reports_dotted_key_path(): void
    {
        $cp949 = self::cp949('티아모라');

        $this->assertSame(
            ['directories.web_server_user'],
            ProcessOutputEncoding::invalidPaths(['directories' => ['web_server_user' => $cp949]]),
            'encode 실패 시 운영자가 어느 필드가 원인인지 알 수 있어야 한다.'
        );

        $this->assertSame(
            [],
            ProcessOutputEncoding::invalidPaths(['directories' => ['web_server_user' => 'www-data']]),
            '정상 입력에서는 보고할 경로가 없어야 한다.'
        );
    }

    #[Test]
    public function invalid_paths_reports_scalar_root_and_invalid_keys(): void
    {
        $cp949 = self::cp949('티아모라');

        $this->assertSame([''], ProcessOutputEncoding::invalidPaths($cp949), '스칼라 루트도 보고 대상이다.');

        $paths = ProcessOutputEncoding::invalidPaths([$cp949 => 'ok']);
        $this->assertNotEmpty($paths, 'invalid 한 배열 키도 encode 를 실패시키므로 보고되어야 한다.');
    }

    /**
     * Windows OEM 코드페이지 폴백(2단계) 경로.
     *
     * 결과 문자열은 머신 로케일에 따라 달라지므로 값은 단언하지 않는다 —
     * "항상 유효 UTF-8 을 돌려준다" 는 계약만 검사한다.
     */
    #[Test]
    public function windows_oem_fallback_still_returns_valid_utf8(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows OEM 폴백 경로는 Windows 에서만 실행된다.');
        }

        foreach (["\x82\x60\x82\x61", "\x81\x40", "\xA1\xA1"] as $raw) {
            $normalized = ProcessOutputEncoding::normalize($raw);
            $this->assertTrue(
                mb_check_encoding($normalized, 'UTF-8'),
                'OEM 폴백을 타더라도 결과는 유효 UTF-8 이어야 한다.'
            );
        }
    }
}
