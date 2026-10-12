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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Files the editor names by sys_file uid ("attach the existing image sys_file uid 3"). A file
 * that does not exist or is gone from the storage is reported once, before the model runs, so
 * the agent neither leaves the attach step open forever nor looks for a replacement on the web.
 *
 * @internal
 */
final readonly class AgentRequestedFiles
{
    public const MESSAGE_TYPE = 'files_unavailable';

    public const GENERATION_TOOL = 't3ai_generate_image';

    public function __construct(private ResourceFactory $resourceFactory) {}

    /**
     * "… with an AI-generated image of a lighthouse", "Generate an image of a mountain lake",
     * "Create a picture of a coffee shop", "Generiere ein Bild von …": an image the AI has to make,
     * not one from the web or the fileadmin.
     *
     * create/make/draw need an indefinite article or a number ("a picture", "3 photos") so
     * "make the image smaller" is not treated as generation.
     */
    public static function asksForGeneratedImage(string $request): bool
    {
        $imageNoun = '(?:images?|pictures?|photos?|illustrations?|graphics?|bild|bilder|foto|fotos|illustration\w*|grafik\w*)';

        return preg_match(
            '/\b(?:ai|ki)[\s-]*(?:generated|generiert\w*|created|erstellt\w*|images?|bild\w*)\b'
            . '|\b(?:generat\w*|generier\w*)\s+(?:\w+\s+){0,3}' . $imageNoun . '\b'
            . '|\b(?:creat\w*|mak\w*|draw\w*|erzeug\w*|erstell\w*|zeichn\w*)\s+'
            . '(?:(?:a|an|one|some|another|\d{1,2}|ein|eine|einen|einem|einer|eines|einige)\s+)'
            . '(?:\w+\s+){0,2}' . $imageNoun . '\b/iu',
            $request,
        ) === 1;
    }

    /**
     * "Generate an image of a red apple": the saved file is the whole result. Nothing has to be created
     * or attached unless the request names a place for it ("…and add it to this page").
     */
    public static function asksOnlyForGeneratedImage(string $request): bool
    {
        return self::asksForGeneratedImage($request)
            && AgentRequestChecklist::parse($request) === []
            // "Create an image page" is a page, not a standalone file.
            && preg_match('/\b(?:new\s+)?(?:pages?|seiten?|subpages?|unterseiten?)\b/iu', $request) !== 1
            && preg_match(
                '/\b(?:attach|add|insert|put|place|use|embed|füg\w*|häng\w*|setz\w*|verwend\w*|einbind\w*)\b'
                . '|\b(?:to|on|in|into|onto|for|zu|zum|zur|auf|für)\s+(?:(?:the|this|that|a|an|my|our|die|der|das|den|dem|diese\w*|eine\w*)\s+)?(?:page|element|content|news|article|record|seite|inhalt\w*|artikel)\b/iu',
                $request,
            ) !== 1;
    }

    /**
     * "Make 3 different pictures …", "Get three photos …", "Erstelle zwei Bilder …" → 3, 3, 2; 0 when no number is given.
     */
    public static function requestedImageCount(string $request): int
    {
        if (preg_match(
            '/\b(\d{1,2}|two|three|four|five|six|zwei|drei|vier|fünf|sechs)\s+(?:[\p{L}-]+\s+){0,3}(?:images?|pictures?|photos?|illustrations?|graphics?|bilder|fotos|illustrationen|grafiken)\b/iu',
            $request,
            $match,
        ) !== 1) {
            return 0;
        }
        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5, 'sechs' => 6];

        return $words[mb_strtolower($match[1])] ?? (int) $match[1];
    }

    public static function namesUrl(string $request): bool
    {
        return preg_match('#\bhttps?://#i', $request) === 1;
    }

    /**
     * "Add / attach / put this image on …": the composer file should become a file_reference_add.
     * Needs a place (page, news, element, …) after the verb, or a German add/attach compound.
     * "What is in this image?" / "Set the alt text…" / rename alone do not.
     */
    public static function asksToAttachOrPlaceFile(string $request): bool
    {
        return preg_match(
            // Hinzufügen / einfügen / anhängen / anfügen / einbinden (compound forms)
            '/\b(?:hinzufüg\w*|einfüg\w*|anhäng\w*|anfüg\w*|einbind\w*)\b'
            // Separable: füge … hinzu
            . '|\bfüg\w*\b(?:\W+\w+){0,6}\W+hinzu\b'
            // Verb … prep (+ article) + place noun — not "set … for this image"
            . '|\b(?:attach|add|insert|put|place|use|set|embed|include|füg\w*|häng\w*|setz\w*|verwend\w*)\b'
            . '(?:\W+\w+){0,8}\W+'
            . '(?:to|on|in|into|onto|for|an|auf|zu|zum|zur|für)\s+'
            // "to news" | "to the news" | "to the Services page" (title words only after an article)
            . '(?:(?:the|this|that|a|an|my|our|die|der|das|den|dem|diese\w*|eine\w*)\s+(?:\w+\s+){0,4})?'
            . '(?:page|element|header|content|news|article|record|seite|inhalt\w*|artikel|nachricht\w*)\b'
            . '/iu',
            $request,
        ) === 1;
    }

    /**
     * Notice for a request that wants an AI-generated image while the editor cannot generate one:
     * it closes the attach step, so the agent neither keeps asking to continue nor fetches a web image.
     *
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    public function generationUnavailableMessage(string $request, AgentTranslator $translator, string $correlationId): ?array
    {
        if (!self::asksForGeneratedImage($request) || self::namesUrl($request) || self::namedFileUids($request) !== []) {
            return null;
        }

        return [
            'role' => 'assistant',
            'content' => $translator->translate('agent.turn.imageGenerationUnavailable'),
            'meta' => [
                'type' => self::MESSAGE_TYPE,
                'correlationId' => $correlationId,
                'request' => self::requestKey($request),
                'unavailableFiles' => [],
                'noFileLeft' => true,
                'imageGenerationUnavailable' => true,
            ],
        ];
    }

    /**
     * sys_file uids named in a request to attach or use files. A uid after "tt_content", "page" or
     * "element" names the target record, not a file.
     *
     * @return list<int>
     */
    public static function namedFileUids(string $request): array
    {
        $text = mb_strtolower($request);
        if (preg_match('/\b(?:attach\w*|add\w*|use|using|insert\w*|with|anh[äa]ng\w*|hinzuf[üu]g\w*|verwend\w*|mit)\b/u', $text) !== 1) {
            return [];
        }
        if (preg_match_all(
            '/\b(sys_file|tt_content|pages?|seiten?|elements?|records?|datensatz|files?|dateien?|images?|bilder?)\b(?:\s+(\d+)\b)?|\buids?\s*[:#=]?\s*(\d+(?:\s*(?:,|and|und)\s*\d+)*)/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) === 0) {
            return [];
        }

        $uids = [];
        $aboutFiles = false;
        foreach ($matches as $match) {
            $word = $match[1] ?? '';
            if ($word !== '') {
                $aboutFiles = preg_match('/^(?:sys_file|files?|dateien?|images?|bilder?)$/u', $word) === 1;
                if ($word === 'sys_file' && ($match[2] ?? '') !== '') {
                    $uids[] = (int) $match[2];
                }
                continue;
            }
            if ($aboutFiles && preg_match_all('/\d+/', $match[3] ?? '', $numbers) > 0) {
                foreach ($numbers[0] as $number) {
                    $uids[] = (int) $number;
                }
            }
        }

        return array_values(array_unique(array_filter($uids, static fn(int $uid): bool => $uid > 0)));
    }

    /**
     * The named files that cannot be shown: unknown uid, or the file is gone from the storage.
     *
     * @param list<int> $fileUids
     * @return array<int, string> uid => label for the editor
     */
    public function unavailable(array $fileUids): array
    {
        $unavailable = [];
        foreach ($fileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable) {
                $unavailable[$fileUid] = 'uid ' . $fileUid;
                continue;
            }
            try {
                $onDisk = !$file->isMissing() && $file->exists();
            } catch (\Throwable) {
                $onDisk = false;
            }
            if (!$onDisk) {
                $unavailable[$fileUid] = $file->getName() . ' (uid ' . $fileUid . ')';
            }
        }

        return $unavailable;
    }

    /**
     * Info message for the turn, or null when every named file can be used.
     *
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    public function messageFor(string $request, AgentTranslator $translator, string $correlationId): ?array
    {
        $named = self::namedFileUids($request);
        $unavailable = $named !== [] ? $this->unavailable($named) : [];
        if ($unavailable === []) {
            return null;
        }

        $usable = array_values(array_diff($named, array_keys($unavailable)));
        $content = $usable === []
            ? $translator->translate('agent.turn.filesUnavailable', [implode(', ', $unavailable)])
            : $translator->translate('agent.turn.filesPartlyUnavailable', [
                implode(', ', $unavailable),
                implode(', ', array_map(static fn(int $uid): string => 'uid ' . $uid, $usable)),
            ]);

        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => [
                'type' => self::MESSAGE_TYPE,
                'correlationId' => $correlationId,
                'request' => self::requestKey($request),
                'unavailableFiles' => $unavailable,
                'noFileLeft' => count($unavailable) === count($named),
            ],
        ];
    }

    /**
     * Whether none of the files named in this request can be attached.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function noFileLeftToAttach(array $history, string $request): bool
    {
        $meta = self::latestMessageMeta($history, $request);
        if ($meta !== null && ($meta['noFileLeft'] ?? false) === true) {
            return true;
        }
        $named = self::namedFileUids($request);

        return $named !== [] && array_diff($named, array_keys(self::knownUnavailable($history, $request))) === [];
    }

    /**
     * Files named in this request that can be attached (not known to be missing).
     *
     * @param list<array<string, mixed>> $history
     * @return list<int>
     */
    public static function attachableNamedFileUids(array $history, string $request): array
    {
        $named = self::namedFileUids($request);
        if ($named === []) {
            return [];
        }

        return array_values(array_diff($named, array_keys(self::knownUnavailable($history, $request))));
    }

    /**
     * Instruction for the model while the request names unavailable files.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function promptNote(array $history, string $request): string
    {
        if ((self::latestMessageMeta($history, $request)['imageGenerationUnavailable'] ?? false) === true) {
            return 'The editor asked for an AI-generated image, but AI image generation is not available to this editor.'
                . ' Do not search, invent, download or upload an image from the web instead, and do not attach any file.'
                . ' Do the rest of the request, then tell the editor in one sentence that the image could not be generated.';
        }
        $unavailable = self::knownUnavailable($history, $request);
        if ($unavailable === []) {
            return '';
        }

        return 'These files named by the editor do not exist or are missing from the file storage: '
            . implode(', ', array_map('strval', $unavailable)) . '.'
            . ' Do not attach them, and do not upload, download or generate a replacement for them.'
            . ' Do the rest of the request, then tell the editor in one sentence which files are missing.';
    }

    /**
     * Files of this request known to be unavailable: from the notice, or from file_reference_add
     * refusing them while working on the request.
     *
     * @param list<array<string, mixed>> $history
     * @return array<int, string> uid => label
     */
    private static function knownUnavailable(array $history, string $request): array
    {
        $meta = self::latestMessageMeta($history, $request);
        $unavailable = [];
        foreach (is_array($meta['unavailableFiles'] ?? null) ? $meta['unavailableFiles'] : [] as $uid => $label) {
            $unavailable[(int) $uid] = (string) $label;
        }
        if (trim($request) === '') {
            return $unavailable;
        }
        foreach (AgentRequestChecklist::historySinceLatestUserRequest($history, $request) as $entry) {
            $entryMeta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($entryMeta['type'] ?? '') !== 'tool_result' || ($entryMeta['tool'] ?? '') !== 'file_reference_add' || ($entryMeta['success'] ?? true) !== false) {
                continue;
            }
            $content = (string) ($entry['content'] ?? '');
            if (preg_match_all('/The file (.+?) \(sys_file uid (\d+)\) is missing from the storage/u', $content, $missing, PREG_SET_ORDER) > 0) {
                foreach ($missing as $match) {
                    $unavailable[(int) $match[2]] ??= $match[1] . ' (uid ' . $match[2] . ')';
                }
            }
            if (preg_match_all('/There is no file with sys_file uid (\d+)/u', $content, $unknown) > 0) {
                foreach ($unknown[1] as $uid) {
                    $unavailable[(int) $uid] ??= 'uid ' . $uid;
                }
            }
        }

        return $unavailable;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>|null
     */
    private static function latestMessageMeta(array $history, string $request): ?array
    {
        $key = self::requestKey($request);
        if ($key === '') {
            return null;
        }
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') === self::MESSAGE_TYPE && ($meta['request'] ?? '') === $key) {
                return $meta;
            }
        }

        return null;
    }

    private static function requestKey(string $request): string
    {
        return mb_substr(trim($request), 0, 200);
    }
}
