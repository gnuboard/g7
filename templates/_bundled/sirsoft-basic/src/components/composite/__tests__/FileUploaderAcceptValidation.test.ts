/**
 * FileUploader accept 검증 테스트
 *
 * useFileUploader의 파일 타입 검증 로직을 테스트합니다.
 * - 파일 확장자 매칭 (.jpg, .png 등)
 * - MIME 타입 와일드카드 매칭 (image/*, video/*)
 * - 정확한 MIME 타입 매칭 (image/jpeg)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
// useFileUploader 가 파일을 받을 때 쓰는 판정 함수를 그대로 잰다(시험 안에 식을 옮겨 적지 않는다)
import { isFileAccepted } from '../FileUploader/utils';

describe('FileUploader accept 검증', () => {
    describe('파일 확장자 매칭', () => {
        /**
         * @scenario accept_form=extension, verdict=accepted
         * @effects extension_accept_matches_file_extension
         */
        it('.jpg 확장자 허용', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, '.jpg,.png')).toBe(true);
        });

        /**
         * @scenario accept_form=extension, verdict=accepted
         * @effects extension_accept_matches_file_extension
         */
        it('.pdf 확장자 허용', () => {
            const file = new File([''], 'doc.pdf', { type: 'application/pdf' });
            expect(isFileAccepted(file, '.jpg,.png,.pdf')).toBe(true);
        });

        /**
         * @scenario accept_form=extension, verdict=rejected
         * @effects extension_accept_rejects_unlisted_extension
         */
        it('.exe 확장자 거부', () => {
            const file = new File([''], 'app.exe', { type: 'application/x-msdownload' });
            expect(isFileAccepted(file, '.jpg,.png,.pdf')).toBe(false);
        });

        /**
         * @scenario accept_form=extension, verdict=accepted
         * @effects extension_accept_ignores_case
         */
        it('대소문자 무시', () => {
            const file = new File([''], 'photo.JPG', { type: 'image/jpeg' });
            expect(isFileAccepted(file, '.jpg,.png')).toBe(true);
        });
    });

    describe('MIME 타입 와일드카드 매칭', () => {
        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('image/* 와일드카드로 JPEG 허용', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('image/* 와일드카드로 PNG 허용', () => {
            const file = new File([''], 'photo.png', { type: 'image/png' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('image/* 와일드카드로 WebP 허용', () => {
            const file = new File([''], 'photo.webp', { type: 'image/webp' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('image/* 와일드카드로 GIF 허용', () => {
            const file = new File([''], 'anim.gif', { type: 'image/gif' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=rejected
         * @effects mime_wildcard_rejects_other_major_type
         */
        it('image/* 와일드카드로 PDF 거부', () => {
            const file = new File([''], 'doc.pdf', { type: 'application/pdf' });
            expect(isFileAccepted(file, 'image/*')).toBe(false);
        });

        /**
         * 주 형식 이름이 같은 글자로 시작할 뿐인 다른 형식은 받지 않는다(접두 비교가 `/` 까지 맞춰야 한다)
         *
         * @scenario accept_form=mime_wildcard, verdict=rejected
         * @effects mime_wildcard_rejects_other_major_type
         */
        it('image/* 와일드카드는 imagex/* 같은 다른 주 형식을 거부', () => {
            const file = new File([''], 'fake.bin', { type: 'imagex/foo' });
            expect(isFileAccepted(file, 'image/*')).toBe(false);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('video/* 와일드카드로 MP4 허용', () => {
            const file = new File([''], 'video.mp4', { type: 'video/mp4' });
            expect(isFileAccepted(file, 'video/*')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_wildcard, verdict=accepted
         * @effects mime_wildcard_accepts_same_major_type
         */
        it('audio/* 와일드카드로 MP3 허용', () => {
            const file = new File([''], 'song.mp3', { type: 'audio/mpeg' });
            expect(isFileAccepted(file, 'audio/*')).toBe(true);
        });
    });

    describe('정확한 MIME 타입 매칭', () => {
        /**
         * @scenario accept_form=mime_exact, verdict=accepted
         * @effects mime_exact_accepts_identical_type
         */
        it('image/jpeg 정확한 매칭', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, 'image/jpeg')).toBe(true);
        });

        /**
         * @scenario accept_form=mime_exact, verdict=rejected
         * @effects mime_exact_rejects_different_subtype
         */
        it('image/png과 image/jpeg 불일치', () => {
            const file = new File([''], 'photo.png', { type: 'image/png' });
            expect(isFileAccepted(file, 'image/jpeg')).toBe(false);
        });
    });

    describe('혼합 accept 값', () => {
        /**
         * @scenario accept_form=mixed, verdict=accepted, verdict=rejected
         * @effects mixed_accept_allows_any_matching_rule
         */
        it('확장자 + MIME 와일드카드 혼합', () => {
            const jpgFile = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            const pdfFile = new File([''], 'doc.pdf', { type: 'application/pdf' });
            const zipFile = new File([''], 'archive.zip', { type: 'application/zip' });

            const accept = 'image/*,.pdf';
            expect(isFileAccepted(jpgFile, accept)).toBe(true);
            expect(isFileAccepted(pdfFile, accept)).toBe(true);
            expect(isFileAccepted(zipFile, accept)).toBe(false);
        });

        /**
         * @scenario accept_form=mixed, verdict=accepted, verdict=rejected
         * @effects mixed_accept_allows_any_matching_rule
         */
        it('여러 MIME 와일드카드', () => {
            const imgFile = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            const videoFile = new File([''], 'clip.mp4', { type: 'video/mp4' });
            const pdfFile = new File([''], 'doc.pdf', { type: 'application/pdf' });

            const accept = 'image/*,video/*';
            expect(isFileAccepted(imgFile, accept)).toBe(true);
            expect(isFileAccepted(videoFile, accept)).toBe(true);
            expect(isFileAccepted(pdfFile, accept)).toBe(false);
        });
    });
});
