<?php

namespace App\Http\Controllers;

use App\Discovery\LandingShowcase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The public landing page: guests see it, readers who are signed in go straight home. `start` is its sign-up box, which hands the
 * typed email to the registration form through the session (an email address does not belong in a URL).
 */
class LandingController extends Controller
{
    /** Books in the hero, and covers on the cards, picked at random from the popular catalog. */
    private const HERO_BOOKS = 7;

    private const CARD_COVERS = 9;

    public function show(LandingShowcase $showcase): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('home');
        }

        $books = $showcase->random(self::HERO_BOOKS + self::CARD_COVERS);

        return view('welcome', [
            'books' => array_slice($books, 0, self::HERO_BOOKS),
            'covers' => array_slice($books, self::HERO_BOOKS),
            'topTen' => $showcase->topTen(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('email'), ['email' => ['required', 'string', 'email:rfc', 'max:254']]);

        if ($validator->fails()) {
            return redirect(route('landing').'#start')->withErrors($validator)->withInput();
        }

        return redirect()->route('register')->withInput(['email' => $validator->validated()['email']]);
    }
}
