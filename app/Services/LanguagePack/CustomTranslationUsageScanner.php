<?php

namespace App\Services\LanguagePack;

/**
 * 레이아웃 콘텐츠에서 참조되는 커스텀 다국어 키 스캐너.
 *
 * 레이아웃 content(JSON 디코드 배열)를 깊이 우선으로 순회하며 모든 문자열
 * 값에서 `$t:custom.{layout}.{seq}` 형태로 참조되는 커스텀 키를 수집합니다.
 * 노드의 `text` 뿐 아니라 props 값·표현식 내 `$t:custom.` 문자열도 보수적으로
 * 전부 스캔합니다(인라인 편집은 `text` 에만 쓰지만, 코드 편집기 경유 props/표현식
 * 참조도 보존 — 과소 orphaned 방지).
 *
 * DB 무접근 순수 함수이므로 단위 테스트가 용이합니다. 좀비(고아) 키 자동 감지
 * 리스너(MarkOrphanedCustomTranslations)가 본 스캐너로 "사용 중" 키 집합을 얻어
 * 미참조 키를 orphaned 로 전이시킵니다.
 *
 * @since engine-v1.54.0
 */
class CustomTranslationUsageScanner
{
    /**
     * `$t:custom.{layoutKey}.{seq}` 매칭 정규식.
     *
     * - layoutKey: 영숫자/언더스코어/하이픈 (normalizeLayoutKey 출력과 동형)
     * - seq: 숫자
     */
    private const CUSTOM_KEY_RE = '/\$t:(custom\.[A-Za-z0-9_\-]+\.\d+)/';

    /**
     * 레이아웃 content 에서 참조되는 커스텀 키 목록을 수집합니다.
     *
     * @param  mixed  $content  레이아웃 content (배열/객체/문자열 — 보수적으로 전체 순회)
     * @return array<int, string> 참조된 커스텀 키 목록 (중복 제거, 예: ['custom.home.1'])
     */
    public function collectReferencedKeys(mixed $content): array
    {
        $keys = [];
        $this->walk($content, $keys);

        return array_values(array_unique($keys));
    }

    /**
     * 레이아웃 이름을 키 네임스페이스로 정규화합니다 (커스텀 키 생성/매칭 SSoT).
     *
     * `board/list` → `board_list`. 슬래시/공백을 언더스코어로 치환하고
     * 키에 안전한 문자만 남깁니다. 키 저장(TemplateCustomTranslationService::createKey)과
     * 스캐너의 layoutKey 추출이 동일 정규화를 공유하도록 단일 SSoT 로 둡니다.
     *
     * @param  string  $layoutName  레이아웃 이름
     * @return string 정규화된 키 네임스페이스
     */
    public static function normalizeLayoutKey(string $layoutName): string
    {
        $normalized = preg_replace('/[\/\s]+/', '_', trim($layoutName));
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $normalized);

        return $normalized === '' ? 'layout' : $normalized;
    }

    /**
     * 값을 깊이 우선 순회하며 문자열에서 커스텀 키를 누적합니다.
     *
     * @param  mixed  $value  순회 대상 값
     * @param  array<int, string>  $keys  누적 키 (참조 전달)
     */
    private function walk(mixed $value, array &$keys): void
    {
        if (is_string($value)) {
            if (str_contains($value, '$t:custom.') && preg_match_all(self::CUSTOM_KEY_RE, $value, $matches)) {
                foreach ($matches[1] as $key) {
                    $keys[] = $key;
                }
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item, $keys);
            }

            return;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $item) {
                $this->walk($item, $keys);
            }
        }
    }
}
