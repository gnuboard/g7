<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 템플릿 업데이트 성공 cleanup 의 config 캐시 무효화 구조 테스트 (#588, 공개 #119)
 *
 * `template.config.{identifier}` 는 버전 접미사 없는 고정 키라 캐시 버전 bump 로
 * 무효화되지 않는다. updateTemplate() 성공 cleanup 이 clearTemplateCache() 를
 * 직접 호출하지 않으면, 비활성 업데이트·레이아웃 무변경 업데이트 경로에서 공개
 * config.json 이 TTL(1시간) 동안 이전 manifest 를 계속 서빙한다.
 *
 * 실제 updateTemplate() 통합 실행은 업데이트 소스(_pending/신버전) 픽스처가 필요해
 * 비용이 크므로, clearTemplateCache() 의 키 삭제는 TemplateCacheManagementTest 가
 * 동작으로 검증하고 본 테스트는 호출 존재를 소스 구조로 고정한다
 * (선례: ExtensionUpdateStatusCacheSymmetryTest).
 */
class TemplateUpdateConfigCacheInvalidationTest extends TestCase
{
    /**
     * @scenario cache_state=warm_current,lifecycle_trigger=template_update,template_type=user
     *
     * @effects template_update_invalidates_public_config_cache
     */
    #[Test]
    public function update_template_cleanup_calls_clear_template_cache(): void
    {
        $body = $this->methodSource(
            base_path('app/Extension/TemplateManager.php'),
            'updateTemplate'
        );

        $this->assertStringContainsString(
            '$this->clearTemplateCache(',
            $body,
            'updateTemplate() 성공 cleanup 이 clearTemplateCache() 를 호출해야 합니다 — '
            .'누락 시 공개 config.json 이 캐시 TTL 동안 이전 manifest 로 응답됩니다.'
        );
    }

    /**
     * 업데이트 tail 은 활성/비활성과 무관하게 **무조건** 캐시 버전을 올린다 (#651 F1).
     *
     * 종전에는 `if ($previousStatus !== ExtensionStatus::Active->value)` 가드 안에서만 올렸다.
     * 활성 템플릿은 `refreshTemplateLayouts()` 의 조건부 bump(레이아웃 변경 건수 > 0)에
     * 위임됐는데, 그 조건은 게시 입력 중 레이아웃 축만 보므로 lang/routes/dist 만 바뀐
     * 릴리스는 정적 게시본이 immutable 로 stale 하게 남았다. 모듈·플러그인 update 는
     * 무조건 bump 다 — 템플릿만 다를 이유가 없다.
     *
     * @scenario cache_state=warm_current,lifecycle_trigger=template_update,template_type=admin
     *
     * @effects template_update_always_bumps_cache_version
     */
    #[Test]
    public function update_template_tail_bumps_cache_version_unconditionally(): void
    {
        $body = $this->methodSource(
            base_path('app/Extension/TemplateManager.php'),
            'updateTemplate'
        );

        $this->assertStringNotContainsString(
            'if ($previousStatus !== ExtensionStatus::Active->value)',
            $body,
            'updateTemplate() 의 bump 가 previousStatus 가드 안에 있으면 활성 템플릿의 lang/routes/dist 변경이 게시본에 반영되지 않습니다.'
        );

        // 성공 tail + 실패 catch 두 자리 — 최소 2회
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($body, '$this->incrementExtensionCacheVersion();'),
            'updateTemplate() 는 성공 tail 과 실패 복원 경로 양쪽에서 캐시 버전을 올려야 합니다.'
        );
    }

    /**
     * 실패·복원 경로도 캐시 버전을 올린다 (#651 F2).
     *
     * 백업 복원이 실패했거나 부분 반영된 디스크가 남을 수 있어, 게시본을 새 버전으로 다시
     * 굽는 쪽이 옛 게시본을 그대로 두는 쪽보다 안전하다. bump 실패는 원래 예외를 가리지
     * 않도록 삼킨다.
     *
     * @scenario cache_state=warm_current,lifecycle_trigger=template_update,template_type=user
     *
     * @effects template_update_failure_bumps_after_restore
     */
    #[Test]
    public function update_template_failure_path_bumps_after_restore(): void
    {
        $body = $this->methodSource(
            base_path('app/Extension/TemplateManager.php'),
            'updateTemplate'
        );

        $catchAt = strrpos($body, 'catch (\Throwable $e)');
        $this->assertNotFalse($catchAt, 'updateTemplate() 의 최외곽 catch 를 찾지 못했습니다.');

        $catchBody = substr($body, $catchAt);

        $restoreAt = strpos($catchBody, "'status' => \$previousStatus");
        $bumpAt = strpos($catchBody, '$this->incrementExtensionCacheVersion();');
        $throwAt = strpos($catchBody, 'throw new \RuntimeException(');

        $this->assertNotFalse($restoreAt, '실패 경로의 상태 복원 코드를 찾지 못했습니다.');
        $this->assertNotFalse($bumpAt, '실패 경로에 캐시 버전 bump 가 없습니다 — 부분 반영된 디스크가 옛 게시본과 함께 남습니다.');
        $this->assertNotFalse($throwAt, '실패 경로의 재던지기를 찾지 못했습니다.');

        $this->assertTrue($restoreAt < $bumpAt && $bumpAt < $throwAt, 'bump 는 상태 복원 뒤, 재던지기 앞에 있어야 합니다.');
    }

    /**
     * 대상 메서드 본문을 주석 제거 후 반환합니다.
     *
     * 주석을 남겨 두면 설명 문장에 들어 있는 호출 표기가 검사를 통과시킨다.
     *
     * @param  string  $path  대상 파일 절대 경로
     * @param  string  $method  대상 메서드명
     * @return string 주석이 제거된 메서드 본문
     */
    private function methodSource(string $path, string $method): string
    {
        $this->assertFileExists($path);

        $stripped = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $start = strpos($stripped, "public function {$method}(");
        $this->assertNotFalse($start, "{$path} 에서 {$method}() 메서드를 찾지 못했습니다.");

        // 중괄호 균형으로 메서드 본문 끝을 찾는다
        $open = strpos($stripped, '{', $start);
        $depth = 0;
        $length = strlen($stripped);

        for ($i = $open; $i < $length; $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($stripped, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("{$path} 의 {$method}() 본문 끝을 찾지 못했습니다.");
    }
}
