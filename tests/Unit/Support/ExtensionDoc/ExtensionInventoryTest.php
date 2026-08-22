<?php

namespace Tests\Unit\Support\ExtensionDoc;

use App\Support\ExtensionDoc\ExtensionInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 진입 문서 제목 조립 규칙(`ExtensionInventory::docTitle`)을 고정합니다.
 *
 * 제목은 「그누보드7 {확장명} {유형}」 이다 (PO 결정 2026-09-04). 확장명이 이미 유형으로
 * 끝나면 겹쳐 붙이지 않는다 — 이 예외가 깨지면 「그누보드7 Hello 모듈 모듈」 이 되는데,
 * 계약 테스트는 같은 헬퍼로 기대값을 만들므로 그 형태도 초록으로 통과한다. 그래서 규칙
 * 자체는 여기서 리터럴로 고정한다.
 */
class ExtensionInventoryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function titles(): array
    {
        return [
            '모듈' => ['게시판', ExtensionInventory::TYPE_MODULE, '그누보드7 게시판 모듈'],
            '플러그인' => ['GDPR (일반 데이터 보호 규정)', ExtensionInventory::TYPE_PLUGIN, '그누보드7 GDPR (일반 데이터 보호 규정) 플러그인'],
            '템플릿' => ['Basic', ExtensionInventory::TYPE_TEMPLATE, '그누보드7 Basic 템플릿'],
            '이름이 이미 유형으로 끝남 (한국어)' => ['Hello 모듈', ExtensionInventory::TYPE_MODULE, '그누보드7 Hello 모듈'],
            '이름이 이미 유형으로 끝남 (영문, 대소문자 무관)' => ['Hello Admin Template', ExtensionInventory::TYPE_TEMPLATE, '그누보드7 Hello Admin Template'],
            '이름 중간의 유형 낱말은 예외가 아님' => ['모듈 관리', ExtensionInventory::TYPE_MODULE, '그누보드7 모듈 관리 모듈'],
            '다른 유형 낱말로 끝나면 자기 유형을 붙임' => ['에디터 플러그인', ExtensionInventory::TYPE_MODULE, '그누보드7 에디터 플러그인 모듈'],
            '앞뒤 공백 정리' => ['  페이지  ', ExtensionInventory::TYPE_MODULE, '그누보드7 페이지 모듈'],
        ];
    }

    #[Test]
    #[DataProvider('titles')]
    public function 진입_문서_제목은_그누보드7_확장명_유형_순이다(string $name, string $type, string $expected): void
    {
        $this->assertSame($expected, ExtensionInventory::docTitle($name, $type));
    }

    #[Test]
    public function 알_수_없는_유형은_유형_문자열을_그대로_붙인다(): void
    {
        // typeLabel 이 모르는 유형은 그 문자열을 라벨로 돌려주므로 제목도 같은 규칙을 따른다.
        $this->assertSame('그누보드7 X widget', ExtensionInventory::docTitle('X', 'widget'));
    }
}
