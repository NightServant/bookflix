{{-- The h1 of a settings page. Pass `title` (the section) and `description` (what the page is for and what to do next).
     The reader shell pads the top of the page; the admin shell leaves that to each page, so an admin's settings page brings it here. --}}
<x-page-header :eyebrow="auth()->user()?->can('access-admin') ? __('Account') : __('Settings')" :title="$title" :description="$description" @class(['mb-8', 'pt-8' => auth()->user()?->can('access-admin')]) />
