<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>htzone — חיפוש מוצרים</title>
    <meta name="description" content="חפשו מוצר לפי שם או תארו מה אתם צריכים — בעברית או באנגלית.">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#000000">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<header class="topbar">
    <div class="topbar__inner">
        <a href="{{ route('home') }}" class="logo" aria-label="htzone — לדף הבית" dir="ltr">htzone<span class="logo__dot"></span></a>
    </div>
</header>

<main class="page">
    <section class="hero" aria-labelledby="hero-title">
        <h1 id="hero-title" class="hero__title">מה בא לכם למצוא?</h1>
        <p class="hero__lead">כתבו שם של מוצר או תארו מה אתם צריכים — נמצא את המוצרים שמתאימים.</p>

        <form class="search" role="search" id="search-form" action="{{ route('home') }}">
            <label for="q" class="visually-hidden">חיפוש מוצרים</label>
            <input id="q" name="q" type="search" class="search__input" dir="auto" autocomplete="off"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="suggest" aria-haspopup="listbox"
                   maxlength="200" placeholder="למשל: מתנה למישהו שאוהב לבשל" value="{{ request('q') }}">
            <button type="button" class="search__clear" id="search-clear" aria-label="ניקוי החיפוש" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
            <button type="submit" class="search__submit">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
                <span>חיפוש</span>
            </button>
            <ul class="suggest" id="suggest" role="listbox" aria-label="הצעות חיפוש" hidden></ul>
            <span class="visually-hidden" id="suggest-status" role="status"></span>
        </form>

        <div class="examples" id="examples">
            <span class="examples__label">נסו:</span>
            @foreach (['שואב אבק', 'iPhone 16', 'אייפון', 'מתנה למישהו שאוהב לבשל', 'משהו שיעזור לנקות שערות של כלב מהספה'] as $example)
                <button type="button" class="chip" data-query="{{ $example }}" dir="auto">{{ $example }}</button>
            @endforeach
        </div>
    </section>

    <section class="results" aria-live="polite" aria-busy="false" id="results">
        <div class="results__head" id="results-head" hidden>
            <h2 class="results__title">מצאנו מוצרים בשבילכם</h2>
            <p class="results__meta" id="results-meta"></p>
        </div>
        <p class="notice" id="degraded" hidden>החיפוש החכם לא זמין כרגע, מוצגות תוצאות לפי מילות מפתח בלבד.</p>
        <div class="grid" id="grid"></div>

        <div class="state" id="state-empty" hidden>
            <h2 class="state__title">לא מצאנו מוצרים שמתאימים ל„<bdi id="empty-query"></bdi>”</h2>
            <p class="state__text">נסו לנסח אחרת, לכתוב את סוג המוצר (למשל „מקדחה”), או לחפש באנגלית.</p>
        </div>

        <div class="state state--error" id="state-error" hidden>
            <h2 class="state__title">החיפוש נכשל</h2>
            <p class="state__text" id="error-text">לא הצלחנו להתחבר לשרת. בדקו את החיבור לאינטרנט ונסו שוב.</p>
            <button type="button" class="btn" id="retry">ניסיון חוזר</button>
        </div>
    </section>
</main>

<template id="card-template">
    <article class="card">
        <div class="card__media"><img alt="" loading="lazy" decoding="async" width="600" height="600"></div>
        <div class="card__body">
            <h3 class="card__name" dir="auto"></h3>
            <p class="card__price"></p>
        </div>
    </article>
</template>
</body>
</html>
