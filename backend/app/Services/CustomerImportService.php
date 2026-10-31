<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SplFileObject;

/** Bulk-adds customers from a CSV file. A phone that already exists (or repeats in the file) is skipped, not an error. */
class CustomerImportService
{
    public const MAX_ROWS = 2000;

    private const COLUMNS = ['name', 'father_name', 'phone', 'alt_phone', 'nid', 'address', 'notes'];

    private const ALIASES = [
        'father' => 'father_name', 'fathers_name' => 'father_name', 'father_s_name' => 'father_name',
        'mobile' => 'phone', 'mobile_number' => 'phone', 'phone_number' => 'phone',
        'alt_mobile' => 'alt_phone', 'alternative_phone' => 'alt_phone', 'alt_phone_number' => 'alt_phone',
        'note' => 'notes',
    ];

    /**
     * @return array{created: int, skipped_duplicates: int, invalid: int, errors: list<array{row: int, message: string}>}
     */
    public function import(string $path): array
    {
        $rows = $this->read($path);
        $shopId = auth()->user()->shop_id;
        if (! $shopId) {
            throw new BusinessRuleException('Only a shop user can import customers.');
        }

        // every phone the shop already has, soft-deleted ones too (they still hold the unique index)
        $seen = Customer::withTrashed()->pluck('phone')->mapWithKeys(fn ($p) => [$this->digits($p) => true])->all();

        $fresh = [];
        $skipped = 0;
        $errors = [];

        foreach ($rows as $line => $row) {
            $row['phone'] = $this->phone($row['phone'] ?? null);
            $row['alt_phone'] = $this->phone($row['alt_phone'] ?? null);

            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'father_name' => ['nullable', 'string', 'max:255'],
                'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                'alt_phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                'nid' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:1000'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $line, 'message' => $validator->errors()->first()];

                continue;
            }

            $key = $this->digits($row['phone']);
            if (isset($seen[$key])) {
                $skipped++;

                continue;
            }
            $seen[$key] = true;
            $fresh[] = $row;
        }

        $now = now();
        DB::transaction(function () use ($fresh, $shopId, $now) {
            foreach (array_chunk($fresh, 500) as $chunk) {
                Customer::query()->insertOrIgnore(array_map(fn ($r) => [...$r, 'shop_id' => $shopId, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });

        return ['created' => count($fresh), 'skipped_duplicates' => $skipped, 'invalid' => count($errors), 'errors' => $errors];
    }

    /** @return array<int, array<string, ?string>> the data rows keyed by their line number in the file */
    private function read(string $path): array
    {
        $contents = file_get_contents($path);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new BusinessRuleException('The file must be a plain-text CSV saved as UTF-8 (in Excel: "CSV UTF-8").');
        }

        $first = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $file = new SplFileObject('php://temp', 'w+');
        $file->fwrite($contents);
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter);

        $map = null;
        $rows = [];
        $line = 0;

        foreach ($file as $cells) {
            $line++;
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }

            if ($map === null) {
                $map = [];
                foreach ($cells as $i => $header) {
                    $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $header)), '_');
                    $key = self::ALIASES[$key] ?? $key;
                    if (in_array($key, self::COLUMNS, true) && ! in_array($key, $map, true)) {
                        $map[$i] = $key;
                    }
                }
                if (! in_array('name', $map, true) || ! in_array('phone', $map, true)) {
                    throw new BusinessRuleException('The first row must be a header with at least the "name" and "phone" columns.');
                }

                continue;
            }

            $row = array_fill_keys(self::COLUMNS, null);
            foreach ($map as $i => $key) {
                $value = $this->clean((string) ($cells[$i] ?? ''), in_array($key, ['phone', 'alt_phone'], true));
                $row[$key] = $value === '' ? null : $value;
            }
            if (count(array_filter($row)) === 0) {
                continue;
            }

            $rows[$line] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new BusinessRuleException('A file can have at most '.self::MAX_ROWS.' customers. Split it and import it in parts.');
            }
        }

        return $rows;
    }

    /**
     * Strips control characters, and defuses spreadsheet formulas: a text cell starting with = + - @ would run as a
     * formula when someone later exports the list and opens it in Excel, so it is stored with a leading apostrophe.
     */
    private function clean(string $value, bool $isPhone): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        if (! $isPhone && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'".$value;
        }

        return $value;
    }

    /** Phone as stored: spaces and dashes removed, and the leading 0 put back when a spreadsheet dropped it. */
    private function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[\s\-]+/', '', $value);

        return preg_match('/^1[0-9]{9}$/', $value) ? '0'.$value : $value;
    }

    private function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }
}
