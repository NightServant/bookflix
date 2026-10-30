<?php

namespace App\Http\Controllers;

use App\Actions\Library\AddToLibrary;
use App\Models\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The plain-form way into the library, for a browser without JavaScript. With scripts on, the Add to Library buttons raise the `library-add`
 * event instead (see the library-listener component); both end in the same action and the same access rules.
 */
class LibraryController extends Controller
{
    public function store(Request $request, AddToLibrary $add): RedirectResponse
    {
        $request->validate(['media_id' => ['required', 'integer', 'min:1']]);
        $user = $request->user();

        // A story the reader may not see is reported like one that does not exist.
        $media = Media::query()->unmerged()->visibleTo($user)->find($request->integer('media_id'))
            ?? throw ValidationException::withMessages(['media_id' => __('That story is not available.')]);

        $entry = $add->handle($user, $media);

        return back()->with('status', $entry->wasRecentlyCreated
            ? __(':title was added to your library.', ['title' => $media->title])
            : __(':title is already in your library.', ['title' => $media->title]));
    }
}
