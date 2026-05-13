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

import { describe, it, expect, vi, beforeEach } from 'vitest';

// useFileUploader의 accept 검증 로직만 추출하여 테스트
function isFileAccepted(file: File, accept: string): boolean {
    const ext = '.' + file.name.split('.').pop()?.toLowerCase();
    const acceptedTypes = accept.split(',').map((e) => e.trim().toLowerCase());
    return acceptedTypes.some((accepted) => {
        if (accepted.includes('/')) {
            if (accepted.endsWith('/*')) {
                const mimePrefix = accepted.slice(0, -2);
                return file.type.toLowerCase().startsWith(mimePrefix + '/');
            }
            return file.type.toLowerCase() === accepted;
        }
        return ext === accepted;
    });
}

describe('FileUploader accept 검증', () => {
    describe('파일 확장자 매칭', () => {
        it('.jpg 확장자 허용', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, '.jpg,.png')).toBe(true);
        });

        it('.pdf 확장자 허용', () => {
            const file = new File([''], 'doc.pdf', { type: 'application/pdf' });
            expect(isFileAccepted(file, '.jpg,.png,.pdf')).toBe(true);
        });

        it('.exe 확장자 거부', () => {
            const file = new File([''], 'app.exe', { type: 'application/x-msdownload' });
            expect(isFileAccepted(file, '.jpg,.png,.pdf')).toBe(false);
        });

        it('대소문자 무시', () => {
            const file = new File([''], 'photo.JPG', { type: 'image/jpeg' });
            expect(isFileAccepted(file, '.jpg,.png')).toBe(true);
        });
    });

    describe('MIME 타입 와일드카드 매칭', () => {
        it('image/* 와일드카드로 JPEG 허용', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        it('image/* 와일드카드로 PNG 허용', () => {
            const file = new File([''], 'photo.png', { type: 'image/png' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        it('image/* 와일드카드로 WebP 허용', () => {
            const file = new File([''], 'photo.webp', { type: 'image/webp' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        it('image/* 와일드카드로 GIF 허용', () => {
            const file = new File([''], 'anim.gif', { type: 'image/gif' });
            expect(isFileAccepted(file, 'image/*')).toBe(true);
        });

        it('image/* 와일드카드로 PDF 거부', () => {
            const file = new File([''], 'doc.pdf', { type: 'application/pdf' });
            expect(isFileAccepted(file, 'image/*')).toBe(false);
        });

        it('video/* 와일드카드로 MP4 허용', () => {
            const file = new File([''], 'video.mp4', { type: 'video/mp4' });
            expect(isFileAccepted(file, 'video/*')).toBe(true);
        });

        it('audio/* 와일드카드로 MP3 허용', () => {
            const file = new File([''], 'song.mp3', { type: 'audio/mpeg' });
            expect(isFileAccepted(file, 'audio/*')).toBe(true);
        });
    });

    describe('정확한 MIME 타입 매칭', () => {
        it('image/jpeg 정확한 매칭', () => {
            const file = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            expect(isFileAccepted(file, 'image/jpeg')).toBe(true);
        });

        it('image/png과 image/jpeg 불일치', () => {
            const file = new File([''], 'photo.png', { type: 'image/png' });
            expect(isFileAccepted(file, 'image/jpeg')).toBe(false);
        });
    });

    describe('혼합 accept 값', () => {
        it('확장자 + MIME 와일드카드 혼합', () => {
            const jpgFile = new File([''], 'photo.jpg', { type: 'image/jpeg' });
            const pdfFile = new File([''], 'doc.pdf', { type: 'application/pdf' });
            const zipFile = new File([''], 'archive.zip', { type: 'application/zip' });

            const accept = 'image/*,.pdf';
            expect(isFileAccepted(jpgFile, accept)).toBe(true);
            expect(isFileAccepted(pdfFile, accept)).toBe(true);
            expect(isFileAccepted(zipFile, accept)).toBe(false);
        });

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
