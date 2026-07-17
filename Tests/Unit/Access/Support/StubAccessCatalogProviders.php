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

namespace NITSAN\NsT3AF\Tests\Unit\Access\Support;

use NITSAN\NsT3AF\Access\Dto\BulkTabBinding;
use NITSAN\NsT3AF\Access\Dto\CardFeatureRule;
use NITSAN\NsT3AF\Access\Dto\FeatureAccessBindingsDescriptor;
use NITSAN\NsT3AF\Access\Dto\FeaturePermissionDescriptor;
use NITSAN\NsT3AF\Access\Dto\ModuleAccessDescriptor;
use NITSAN\NsT3AF\Access\Dto\RecordPermissionDescriptor;
use NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface;

/**
 * In-package catalog stubs so ns_t3af unit tests stay standalone.
 */
final class StubAccessCatalogProviders
{
    /**
     * @return list<AiAccessCatalogProviderInterface>
     */
    public static function all(): array
    {
        return [
            new StubT3AiAccessCatalogProvider(),
            new StubT3AaAccessCatalogProvider(),
            new StubT3CsAccessCatalogProvider(),
        ];
    }
}

final class StubT3AiAccessCatalogProvider implements AiAccessCatalogProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getCatalogModuleKey(): string
    {
        return 't3ai';
    }

    public function getModuleAccess(): ?ModuleAccessDescriptor
    {
        return new ModuleAccessDescriptor(
            'T3AI',
            'AI Assistant',
            'AI-powered content writing, SEO, translation, media alt texts and prompt templates.',
            '#1a56db',
            'nitsan_nst3ai_dashboard',
            'ns_t3ai',
        );
    }

    public function getFeaturePermissions(): array
    {
        return [
            new FeaturePermissionDescriptor('content', 'Content Generation', 'Rewrite, summarise and generate body text.', 'Content', ['t3ai'], 't3ai', 'level', 'ns_t3ai'),
            new FeaturePermissionDescriptor('seo', 'SEO Tools', 'Generate meta descriptions, OG tags and SEO audits.', 'SEO', ['t3ai'], 't3ai', 'level', 'ns_t3ai'),
            new FeaturePermissionDescriptor('translation', 'Translation', 'Translate content to other languages.', 'Translation', ['t3ai'], 't3ai', 'level', 'ns_t3ai'),
            new FeaturePermissionDescriptor('media', 'Media & Alt Texts', 'Generate AI alt texts and captions.', 'Media', ['t3ai'], 't3ai', 'level', 'ns_t3ai'),
            new FeaturePermissionDescriptor('prompts', 'Prompt Templates', 'Use or manage prompt templates.', 'Prompts', ['t3ai'], 't3ai', 'level', 'ns_t3ai'),
            new FeaturePermissionDescriptor('bulkOps', 'Bulk Operations', 'Run AI actions across multiple pages simultaneously.', 'Pages', ['t3ai'], 't3ai', 'bulk', 'ns_t3ai'),
        ];
    }

    public function getRecordPermissions(): array
    {
        return [
            new RecordPermissionDescriptor('pageContent', 'Page Content Elements', ['tt_content'], ['t3ai'], ['translation', 'content'], 'View content elements in the Page module', 'Edit and translate page content (required for Re-translate in Page module)'),
            new RecordPermissionDescriptor('translationGlossary', 'Translation Glossary', ['tx_nst3ai_domain_model_glossary'], ['t3ai'], ['translation'], 'View glossary terms', 'Manage glossary', 'ns_t3ai'),
            new RecordPermissionDescriptor('rteCommands', 'RTE AI Commands', ['tx_nst3af_ai_prompt'], ['t3ai'], ['content'], 'Use RTE commands', 'Manage RTE command groups', 'ns_t3ai'),
            new RecordPermissionDescriptor('bulkTranslation', 'Bulk Translation Jobs', ['tx_nst3ai_domain_model_bulktranslation'], ['t3ai'], ['translation', 'bulkOps'], 'View bulk jobs', 'Manage bulk translation jobs', 'ns_t3ai'),
            new RecordPermissionDescriptor('bulkSeo', 'Bulk SEO Jobs', ['tx_nst3ai_domain_model_bulkseo'], ['t3ai'], ['seo', 'bulkOps'], 'View bulk SEO jobs', 'Manage bulk SEO jobs', 'ns_t3ai'),
            new RecordPermissionDescriptor('schemaMarkup', 'Schema Markup Records', ['tx_nst3ai_domain_model_schema'], ['t3ai'], ['seo'], 'View schema records', 'Manage schema markup', 'ns_t3ai'),
        ];
    }

    public function getFeatureAccessBindings(): FeatureAccessBindingsDescriptor
    {
        return new FeatureAccessBindingsDescriptor(
            moduleKey: 't3ai',
            legacyCardPermPrefix: 'tx_t3ai_',
            moduleGroupMod: 'nitsan_nst3ai_',
            alwaysOpenTabs: ['resources', 'settings', 'wishlist'],
            tabFeatureMap: [
                'content' => 'Content',
                'seo' => 'SEO',
                'translation' => 'Translation',
                'media' => 'Media',
                'pages' => 'Pages',
                'prompts' => 'Prompts',
                'dashboard' => null,
            ],
            bulkTabBindings: [
                'bulktranslation' => new BulkTabBinding('translation', 'Pages', 'tx_t3ai_bulktranslation:bulktranslation'),
                'bulkseo' => new BulkTabBinding('seo', 'Pages', 'tx_t3ai_bulkseo:bulkseo'),
            ],
            manageableBaseFeatures: ['Content', 'SEO', 'Translation', 'Media', 'Prompts', 'Pages'],
            legacyPermissionFallbacks: [
                'Content' => ['tx_t3ai_content:content'],
                'SEO' => ['tx_t3ai_seo:seoAll'],
                'Translation' => ['tx_t3ai_translation:translationPages'],
                'Media' => ['tx_t3ai_media:mediaAiImages'],
                'Prompts' => ['tx_t3ai_content:contentAssistant'],
                'Pages' => ['tx_t3ai_pages:pageTree'],
            ],
            moduleGrantedTabs: ['dashboard', 'seo', 'pages', 'content', 'translation', 'media'],
            grantsCapabilities: true,
            featureRecordDefaults: [
                ['featureId' => 'translation', 'recordId' => 'translationGlossary'],
                ['featureId' => 'translation', 'recordId' => 'pageContent'],
                ['featureId' => 'translation', 'recordId' => 'bulkTranslation', 'requiresBulkOps' => true],
                ['featureId' => 'seo', 'recordId' => 'schemaMarkup'],
                ['featureId' => 'seo', 'recordId' => 'bulkSeo', 'requiresBulkOps' => true],
                ['featureId' => 'content', 'recordId' => 'pageContent'],
                ['featureId' => 'content', 'recordId' => 'rteCommands'],
                ['featureId' => 'prompts', 'recordId' => 'aiPromptStorage'],
            ],
        );
    }
}

final class StubT3AaAccessCatalogProvider implements AiAccessCatalogProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3aa';
    }

    public function getCatalogModuleKey(): string
    {
        return 't3aa';
    }

    public function getModuleAccess(): ?ModuleAccessDescriptor
    {
        return new ModuleAccessDescriptor(
            'T3AA',
            'AI Accessibility',
            'Automated WCAG accessibility scanning, AI file metadata, and media generation.',
            '#f97316',
            'nitsan_nst3aa_dashboard',
            'ns_t3aa',
        );
    }

    public function getFeaturePermissions(): array
    {
        return [
            new FeaturePermissionDescriptor('t3aaPageSpeed', 'PageSpeed & Accessibility', 'Run Lighthouse accessibility checks and inspect PageSpeed results.', 'T3AA.PageSpeed', ['t3aa'], 't3aa', 'level', 'ns_t3aa'),
            new FeaturePermissionDescriptor('t3aaFileMeta', 'File Metadata & Alt Text', 'Generate AI alt text, metadata, and bulk file metadata jobs.', 'T3AA.FileMeta', ['t3aa'], 't3aa', 'level', 'ns_t3aa'),
            new FeaturePermissionDescriptor('t3aaMedia', 'AI Audio & Voiceover', 'Generate audio and voiceover assets for pages.', 'T3AA.Media', ['t3aa'], 't3aa', 'level', 'ns_t3aa'),
        ];
    }

    public function getRecordPermissions(): array
    {
        return [
            new RecordPermissionDescriptor('t3aaFileMetadata', 'File Metadata (FAL)', ['sys_file', 'sys_file_metadata'], ['t3aa'], ['t3aaFileMeta'], 'View file metadata in the file list', 'Generate and save AI file metadata', 'ns_t3aa'),
            new RecordPermissionDescriptor('t3aaBulkMeta', 'Bulk Metadata Jobs', ['tx_nst3aa_domain_model_bulkmeta'], ['t3aa'], ['t3aaFileMeta'], 'View bulk metadata jobs', 'Manage bulk metadata jobs', 'ns_t3aa'),
        ];
    }

    public function getFeatureAccessBindings(): FeatureAccessBindingsDescriptor
    {
        return new FeatureAccessBindingsDescriptor(
            moduleKey: 't3aa',
            legacyCardPermPrefix: 'tx_t3aa_',
            moduleGroupMod: 'nitsan_nst3aa_dashboard',
            defaultTabFeature: 'T3AA',
            alwaysOpenTabs: ['resources', 'settings', 'wishlist'],
            tabFeatureMap: ['dashboard' => 'T3AA'],
            cardFeatureRules: [
                new CardFeatureRule('dashboard', 'seospeed', 'T3AA.PageSpeed'),
                new CardFeatureRule('dashboard', 'speed', 'T3AA.PageSpeed'),
                new CardFeatureRule('dashboard', 'meta', 'T3AA.FileMeta'),
                new CardFeatureRule('dashboard', 'file', 'T3AA.FileMeta'),
                new CardFeatureRule('dashboard', 'audio', 'T3AA.Media'),
                new CardFeatureRule('dashboard', 'voice', 'T3AA.Media'),
            ],
            manageableFullFeatures: ['T3AA.PageSpeed', 'T3AA.FileMeta', 'T3AA.Media'],
            grantDashboardViaModuleGroup: true,
            suiteBaseFeature: 'T3AA',
            grantsCapabilities: true,
            legacyPermissionFallbacks: [
                'T3AA' => ['tx_t3aa_dashboard:seoSpeedCore', 'tx_t3aa_dashboard:dashboard'],
                'T3AA.PageSpeed' => ['tx_t3aa_dashboard:seoSpeedCore'],
                'T3AA.FileMeta' => [
                    'tx_t3aa_dashboard:aiFileMetaVision',
                    'tx_t3aa_dashboard:aiFileMetaAltText',
                    'tx_t3aa_dashboard:aiFileMetaBulk',
                ],
                'T3AA.Media' => [
                    'tx_t3aa_dashboard:mediaAiAudio',
                    'tx_t3aa_dashboard:mediaAiVoiceOver',
                ],
            ],
            legacyDeserializerAliases: [
                'T3AA.Scan' => ['t3aaPageSpeed', 't3aaFileMeta', 't3aaMedia'],
                'T3AA.Autofix' => ['t3aaFileMeta'],
                'T3AA.Reports' => ['t3aaPageSpeed'],
                'T3AA.Scheduled' => ['t3aaFileMeta'],
            ],
            featureRecordDefaults: [
                ['featureId' => 't3aaFileMeta', 'recordId' => 't3aaFileMetadata'],
                ['featureId' => 't3aaFileMeta', 'recordId' => 't3aaBulkMeta'],
                ['featureId' => 't3aaFileMeta', 'recordId' => 'aiPromptStorage'],
            ],
        );
    }
}

final class StubT3CsAccessCatalogProvider implements AiAccessCatalogProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3cs';
    }

    public function getCatalogModuleKey(): string
    {
        return 't3cs';
    }

    public function getModuleAccess(): ?ModuleAccessDescriptor
    {
        return new ModuleAccessDescriptor(
            'T3AC / T3AS',
            'AI Chatbot/Search',
            'AI chatbot widget, semantic search indexing and conversation analytics.',
            '#16a34a',
            'nitsan_nst3cs_t3cs',
            'ns_t3cs',
        );
    }

    public function getFeaturePermissions(): array
    {
        return [
            new FeaturePermissionDescriptor('t3csChat', 'Chat Widget Config', 'Configure chatbot appearance and behaviour.', 'T3CS.Chat', ['t3cs'], 't3cs', 'level', 'ns_t3cs'),
            new FeaturePermissionDescriptor('t3csSearch', 'Search Widget Config', 'Configure search widget appearance and behaviour.', 'T3CS.Search', ['t3cs'], 't3cs', 'level', 'ns_t3cs'),
            new FeaturePermissionDescriptor('t3csIndex', 'Data Sources', 'Manage data sources, source groups, and training queue.', 'T3CS.Index', ['t3cs'], 't3cs', 'level', 'ns_t3cs'),
            new FeaturePermissionDescriptor('t3csAnalytics', 'Usage Analytics', 'View chat and search logs, query analytics, and conversation history.', 'T3CS.Analytics', ['t3cs'], 't3cs', 'level', 'ns_t3cs'),
        ];
    }

    public function getRecordPermissions(): array
    {
        return [
            new RecordPermissionDescriptor('t3csDatasource', 'Data Sources', ['tx_nst3cs_domain_model_datasource'], ['t3cs'], ['t3csIndex'], 'View data sources', 'Create, edit and sync data sources', 'ns_t3cs'),
            new RecordPermissionDescriptor('t3csSourceGroup', 'Source Groups', ['tx_nst3cs_domain_model_sourcegroup'], ['t3cs'], ['t3csIndex'], 'View source groups', 'Manage source groups', 'ns_t3cs'),
            new RecordPermissionDescriptor('t3csDatasourceQueue', 'Training Queue', ['tx_nst3cs_domain_model_datasource_queue'], ['t3cs'], ['t3csIndex'], 'View queue status', 'Re-queue and clear failed items', 'ns_t3cs'),
            new RecordPermissionDescriptor('t3csDatasourceEmbedd', 'Search Embeddings', ['tx_nst3cs_domain_model_datasource_embedd'], ['t3cs'], ['t3csIndex'], 'Inspect embedding chunks', 'Delete or rebuild embeddings', 'ns_t3cs'),
            new RecordPermissionDescriptor('t3csChatbot', 'Chatbot Configuration', ['tx_nst3ac_domain_model_chatbot'], ['t3cs'], ['t3csChat'], 'View chatbot records', 'Edit chatbot configuration', 'ns_t3ac'),
            new RecordPermissionDescriptor('t3csChatbotChat', 'Chat Sessions', ['tx_nst3ac_domain_model_chatbot_chat'], ['t3cs'], ['t3csChat', 't3csAnalytics'], 'View chat sessions', 'Manage sessions', 'ns_t3ac'),
            new RecordPermissionDescriptor('t3csChatbotMessages', 'Chat Messages', ['tx_nst3ac_domain_model_chatbot_messages'], ['t3cs'], ['t3csAnalytics'], 'Read conversations', 'Delete or moderate messages', 'ns_t3ac'),
            new RecordPermissionDescriptor('t3csChatbotHistory', 'Chat Visitor History', ['tx_nst3ac_domain_model_chatbot_history'], ['t3cs'], ['t3csAnalytics'], 'View visitor history', 'Purge history', 'ns_t3ac'),
            new RecordPermissionDescriptor('t3csSearchSettings', 'Search Widget Settings', ['tx_nst3as_domain_model_settings'], ['t3cs'], ['t3csSearch'], 'View search settings', 'Edit search widget configuration', 'ns_t3as'),
            new RecordPermissionDescriptor('t3csPredefinedQuestions', 'Predefined Questions', ['tx_nst3as_domain_model_predefinedquestions'], ['t3cs'], ['t3csSearch'], 'View suggested questions', 'Manage predefined questions', 'ns_t3as'),
            new RecordPermissionDescriptor('t3csSearchHistory', 'Search Query History', ['tx_nst3as_domain_model_searchhistory'], ['t3cs'], ['t3csAnalytics'], 'View search history', 'Delete or export history', 'ns_t3as'),
        ];
    }

    public function getFeatureAccessBindings(): FeatureAccessBindingsDescriptor
    {
        return new FeatureAccessBindingsDescriptor(
            moduleKey: 't3cs',
            legacyCardPermPrefix: 'tx_t3cs_',
            moduleGroupMod: 'nitsan_nst3cs_t3cs',
            defaultTabFeature: 'T3CS',
            suiteTabFeatureMap: [
                'Dashboard' => 'T3CS.Index',
                'DataSource' => 'T3CS.Index',
                'TrainingCenter' => 'T3CS.Index',
                'Chatbot' => 'T3CS.Chat',
                'Search' => 'T3CS.Search',
                'UsageAnalytics' => 'T3CS.Analytics',
            ],
            alternateTabFeatures: [
                'UsageAnalytics' => ['T3CS.Logs'],
            ],
            manageableFullFeatures: [
                'T3CS.Chat',
                'T3CS.Search',
                'T3CS.Index',
                'T3CS.Analytics',
                'T3CS.Logs',
            ],
            openWhenNoFeatureBits: true,
            featureBitPrefix: 'T3CS.',
            suiteBaseFeature: 'T3CS',
            grantsCapabilities: true,
            legacyPermissionFallbacks: [
                'T3CS' => ['tx_t3cs_dashboard:dashboard'],
                'T3CS.Chat' => ['tx_t3cs_dashboard:dashboard'],
                'T3CS.Search' => ['tx_t3cs_dashboard:dashboard'],
                'T3CS.Index' => ['tx_t3cs_dashboard:dashboard'],
                'T3CS.Logs' => ['tx_t3cs_dashboard:dashboard'],
                'T3CS.Analytics' => ['tx_t3cs_dashboard:dashboard'],
            ],
            recordAreaCatalogIds: [
                'dataSource' => ['t3csDatasource', 't3csSourceGroup'],
                'trainingCenter' => ['t3csDatasourceQueue', 't3csDatasourceEmbedd'],
                'search' => ['t3csSearchSettings', 't3csPredefinedQuestions'],
                'chatbot' => ['t3csChatbot'],
                'usageAnalyticsSearch' => ['t3csSearchHistory'],
                'usageAnalyticsChat' => ['t3csChatbotMessages', 't3csChatbotHistory'],
            ],
        );
    }
}
