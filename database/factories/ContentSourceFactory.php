<?php

namespace Database\Factories;

use App\Enums\AccessType;
use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * @extends Factory<ContentSource>
 */
class ContentSourceFactory extends Factory
{
    /**
     * Define the model's default state. A new source is an unverified public-domain EPUB whose file has not arrived yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'access_type' => AccessType::PublicDomain,
            'format' => ContentFormat::Epub,
            'license' => 'Public domain in the United States',
            'status' => ContentStatus::Pending,
        ];
    }

    /**
     * Verified by an admin.
     */
    public function verified(?User $admin = null): static
    {
        return $this->state(fn () => [
            'verified_by' => $admin ? $admin->id : User::factory()->admin(),
            'verified_at' => now(),
        ]);
    }

    public function publicDomain(): static
    {
        return $this->state(fn () => ['access_type' => AccessType::PublicDomain, 'license' => 'Public domain in the United States']);
    }

    public function licensed(): static
    {
        return $this->state(fn () => ['access_type' => AccessType::Licensed, 'license' => 'Creative Commons BY 4.0', 'rights_holder' => 'The author']);
    }

    /**
     * A reader's own upload: verified for its owner by the upload itself (no admin), so `verified_by` stays null.
     */
    public function userOwned(User $owner): static
    {
        return $this->state(fn () => [
            'access_type' => AccessType::UserOwned,
            'owner_user_id' => $owner->id,
            'license' => 'Uploaded by its owner',
            'verified_at' => now(),
        ]);
    }

    /**
     * A link out: never streamed, so no file.
     */
    public function externalLink(): static
    {
        return $this->state(fn () => [
            'access_type' => AccessType::ExternalLink,
            'format' => ContentFormat::Url,
            'url' => 'https://example.com/read-here',
            'license' => 'Free to read on the publisher\'s site',
        ]);
    }

    /**
     * The file has arrived: a small one is written to the reader disk and the source is ready.
     */
    public function ready(): static
    {
        return $this->state(function (array $attributes): array {
            $path = 'content/factory/'.Str::ulid();
            $body = 'bookflix test file';

            Storage::disk(ContentSource::disk())->put($path, $body);

            return [
                'storage_path' => $path,
                'byte_size' => strlen($body),
                'status' => ContentStatus::Ready,
            ];
        });
    }

    /**
     * A ready comic: a real zip of tiny PNG pages (`01.png`, `02.png`, ...) written to the reader disk. By default page n is n pixels wide, so each page
     * has its own bytes; with `$size` every page is a comic-looking page of that size in pixels, and `$sizes` gives single pages their own.
     *
     * @param  array{0: int<1, max>, 1: int<1, max>}|null  $size
     * @param  array<int, array{0: int<1, max>, 1: int<1, max>}>  $sizes
     */
    public function cbz(int $pages = 3, ?array $size = null, array $sizes = []): static
    {
        return $this->state(function (array $attributes) use ($pages, $size, $sizes): array {
            $temp = (string) tempnam(sys_get_temp_dir(), 'cbz');
            $zip = new ZipArchive;
            $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            for ($n = 1; $n <= $pages; $n++) {
                $zip->addFromString(sprintf('%02d.png', $n), $size === null && ! isset($sizes[$n]) ? self::png($n) : self::page(...($sizes[$n] ?? $size)));
            }

            // A zip normally ends in NUL bytes, which the browser-test server's trimming of a streamed body would cut off: a comment ends it in text.
            $zip->setArchiveComment('bookflix test fixture');
            $zip->close();

            $path = 'content/factory/'.Str::ulid();
            $body = (string) file_get_contents($temp);
            unlink($temp);
            Storage::disk(ContentSource::disk())->put($path, $body);

            return [
                'format' => ContentFormat::Cbz,
                'storage_path' => $path,
                'byte_size' => strlen($body),
                'checksum' => hash('sha256', $body),
                'status' => ContentStatus::Ready,
            ];
        });
    }

    /**
     * The bytes of a small PNG that looks like a comic page, `$width` by `$height` pixels: a light sheet with three dark-edged panels.
     *
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    public static function page(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $sheet = (int) imagecolorallocate($image, 236, 236, 232);
        $panel = (int) imagecolorallocate($image, 120, 120, 128);
        $edge = (int) imagecolorallocate($image, 20, 20, 20);

        imagefill($image, 0, 0, $sheet);

        $m = max(2, intdiv($width, 20));
        $third = intdiv($height - $m * 4, 3);

        foreach ([0, 1, 2] as $i) {
            $top = $m + $i * ($third + $m);

            imagefilledrectangle($image, $m, $top, $width - $m - 1, $top + $third, $edge);
            imagefilledrectangle($image, $m + 1, $top + 1, $width - $m - 2, $top + $third - 1, $panel);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * The bytes of a valid PNG that is `$width` pixels wide and one high.
     */
    public static function png(int $width = 1): string
    {
        $image = imagecreatetruecolor(max($width, 1), 1);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
