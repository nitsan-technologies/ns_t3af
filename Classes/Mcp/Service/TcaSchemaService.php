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
 * file that was distributed with this source code.
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
        'category',
    ];

    /** TCA types that may store simple values depending on configuration. */
    private const CONDITIONAL_TYPES = [
        'select',
        'group',
    ];

    /** Discoverable relation containers (schema only; write path varies). */
    private const RELATION_CONTAINER_TYPES = [
        'file',
        'inline',
        'folder',
        'imageManipulation',
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
     * Relation fields writable as a comma-separated list of UIDs (category / MM select / MM group).
     *
     * @return list<string>
     */
    public function getRelationUidListFields(string $tableName): array
    {
        return $this->collectRelationUidListFields($tableName, requireWritable: true);
    }

    /**
     * Relation fields readable as UID lists (includes read-only category / MM select).
     *
     * @return list<string>
     */
    public function getReadableRelationUidListFields(string $tableName): array
    {
        return $this->collectRelationUidListFields($tableName, requireWritable: false);
    }

    /**
     * TCA `config` array for a column, or null if missing.
     *
     * @return array<string, mixed>|null
     */
    public function getColumnFieldConfig(string $tableName, string $fieldName): ?array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return null;
        }

        $columnConfig = $tca['columns'][$fieldName] ?? null;
        if (!is_array($columnConfig)) {
            return null;
        }

        $config = $columnConfig['config'] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * @return list<string>
     */
    private function collectRelationUidListFields(string $tableName, bool $requireWritable): array
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
            if (!$this->isRelationUidListField($columnConfig)) {
                continue;
            }
            if ($requireWritable && !$this->isWritableField($columnConfig)) {
                continue;
            }
            $fields[] = $fieldName;
        }

        return $fields;
    }

    /**
     * Non-file inline/collection fields (e.g. Content Blocks collections).
     *
     * @return list<string>
     */
    public function getCollectionFields(string $tableName): array
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
            if ($this->isCollectionField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Explain how to write an ignored field (collection / file / unknown).
     *
     * @return array{field: string, reason: string, hint: string, foreignTable?: string, foreignField?: string}
     */
    public function describeIgnoredField(string $tableName, string $fieldName): array
    {
        $tca = $this->getTca($tableName);
        $columnConfig = is_array($tca) ? ($tca['columns'][$fieldName] ?? null) : null;
        if (is_array($columnConfig)) {
            if ($this->isCollectionField($columnConfig)) {
                $config = is_array($columnConfig['config'] ?? null) ? $columnConfig['config'] : [];
                $foreignTable = is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : $fieldName;
                $foreignField = is_string($config['foreign_field'] ?? null) && $config['foreign_field'] !== ''
                    ? $config['foreign_field']
                    : 'foreign_table_parent_uid';

                return $this->collectionIgnoreInfo($fieldName, $foreignTable, $foreignField);
            }

            if ($this->isFileField($columnConfig)) {
                return [
                    'field' => $fieldName,
                    'reason' => 'file_field',
                    'hint' => 'Use file_reference_add, or write_table with '
                        . $fieldName . ' as [{"uid_local": <sys_file uid>, "alternative": "..."}] '
                        . '(full replace on update; [] clears). Do not set a bare integer counter.',
                ];
            }

            if ($this->isRelationUidListField($columnConfig)) {
                return [
                    'field' => $fieldName,
                    'reason' => 'relation',
                    'hint' => 'Pass a comma-separated list of UIDs as a string, e.g. "126" or "8,12".',
                ];
            }

            return [
                'field' => $fieldName,
                'reason' => 'not_writable',
                'hint' => 'Field exists in TCA but is not writable through write_table (readOnly or unsupported type).',
            ];
        }

        // Content Blocks convention: collection child table is often named like the parent column.
        $childTca = $this->getTca($fieldName);
        if ($childTca !== null) {
            $childColumns = $childTca['columns'] ?? [];
            if (is_array($childColumns) && isset($childColumns['foreign_table_parent_uid'])) {
                return $this->collectionIgnoreInfo($fieldName, $fieldName, 'foreign_table_parent_uid');
            }
        }

        return [
            'field' => $fieldName,
            'reason' => 'unknown_or_not_in_tca',
            'hint' => 'Field is not in TCA for ' . $tableName . ', or is a system field.',
        ];
    }

    /**
     * @return array{field: string, reason: string, hint: string, foreignTable: string, foreignField: string}
     */
    private function collectionIgnoreInfo(string $fieldName, string $foreignTable, string $foreignField): array
    {
        return [
            'field' => $fieldName,
            'reason' => 'collection',
            'foreignTable' => $foreignTable,
            'foreignField' => $foreignField,
            'hint' => 'This is a Content Blocks / inline collection. Do not write the parent counter. '
                . 'Create/update child rows in table "' . $foreignTable . '" with "' . $foreignField . '" '
                . 'set to this record uid. For order: first child pid=<pageUid>, later children pid=-<previousChildUid>.',
        ];
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
        $this->addRelationMetadata($schema, $config, $type, $columnConfig);

        return $schema;
    }

    /**
     * @param array<string, mixed> &$schema
     * @param array<mixed> $config
     * @param array<mixed> $columnConfig
     */
    private function addRelationMetadata(array &$schema, array $config, string $type, array $columnConfig): void
    {
        if ($type === 'category' || $this->hasMMTable($config)) {
            $schema['writableAs'] = 'uid_list';
            $schema['writeHint'] = 'Comma-separated UIDs, e.g. "126" or "8,12".';
            $mm = $config['MM'] ?? null;
            if (is_string($mm) && $mm !== '') {
                $schema['mm'] = $mm;
            }
            $foreignTable = $config['foreign_table'] ?? null;
            if (is_string($foreignTable) && $foreignTable !== '') {
                $schema['foreignTable'] = $foreignTable;
            }
            if ($type === 'category') {
                $schema['relationKind'] = 'category';
            }
        }

        if ($this->isFileField($columnConfig)) {
            $schema['writableAs'] = 'file_references';
            $schema['writeHint'] = 'Use file_reference_add or write_table value '
                . '[{"uid_local":N,"alternative":"..."}] (replace on update; [] clears). '
                . 'Parent column stores the relation count.';
            $schema['relationKind'] = 'file';
        }

        if ($this->isCollectionField($columnConfig)) {
            $foreignTable = is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : $schema['name'];
            $foreignField = is_string($config['foreign_field'] ?? null) && $config['foreign_field'] !== ''
                ? $config['foreign_field']
                : 'foreign_table_parent_uid';
            $schema['type'] = 'collection';
            $schema['tcaType'] = $type;
            $schema['foreignTable'] = $foreignTable;
            $schema['foreignField'] = $foreignField;
            $schema['writable'] = false;
            $schema['relationKind'] = 'collection';
            $schema['writeHint'] = 'Write child rows in "' . $foreignTable . '" with "' . $foreignField
                . '" = parent uid. Order with pid=<page> then pid=-<previousChildUid>.';
        }
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
    private function isCollectionField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if ($type !== 'inline') {
            return false;
        }

        $foreignTable = $config['foreign_table'] ?? null;
        if (!is_string($foreignTable) || $foreignTable === '' || $foreignTable === 'sys_file_reference') {
            return false;
        }

        return true;
    }

    /** @param array<mixed> $columnConfig */
    private function isRelationUidListField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }

        if ($type === 'category') {
            return true;
        }

        if (in_array($type, self::CONDITIONAL_TYPES, true) && $this->hasMMTable($config)) {
            return true;
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
            // Include MM-backed select/group so categories/authors/tags are discoverable and writable.
            return true;
        }

        if (in_array($type, self::RELATION_CONTAINER_TYPES, true)) {
            return true;
        }

        return false;
    }

    /** @param array<mixed> $columnConfig */
    private function isWritableField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $readOnly = $config['readOnly'] ?? false;
        if ($readOnly === true) {
            return false;
        }

        // File / collection parents are not scalar writes; handled via dedicated APIs / child tables.
        if ($this->isFileField($columnConfig) || $this->isCollectionField($columnConfig)) {
            return false;
        }

        if (!$this->isReadableField($columnConfig)) {
            return false;
        }

        return true;
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
