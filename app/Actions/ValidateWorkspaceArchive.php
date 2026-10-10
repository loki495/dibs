<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\WorkspaceArchive;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class ValidateWorkspaceArchive
{
    /** @return array<string, list<array<string, mixed>>> */
    public function handle(string $json): array
    {
        if (strlen($json) > (int) config('workspace-transfer.max_file_kib') * 1024) {
            $this->invalid(__('The archive exceeds the import size limit.'));
        }
        try {
            $archive = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid(__('Upload a valid DIBS JSON archive.'));
        }
        if (! is_array($archive) || ($archive['format'] ?? null) !== WorkspaceArchive::FORMAT || ($archive['version'] ?? null) !== WorkspaceArchive::VERSION || ! is_array($archive['data'] ?? null)) {
            $this->invalid(__('This is not a supported DIBS workspace archive.'));
        }
        $data = $archive['data'];
        $tables = array_keys($data);
        sort($tables);
        $expected = WorkspaceArchive::TABLES;
        sort($expected);
        if ($tables !== $expected) {
            $this->invalid(__('The archive has missing or unsupported sections.'));
        }
        $total = 0;
        foreach ($data as $table => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                $this->invalid(__('Every archive section must be a list of records.'));
            }
            $total += count($rows);
            if ($total > (int) config('workspace-transfer.max_records')) {
                $this->invalid(__('The archive contains too many records.'));
            }
            $columns = Schema::getColumns($table);
            $names = array_column($columns, 'name');
            sort($names);
            $indexes = array_filter(Schema::getIndexes($table), fn (array $index): bool => $index['unique']);
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    $this->invalid(__('Each record must be an object.'));
                }
                $keys = array_keys($row);
                sort($keys);
                if ($keys !== $names) {
                    $this->invalid(__('Archive columns do not match this DIBS version.'));
                }
                foreach ($columns as $column) {
                    $value = $row[$column['name']];
                    if ($value === null) {
                        if (! $column['nullable']) {
                            $this->invalid(__('A required archive value is missing.'));
                        }
                    } elseif (str_contains($column['type_name'], 'int') || $column['type_name'] === 'boolean') {
                        if (! is_int($value) && ! is_bool($value)) {
                            $this->invalid(__('An archive number has an invalid type.'));
                        }
                        if ($column['name'] === 'id' && (! is_int($value) || $value < 1)) {
                            $this->invalid(__('Archive record IDs must be positive integers.'));
                        }
                    } elseif (! is_string($value)) {
                        $this->invalid(__('An archive text value has an invalid type.'));
                    }
                }
                $this->values($table, $row);
                foreach ($indexes as $index) {
                    $values = array_map(fn (string $column): mixed => $row[$column], $index['columns']);
                    if (in_array(null, $values, true)) {
                        continue;
                    }
                    $key = $index['name'].':'.json_encode($values, JSON_THROW_ON_ERROR);
                    if (isset($seen[$key])) {
                        $this->invalid(__('The archive contains duplicate identities.'));
                    }
                    $seen[$key] = true;
                }
            }
        }
        $this->relationships($data);

        return $data;
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function relationships(array $data): void
    {
        $byId = [];
        foreach ($data as $table => $rows) {
            $byId[$table] = array_column($rows, null, 'id');
        }
        foreach ($data as $table => $rows) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if ($foreign['foreign_table'] === 'users') {
                    continue;
                }
                foreach ($rows as $row) {
                    $value = $row[$foreign['columns'][0]];
                    if ($value !== null && (! is_int($value) || ! isset($byId[$foreign['foreign_table']][$value]))) {
                        $this->invalid(__('The archive contains a missing relationship.'));
                    }
                }
            }
        }
        foreach ($data['issue_label'] as $link) {
            if ($byId['issues'][$link['issue_id']]['repository_id'] !== $byId['labels'][$link['label_id']]['repository_id']) {
                $this->invalid(__('A label belongs to a different repository.'));
            }
        }
        foreach ($data['project_items'] as $item) {
            foreach (['group', 'priority', 'status'] as $semantic) {
                $id = $item[$semantic.'_option_id'];
                if ($id !== null) {
                    $field = $byId['project_fields'][$byId['project_field_options'][$id]['project_field_id']];
                    if ($field['project_id'] !== $item['project_id'] || $field['semantic_key'] !== $semantic) {
                        $this->invalid(__('A task option belongs to a different project or field.'));
                    }
                }
            }
        }
        $finished = [];
        foreach ($data['issues'] as $issue) {
            $path = [];
            $id = $issue['id'];
            while ($id !== null && ! isset($finished[$id])) {
                if (isset($path[$id])) {
                    $this->invalid(__('The archive contains a parent cycle.'));
                }
                $path[$id] = true;
                $id = $byId['issues'][$id]['parent_issue_id'];
            }
            $finished += $path;
        }
    }

    /** @param array<string, mixed> $row */
    private function values(string $table, array $row): void
    {
        $rules = [];
        foreach (array_keys($row) as $name) {
            if (str_ends_with($name, '_at')) {
                $rules[$name] = ['nullable', 'date'];
            } elseif (in_array($name, ['planned_on', 'due_on'], true)) {
                $rules[$name] = ['nullable', 'date_format:Y-m-d,Y-m-d H:i:s'];
            } elseif (str_starts_with($name, 'is_') || $name === 'enabled') {
                $rules[$name] = ['boolean'];
            }
        }
        $jsonColumns = [
            'project_fields' => ['configuration_json'], 'project_items' => ['raw_fields_json'],
            'comments' => ['references'], 'capture_settings' => ['enabled_agents'],
            'todo_capture_requests' => ['draft'], 'mcp_call_logs' => ['arguments'], 'change_logs' => ['changes'],
        ];
        foreach ($jsonColumns[$table] ?? [] as $name) {
            $rules[$name] = ['nullable', 'json'];
        }
        if ($table === 'issues') {
            $rules['state'] = ['in:OPEN,CLOSED'];
            $rules['state_reason'] = ['nullable', 'in:COMPLETED,NOT_PLANNED,REOPENED'];
            $rules['title'] = ['required', 'string', 'max:255'];
        }
        $validator = Validator::make($row, $rules);
        if ($validator->fails()) {
            $this->invalid(__('An archive record in :section has invalid values for: :fields.', ['section' => WorkspaceArchive::SECTION_LABELS[$table], 'fields' => implode(', ', array_keys($validator->errors()->messages()))]));
        }
        foreach ($jsonColumns[$table] ?? [] as $name) {
            if ($row[$name] !== null) {
                $value = json_decode($row[$name], true, 64);
                if (json_last_error() !== JSON_ERROR_NONE || ($value !== null && ! is_array($value))) {
                    $this->invalid(__('Structured archive fields must contain objects or lists.'));
                }
                if ($table === 'comments' && $value !== null && (! array_is_list($value) || ! array_all($value, fn (mixed $reference): bool => is_string($reference)))) {
                    $this->invalid(__('Closing references must be a list of strings.'));
                }
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
