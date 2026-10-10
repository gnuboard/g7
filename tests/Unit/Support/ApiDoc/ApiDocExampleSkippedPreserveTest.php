<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiDocScaffolder;
use Tests\TestCase;

/**
 * 실측 제외 엔드포인트의 손으로 쓴 예시 보존 테스트
 *
 * 2026-10-09 실측(O-R2-4): `api:docgen --examples-only` 를 CKEditor5 범위로 돌리자 실측이 제외된 엔드포인트
 * (관리자 목록 403, 이미지 404, 업로드 부수효과 제외)의 `@generated` 블록 안에 사람이 써 둔 요청·응답 예시가
 * 「실측 제외」 마커와 자동 요청 예시로 덮이거나 지워졌다. 실측이 제외된 엔드포인트는 기존 예시를 보존하고,
 * 실측된 엔드포인트와 기계가 넣은 예시는 종전처럼 갱신하는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/apidoc-examples-skipped-preserve.yaml
 */
class ApiDocExampleSkippedPreserveTest extends TestCase
{
    /** 실측 제외 마커 */
    private const SKIP_MARKER = '<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->';

    /**
     * 실측이 제외되면 손으로 쓴 요청·응답 예시를 그대로 둔다.
     *
     * @scenario probe=skipped,existing=hand_written
     *
     * @effects apidoc_hand_examples_kept_when_probe_skipped
     *
     * @return void 반환값 없음
     */
    public function test_skipped_probe_keeps_hand_written_examples(): void
    {
        $doc = $this->doc(
            "```http\nGET /api/x?referenced=unreferenced HTTP/1.1\n```",
            "```json\n{\n    \"success\": true,\n    \"data\": {\"hash\": \"a1b2c3d4e5f6\"}\n}\n```"
        );

        [$updated] = (new ApiDocScaffolder)->insertExampleBlocks($doc, [
            'api.x.index' => ['request' => "```http\nGET /api/x?referenced=예시값 HTTP/1.1\n```", 'response' => self::SKIP_MARKER, 'skipped' => true],
        ]);

        $this->assertStringContainsString('referenced=unreferenced', $updated, '손으로 쓴 요청 예시가 바뀌었다');
        $this->assertStringContainsString('a1b2c3d4e5f6', $updated, '손으로 쓴 응답 예시가 지워졌다');
        $this->assertStringNotContainsString('실측 제외', $updated);
    }

    /**
     * 실측이 제외되고 서술 영역에도 응답 예시가 있어도, 블록 안의 손으로 쓴 응답 예시를 지우지 않는다.
     *
     * @scenario probe=skipped,existing=hand_written_with_prose_example
     *
     * @effects apidoc_hand_examples_kept_when_probe_skipped
     *
     * @return void 반환값 없음
     */
    public function test_skipped_probe_keeps_hand_written_block_example_beside_prose_example(): void
    {
        $doc = $this->doc("```http\nGET /api/x HTTP/1.1\n```", "```http\nHTTP/1.1 200 OK\nContent-Type: image/jpeg\n```")
            ."\n**응답 예시** (실패 시에만 JSON)\n\n```json\n{\"success\": false}\n```\n";

        [$updated] = (new ApiDocScaffolder)->insertExampleBlocks($doc, [
            'api.x.index' => ['request' => "```http\nGET /api/x HTTP/1.1\n```", 'response' => self::SKIP_MARKER, 'skipped' => true],
        ]);

        $this->assertStringContainsString('Content-Type: image/jpeg', $updated, '블록 안의 손으로 쓴 응답 예시가 지워졌다');
    }

    /**
     * 실측된 엔드포인트는 종전처럼 실측 응답으로 예시를 갱신한다.
     *
     * @scenario probe=probed,existing=hand_written
     *
     * @effects apidoc_probed_examples_refreshed
     *
     * @return void 반환값 없음
     */
    public function test_probed_endpoint_refreshes_examples(): void
    {
        $doc = $this->doc("```http\nGET /api/x HTTP/1.1\n```", "```json\n{\"old\": true}\n```");

        [$updated] = (new ApiDocScaffolder)->insertExampleBlocks($doc, [
            'api.x.index' => ['request' => "```http\nGET /api/x?page=1 HTTP/1.1\n```", 'response' => "<!-- @probed -->\n\n```json\n{\"new\": true}\n```"],
        ]);

        $this->assertStringContainsString('"new": true', $updated);
        $this->assertStringNotContainsString('"old": true', $updated);
        $this->assertStringContainsString('page=1', $updated);
    }

    /**
     * 기계가 넣은 예시(실측 제외 마커·실측 표식)는 실측이 제외돼도 새 예시로 갱신되고, 서술 영역 예시와 겹치면 지운다.
     *
     * @scenario probe=skipped,existing=machine_made
     *
     * @effects apidoc_machine_examples_refreshed
     *
     * @return void 반환값 없음
     */
    public function test_machine_made_examples_are_refreshed_or_deduplicated(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $marker = $this->doc("```http\nGET /api/x HTTP/1.1\n```", '<!-- 실측 제외: not-probed — 응답 예시는 사람이 작성하세요. -->');
        [$refreshed] = $scaffolder->insertExampleBlocks($marker, [
            'api.x.index' => ['request' => "```http\nGET /api/x HTTP/1.1\n```", 'response' => self::SKIP_MARKER, 'skipped' => true],
        ]);
        $this->assertStringContainsString('http-403', $refreshed, '기계가 넣은 실측 제외 마커가 갱신되지 않았다');

        $probed = $this->doc("```http\nGET /api/x HTTP/1.1\n```", "<!-- @probed -->\n\n```json\n{\"old\": true}\n```")
            ."\n**응답 예시** (사람이 쓴 서술 예시)\n\n```json\n{}\n```\n";
        [$deduped] = $scaffolder->insertExampleBlocks($probed, [
            'api.x.index' => ['request' => "```http\nGET /api/x HTTP/1.1\n```", 'response' => self::SKIP_MARKER, 'skipped' => true],
        ]);
        $this->assertStringNotContainsString('"old": true', $deduped, '서술 영역과 겹치는 기계 예시가 남았다');
    }

    /**
     * 예시 블록이 든 엔드포인트 문서를 만듭니다.
     *
     * @param  string  $request  요청 예시 본문
     * @param  string  $response  응답 예시 본문
     * @return string 문서
     */
    private function doc(string $request, string $response): string
    {
        return <<<MD
### GET /api/x
<!-- @generated:start:api.x.index -->
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

{$request}

**응답 필드** (`data` 내부)

_단건._

**응답 예시**

{$response}

**에러 응답**

| 403 |

<!-- @generated:end -->

**설명** 사람이 쓴 설명.

MD;
    }
}
