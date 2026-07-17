<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Service;

/**
 * Extracts plain text from brand document uploads (PDF, DOCX, text formats).
 *
 * @internal
 */
final class BrandContextDocumentExtractor
{
    public const MAX_FILES = 3;
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_EXTRACT_CHARS = 100000;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'md', 'txt', 'csv', 'json'];

    /**
     * @param list<array{path: string, name: string}> $files
     *
     * @return array{
     *   extract: string,
     *   files: list<array{name: string, chars: int}>,
     *   warnings: list<string>
     * }
     */
    public function extractFromFiles(array $files): array
    {
        $chunks = [];
        $meta = [];
        $warnings = [];

        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            $name = trim((string) ($file['name'] ?? ''));
            if ($name === '' || !is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $warnings[] = sprintf('Skipped "%s": unsupported file type.', $name);
                continue;
            }

            $size = filesize($path);
            if ($size === false || $size > self::MAX_FILE_BYTES) {
                $warnings[] = sprintf('Skipped "%s": file exceeds 10 MB limit.', $name);
                continue;
            }

            try {
                $text = $this->extractByExtension($path, $extension);
            } catch (\Throwable $exception) {
                $warnings[] = sprintf('Could not extract text from "%s": %s', $name, $exception->getMessage());
                continue;
            }

            $text = $this->normalizeText($text);
            if ($text === '') {
                $warnings[] = sprintf('No readable text found in "%s".', $name);
                continue;
            }

            $chunks[] = "--- {$name} ---\n" . $text;
            $meta[] = ['name' => $name, 'chars' => strlen($text), 'extract' => "--- {$name} ---\n" . $text];
        }

        $extract = $this->truncate(implode("\n\n", $chunks), self::MAX_EXTRACT_CHARS);

        return [
            'extract' => $extract,
            'files' => $meta,
            'warnings' => $warnings,
        ];
    }

    private function extractByExtension(string $path, string $extension): string
    {
        return match ($extension) {
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            'json' => $this->extractJson($path),
            default => $this->extractPlainText($path),
        };
    }

    private function extractPlainText(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read file.');
        }

        return $contents;
    }

    private function extractJson(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read file.');
        }
        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $contents;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $contents;
    }

    private function extractPdf(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF extraction requires smalot/pdfparser (install via Composer).');
        }

        $parser = new \Smalot\PdfParser\Parser();

        return $parser->parseFile($path)->getText();
    }

    private function extractDocx(string $path): string
    {
        if (!class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
            throw new \RuntimeException('DOCX extraction requires phpoffice/phpword (install via Composer).');
        }

        $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (is_object($element) && method_exists($element, 'getText')) {
                    $text .= (string) $element->getText() . "\n";
                } elseif (is_object($element) && method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (is_object($child) && method_exists($child, 'getText')) {
                            $text .= (string) $child->getText() . "\n";
                        }
                    }
                }
            }
        }

        return $text;
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/\r\n|\r/u', "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function truncate(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
