<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Verify your email')" :description="__('We emailed you a link. Open it to verify your address and unlock your library. Nothing arrived? Resend it below.')" />

        @if (session('status') == 'verification-link-sent')
            <flux:text class="font-medium !text-success">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        {{-- Two columns on a wide screen, two full-width rows on a phone. --}}
        <div class="grid gap-3 sm:grid-cols-2" data-test="verify-email-actions">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-cta type="submit" icon="paper-airplane" icon:variant="outline" class="w-full">
                    {{ __('Resend email') }}
                </x-cta>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-cta variant="secondary" type="submit" icon="arrow-right-start-on-rectangle" icon:variant="outline" class="w-full cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </x-cta>
            </form>
        </div>
    </div>
</x-layouts::auth>
