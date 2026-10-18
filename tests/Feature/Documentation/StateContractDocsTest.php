<?php

namespace Tests\Feature\Documentation;

use App\Support\ExtensionDoc\ExtensionInventory;
use Tests\TestCase;

/**
 * 공개 상태 계약의 문서 도달 (#639, 결정 P8)
 *
 * manifest `state_contracts` 는 다른 확장이 기대도 되는 전역 상태라는 약속이다. 그 확장 문서에 이름이 없으면
 * 읽는 쪽 개발자가 계약을 알 길이 manifest 를 여는 것뿐이다. 모집단은 번들 manifest 스캔에서 파생한다.
 */
class StateContractDocsTest extends TestCase
{
    /**
     * 선언한 계약 이름이 모두 그 확장의 docs/*.md 에 적혀 있는지 확인합니다.
     *
     * @scenario case=documented
     *
     * @effects declared_contracts_reach_the_extension_docs
     *
     * @return void 반환값 없음
     */
    public function test_declared_state_contracts_reach_the_extension_docs(): void
    {
        $checked = [];

        foreach ((new ExtensionInventory)->collect('all') as $record) {
            $manifest = json_decode((string) file_get_contents($record['manifestPath']), true);
            $contracts = is_array($manifest['state_contracts'] ?? null) ? array_keys($manifest['state_contracts']) : [];
            if ($contracts === []) {
                continue;
            }

            $docs = '';
            foreach (glob($record['path'].DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
                $docs .= (string) file_get_contents($file)."\n";
            }

            foreach ($contracts as $name) {
                $this->assertStringContainsString(
                    '`'.$name.'`',
                    $docs,
                    "{$record['relPath']} — 공개 상태 계약 `{$name}` 이 docs/*.md 어디에도 없습니다.",
                );
                $checked[] = $record['relPath'].':'.$name;
            }
        }

        // 하한 — 선언한 확장이 하나도 없으면 위 루프가 돌지 않고도 통과한다.
        $this->assertNotEmpty($checked, 'state_contracts 를 선언한 번들 확장을 하나도 찾지 못했습니다 — 모집단 파생이 죽었습니다.');
    }
}
