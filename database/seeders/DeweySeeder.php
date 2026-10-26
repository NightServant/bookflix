<?php

namespace Database\Seeders;

use App\Models\DeweyClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class DeweySeeder extends Seeder
{
    /**
     * Seed the Dewey-inspired class tree from database/data/dewey_classes.csv, safe to run again.
     */
    public function run(): void
    {
        $rows = $this->readRows();

        DB::transaction(function () use ($rows): void {
            DeweyClass::upsert(
                array_map(fn (array $row) => [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'level' => $row['level'],
                    'path' => $row['path'],
                ], $rows),
                ['code'],
                ['name', 'description', 'level', 'path'],
            );

            $ids = DeweyClass::pluck('id', 'code');

            foreach ($rows as $row) {
                DeweyClass::where('code', $row['code'])->update(['parent_id' => $row['parent_code'] === null ? null : $ids[$row['parent_code']]]);
            }
        });

        Cache::forget('dewey:roots');
    }

    /**
     * The CSV rows with `level` and `path` worked out; a parent must come before its children.
     *
     * @return list<array{code: string, name: string, description: ?string, parent_code: ?string, level: int, path: string}>
     */
    protected function readRows(): array
    {
        $lines = File::lines(database_path('data/dewey_classes.csv'))->reject(fn (string $line) => $line === '')->all();
        array_shift($lines);

        $rows = [];
        $paths = [];

        foreach ($lines as $line) {
            [$code, $name, $description, $parentCode] = str_getcsv($line);
            $parent = blank($parentCode) ? null : $parentCode;

            if ($parent !== null && ! isset($paths[$parent])) {
                throw new RuntimeException("Class {$code} comes before its parent {$parent}.");
            }

            $paths[$code] = $path = $parent === null ? $code : "{$paths[$parent]}/{$code}";

            $rows[] = [
                'code' => $code,
                'name' => $name,
                'description' => blank($description) ? null : $description,
                'parent_code' => $parent,
                'level' => substr_count($path, '/') + 1,
                'path' => $path,
            ];
        }

        return $rows;
    }
}
