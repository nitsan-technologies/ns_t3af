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

namespace NITSAN\NsT3AF\AiLabel\EventListener;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\IptcDigitalSourceTypeService;
use TYPO3\CMS\Core\Resource\Event\AfterFileProcessingEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Preserve IPTC DigitalSourceType on processed files; optional EU stamp when written_in.
 */
final class AfterFileProcessingListener
{
    public function __construct(
        private readonly IptcDigitalSourceTypeService $iptcDigitalSourceTypeService,
        private readonly AiLabelSettingsService $settingsService,
        private readonly ConfirmationService $confirmationService,
    ) {}

    public function __invoke(AfterFileProcessingEvent $event): void
    {
        $original = $event->getFile();
        $processed = $event->getProcessedFile();
        if (!$original instanceof File || !$processed->exists()) {
            return;
        }

        if (!str_starts_with((string) $processed->getMimeType(), 'image/')) {
            return;
        }

        try {
            $path = $processed->getForLocalProcessing(true);
        } catch (\Throwable) {
            return;
        }

        $touched = $this->iptcDigitalSourceTypeService->copyDigitalSourceType($original, $path);
        $touched = $this->maybeStamp($original, $path) || $touched;

        if ($touched) {
            $processed->updateWithLocalFile($path);
            $event->setProcessedFile($processed);
        }
    }

    private function maybeStamp(File $original, string $targetPath): bool
    {
        $settings = $this->settingsService->all();
        if (($settings['markImageFile'] ?? '') !== 'written_in' || !$this->iptcDigitalSourceTypeService->imagickAvailable()) {
            return false;
        }

        $meta = $original->getMetaData()->get();
        $metaUid = (int) ($meta['uid'] ?? 0);
        if ($metaUid <= 0 || !$this->confirmationService->isConfirmed('sys_file_metadata', $metaUid)) {
            return false;
        }

        $involvement = Involvement::tryFrom((string) ($meta['tx_nst3af_ailabel_involvement'] ?? ''))
            ?? Involvement::NotReviewed;
        if ($involvement !== Involvement::AiGenerated && $involvement !== Involvement::AiModified) {
            return false;
        }

        $iconRel = $involvement === Involvement::AiModified
            ? 'EXT:ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI MODIFIED_white.svg'
            : 'EXT:ns_t3af/Resources/Public/Icons/EuAiLabel/LABEL_AI GENERATED_white.svg';
        $iconPath = GeneralUtility::getFileAbsFileName($iconRel);
        if ($iconPath === '' || !is_file($iconPath)) {
            return false;
        }

        try {
            $canvas = new \Imagick($targetPath);
            $stamp = new \Imagick();
            $stamp->setBackgroundColor(new \ImagickPixel('transparent'));
            $stamp->readImage($iconPath);
            $geometry = $canvas->getImageGeometry();
            $maxWidth = max(24, (int) round(((int) ($geometry['width'] ?? 100)) * 0.18));
            $stamp->thumbnailImage($maxWidth, 0);
            $margin = 8;
            $stampW = $stamp->getImageWidth();
            $stampH = $stamp->getImageHeight();
            $canvasW = (int) ($geometry['width'] ?? $stampW);
            $canvasH = (int) ($geometry['height'] ?? $stampH);
            [$x, $y] = $this->stampOffset(
                (string) ($settings['labelPosition'] ?? 'bottom_right'),
                $canvasW,
                $canvasH,
                $stampW,
                $stampH,
                $margin,
            );
            $canvas->compositeImage($stamp, \Imagick::COMPOSITE_OVER, $x, $y);
            $canvas->writeImage($targetPath);
            $stamp->clear();
            $canvas->clear();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function stampOffset(
        string $position,
        int $canvasW,
        int $canvasH,
        int $stampW,
        int $stampH,
        int $margin,
    ): array {
        return match ($position) {
            'bottom_left' => [$margin, max(0, $canvasH - $stampH - $margin)],
            'top_right' => [max(0, $canvasW - $stampW - $margin), $margin],
            'top_left' => [$margin, $margin],
            default => [max(0, $canvasW - $stampW - $margin), max(0, $canvasH - $stampH - $margin)],
        };
    }
}
