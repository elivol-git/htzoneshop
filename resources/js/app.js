const form = document.getElementById('search-form');
const input = document.getElementById('q');
const clearBtn = document.getElementById('search-clear');
const grid = document.getElementById('grid');
const head = document.getElementById('results-head');
const meta = document.getElementById('results-meta');
const results = document.getElementById('results');
const degraded = document.getElementById('degraded');
const stateEmpty = document.getElementById('state-empty');
const stateError = document.getElementById('state-error');
const errorText = document.getElementById('error-text');
const template = document.getElementById('card-template');
const chips = [...document.querySelectorAll('.chip')];

const price = new Intl.NumberFormat('he-IL', { style: 'currency', currency: 'ILS', maximumFractionDigits: 0 });
const DEBOUNCE_MS = 400;
const NETWORK_ERROR = 'לא הצלחנו להתחבר לשרת. בדקו את החיבור לאינטרנט ונסו שוב.';
let controller = null;
let debounceTimer = null;
let lastQuery = null;

const show = (el, visible) => { el.hidden = !visible; };

function setState(state) {
    results.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    show(stateEmpty, state === 'empty');
    show(stateError, state === 'error');
    show(head, state === 'results');
    if (state !== 'results') show(degraded, false);
    if (['idle', 'empty', 'error'].includes(state)) grid.replaceChildren();
}

function renderSkeleton() {
    const cards = Array.from({ length: 8 }, () => {
        const el = document.createElement('div');
        el.className = 'card card--skeleton';
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = '<div class="card__media"></div><div class="card__body"><div class="sk"></div><div class="sk sk--price"></div></div>';
        return el;
    });
    grid.replaceChildren(...cards);
}

const FALLBACK_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/></svg>';

function useFallbackImage(media) {
    media.classList.add('card__media--fallback');
    media.innerHTML = FALLBACK_ICON;
}

function renderResults(items) {
    const cards = items.map((item) => {
        const node = template.content.firstElementChild.cloneNode(true);
        const img = node.querySelector('img');
        node.querySelector('.card__name').textContent = item.name;
        node.querySelector('.card__price').textContent = price.format(item.price);
        if (item.image_url) {
            img.alt = item.name;
            img.addEventListener('error', () => useFallbackImage(img.parentElement), { once: true });
            img.src = item.image_url;
        } else {
            useFallbackImage(img.parentElement);
        }
        return node;
    });
    grid.replaceChildren(...cards);
}

function syncUrl(q) {
    const url = new URL(window.location);
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    history.replaceState(null, '', url);
}

function syncChips(q) {
    chips.forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.query === q)));
}

async function search(raw) {
    const q = raw.trim();
    show(clearBtn, raw.length > 0);
    syncChips(q);
    syncUrl(q);
    if (!q) {
        controller?.abort();
        lastQuery = null;
        setState('idle');
        return;
    }
    if (q === lastQuery) return;
    lastQuery = q;

    controller?.abort();
    controller = new AbortController();
    setState('loading');
    renderSkeleton();

    try {
        const res = await fetch(`/api/search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (res.status === 429) throw new Error('יותר מדי חיפושים בזמן קצר. המתינו כמה שניות ונסו שוב.');
        if (!res.ok) throw new Error('השרת החזיר שגיאה. נסו שוב בעוד רגע.');
        const data = await res.json();

        if (data.results.length === 0) {
            document.getElementById('empty-query').textContent = q;
            setState('empty');
            return;
        }
        setState('results');
        meta.textContent = data.count === 1 ? 'מוצר אחד' : `${data.count} מוצרים`;
        show(degraded, data.degraded);
        renderResults(data.results);
    } catch (err) {
        if (err.name === 'AbortError') return;
        lastQuery = null;
        errorText.textContent = err instanceof TypeError ? NETWORK_ERROR : err.message;
        setState('error');
    }
}

form.addEventListener('submit', (e) => {
    e.preventDefault();
    clearTimeout(debounceTimer);
    lastQuery = null;
    search(input.value);
});

input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    show(clearBtn, input.value.length > 0);
    debounceTimer = setTimeout(() => search(input.value), DEBOUNCE_MS);
});

clearBtn.addEventListener('click', () => {
    input.value = '';
    input.focus();
    search('');
});

chips.forEach((chip) => chip.addEventListener('click', () => {
    input.value = chip.dataset.query;
    clearTimeout(debounceTimer);
    search(input.value);
}));

document.getElementById('retry').addEventListener('click', () => {
    lastQuery = null;
    search(input.value);
});

if (input.value.trim()) search(input.value);
