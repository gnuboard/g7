<?php

namespace Tests\Unit\Console;

use App\Console\Commands\ApiDocBackfillFieldsCommand;
use App\Support\ApiDoc\ResourceFieldDescriber;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ApiDocBackfillFieldsCommand 응답 필드 백필 로직 단위 테스트.
 *
 * 실제 문서 파일에 의존하지 않고, 응답 필드 표의 `<!-- TODO: 설명 -->` 셀만
 * 리소스 계약 사전 설명으로 치환되는지(파라미터 표·실측 예시값·타입은 불변) 검증한다.
 * 재실행 멱등성과 도메인 특이 필드 TODO 유지도 확인한다.
 */
class ApiDocBackfillFieldsCommandTest extends TestCase
{
    /**
     * 커맨드의 backfillFile private 메서드를 리플렉션으로 호출합니다.
     *
     * @param  string  $content  문서 내용
     * @return array{result: string, filled: int, skipped: int} 치환 결과와 카운트
     */
    private function backfill(string $content): array
    {
        $command = app(ApiDocBackfillFieldsCommand::class);
        $ref = new ReflectionMethod($command, 'backfillFile');
        $ref->setAccessible(true);

        $describer = new ResourceFieldDescriber;
        $filled = 0;
        $skipped = 0;
        $result = $ref->invokeArgs($command, [$content, $describer, &$filled, &$skipped]);

        return ['result' => $result, 'filled' => $filled, 'skipped' => $skipped];
    }

    /**
     * 응답 필드 표 뼈대를 만듭니다.
     *
     * @param  string  $rows  표 행들
     * @return string 마크다운
     */
    private function fieldTable(string $rows): string
    {
        return "**응답 필드** (`data` 내부)\n\n"
            ."| 필드 | 타입 | 실측 예시값 | 용도/설명 |\n"
            ."| --- | --- | --- | --- |\n"
            .$rows."\n";
    }

    #[Test]
    public function 응답_필드_표의_todo_를_계약_설명으로_채운다(): void
    {
        $content = $this->fieldTable(
            "| created_at | string | `2026-07-07` | <!-- TODO: 설명 --> |\n"
            .'| is_owner | boolean | `true` | <!-- TODO: 설명 --> |'
        );

        $out = $this->backfill($content);

        $this->assertStringContainsString('생성 일시', $out['result']);
        $this->assertStringContainsString('소유자', $out['result']);
        $this->assertStringNotContainsString('<!-- TODO: 설명 -->', $out['result']);
        $this->assertSame(2, $out['filled']);
        $this->assertSame(0, $out['skipped']);
    }

    #[Test]
    public function 실측_예시값과_타입은_불변이다(): void
    {
        $content = $this->fieldTable(
            '| created_at | string | `2026-07-07 05:13:51` | <!-- TODO: 설명 --> |'
        );

        $out = $this->backfill($content);

        // 실측 예시값 셀과 타입 셀이 그대로 유지되어야 한다
        $this->assertStringContainsString('| created_at | string | `2026-07-07 05:13:51` |', $out['result']);
    }

    #[Test]
    public function 파라미터_표의_todo_는_건드리지_않는다(): void
    {
        // 응답 표 헤더가 아니므로 진입하지 않아야 한다 (파라미터 TODO 마커도 다름)
        $content = "**요청 파라미터**\n\n"
            ."| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |\n"
            ."| --- | --- | --- | --- | --- | --- |\n"
            ."| page | query | integer | 아니오 | — | <!-- TODO: 용도 --> |\n";

        $out = $this->backfill($content);

        $this->assertSame($content, $out['result']);
        $this->assertStringContainsString('<!-- TODO: 용도 -->', $out['result']);
        $this->assertSame(0, $out['filled']);
    }

    #[Test]
    public function 도메인_특이_필드는_todo_를_유지한다(): void
    {
        $content = $this->fieldTable(
            "| purpose | string | `login` | <!-- TODO: 설명 --> |\n"
            .'| channels | array | `[]` | <!-- TODO: 설명 --> |'
        );

        $out = $this->backfill($content);

        // ResourceFieldDescriber 가 null 반환 → TODO 유지
        $this->assertSame(2, substr_count($out['result'], '<!-- TODO: 설명 -->'));
        $this->assertSame(0, $out['filled']);
        $this->assertSame(2, $out['skipped']);
    }

    #[Test]
    public function 두번_실행해도_결과가_동일하다_멱등(): void
    {
        $content = $this->fieldTable(
            "| created_at | string | `2026-07-07` | <!-- TODO: 설명 --> |\n"
            .'| purpose | string | `login` | <!-- TODO: 설명 --> |'
        );

        $first = $this->backfill($content)['result'];
        $second = $this->backfill($first)['result'];

        $this->assertSame($first, $second);
        // 이미 채워진 셀은 다시 채우지 않음 (도메인 특이만 skipped 로 카운트)
        $this->assertSame(0, $this->backfill($first)['filled']);
    }

    #[Test]
    public function 표_밖의_todo_는_무시한다(): void
    {
        // 응답 필드 표가 끝난 뒤(사람 서술)의 동일 마커는 치환 대상이 아니다
        $content = $this->fieldTable(
            '| created_at | string | `2026-07-07` | <!-- TODO: 설명 --> |'
        )."\n본문 설명 <!-- TODO: 설명 --> 은 유지되어야 한다\n";

        $out = $this->backfill($content);

        $this->assertStringContainsString('본문 설명 <!-- TODO: 설명 --> 은 유지', $out['result']);
        $this->assertSame(1, $out['filled']);
    }
}
