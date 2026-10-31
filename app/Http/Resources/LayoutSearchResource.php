<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 레이아웃 **검색 결과** 리소스.
 *
 * 한 행이 트리의 파일 노드 하나에 대응한다. `key` 는 트리 노드 키와 같은 표기
 * (`layout:<name>` · `ext:<id>`)를 써서, 화면이 결과를 트리에 얹을 때 별도 매핑 없이 붙는다.
 *
 * `lines` 의 좌표는 **편집기와 같은 1-base 줄·열**이다 — 검색 결과를 눌러 그 줄로 이동하는
 * 동작이 성립하려면 서버가 본 좌표계와 Monaco 가 쓰는 좌표계가 같아야 한다. 그래서 검색은
 * DB 의 JSON 이 아니라 역변환한 `.g7.html` 마크업을 훑는다.
 */
class LayoutSearchResource extends BaseApiResource
{
    /**
     * 검색 결과 행을 배열로 변환합니다.
     *
     * @param  Request  $request  요청 객체
     * @return array<string, mixed> 검색 결과 행
     */
    public function toArray(Request $request): array
    {
        $lines = $this->getValue('lines', []);

        return [
            'key' => (string) $this->getValue('key', ''),
            'kind' => (string) $this->getValue('kind', 'layout'),
            'name' => (string) $this->getValue('name', ''),

            // 파일명 자체가 검색어를 포함하는지 — 화면이 이름에 하이라이트를 칠지 정한다.
            'name_matched' => (bool) $this->getValue('name_matched', false),

            'match_count' => (int) $this->getValue('match_count', 0),

            'lines' => array_map(static fn (array $line): array => [
                'line' => (int) ($line['line'] ?? 0),
                'column' => (int) ($line['column'] ?? 0),
                'preview' => (string) ($line['preview'] ?? ''),
            ], is_array($lines) ? $lines : []),
        ];
    }
}
