<?php

namespace App\Extension\Layout;

use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트 백업 디렉토리를 다시 컴파일해 "이전 판 원본" 을 만듭니다 — 3자 병합 기준(base)의 두 번째 출처.
 *
 * 원본 본문 컬럼(`original_content`)이 비어 있는 행(컬럼 도입 전에 설치된 행)은 업데이트 때 만들어지는 백업
 * (`storage/app/extension_backups/{type}/{id}_{Ymd_His}` — 교체 전 디렉토리 통째)을 컴파일한 결과로 기준을 얻는다.
 * 동기화기는 그 결과의 해시가 행의 `original_content_hash` 와 같을 때만 기준으로 쓴다(컴파일러가 바뀌어 다른 판이
 * 나오면 기준으로 삼지 않는다).
 *
 * 백업 경로에서는 경로로 대상 템플릿·소유 확장을 뽑을 수 없으므로(`{id}_{타임스탬프}`) 컴파일 문맥을 강제 지정한다.
 * 읽지 못한 레이아웃 파일은 그것만 빼고(로그) 나머지를 기준으로 돌려준다.
 */
final class BackupLayoutReader
{
    use CollectsFragmentSources;
    use ValidatesLayoutFiles;

    /**
     * 백업의 레이아웃을 컴파일합니다.
     *
     * @param  string  $backupPath  백업 디렉토리
     * @param  string  $type  확장 유형 (`templates`·`modules`·`plugins`)
     * @param  string  $identifier  확장 식별자
     * @return array<string, array<string, mixed>> DB 레이아웃 이름 => 원본(조각 출처 표식 포함). 읽지 못한 파일은 빠지고, 전체 실패면 빈 배열
     */
    public function layouts(string $backupPath, string $type, string $identifier): array
    {
        $layoutsPath = $type === 'templates'
            ? $backupPath.DIRECTORY_SEPARATOR.'layouts'
            : $backupPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'layouts';

        if (! File::isDirectory($layoutsPath)) {
            return [];
        }

        $this->compileContextOverride = ['template' => $type === 'templates' ? $identifier : null, 'extension' => $identifier];
        // 읽지 못한 파일만 빼고 나머지는 기준으로 쓴다 — 하나 때문에 전체를 버리면 그 확장의 옛 행이 모두 기준 없이 남아
        // keep 업데이트가 새 판을 하나도 반영하지 못한다.
        $this->skipInvalidLayouts = true;
        $this->skippedLayoutErrors = [];

        try {
            $validated = $this->validateLayoutFiles($layoutsPath, $identifier, rtrim($type, 's'), true);

            if ($this->skippedLayoutErrors !== []) {
                Log::warning('업데이트 백업 레이아웃 일부를 읽지 못했습니다 — 그 화면만 비교 기준 없이 진행합니다', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'errors' => $this->skippedLayoutErrors,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('업데이트 백업 레이아웃 컴파일 실패 — 비교 기준 없이 진행합니다', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            $this->compileContextOverride = null;
            $this->skipInvalidLayouts = false;
        }

        $originals = [];
        foreach ($validated as $layout) {
            $name = $type === 'templates' ? $layout['layout_name'] : $identifier.'.'.$layout['layout_name'];
            $originals[$name] = $layout['original'];
        }

        return $originals;
    }

    /**
     * 백업의 확장 조각을 특정 템플릿 표로 컴파일합니다.
     *
     * @param  string  $backupPath  백업 디렉토리
     * @param  string  $identifier  확장 식별자
     * @param  string  $templateIdentifier  조각이 붙을 템플릿 식별자
     * @return array<string, array<string, mixed>> 조각 식별자 => 원본. 실패한 조각은 빠진다
     */
    public function fragments(string $backupPath, string $identifier, string $templateIdentifier): array
    {
        $extensionsPath = $backupPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'extensions';

        if (! File::isDirectory($extensionsPath)) {
            return [];
        }

        $this->compileContextOverride = ['template' => null, 'extension' => $identifier];

        try {
            $definitions = $this->loadFragmentDefinitions($extensionsPath, [], ['source' => $identifier, 'backup' => true], $templateIdentifier);
        } finally {
            $this->compileContextOverride = null;
        }

        $originals = [];
        foreach ($definitions as $definition) {
            $originals[$definition['key']] = $definition['data'];
        }

        return $originals;
    }
}
