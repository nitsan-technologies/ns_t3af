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

namespace NITSAN\NsT3AF\EventListener;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextLineage;
use NITSAN\NsT3AF\Service\BrandContextPlaceholderService;
use NITSAN\NsT3AF\Service\BrandContextResolver;

/**
 * Injects site default brand context into prompts before provider calls.
 *
 * @internal
 */
final class BrandContextPromptInjectionListener
{
    public function __construct(
        private readonly BrandContextResolver $resolver,
        private readonly BrandContextPlaceholderService $placeholders,
        private readonly BrandContextAssembler $assembler,
    ) {}

    public function __invoke(BeforeProviderRequestEvent $event): void
    {
        if ($event->isCancelled()) {
            return;
        }

        $options = $event->getOptions();
        if ($this->shouldSkip($options)) {
            return;
        }

        $scope = $this->resolveScope($options);
        $profile = $this->resolver->resolveForPageId(
            $options->pageId,
            $options->extensionKey,
            $scope,
        );
        if ($profile === null) {
            // No profile resolves: strip all known brand tokens so literal
            // `{brand_*}` / `[brand_profile]` placeholders never reach the model.
            $this->stripBrandTokens($event);
            return;
        }

        // Lineage stamp for request log + AiResponse echo (CTX-13 / CTX-15).
        // Applied for every call kind (complete/stream/embed/tts/image).
        $options = $this->withBrandProfileUid($options, $profile->uid);
        $event->setOptions($options);

        $brandBlock = $this->assembler->assemble($profile);
        $map = $this->placeholders->buildMap($profile);
        $map['{brand_context}'] = $brandBlock;
        // [brand_profile] is the explicit inline token used by the prompt catalog:
        // wherever it appears it expands to the full assembled brand block.
        $map['[brand_profile]'] = $brandBlock;

        // When the request carries an explicit [brand_profile] token, the brand
        // block is delivered inline; the separate system-block injection is then
        // suppressed to avoid duplicating the brand context.
        $hasBrandProfileToken = str_contains($event->getPrompt(), '[brand_profile]')
            || $this->messagesContainToken($options, '[brand_profile]');

        // Substitute tokens in the plain prompt string. Used by callers that do
        // not pass chat messages via AiOptions->extra['messages'].
        $event->setPrompt($this->placeholders->replace($event->getPrompt(), $map));

        // The system-block injection below only makes sense for chat-style calls;
        // embed / tts / image_generation get token substitution only.
        if (!in_array($event->callKind, ['complete', 'stream'], true)) {
            return;
        }

        // Primary path: AiService builds the provider request from
        // extra['messages'] (the plain prompt string and options.systemPrompt are
        // ignored there). Substitute tokens inside those messages and inject the
        // brand context as a system message so it actually reaches the provider.
        $messages = $this->extractMessages($options);
        if ($messages !== null) {
            $messages = $this->replaceInMessages($messages, $map);
            if (!$hasBrandProfileToken && $brandBlock !== '') {
                $messages = $this->injectBrandSystemMessage($messages, $brandBlock);
            }
            $event->setOptions($this->withMessages($options, $messages));
            return;
        }

        // Fallback path: no chat messages. When [brand_profile] was present it has
        // already been expanded inline into the prompt, so skip systemPrompt injection.
        if ($hasBrandProfileToken) {
            return;
        }

        // Caller-supplied systemPrompt (empty string treated like null): resolve
        // brand tokens inside it and prepend the brand block, mirroring the
        // messages path, so an explicit systemPrompt no longer silently drops
        // brand context (CTX-03).
        $callerSystem = trim((string) $options->systemPrompt);
        if ($callerSystem !== '') {
            $resolved = $this->placeholders->replace($callerSystem, $map);
            if ($brandBlock !== '' && !str_contains($resolved, $brandBlock)) {
                $resolved = $brandBlock . "\n\n" . $resolved;
            }
            $event->setOptions($this->withSystemPrompt($options, $resolved));
            return;
        }

        $providerSystem = trim($event->provider->systemPrompt);
        $providerSystem = $providerSystem !== '' ? $this->placeholders->replace($providerSystem, $map) : '';
        if ($brandBlock === '') {
            return;
        }

        $combined = $providerSystem !== '' ? $brandBlock . "\n\n" . $providerSystem : $brandBlock;
        $event->setOptions($this->withSystemPrompt($options, $combined));
    }

    /**
     * Replaces every known brand token with an empty string in the prompt, the
     * chat messages, and a caller-supplied systemPrompt (CTX-04).
     */
    private function stripBrandTokens(BeforeProviderRequestEvent $event): void
    {
        $map = $this->placeholders->buildEmptyMap();
        $event->setPrompt($this->placeholders->replace($event->getPrompt(), $map));

        $options = $event->getOptions();
        $messages = $this->extractMessages($options);
        if ($messages !== null) {
            $options = $this->withMessages($options, $this->replaceInMessages($messages, $map));
        }

        $systemPrompt = $options->systemPrompt;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $stripped = $this->placeholders->replace($systemPrompt, $map);
            if ($stripped !== $systemPrompt) {
                $options = $this->withSystemPrompt($options, $stripped);
            }
        }

        if ($options !== $event->getOptions()) {
            $event->setOptions($options);
        }
    }

    /**
     * Returns the chat messages stored on AiOptions->extra['messages'] as a list,
     * or null when no usable messages are present.
     *
     * @return list<mixed>|null
     */
    private function extractMessages(AiOptions $options): ?array
    {
        $extra = $options->extra;
        $messages = $extra['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            return null;
        }

        return array_values($messages);
    }

    /**
     * Returns true when any chat message content contains the given token.
     */
    private function messagesContainToken(AiOptions $options, string $token): bool
    {
        $messages = $this->extractMessages($options);
        if ($messages === null) {
            return false;
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (is_string($content) && str_contains($content, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace brand placeholder tokens inside each (string) message content.
     *
     * @param list<mixed> $messages
     * @param array<string, string> $map
     * @return list<mixed>
     */
    private function replaceInMessages(array $messages, array $map): array
    {
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (is_string($content) && $content !== '') {
                $messages[$index]['content'] = $this->placeholders->replace($content, $map);
            }
        }

        return array_values($messages);
    }

    /**
     * Prepend the brand block to the first system message, or insert a new system
     * message at the front when none exists.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    private function injectBrandSystemMessage(array $messages, string $brandBlock): array
    {
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = trim((string) ($message['role'] ?? ''));
            if ($role !== 'system') {
                continue;
            }
            $existing = $message['content'] ?? '';
            if (is_string($existing) && $existing !== '') {
                if (!str_contains($existing, $brandBlock)) {
                    $messages[$index]['content'] = $brandBlock . "\n\n" . $existing;
                }
            } else {
                $messages[$index]['content'] = $brandBlock;
            }

            return array_values($messages);
        }

        array_unshift($messages, ['role' => 'system', 'content' => $brandBlock]);

        return array_values($messages);
    }

    private function shouldSkip(AiOptions $options): bool
    {
        return !empty($options->extra['skipBrandContext']);
    }

    /**
     * The calling extension (e.g. ns_t3ai) tags the feature scope on AiOptions->extra
     * so the resolver can pick the per-feature brand context profile override.
     */
    private function resolveScope(AiOptions $options): ?string
    {
        $extra = $options->extra;
        if (isset($extra['brandContextScope']) && is_string($extra['brandContextScope'])) {
            return $extra['brandContextScope'];
        }

        return null;
    }

    private function withSystemPrompt(AiOptions $options, string $systemPrompt): AiOptions
    {
        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: $options->requestSource,
            contentEntityType: $options->contentEntityType,
            contentEntityUid: $options->contentEntityUid,
            pageId: $options->pageId,
            requestUuid: $options->requestUuid,
            extra: $options->extra,
        );
    }

    private function withBrandProfileUid(AiOptions $options, int $profileUid): AiOptions
    {
        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $options->systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: $options->requestSource,
            contentEntityType: $options->contentEntityType,
            contentEntityUid: $options->contentEntityUid,
            pageId: $options->pageId,
            requestUuid: $options->requestUuid,
            extra: BrandContextLineage::stampExtra($options->extra, $profileUid),
        );
    }

    /**
     * @param list<mixed> $messages
     */
    private function withMessages(AiOptions $options, array $messages): AiOptions
    {
        $extra = $options->extra;
        $extra['messages'] = $messages;

        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $options->systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: $options->requestSource,
            contentEntityType: $options->contentEntityType,
            contentEntityUid: $options->contentEntityUid,
            pageId: $options->pageId,
            requestUuid: $options->requestUuid,
            extra: $extra,
        );
    }
}
