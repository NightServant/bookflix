<?php

namespace App\Http\Controllers;

use App\Discovery\LandingShowcase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The public landing page: guests see it, signed-in users go straight home (an admin to the admin dashboard).
 */
class LandingController extends Controller
{
    /** Books in the hero, and covers on the cards, picked at random from the popular catalog. */
    private const HERO_BOOKS = 7;

    private const CARD_COVERS = 9;

    public function show(LandingShowcase $showcase): View|RedirectResponse
    {
        if ($user = auth()->user()) {
            return redirect($user->homePath());
        }

        $books = $showcase->random(self::HERO_BOOKS + self::CARD_COVERS);

        return view('welcome', [
            'books' => array_slice($books, 0, self::HERO_BOOKS),
            'covers' => array_slice($books, self::HERO_BOOKS),
            'topTen' => $showcase->topTen(),
        ]);
    }
}
