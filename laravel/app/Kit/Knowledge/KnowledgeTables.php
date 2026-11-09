<?php

namespace App\Kit\Knowledge;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A knowledge document that is a table (console → Knowledge → a document → View table; contract/kit-api.yaml getKnowledgeTable,
 * setKnowledgeColumnTypes). Telenow keeps a CSV, JSON or Excel file as a table the moment it is uploaded (recorded, contract_k19tables.py): on
 * calls every agent answering from its base looks rows up in it, and a small one (Telenow's `inlined`) is also put in the agent's prompt whole.
 * Read live with the account's own key, a page of rows at a time; nothing of it is kept in the kit, and Telenow's ids never leave it (a column
 * is named by its heading in plain letters, Telenow's `sql_name`). A column's type is how look-ups read it: numbers and dates are compared, numbers
 * added up, and a cell that does not fit is left out of those (Telenow's lookup, not an error); it takes effect on calls that start afterwards and
 * never changes the rows. Every member may, as with the rest of knowledge.
 *
 * Not here: adding and removing documents (Knowledge), which bases an agent answers from (AgentKnowledge).
 */
final class KnowledgeTables
{
    public const TYPES = ['text', 'number', 'bool', 'date'];

    public function __construct(private readonly TelenowClient $telenow) {}

    /** A file of a kind Telenow keeps as a table, by its name (the name the kit sends it with). */
    public static function isTable(KnowledgeDocument $d): bool
    {
        return $d->source_type === 'upload' && in_array(self::kind($d), config('kit.knowledge.table_types'), true);
    }

    /** The file's kind by its name: csv, json, xlsx… */
    private static function kind(KnowledgeDocument $d): string
    {
        return strtolower(pathinfo((string) $d->source_uri, PATHINFO_EXTENSION));
    }

    /** @return array{columns: list<array{key: string, name: string, type: string}>, rows: list<list<mixed>>, rowCount: int, offset: int, limit: int, format: string, inPrompt: bool, searchByMeaningRows: ?int} */
    public function read(KnowledgeDocument $doc, array $input): array
    {
        $page = Validator::make($input, [
            'offset' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('kit.knowledge.table_page_max')],
        ], [], self::names())->validate();
        [$key, $org, $kb, $id] = $this->at($doc);
        $offset = (int) ($page['offset'] ?? 0);
        $limit = (int) ($page['limit'] ?? config('kit.knowledge.table_page'));
        $table = $this->table($key, $org, $kb, $id);
        try {
            $rows = $this->telenow->knowledgeTableRows($key, $org, $kb, $id, $offset, $limit);
        } catch (TelenowError) {
            throw new KnowledgeRefused(503, __('knowledge.table_unavailable'));
        }
        if ($rows === null) {
            throw new KnowledgeRefused(404, __('knowledge.not_a_table'));
        }
        $columns = self::columns($table['columns'] ?? []);
        $keys = array_column($columns, 'key');

        return [
            'columns' => $columns,
            'rows' => array_map(fn ($row) => array_map(fn (string $k) => self::cell(is_array($row) ? ($row[$k] ?? null) : null), $keys), array_values($rows['rows'] ?? [])),
            'rowCount' => (int) ($table['rowCount'] ?? 0),
            'offset' => (int) ($rows['offset'] ?? $offset),
            'limit' => (int) ($rows['limit'] ?? $limit),
            'format' => in_array($table['format'] ?? null, config('kit.knowledge.table_types'), true) ? $table['format'] : self::kind($doc),
            'inPrompt' => ($table['inlined'] ?? false) === true,
            'searchByMeaningRows' => ($table['fuzzyTruncated'] ?? false) === true ? (int) ($table['fuzzyRowCap'] ?? 0) : null,
        ];
    }

    /**
     * Change how look-ups read some columns, by their `key`; answers the columns as they are now. The table is read first, so a column it does
     * not have is refused with its name before anything is sent, and the answer is that read with the change applied (as Telenow applies it).
     *
     * @return list<array{key: string, name: string, type: string}>
     */
    public function setTypes(KnowledgeDocument $doc, array $input): array
    {
        $types = Validator::make($input, [
            'types' => ['required', 'array', 'min:1', 'max:'.config('kit.knowledge.table_columns_max')],
        ], [], self::names())->validate()['types'];
        if (array_filter($types, fn ($type) => ! in_array($type, self::TYPES, true)) !== []) {
            throw ValidationException::withMessages(['types' => [__('knowledge.table_type_invalid')]]);
        }
        [$key, $org, $kb, $id] = $this->at($doc);
        $columns = self::columns($this->table($key, $org, $kb, $id)['columns'] ?? []);
        $unknown = array_values(array_diff(array_keys($types), array_column($columns, 'key')));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['types' => [__('knowledge.table_column_unknown')]]);
        }
        try {
            $this->telenow->setKnowledgeColumnTypes($key, $org, $kb, $id, $types);
        } catch (TelenowError $e) {
            throw match ($e->status) {
                400 => ValidationException::withMessages(['types' => [__('knowledge.table_column_unknown')]]),
                404 => new KnowledgeRefused(404, __('knowledge.not_a_table')),
                default => new KnowledgeRefused(503, __('knowledge.table_not_saved')),
            };
        }

        return array_map(fn (array $c) => array_replace($c, ['type' => $types[$c['key']] ?? $c['type']]), $columns);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} the account's key, its org, the base's and the document's Telenow ids */
    private function at(KnowledgeDocument $doc): array
    {
        if (! self::isTable($doc)) {
            throw new KnowledgeRefused(404, __('knowledge.not_a_table'));
        }
        $base = $doc->base;
        $workspace = $base->workspace;
        $ready = $doc->sync_state !== 'failed' && $doc->telenow_id !== null && $base->telenow_id !== null
            && in_array($doc->status, config('kit.knowledge.statuses.ready'), true);
        if (! $ready || $workspace->telenow_key === null || $workspace->telenow_sub_account_id === null) {
            throw new KnowledgeRefused(409, __('knowledge.table_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id, $base->telenow_id, $doc->telenow_id];
    }

    private function table(string $key, string $org, string $kb, string $id): array
    {
        try {
            $table = $this->telenow->knowledgeTable($key, $org, $kb, $id);
        } catch (TelenowError) {
            throw new KnowledgeRefused(503, __('knowledge.table_unavailable'));
        }

        return $table ?? throw new KnowledgeRefused(404, __('knowledge.not_a_table'));
    }

    /** @return list<array{key: string, name: string, type: string}> */
    private static function columns(array $columns): array
    {
        return array_values(array_map(fn (array $c) => [
            'key' => (string) ($c['sql_name'] ?? ''),
            'name' => (string) ($c['name'] ?? ''),
            'type' => in_array($c['type'] ?? null, self::TYPES, true) ? $c['type'] : 'text',
        ], array_filter($columns, 'is_array')));
    }

    /** A cell as the customer's file had it: text, a number, true or false, or null; anything else (a nested value) as its JSON text. */
    private static function cell(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> */
    private static function names(): array
    {
        return array_map(fn (string $f) => __("knowledge.fields.{$f}"), ['offset' => 'offset', 'limit' => 'limit', 'types' => 'types']);
    }
}
