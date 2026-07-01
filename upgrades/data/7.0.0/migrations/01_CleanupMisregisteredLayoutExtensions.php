<?php

namespace App\Upgrades\Data\V7_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 대상 레이아웃이 존재하지 않는 템플릿에 잘못 등록된 레이아웃 확장을 정리한다.
 *
 * 결함:
 *   beta.8 이전의 ModuleManager / PluginManager::refreshLayoutExtensions 는
 *   모듈/플러그인 확장을 모든 활성 템플릿(admin + user)에 무차별 등록했다.
 *   그 결과 admin 레이아웃을 대상으로 하는 overlay/extension_point 확장이
 *   user 템플릿에도(또는 그 반대로) `template_layout_extensions` 행으로 생성되어,
 *   레이아웃 편집 화면에 해당 템플릿과 무관한 확장이 노출된다.
 *
 *   beta.8 부터 RefreshesLayoutExtensions 가 대상 존재 여부를 사전 판별하므로
 *   앞으로의 등록은 차단되지만, 이미 운영 중인 환경의 DB 에 누적된 오등록 행은
 *   본 마이그레이션이 1회 정리한다.
 *
 * 정리 기준 (cross-template relative 신호):
 *   단순히 "이 템플릿에 대상이 없으면 삭제" 하지 않는다. 그 절대 신호는
 *   코어 업데이트 중 레이아웃 content 가 신버전으로 갱신되기 *전* 시점에 이
 *   마이그레이션이 돌면(코어 파일 교체 직후·번들 일괄 업데이트 전), 정상 확장도
 *   레이아웃이 아직 stale 이라 "대상 부재" 로 오판되어 전멸한다.
 *
 *   대신 동일 4키(source_type, source_identifier, extension_type, target_name)
 *   조합이 *다른* 템플릿에서는 적용 가능한지 본다:
 *   - overlay: target_name 이 그 템플릿의 레이아웃명으로 존재
 *   - extension_point: target_name(확장점명)이 그 템플릿의 어느 레이아웃 content 에
 *     `type: extension_point` 노드로 정의됨
 *
 *   판정:
 *   - 이 template_id 엔 부재 ∧ 동일 4키가 *다른* template_id 엔 적용 가능
 *       → 오등록 (타입 불일치로 잘못 등록된 상대적 부재) → soft delete
 *   - 어느 템플릿에서도 적용 가능한 행이 없음 (= 레이아웃 전반이 stale)
 *       → 전부 보존 (정상 확장 전멸 차단)
 *   - 일부 템플릿엔 적용 가능, 일부엔 부재
 *       → 부재인 일부만 삭제 (오등록 정확 제거)
 *
 * 멱등성:
 *   - 이미 soft delete 된 행은 재대상이 아님 (whereNull('deleted_at') 필터)
 *   - 두 번 실행해도 동일 결과 (오등록 행만 반복 검출 → 이미 삭제됨)
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md):
 *   - LayoutExtensionService / LayoutRepository 등 코어 클래스 호출 금지
 *     (업그레이드 스텝은 이전 버전 프로세스에서 실행될 수 있어 stale 클래스 위험)
 *   - 판정 로직을 본 클래스 안에 인라인 구현, Illuminate\Support\Facades\* 만 사용
 */
final class CleanupMisregisteredLayoutExtensions implements DataMigration
{
    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'CleanupMisregisteredLayoutExtensions';
    }

    /**
     * 오등록 레이아웃 확장 행을 정리한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거, 테이블 프리픽스 등)
     */
    public function run(UpgradeContext $context): void
    {
        // 테이블 프리픽스는 DB::table() 이 자동 적용하므로 평문 테이블명을 사용한다.
        // (UpgradeContext::table() 은 raw SQL 용 — 쿼리 빌더에 쓰면 프리픽스 이중 적용)
        if (! Schema::hasTable('template_layout_extensions') || ! Schema::hasTable('template_layouts')) {
            $context->logger->info('[7.0.0] 레이아웃 확장 정리 스킵 — 대상 테이블 부재');

            return;
        }

        $now = now();

        // 후보(이 템플릿엔 부재)를 먼저 전부 수집한 뒤 일괄 삭제한다.
        // 청크 도중 삭제하면 cross-template 조회(whereNull('deleted_at'))의
        // 기준 집합이 바뀌어 판정 일관성이 깨지므로, 수집과 삭제를 분리한다.
        $candidateIds = [];

        DB::table('template_layout_extensions')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($extensions) use (&$candidateIds) {
                foreach ($extensions as $extension) {
                    if (! $this->isExtensionApplicable($extension)) {
                        $candidateIds[] = $extension->id;
                    }
                }
            });

        $orphanIds = [];

        foreach ($candidateIds as $candidateId) {
            $candidate = DB::table('template_layout_extensions')->find($candidateId);

            // 수집 이후 외부에서 삭제되었을 수 있으니 재확인 (멱등성)
            if ($candidate === null || $candidate->deleted_at !== null) {
                continue;
            }

            // 동일 4키 조합이 *다른* 템플릿에서는 적용 가능한가 → 그렇다면 오등록.
            // 어디서도 적용 불가(레이아웃 전반 stale)면 보존하여 정상 확장 전멸을 막는다.
            if ($this->isApplicableInAnotherTemplate($candidate)) {
                $orphanIds[] = $candidate->id;
            }
        }

        if (! empty($orphanIds)) {
            // is_active=0 동반 — soft delete 행이 활성으로 남는 모순 상태 방지.
            DB::table('template_layout_extensions')
                ->whereIn('id', $orphanIds)
                ->update(['deleted_at' => $now, 'is_active' => 0]);
        }

        $context->logger->info(sprintf(
            '[7.0.0] 오등록 레이아웃 확장 정리 완료 — 후보 %d건 중 %d건 soft delete (cross-template 판정)',
            count($candidateIds),
            count($orphanIds)
        ));
    }

    /**
     * 동일 4키(source_type, source_identifier, extension_type, target_name) 조합이
     * 후보의 template_id 가 아닌 *다른* 템플릿에서는 적용 가능한지 확인한다.
     *
     * true → 이 확장은 다른 템플릿엔 정상 적용되는데 이 행만 부재 = 오등록.
     * false → 어느 템플릿에서도 적용 가능 행이 없음 = 레이아웃 전반 stale → 보존.
     *
     * @param  object  $candidate  부재로 판정된 후보 행
     * @return bool 다른 템플릿에서 적용 가능한 동일 확장이 존재하는지
     */
    private function isApplicableInAnotherTemplate(object $candidate): bool
    {
        $siblings = DB::table('template_layout_extensions')
            ->whereNull('deleted_at')
            ->where('id', '!=', $candidate->id)
            ->where('source_type', $candidate->source_type)
            ->where('source_identifier', $candidate->source_identifier)
            ->where('extension_type', $candidate->extension_type)
            ->where('target_name', $candidate->target_name)
            ->where('template_id', '!=', $candidate->template_id)
            ->get();

        foreach ($siblings as $sibling) {
            if ($this->isExtensionApplicable($sibling)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 확장의 대상(target_layout 또는 extension_point)이 해당 템플릿에 존재하는지 확인한다.
     *
     * @param  object  $extension  template_layout_extensions 행
     * @return bool 적용 가능 여부
     */
    private function isExtensionApplicable(object $extension): bool
    {
        // overlay: target_name 이 그 템플릿의 레이아웃명
        if ($extension->extension_type === 'overlay') {
            return DB::table('template_layouts')
                ->where('template_id', $extension->template_id)
                ->where('name', $extension->target_name)
                ->exists();
        }

        // extension_point: 두 단계로 판정한다.
        //
        // (1) 이 템플릿 content 에 그 확장점 노드가 박혀 있으면 적용 가능(신버전).
        if ($extension->extension_type === 'extension_point') {
            $layouts = DB::table('template_layouts')
                ->where('template_id', $extension->template_id)
                ->where('content', 'like', '%'.$extension->target_name.'%')
                ->pluck('content');

            foreach ($layouts as $content) {
                $decoded = json_decode((string) $content, true);
                if (is_array($decoded) && $this->containsExtensionPoint($decoded, $extension->target_name)) {
                    return true;
                }
            }

            // (2) content 에 없어도 — 코어 업데이트 흐름에서 이 판정이 도는 시점에는 호스트
            //     레이아웃 content 가 아직 구버전이라 슬롯이 안 박혔을 수 있다(정상 확장 오삭제
            //     원인). 그 확장점을 담는 **호스트 레이아웃명**을 신버전 템플릿(슬롯이 이미 박힌
            //     다른 템플릿)에서 알아낸 뒤, **그 이름의 레이아웃 행이 이 template_id 에 존재**
            //     하면 적용 가능으로 본다. 레이아웃 행의 존재 자체는 content 버전과 무관하게
            //     정직하므로, 구버전이라 슬롯만 없는 정상 확장을 오삭제하지 않는다.
            $hostLayoutNames = $this->resolveHostLayoutNames($extension->target_name);
            if ($hostLayoutNames !== []) {
                return DB::table('template_layouts')
                    ->where('template_id', $extension->template_id)
                    ->whereIn('name', $hostLayoutNames)
                    ->exists();
            }

            return false;
        }

        // 알 수 없는 타입은 보존 (false positive 회피)
        return true;
    }

    /**
     * 주어진 확장점을 content 에 담고 있는 호스트 레이아웃들의 이름을 전 템플릿에서 수집한다.
     *
     * 어느 한 템플릿이라도 신버전이라 그 확장점 노드가 박혀 있으면, 그 레이아웃명을 통해
     * "이 확장점은 어느 레이아웃에 속하는가" 를 content 버전과 무관하게 식별할 수 있다.
     *
     * @param  string  $extensionPointName  확장점 이름
     * @return array<int, string> 호스트 레이아웃명 목록 (중복 제거)
     */
    private function resolveHostLayoutNames(string $extensionPointName): array
    {
        $rows = DB::table('template_layouts')
            ->where('content', 'like', '%'.$extensionPointName.'%')
            ->get(['name', 'content']);

        $names = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->content, true);
            if (is_array($decoded) && $this->containsExtensionPoint($decoded, $extensionPointName)) {
                $names[$row->name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * content 트리를 재귀 순회하여 extension_point 노드를 검색한다.
     *
     * @param  mixed  $node  탐색 노드
     * @param  string  $name  확장점 이름
     * @return bool 발견 여부
     */
    private function containsExtensionPoint(mixed $node, string $name): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (($node['type'] ?? null) === 'extension_point' && ($node['name'] ?? null) === $name) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && $this->containsExtensionPoint($value, $name)) {
                return true;
            }
        }

        return false;
    }
}
