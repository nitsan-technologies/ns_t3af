<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

namespace NITSAN\NsT3AF\Mcp\Service;

readonly class TcaSchemaService
{
    /** TCA types that store simple scalar values readable/writable via DataHandler. */
    private const VALUE_TYPES = [
        'input',
        'text',
        'number',
        'datetime',
        'email',
        'link',
        'color',
        'slug',
        'check',
        'radio',
        'json',
        'uuid',
        'country',
        'language',
        'flex',
        'passthrough',
    ];

    /** TCA types that may store simple values depending on configuration (no MM table). */
    private const CONDITIONAL_TYPES = [
        'select',
        'group',
    ];

    /** @return array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null} */
    public function getTranslationConfig(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        $ctrl = is_array($tca) ? ($tca['ctrl'] ?? []) : [];
        if (!is_array($ctrl)) {
            $ctrl = [];
        }

        $languageField = $ctrl['languageField'] ?? null;
        $transOrigPointerField = $ctrl['transOrigPointerField'] ?? null;
        $translationSource = $ctrl['translationSource'] ?? null;

        return [
            'languageField' => is_string($languageField) && $languageField !== '' ? $languageField : null,
            'transOrigPointerField' => is_string($transOrigPointerField) && $transOrigPointerField !== '' ? $transOrigPointerField : null,
            'translationSource' => is_string($translationSource) && $translationSource !== '' ? $translationSource : null,
        ];
    }

    /** @return list<string> Fields suitable for list views (uid, pid, label fields, enablecolumns.disabled). */
    public function getListFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return ['uid', 'pid'];
        }

        $ctrl = $tca['ctrl'] ?? [];
        if (!is_array($ctrl)) {
            return ['uid', 'pid'];
        }

        $fields = ['uid', 'pid'];

        $labelField = $ctrl['label'] ?? null;
        if (is_string($labelField) && $labelField !== '') {
            $fields[] = $labelField;
        }

        $labelAlt = $ctrl['label_alt'] ?? null;
        if (is_string($labelAlt) && $labelAlt !== '') {
            foreach (explode(',', $labelAlt) as $altField) {
                $altField = trim($altField);
                if ($altField !== '') {
                    $fields[] = $altField;
                }
            }
        }

        $enableColumns = $ctrl['enablecolumns'] ?? [];
        if (is_array($enableColumns)) {
            $disabled = $enableColumns['disabled'] ?? null;
            if (is_string($disabled) && $disabled !== '') {
                $fields[] = $disabled;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> All fields that can be read (simple value types + uid + pid + sortby). */
    public function getReadFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return ['uid', 'pid'];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return ['uid', 'pid'];
        }

        $systemFields = $this->getSystemFields($tca);
        $fields = ['uid', 'pid'];

        $sortField = $this->getSortField($tca);
        if ($sortField !== null) {
            $fields[] = $sortField;
        }

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if (in_array($fieldName, $systemFields, true)) {
                continue;
            }

            if ($this->isReadableField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> Fields that can be written (readable fields minus uid, pid, readOnly, system fields). */
    public function getWritableFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return [];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $systemFields = $this->getSystemFields($tca);
        $fields = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if (in_array($fieldName, $systemFields, true)) {
                continue;
            }

            if ($this->isWritableField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /** @return list<string> Field names that are file reference fields (TCA type 'file' or inline with sys_file_reference). */
    public function getFileFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return [];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $fields = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if ($this->isFileField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Returns detailed schema information for all readable fields of a table.
     *
     * @return array{table: string, fields: list<array<string, mixed>>}
     */
    public function getFieldsSchema(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return ['table' => $tableName, 'fields' => []];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return ['table' => $tableName, 'fields' => []];
        }

        $systemFields = $this->getSystemFields($tca);
        $fields = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if (in_array($fieldName, $systemFields, true)) {
                continue;
            }

            if (!$this->isReadableField($columnConfig)) {
                continue;
            }

            $fields[] = $this->buildFieldSchema($fieldName, $columnConfig);
        }

        return ['table' => $tableName, 'fields' => $fields];
    }

    /**
     * @param array<mixed> $columnConfig
     * @return array<string, mixed>
     */
    private function buildFieldSchema(string $fieldName, array $columnConfig): array
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return ['name' => $fieldName, 'type' => 'unknown'];
        }

        $type = $config['type'] ?? 'unknown';
        if (!is_string($type)) {
            $type = 'unknown';
        }

        $schema = ['name' => $fieldName, 'type' => $type];

        $label = $columnConfig['label'] ?? null;
        if (is_string($label) && $label !== '') {
            $schema['label'] = $label;
        }

        $description = $columnConfig['description'] ?? null;
        if (is_string($description) && $description !== '') {
            $schema['description'] = $description;
        }

        $readOnly = $config['readOnly'] ?? false;
        if ($readOnly === true) {
            $schema['readOnly'] = true;
        }

        $required = $config['required'] ?? false;
        if ($required === true) {
            $schema['required'] = true;
        }

        $this->addConstraints($schema, $config, $type);
        $this->addItems($schema, $config, $type);

        return $schema;
    }

    /**
     * @param array<string, mixed> &$schema
     * @param array<mixed> $config
     */
    private function addConstraints(array &$schema, array $config, string $type): void
    {
        $max = $config['max'] ?? null;
        if (is_int($max) && $max > 0) {
            $schema['max'] = $max;
        }

        $min = $config['min'] ?? null;
        if (is_int($min)) {
            $schema['min'] = $min;
        }

        $size = $config['size'] ?? null;
        if (is_int($size) && $size > 0) {
            $schema['size'] = $size;
        }

        $eval = $config['eval'] ?? null;
        if (is_string($eval) && $eval !== '') {
            $schema['eval'] = $eval;
        }

        $placeholder = $config['placeholder'] ?? null;
        if (is_string($placeholder) && $placeholder !== '') {
            $schema['placeholder'] = $placeholder;
        }

        $default = $config['default'] ?? null;
        if ($default !== null && (is_string($default) || is_int($default) || is_bool($default))) {
            $schema['default'] = $default;
        }

        if ($type === 'input' || $type === 'text' || $type === 'number') {
            $range = $config['range'] ?? null;
            if (is_array($range)) {
                $rangeData = [];
                $lower = $range['lower'] ?? null;
                if (is_int($lower)) {
                    $rangeData['lower'] = $lower;
                }
                $upper = $range['upper'] ?? null;
                if (is_int($upper)) {
                    $rangeData['upper'] = $upper;
                }
                if ($rangeData !== []) {
                    $schema['range'] = $rangeData;
                }
            }
        }

        if ($type === 'slug') {
            $generatorOptions = $config['generatorOptions'] ?? null;
            if (is_array($generatorOptions)) {
                $slugFields = $generatorOptions['fields'] ?? null;
                if (is_array($slugFields)) {
                    $schema['generatedFrom'] = $slugFields;
                }
            }
        }

        if ($type === 'check') {
            $items = $config['items'] ?? null;
            if (is_array($items) && $items !== []) {
                $checkboxLabels = [];
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $itemLabel = $item['label'] ?? $item[0] ?? null;
                        if (is_string($itemLabel)) {
                            $checkboxLabels[] = $itemLabel;
                        }
                    }
                }
                if ($checkboxLabels !== []) {
                    $schema['items'] = $checkboxLabels;
                }
            }
        }

        if ($type === 'datetime') {
            $dbType = $config['dbType'] ?? null;
            if (is_string($dbType) && $dbType !== '') {
                $schema['dbType'] = $dbType;
            }
            $format = $config['format'] ?? null;
            if (is_string($format) && $format !== '') {
                $schema['format'] = $format;
            }
        }

        if ($type !== 'link') {
            return;
        }

        $allowedTypes = $config['allowedTypes'] ?? null;
        if (is_array($allowedTypes) && $allowedTypes !== []) {
            $schema['allowedTypes'] = array_values($allowedTypes);
        }
    }

    /**
     * @param array<string, mixed> &$schema
     * @param array<mixed> $config
     */
    private function addItems(array &$schema, array $config, string $type): void
    {
        if ($type !== 'select' && $type !== 'radio') {
            return;
        }

        $renderType = $config['renderType'] ?? null;
        if (is_string($renderType) && $renderType !== '') {
            $schema['renderType'] = $renderType;
        }

        $items = $config['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $foreignTable = $config['foreign_table'] ?? null;
            if (is_string($foreignTable) && $foreignTable !== '') {
                $schema['foreignTable'] = $foreignTable;
            }

            return;
        }

        $parsedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemLabel = $item['label'] ?? $item[0] ?? null;
            $itemValue = $item['value'] ?? $item[1] ?? null;

            if ($itemValue === null && $itemLabel === null) {
                continue;
            }

            $entry = [];
            if (is_string($itemValue) || is_int($itemValue)) {
                $entry['value'] = $itemValue;
            }
            if (is_string($itemLabel) && $itemLabel !== '') {
                $entry['label'] = $itemLabel;
            }

            if ($entry !== []) {
                $parsedItems[] = $entry;
            }
        }

        if ($parsedItems !== []) {
            $schema['items'] = $parsedItems;
        }
    }

    /** @param array<mixed> $columnConfig */
    private function isFileField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }

        if ($type === 'file') {
            return true;
        }

        if ($type === 'inline') {
            $foreignTable = $config['foreign_table'] ?? null;

            return $foreignTable === 'sys_file_reference';
        }

        return false;
    }

    /** @param array<mixed> $columnConfig */
    private function isReadableField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }

        if (in_array($type, self::VALUE_TYPES, true)) {
            return true;
        }

        if (in_array($type, self::CONDITIONAL_TYPES, true)) {
            return !$this->hasMMTable($config);
        }

        return false;
    }

    /** @param array<mixed> $columnConfig */
    private function isWritableField(array $columnConfig): bool
    {
        if (!$this->isReadableField($columnConfig)) {
            return false;
        }

        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $readOnly = $config['readOnly'] ?? false;

        return $readOnly !== true;
    }

    /** @param array<mixed> $config */
    private function hasMMTable(array $config): bool
    {
        $mm = $config['MM'] ?? null;

        return is_string($mm) && $mm !== '';
    }

    /**
     * Returns system/internal field names that should be excluded from tool fields.
     *
     * @param array<mixed> $tca
     * @return list<string>
     */
    private function getSystemFields(array $tca): array
    {
        $ctrl = $tca['ctrl'] ?? [];
        if (!is_array($ctrl)) {
            return [];
        }

        $systemFields = [];

        $ctrlStringFields = [
            'tstamp',
            'crdate',
            'delete',
            'sortby',
            'translationSource',
            'origUid',
            'descriptionColumn',
        ];

        foreach ($ctrlStringFields as $ctrlKey) {
            $value = $ctrl[$ctrlKey] ?? null;
            if (is_string($value) && $value !== '') {
                $systemFields[] = $value;
            }
        }

        // enablecolumns (hidden, starttime, endtime, fe_group) are user-editable, not system fields

        // l10n_diffsource is always a system field
        $systemFields[] = 'l10n_diffsource';
        $systemFields[] = 'l10n_source';
        $systemFields[] = 't3ver_label';

        return array_values(array_unique($systemFields));
    }

    /**
     * Returns the table's sort field (TCA ctrl.sortby), if defined.
     *
     * @param array<mixed> $tca
     */
    private function getSortField(array $tca): ?string
    {
        $ctrl = $tca['ctrl'] ?? [];
        if (!is_array($ctrl)) {
            return null;
        }

        $sortBy = $ctrl['sortby'] ?? null;

        return is_string($sortBy) && $sortBy !== '' ? $sortBy : null;
    }

    /** @return array<mixed>|null */
    private function getTca(string $tableName): ?array
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return null;
        }

        $tableConfig = $tca[$tableName] ?? null;

        return is_array($tableConfig) ? $tableConfig : null;
    }
}
