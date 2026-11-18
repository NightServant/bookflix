<?php

namespace App\Reader\Cbz;

use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Models\ContentSource;
use App\Reader\Archives\LocalFile;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Archives\ZipGuard;
use FilesystemIterator;
use finfo;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZipArchive;

/**
 * Turns a stored RAR comic (CBR) into a CBZ, because only zips are read in the browser (P9-R11). The unpacking is done by a system program, `unar`
 * or else `bsdtar`, run as its own process with a timeout and never bundled. RAR is not trusted: the archive is listed first and refused before a
 * single byte is written if the listing is hostile (the same name, count and size rules as ZipGuard), the extracted tree is checked again for links
 * and escapes, and only real image files are kept, renamed and rebuilt into a fresh zip that passes ZipGuard. Any failure leaves the CBR untouched
 * and the source `failed` with a short reason.
 */
class CbrConverter
{
    public const CONVERSION_FAILED_MESSAGE = 'The CBR file could not be converted. Try uploading it again.';

    public const UNAVAILABLE_MESSAGE = 'This server reads CBZ, not CBR. Convert the file to CBZ and upload it again.';

    /** What finfo may say about a page, and the extension each is saved with. */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * How long extraction is left alone between size checks. A property so a test need not wait.
     */
    public int $pollMicroseconds = 250_000;

    public function __construct(private readonly ZipGuard $guard) {}

    /**
     * Whether a tool that unpacks RAR is installed.
     */
    public function available(): bool
    {
        return $this->tool() !== null;
    }

    /**
     * The tool that will run: `unar` when it is there, else `bsdtar`, else null.
     */
    public function tool(): ?string
    {
        return $this->unar() !== null ? 'unar' : ($this->bsdtar() !== null ? 'bsdtar' : null);
    }

    /**
     * Convert the source's stored CBR into a CBZ and update the source. Never throws for a bad file: the source is marked failed instead.
     */
    public function convert(ContentSource $source): ContentSource
    {
        $disk = Storage::disk(ContentSource::disk());
        $stored = (string) $source->storage_path;
        $tool = $this->tool();
        $dir = storage_path('app/private/tmp/cbr-'.Str::ulid());
        $zipPath = null;

        try {
            if ($source->format !== ContentFormat::Cbr || $stored === '' || ! $disk->exists($stored)) {
                throw new UnsafeArchive(__('The CBR file is no longer available.'));
            }

            if ($tool === null) {
                throw new UnsafeArchive(__(self::UNAVAILABLE_MESSAGE));
            }

            $pages = LocalFile::with($stored, function (string $file) use ($source, $tool, $dir, $stored, $disk, &$zipPath): int {
                $this->checkListing($tool, $file);

                File::ensureDirectoryExists($dir, 0700);
                $this->extract($tool, $file, $dir);

                $zipPath = (string) tempnam(sys_get_temp_dir(), 'cbr-zip');
                $pages = $this->buildZip($dir, $zipPath);
                $this->guard->inspect($zipPath);

                $this->store($source, $zipPath, $stored, $disk);

                return $pages;
            });

            Log::info('CBR converted to CBZ.', ['content_source' => $source->id, 'pages' => $pages, 'tool' => $tool]);
        } catch (UnsafeArchive $refusal) {
            $this->fail($source, $refusal->getMessage());
        } catch (Throwable $exception) {
            // A disk or process failure is not the file's fault, but the source must not stay pending forever: the reason is short and plain,
            // the detail goes to the log.
            report($exception);
            $this->fail($source, __(self::CONVERSION_FAILED_MESSAGE));
        } finally {
            File::deleteDirectory($dir);

            if ($zipPath !== null) {
                @unlink($zipPath);
            }
        }

        return $source;
    }

    private function unar(): ?string
    {
        return $this->resolve((string) config('bookflix.reader.cbr.unar_path'));
    }

    private function bsdtar(): ?string
    {
        return $this->resolve((string) config('bookflix.reader.cbr.bsdtar_path'));
    }

    /**
     * An absolute path must be an executable file; a bare name is looked up on PATH.
     */
    private function resolve(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (str_contains($path, '/')) {
            return is_file($path) && is_executable($path) ? $path : null;
        }

        return (new ExecutableFinder)->find($path);
    }

    /**
     * `lsar` ships with `unar`: look next to it first, then on PATH.
     */
    private function lsar(): ?string
    {
        $unar = $this->unar();

        if ($unar !== null) {
            $beside = dirname($unar).'/lsar';

            if (is_file($beside) && is_executable($beside)) {
                return $beside;
            }
        }

        return (new ExecutableFinder)->find('lsar');
    }

    /**
     * Run a tool as an array command (no shell) with the configured timeout.
     *
     * @param  list<string>  $command
     *
     * @throws UnsafeArchive
     */
    private function run(array $command): string
    {
        try {
            $result = Process::timeout((int) config('bookflix.reader.cbr.timeout_seconds'))->run($command);
        } catch (ProcessTimedOutException) {
            throw new UnsafeArchive(__('Unpacking the CBR file took too long.'));
        }

        if ($result->failed()) {
            throw new UnsafeArchive(__('The CBR file could not be unpacked. It may be damaged or use a format this server cannot read.'));
        }

        return $result->output();
    }

    /**
     * List the archive and refuse it before anything is extracted.
     *
     * @throws UnsafeArchive
     */
    private function checkListing(string $tool, string $file): void
    {
        if ($tool === 'unar') {
            $lsar = $this->lsar();

            if ($lsar === null) {
                throw new UnsafeArchive(__('The CBR file cannot be checked on this server.'));
            }

            $entries = $this->parseLsar($this->run([$lsar, '-j', $file]));
        } else {
            $entries = $this->parseBsdtar($this->run([(string) $this->bsdtar(), '-tvf', $file]));
        }

        if (count($entries) > (int) config('bookflix.reader.archive.max_entries')) {
            throw UnsafeArchive::because(__('it holds too many files.'));
        }

        $maxBytes = (int) config('bookflix.reader.archive.max_uncompressed_mb') * 1048576;
        $total = 0;

        foreach ($entries as $entry) {
            ZipGuard::checkName($entry['name']);

            if ($entry['link']) {
                throw UnsafeArchive::because(__('it holds a link.'));
            }

            $total += $entry['directory'] ? 0 : $entry['size'];

            if ($total > $maxBytes) {
                throw UnsafeArchive::because(__('it unpacks to too much data.'));
            }
        }
    }

    /**
     * @return list<array{name: string, size: int, directory: bool, link: bool}>
     *
     * @throws UnsafeArchive
     */
    private function parseLsar(string $json): array
    {
        $data = json_decode($json, true);
        $contents = is_array($data) ? ($data['lsarContents'] ?? null) : null;

        if (! is_array($contents)) {
            throw new UnsafeArchive(__('The CBR file could not be read.'));
        }

        $entries = [];

        foreach ($contents as $item) {
            if (! is_array($item) || ! is_string($item['XADFileName'] ?? null)) {
                throw new UnsafeArchive(__('The CBR file could not be read.'));
            }

            $entries[] = [
                'name' => rtrim($item['XADFileName'], '/'),
                'size' => (int) ($item['XADFileSize'] ?? 0),
                'directory' => ! empty($item['XADIsDirectory']),
                'link' => ! empty($item['XADIsLink']) || ! empty($item['XADIsHardLink']),
            ];
        }

        return $entries;
    }

    /**
     * Reads `bsdtar -tvf` lines: mode, links, owner, group, size, month, day, time or year, then the name.
     *
     * @return list<array{name: string, size: int, directory: bool, link: bool}>
     *
     * @throws UnsafeArchive
     */
    private function parseBsdtar(string $output): array
    {
        $entries = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\S)\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\d+\s+\S+\s+(.+)$/', $line, $m) !== 1) {
                throw new UnsafeArchive(__('The CBR file could not be read.'));
            }

            $entries[] = [
                'name' => rtrim($m[3], '/'),
                'size' => (int) $m[2],
                'directory' => $m[1] === 'd',
                'link' => in_array($m[1], ['l', 'h'], true) || str_contains($m[3], ' link to '),
            ];
        }

        return $entries;
    }

    /**
     * Unpack into `$dir` as a running process and, while it runs, measure what is really on disk. The listing can lie about sizes, so a RAR that
     * claims to be small could fill the disk long before the check after extraction; here the process is stopped the moment the tree passes the
     * uncompressed cap.
     *
     * @throws UnsafeArchive
     */
    private function extract(string $tool, string $file, string $dir): void
    {
        $command = $tool === 'unar'
            ? [(string) $this->unar(), '-q', '-f', '-D', '-o', $dir, $file]
            : [(string) $this->bsdtar(), '-xf', $file, '-C', $dir, '--no-same-owner', '--no-same-permissions'];
        $maxBytes = (int) config('bookflix.reader.archive.max_uncompressed_mb') * 1048576;

        try {
            $limit = (float) config('bookflix.reader.cbr.timeout_seconds');
            $process = Process::timeout((int) $limit)->start($command);
            $started = hrtime(true);

            while ($process->running()) {
                // `running()` never checks the process's timeout, so the time is kept here.
                if ($limit > 0 && (hrtime(true) - $started) / 1e9 > $limit) {
                    $process->stop();
                    Log::info('CBR extraction stopped because it ran past the time limit.', ['limit_seconds' => $limit]);

                    throw new UnsafeArchive(__('Unpacking the CBR file took too long.'));
                }

                if ($this->treeBytes($dir) > $maxBytes) {
                    $process->stop();
                    Log::info('CBR extraction stopped because it grew past the size limit.', ['limit_bytes' => $maxBytes]);

                    throw UnsafeArchive::because(__('it unpacks to too much data.'));
                }

                usleep($this->pollMicroseconds);
            }

            $result = $process->wait();
        } catch (ProcessTimedOutException) {
            throw new UnsafeArchive(__('Unpacking the CBR file took too long.'));
        }

        if ($result->failed()) {
            throw new UnsafeArchive(__('The CBR file could not be unpacked. It may be damaged or use a format this server cannot read.'));
        }
    }

    /**
     * The real size of every file under `$dir` right now, links not followed. Public so the limit can be tested directly.
     */
    public function treeBytes(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $item */
        foreach ($walk as $item) {
            if ($item->isFile() && ! $item->isLink()) {
                $total += (int) $item->getSize();
            }
        }

        return $total;
    }

    /**
     * Walk what was extracted, keep the images and write them into a new zip. Returns the page count.
     *
     * @throws UnsafeArchive
     */
    private function buildZip(string $dir, string $zipPath): int
    {
        $base = realpath($dir);

        if ($base === false) {
            throw new UnsafeArchive(__('The CBR file could not be unpacked. It may be damaged or use a format this server cannot read.'));
        }

        $maxEntries = (int) config('bookflix.reader.archive.max_entries');
        $maxBytes = (int) config('bookflix.reader.archive.max_uncompressed_mb') * 1048576;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $pages = [];
        $count = 0;
        $total = 0;

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($walk as $item) {
            $path = $item->getPathname();
            $real = realpath($path);

            if ($item->isLink() || $real === false || ! str_starts_with($real, $base.'/')) {
                throw UnsafeArchive::because(__('it holds a link.'));
            }

            if (++$count > $maxEntries) {
                throw UnsafeArchive::because(__('it holds too many files.'));
            }

            if (! $item->isFile()) {
                continue;
            }

            $total += (int) $item->getSize();

            if ($total > $maxBytes) {
                throw UnsafeArchive::because(__('it unpacks to too much data.'));
            }

            $relative = substr($path, strlen($base) + 1);

            if ($this->skipped($relative)) {
                continue;
            }

            $extension = self::IMAGE_TYPES[(string) $finfo->file($path)] ?? null;

            if ($extension !== null) {
                $pages[$relative] = $extension;
            }
        }

        if ($pages === []) {
            throw new UnsafeArchive(__('This comic has no pages.'));
        }

        $names = array_keys($pages);
        usort($names, strnatcasecmp(...));

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UnsafeArchive(__('The CBZ file could not be made.'));
        }

        foreach ($names as $position => $relative) {
            $entry = sprintf('page-%04d.%s', $position + 1, $pages[$relative]);

            // Images are compressed already, so they are stored as they are.
            if (! $zip->addFile($base.'/'.$relative, $entry) || ! $zip->setCompressionName($entry, ZipArchive::CM_STORE)) {
                $zip->unchangeAll();
                $zip->close();

                throw new UnsafeArchive(__('The CBZ file could not be made.'));
            }
        }

        if (! $zip->close()) {
            throw new UnsafeArchive(__('The CBZ file could not be made.'));
        }

        return count($names);
    }

    /**
     * Mac resource folders and hidden files are never pages.
     */
    private function skipped(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '__MACOSX' || str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    private function store(ContentSource $source, string $zipPath, string $oldPath, mixed $disk): void
    {
        $folder = dirname($oldPath);
        $path = ($folder === '.' ? '' : $folder.'/').Str::ulid().'.cbz';
        $stream = fopen($zipPath, 'rb');

        if ($stream === false || ! $disk->put($path, $stream)) {
            throw new UnsafeArchive(__('The CBZ file could not be stored.'));
        }

        fclose($stream);

        $source->forceFill([
            'format' => ContentFormat::Cbz,
            'storage_path' => $path,
            'byte_size' => (int) filesize($zipPath),
            'checksum' => hash_file('sha256', $zipPath),
            'mime_type' => ContentFormat::Cbz->mimeType(),
            'status' => ContentStatus::Ready,
            'failure_reason' => null,
        ])->save();

        $disk->delete($oldPath);
    }

    /**
     * Mark the source failed with a short reason. Public so the queued job can do the same when it gives up.
     */
    public function fail(ContentSource $source, string $reason): void
    {
        Log::info('CBR conversion refused.', ['content_source' => $source->id, 'reason' => $reason]);

        $source->forceFill(['status' => ContentStatus::Failed, 'failure_reason' => $reason])->save();
    }
}
