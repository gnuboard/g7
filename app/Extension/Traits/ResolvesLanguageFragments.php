<?php

namespace App\Extension\Traits;

/**
 * 다국어 파일의 $partial 디렉티브를 해석하는 Trait
 *
 * 레이아웃 시스템의 partial 패턴과 유사하게 다국어 파일을
 * 여러 개의 fragment 파일로 분할하고 런타임에 병합합니다.
 */
trait ResolvesLanguageFragments
{
    /**
     * 순환 참조 감지를 위한 스택
     *
     * @var array<string>
     */
    private array $fragmentStack = [];

    /**
     * 최대 중첩 깊이
     */
    private const MAX_FRAGMENT_DEPTH = 10;

    /**
     * 다국어 데이터에서 $partial 디렉티브를 해석합니다.
     *
     * @param array $data 다국어 데이터
     * @param string $basePath 기준 경로 (fragments 디렉토리 포함)
     * @param int $depth 현재 깊이
     * @return array 해석된 데이터
     *
     * @throws \RuntimeException 최대 깊이 초과 시
     */
    protected function resolveLanguageFragments(array $data, string $basePath, int $depth = 0): array
    {
        if ($depth > self::MAX_FRAGMENT_DEPTH) {
            throw new \RuntimeException("다국어 fragment 최대 깊이(10) 초과: {$basePath}");
        }

        $resolved = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (isset($value['$partial'])) {
                    // $partial 디렉티브 처리
                    $fragmentPath = $basePath . '/' . $value['$partial'];
                    $resolved[$key] = $this->loadLanguageFragment($fragmentPath, $basePath, $depth);
                } else {
                    // 재귀적으로 하위 배열 처리
                    $resolved[$key] = $this->resolveLanguageFragments($value, $basePath, $depth);
                }
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Fragment 파일을 로드합니다.
     *
     * @param string $fragmentPath fragment 파일 경로
     * @param string $basePath 기준 경로
     * @param int $depth 현재 깊이
     * @return array fragment 데이터
     *
     * @throws \RuntimeException 순환 참조, 파일 없음, JSON 파싱 오류 시
     */
    private function loadLanguageFragment(string $fragmentPath, string $basePath, int $depth): array
    {
        // Windows 경로 정규화
        $fragmentPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fragmentPath);
        $normalizedPath = realpath($fragmentPath) ?: $fragmentPath;

        // 순환 참조 검사
        if (in_array($normalizedPath, $this->fragmentStack)) {
            $cycle = implode(' -> ', $this->fragmentStack) . ' -> ' . $normalizedPath;
            throw new \RuntimeException("다국어 fragment 순환 참조 감지: {$cycle}");
        }

        if (! file_exists($fragmentPath)) {
            throw new \RuntimeException("다국어 fragment 파일을 찾을 수 없습니다: {$fragmentPath}");
        }

        $this->fragmentStack[] = $normalizedPath;

        try {
            $content = file_get_contents($fragmentPath);
            $fragmentData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("다국어 fragment JSON 파싱 오류: {$fragmentPath} - " . json_last_error_msg());
            }

            // fragment 내부의 $partial도 재귀적으로 처리
            $fragmentBasePath = dirname($fragmentPath);
            $resolved = $this->resolveLanguageFragments($fragmentData, $fragmentBasePath, $depth + 1);

            return $resolved;
        } finally {
            array_pop($this->fragmentStack);
        }
    }

    /**
     * Fragment 스택을 초기화합니다.
     *
     * @return void
     */
    protected function resetFragmentStack(): void
    {
        $this->fragmentStack = [];
    }
}
