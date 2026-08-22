<?php

namespace Modules\Sirsoft\Page\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageVersion;

/**
 * 페이지 시더
 *
 * 샘플 페이지를 생성합니다.
 * 모델을 직접 사용하여 데이터를 삽입합니다 (Auth 의존 없음).
 *
 * 생성 목록:
 * 1. terms       - 이용약관 (발행)
 * 2. privacy     - 개인정보처리방침 (발행)
 * 3. refund      - 취소/반품/교환 정책 (발행)
 * 4. about       - 그누보드7 소개 (발행)
 * 5. faq         - 자주 묻는 질문 (발행)
 * 6. contact     - 문의하기 (발행)
 */
class PageSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('페이지 데이터 생성 중...');

        // 최고 관리자 ID 조회
        $superAdminId = User::where('is_super', true)->value('id');

        $now = now();
        $pages = $this->getSamplePages($now);

        foreach ($pages as $pageData) {
            if (Page::where('slug', $pageData['slug'])->exists()) {
                $this->command->warn("  - [{$pageData['slug']}] 이미 존재합니다. 스킵합니다.");

                continue;
            }

            $page = Page::create([
                'slug' => $pageData['slug'],
                'title' => $pageData['title'],
                'content' => $pageData['content'],
                'content_mode' => $pageData['content_mode'],
                'published' => $pageData['published'],
                'published_at' => $pageData['published_at'],
                'current_version' => 1,
                'created_by' => $superAdminId,
                'updated_by' => $superAdminId,
            ]);

            // 버전 1 스냅샷 저장
            PageVersion::create([
                'page_id' => $page->id,
                'version' => 1,
                'title' => $page->title,
                'content' => $page->content,
                'content_mode' => $page->content_mode,
                'seo_meta' => $page->seo_meta,
                'created_by' => $superAdminId,
            ]);

            $status = $page->published ? '발행' : '미발행';
            $this->command->info("  - [{$page->slug}] 생성 완료 ({$status})");
        }

        $this->command->info('페이지 데이터 생성 완료: '.count($pages).'개');
    }

    /**
     * 샘플 페이지 데이터를 반환합니다.
     *
     * @param  \Illuminate\Support\Carbon  $now  현재 시각
     * @return array<int, array<string, mixed>>
     */
    private function getSamplePages(\Illuminate\Support\Carbon $now): array
    {
        return [
            [
                'slug' => 'terms',
                'title' => ['ko' => '이용약관', 'en' => 'Terms of Service'],
                'content' => ['ko' => $this->getTermsContentKo(), 'en' => $this->getTermsContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
            [
                'slug' => 'privacy',
                'title' => ['ko' => '개인정보처리방침', 'en' => 'Privacy Policy'],
                'content' => ['ko' => $this->getPrivacyContentKo(), 'en' => $this->getPrivacyContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
            [
                'slug' => 'refund',
                'title' => ['ko' => '취소/반품/교환 정책', 'en' => 'Cancellation/Return/Exchange Policy'],
                'content' => ['ko' => $this->getRefundContentKo(), 'en' => $this->getRefundContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
            [
                'slug' => 'about',
                'title' => ['ko' => '그누보드7 소개', 'en' => 'About Gnuboard7'],
                'content' => ['ko' => $this->getAboutContentKo(), 'en' => $this->getAboutContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
            [
                'slug' => 'faq',
                'title' => ['ko' => '자주 묻는 질문', 'en' => 'FAQ'],
                'content' => ['ko' => $this->getFaqContentKo(), 'en' => $this->getFaqContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
            [
                'slug' => 'contact',
                'title' => ['ko' => '문의하기', 'en' => 'Contact Us'],
                'content' => ['ko' => $this->getContactContentKo(), 'en' => $this->getContactContentEn()],
                'content_mode' => 'html',
                'published' => true,
                'published_at' => $now,
            ],
        ];
    }

    // ──────────────────────────────────────
    // About (그누보드7 소개)
    // ──────────────────────────────────────

    private function getAboutContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">그누보드7에 오신 것을 환영합니다</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">그누보드7은 Laravel과 React 기반의 오픈소스 CMS 플랫폼입니다.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">모듈, 플러그인, 템플릿 시스템을 통해 코어 수정 없이 원하는 기능을 자유롭게 확장할 수 있습니다. 소규모 웹사이트부터 대규모 서비스까지, 그누보드7과 함께 빠르고 유연하게 구축하세요.</p>

<div style="border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 24px 0;">
<p style="margin: 0; font-size: 1rem; font-weight: 600;"><strong>핵심 기술 스택</strong></p>
<p style="margin: 8px 0 0 0; font-size: 0.95rem; line-height: 1.75;">백엔드: <strong>Laravel 12.x</strong> · 프론트엔드: <strong>React 19</strong> · 데이터베이스: <strong>MySQL 8.0</strong></p>
</div>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">왜 그누보드7인가요?</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">기존 CMS는 기능을 추가하려면 코어를 수정해야 했습니다. 업데이트할 때마다 커스터마이징이 깨지고, 유지보수가 어려워지는 악순환이 반복됩니다.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">그누보드7은 이 문제를 해결하기 위해 설계되었습니다.</p>
<ul style="margin-bottom: 1rem; padding-left: 1.5rem;">
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>모듈 시스템</strong> — 독립적인 기능 단위로 개발·배포</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>플러그인 시스템</strong> — 결제, 알림 등 부가 기능 확장</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>템플릿 시스템</strong> — 관리자/사용자 UI를 자유롭게 커스터마이징</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>훅 시스템</strong> — 코어 수정 없이 동작을 변경·확장</li>
</ul>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">함께 만들어갑니다</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">그누보드7은 완전한 오픈소스입니다. 소스 코드를 자유롭게 사용, 수정, 배포할 수 있으며 상업적 프로젝트에도 제한이 없습니다.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">버그 리포트, 기능 제안, 코드 기여 모두 환영합니다. GitHub에서 개발 현황을 확인하고 커뮤니티에 참여해 주세요.</p>
HTML;
    }

    private function getAboutContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">Welcome to Gnuboard7</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Gnuboard7 is an open-source CMS platform built on Laravel and React.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">With modules, plugins, and a template system, you can freely extend functionality without modifying the core. From small websites to large-scale services, build fast and flexible with Gnuboard7.</p>

<div style="border-left: 4px solid #3b82f6; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 24px 0;">
<p style="margin: 0; font-size: 1rem; font-weight: 600;"><strong>Core Tech Stack</strong></p>
<p style="margin: 8px 0 0 0; font-size: 0.95rem; line-height: 1.75;">Backend: <strong>Laravel 12.x</strong> · Frontend: <strong>React 19</strong> · Database: <strong>MySQL 8.0</strong></p>
</div>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Why Gnuboard7?</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Traditional CMS platforms require core modifications to add features. Every update breaks customizations, creating a vicious cycle of maintenance headaches.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Gnuboard7 was designed to solve this problem.</p>
<ul style="margin-bottom: 1rem; padding-left: 1.5rem;">
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>Module System</strong> — Develop and deploy as independent feature units</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>Plugin System</strong> — Extend with payments, notifications, and more</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>Template System</strong> — Freely customize admin/user interfaces</li>
<li style="margin-bottom: 0.5rem; line-height: 1.75; font-size: 1rem;"><strong>Hook System</strong> — Modify and extend behavior without touching the core</li>
</ul>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Built Together</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Gnuboard7 is completely open-source. You can freely use, modify, and distribute the source code, including for commercial projects.</p>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Bug reports, feature suggestions, and code contributions are all welcome. Check out the development progress on GitHub and join our community.</p>
HTML;
    }

    // ──────────────────────────────────────
    // Terms (이용약관)
    // ──────────────────────────────────────

    private function getTermsContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">제1조 (목적)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[이용약관의 목적을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">제2조 (정의)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[주요 용어의 정의를 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">제3조 (약관의 효력 및 변경)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[약관의 효력 및 변경에 관한 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">제4조 (서비스의 제공 및 변경)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[서비스 제공 및 변경에 관한 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">제5조 (회원의 의무)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[회원의 의무에 관한 내용을 입력하세요.]</p>
HTML;
    }

    private function getTermsContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">Article 1 (Purpose)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter the purpose of the Terms of Service.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Article 2 (Definitions)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter definitions of key terms.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Article 3 (Effect and Amendment of Terms)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter details on the effect and amendment of these terms.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Article 4 (Provision and Modification of Service)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter details on service provision and modification.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Article 5 (Obligations of Members)</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter member obligations.]</p>
HTML;
    }

    // ──────────────────────────────────────
    // Privacy (개인정보처리방침)
    // ──────────────────────────────────────

    private function getPrivacyContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">1. 개인정보의 수집 및 이용 목적</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[개인정보 수집 및 이용 목적을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">2. 수집하는 개인정보 항목</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[수집하는 개인정보 항목을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">3. 개인정보의 보유 및 이용 기간</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[개인정보 보유 및 이용 기간을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">4. 개인정보의 제3자 제공</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[개인정보 제3자 제공에 관한 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">5. 개인정보의 파기</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[개인정보 파기에 관한 내용을 입력하세요.]</p>
HTML;
    }

    private function getPrivacyContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">1. Purpose of Collection and Use of Personal Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter the purpose of collecting and using personal information.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">2. Personal Information Items Collected</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter the personal information items collected.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">3. Retention and Use Period of Personal Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter the retention and use period of personal information.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">4. Provision of Personal Information to Third Parties</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter details on provision of personal information to third parties.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">5. Destruction of Personal Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter details on destruction of personal information.]</p>
HTML;
    }

    // ──────────────────────────────────────
    // Refund (취소/반품/교환 정책)
    // ──────────────────────────────────────

    private function getRefundContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">배송 안내</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[배송 안내 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">주문 취소</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[주문 취소 정책을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">반품 안내</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[반품 안내 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">교환 안내</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[교환 안내 내용을 입력하세요.]</p>
HTML;
    }

    private function getRefundContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">Shipping Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter shipping information.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Order Cancellation</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter order cancellation policy.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Return Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter return policy details.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Exchange Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter exchange policy details.]</p>
HTML;
    }

    // ──────────────────────────────────────
    // FAQ (자주 묻는 질문)
    // ──────────────────────────────────────

    private function getFaqContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">회원 관련</h2>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">회원가입은 어떻게 하나요?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">홈페이지 상단의 [회원가입] 버튼을 클릭한 후 이름, 이메일, 비밀번호를 입력하시면 즉시 가입이 완료됩니다. 가입 후 이메일로 인증 메일이 발송되니 확인 부탁드립니다.</p>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">비밀번호를 잊어버렸어요. 어떻게 하나요?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">로그인 페이지의 [비밀번호 찾기] 링크를 클릭하시고, 가입 시 사용한 이메일 주소를 입력해 주세요. 해당 이메일로 비밀번호 재설정 링크가 발송됩니다.</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">서비스 이용</h2>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">서비스 이용 중 문제가 발생했어요. 어디에 문의하나요?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">문의하기 페이지를 통해 문의사항을 남겨 주시면 담당자가 확인 후 빠르게 답변 드립니다. 운영 시간은 평일 오전 9시부터 오후 6시까지이며, 접수된 문의는 1~2 영업일 내에 처리됩니다.</p>
HTML;
    }

    private function getFaqContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">Account</h2>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">How do I create an account?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Click the [Sign Up] button at the top of the homepage, then enter your name, email address, and password. Your account will be created immediately. A verification email will be sent to confirm your address.</p>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">I forgot my password. What should I do?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Click the [Forgot Password] link on the login page and enter the email address you used to register. A password reset link will be sent to that email address.</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Using the Service</h2>

<h3 style="font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.75rem;">I encountered an issue while using the service. Who should I contact?</h3>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">Please submit your inquiry through the Contact page and our team will respond as quickly as possible. Support is available Monday through Friday, 9 AM to 6 PM. Inquiries are typically handled within 1–2 business days.</p>
HTML;
    }

    // ──────────────────────────────────────
    // Contact (문의하기)
    // ──────────────────────────────────────

    private function getContactContentKo(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">문의 안내</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[문의 안내 내용을 입력하세요.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">연락처</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[연락처 정보를 입력하세요. (이메일, 운영시간, 응답시간 등)]</p>
HTML;
    }

    private function getContactContentEn(): string
    {
        return <<<'HTML'
<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem;">Contact Information</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter contact guidance details.]</p>

<h2 style="font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: 1rem;">Contact</h2>
<p style="font-size: 1rem; line-height: 1.75; margin-bottom: 1rem;">[Enter contact information. (email, hours, response time, etc.)]</p>
HTML;
    }
}
