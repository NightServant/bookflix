<?php

use Illuminate\Support\Facades\DB;

test('required postgres extensions are installed', function () {
    $installed = collect(DB::select('select extname from pg_extension'))->pluck('extname');

    expect($installed)->toContain('vector', 'pg_trgm', 'unaccent');
});
