<?php

namespace App\Providers;

use App\Discovery\Search\QueryParser;
use App\Discovery\Search\RuleBasedQueryParser;
use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\Creator;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Theme;
use App\Models\Trope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QueryParser::class, RuleBasedQueryParser::class);

        // Registered here, not in boot, so the guard is on Livewire's list before any of its own hooks.
        $this->guardReaderComponentsFromAdmins();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // What a preference points at (user_preferences.preferable_type). A plain map, not an enforced one: notifications and the rest keep their class names.
        Relation::morphMap([
            'genre' => Genre::class,
            'theme' => Theme::class,
            'trope' => Trope::class,
            'demographic' => Demographic::class,
            'media_type' => MediaType::class,
            'creator' => Creator::class,
            // What a report points at (reports.reportable_type).
            'review' => Review::class,
            'collection' => Collection::class,
            'profile' => Profile::class,
        ]);

        Gate::define('access-admin', fn (User $user): bool => $user->is_admin);

        // An admin account runs the catalog and has no reader side (see EnsureReaderAccount); the same line guards the reader writes an admin could still call straight through Livewire.
        Gate::define('use-reader-side', fn (User $user): bool => ! $user->is_admin);

        // One seam for who may see a reader's public side (P10-R3): the owner, or an active, non-admin owner with a public profile that moderators have not hidden.
        // Callers answer a refusal with 404, never 403, so a private profile's existence doesn't leak.
        Gate::define('view-profile', fn (?User $viewer, User $owner): bool => $viewer?->is($owner)
            || (! $owner->is_admin
                && ! $owner->isDeactivated()
                && $owner->profile->profile_visibility === Visibility::Public
                && $owner->profile->moderation_hidden_at === null));

        // A reader's library is theirs; others may see it only when the profile is viewable and the owner made the library public.
        Gate::define('view-library', fn (?User $viewer, User $owner): bool => $viewer?->is($owner)
            || (Gate::forUser($viewer)->allows('view-profile', $owner) && $owner->profile->library_visibility === Visibility::Public));

        // Named so it is tuned in one place; FollowUser applies it, so Livewire calls count too.
        RateLimiter::for('follows', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.follows_per_hour'))->by($user->id));

        RateLimiter::for('reviews', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.reviews_per_hour'))->by($user->id));
        RateLimiter::for('collection-clones', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.collection_clones_per_hour'))->by($user->id));
        RateLimiter::for('reports', fn (User $user): Limit => Limit::perHour((int) config('bookflix.social.reports_per_hour'))->by($user->id));

        // pdf.js reads a file in many small ranges, so a reader gets a roomy allowance (per person, not per address).
        // One save every couple of seconds while reading, plus the beacons; a person, not an address.
        RateLimiter::for('reader-progress', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('reader-stream', fn (Request $request): Limit => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));

        foreach (config('bookflix.providers') as $key => $provider) {
            if ($provider['class'] === null) {
                continue;
            }

            RateLimiter::for("provider:{$key}", fn (): Limit => Limit::perMinute($provider['rate_per_minute']));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * An admin cannot open a reader page, but a Livewire request does not pass through the route's middleware: the library listener sits in the shared layout,
     * and a reader page left open from before the account was promoted keeps its snapshot. So every request from an admin to a component outside the admin
     * pages and the account pages is refused as the component is restored, before a method call, a property update or a render can read or write reader data.
     */
    private function guardReaderComponentsFromAdmins(): void
    {
        $adminSide = ['pages::admin.', 'pages::settings.'];
        $readerSettings = ['pages::settings.preferences', 'pages::settings.privacy'];

        on('hydrate', function (Component $component) use ($adminSide, $readerSettings): void {
            $user = auth()->user();

            if ($user === null || Gate::forUser($user)->allows('use-reader-side')) {
                return;
            }

            $name = $component->getName();

            $allowed = Str::startsWith($name, $adminSide) && ! Str::startsWith($name, $readerSettings);

            abort_unless($allowed, 403);
        });
    }
}
