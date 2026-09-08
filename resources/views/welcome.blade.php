<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>FireFightersChat.com — Text Your Whole Department, Instantly</title>
        <meta name="description" content="Any authorized member can send a mass text — every recipient gets their own private message, not a group thread. No per-seat fees. Built for volunteer fire departments moving off legacy paging tools.">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="bg-zinc-950 text-white antialiased">
        {{-- Hazard-stripe accent — a nod to turnout-gear trim / apparatus chevrons --}}
        <div class="h-1.5 w-full" style="background-image: repeating-linear-gradient(135deg, #dc2626 0 14px, #18181b 14px 28px);" aria-hidden="true"></div>

        <div class="relative isolate min-h-[calc(100vh-0.375rem)] overflow-hidden">
            {{-- Background art --}}
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                {{-- Ember glow rising from the bottom --}}
                <div
                    class="absolute inset-x-0 bottom-0 h-[70vh]"
                    style="background: radial-gradient(ellipse 80% 60% at 50% 100%, rgba(234,88,12,0.28), rgba(220,38,38,0.12) 45%, transparent 70%);"
                ></div>
                <div
                    class="absolute inset-x-0 top-0 h-[40vh]"
                    style="background: radial-gradient(ellipse 60% 100% at 50% 0%, rgba(24,24,27,0.6), transparent 70%);"
                ></div>

                {{-- Large Maltese cross watermark — the universal fire service emblem --}}
                <svg
                    class="absolute top-1/2 left-1/2 h-[130vh] w-[130vh] -translate-x-1/2 -translate-y-1/2 opacity-[0.05]"
                    viewBox="-100 -100 200 200"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <g class="text-red-500">
                        <g transform="rotate(0)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                        <g transform="rotate(90)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                        <g transform="rotate(180)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                        <g transform="rotate(270)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                    </g>
                </svg>
            </div>

            <div class="relative flex min-h-[calc(100vh-0.375rem)] flex-col">
                <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6 lg:px-8">
                    <div class="flex items-center gap-2">
                        <svg viewBox="-100 -100 200 200" fill="currentColor" class="size-7 text-red-500" aria-hidden="true">
                            <g transform="rotate(0)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                            <g transform="rotate(90)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                            <g transform="rotate(180)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                            <g transform="rotate(270)"><polygon points="-9,-25 -33,-68 -35,-92 0,-75 35,-92 33,-68 9,-25" /></g>
                        </svg>
                        <span class="text-lg font-bold tracking-tight">
                            FireFighters<span class="text-red-500">Chat</span><span class="text-white/40">.com</span>
                        </span>
                    </div>

                    @if (Route::has('login'))
                        <nav class="flex items-center gap-3">
                            @auth
                                <flux:button :href="route('dashboard')" variant="primary" color="red">
                                    Dashboard
                                </flux:button>
                            @else
                                <flux:button :href="route('login')" variant="ghost">
                                    Log in
                                </flux:button>

                                @if (Route::has('register'))
                                    <flux:button :href="route('register')" variant="primary" color="red">
                                        Register
                                    </flux:button>
                                @endif
                            @endauth
                        </nav>
                    @endif
                </header>

                <main class="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
                    <span class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-1.5 text-xs font-semibold tracking-widest text-red-400 uppercase">
                        Built for Volunteer Fire Departments
                    </span>

                    <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                        Every member gets the message.
                        <span class="text-red-500">Every single time.</span>
                    </h1>

                    <p class="mt-6 max-w-2xl text-lg text-balance text-white/70">
                        Any authorized member can send a mass text — not just one admin. Every recipient gets
                        their own private message, not a group thread. No per-seat fees, and no aging
                        email-to-SMS gateway the carriers are shutting down.
                    </p>

                    @if (Route::has('register'))
                        <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
                            <flux:button :href="route('register')" variant="primary" color="red" class="px-8">
                                Get started
                            </flux:button>
                            <flux:button :href="route('login')" variant="ghost" class="px-8">
                                Log in
                            </flux:button>
                        </div>
                    @endif

                    <dl class="mt-16 grid w-full max-w-3xl grid-cols-1 gap-8 border-t border-white/10 pt-10 text-left sm:grid-cols-3">
                        <div>
                            <dt class="text-sm font-semibold text-red-400">Unlimited senders</dt>
                            <dd class="mt-1 text-sm text-white/60">No per-seat fee for additional senders — ever.</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-semibold text-red-400">Private, individual texts</dt>
                            <dd class="mt-1 text-sm text-white/60">Every member replies privately — not into a group thread.</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-semibold text-red-400">Real carrier-registered SMS</dt>
                            <dd class="mt-1 text-sm text-white/60">Built on A2P 10DLC — not an expiring email-to-SMS trick.</dd>
                        </div>
                    </dl>
                </main>

                <footer class="mx-auto w-full max-w-6xl px-6 py-8 text-center text-xs text-white/40 lg:px-8">
                    &copy; {{ date('Y') }} FireFightersChat.com
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
