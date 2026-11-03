// Autocomplete dropdown for the search box (WAI-ARIA combobox + listbox pattern).
const DEBOUNCE_MS = 120;
const MIN_CHARS = 2;
const TYPE_LABEL = { category: 'קטגוריה', brand: 'מותג' };

/** Wrap the typed words inside `text` in <mark>, building DOM nodes (never innerHTML). */
function highlight(text, query) {
    const words = query.trim().split(/\s+/).filter(Boolean)
        .map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const frag = document.createDocumentFragment();
    if (!words.length) {
        frag.append(text);
        return frag;
    }
    text.split(new RegExp(`(${words.join('|')})`, 'i')).forEach((part, i) => {
        if (i % 2 === 1) {
            const mark = document.createElement('mark');
            mark.textContent = part;
            frag.append(mark);
        } else if (part) {
            frag.append(part);
        }
    });
    return frag;
}

export function initSuggest({ input, list, status, onPick }) {
    const cache = new Map();
    let items = [];
    let active = -1;
    let controller = null;
    let timer = null;

    const isOpen = () => !list.hidden;

    function setActive(index) {
        active = index;
        [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === index)));
        if (index >= 0) {
            input.setAttribute('aria-activedescendant', list.children[index].id);
            list.children[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function close() {
        clearTimeout(timer);
        controller?.abort();
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        setActive(-1);
    }

    function render(suggestions, query) {
        items = suggestions;
        if (!items.length) {
            close();
            return;
        }
        list.replaceChildren(...items.map((item, i) => {
            const li = document.createElement('li');
            li.id = `suggest-${i}`;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dir = 'auto';
            const text = document.createElement('span');
            text.className = 'suggest__text';
            text.append(highlight(item.text, query));
            li.append(text);
            if (TYPE_LABEL[item.type]) {
                const tag = document.createElement('span');
                tag.className = 'suggest__tag';
                tag.textContent = TYPE_LABEL[item.type];
                li.append(tag);
            }
            return li;
        }));
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(-1);
        status.textContent = `${items.length} הצעות, השתמשו בחצים לבחירה`;
    }

    async function load(raw) {
        const q = raw.trim();
        if (q.length < MIN_CHARS) {
            close();
            return;
        }
        if (cache.has(q)) {
            render(cache.get(q), q);
            return;
        }
        controller?.abort();
        controller = new AbortController();
        try {
            const res = await fetch(`/api/suggest?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!res.ok) return; // autocomplete is optional: stay silent on errors
            const { suggestions } = await res.json();
            cache.set(q, suggestions);
            // The user kept typing while we waited: this answer is stale.
            if (input.value.trim() === q) render(suggestions, q);
        } catch {
            // aborted or offline: ignore
        }
    }

    function pick(index) {
        const item = items[index];
        if (!item) return;
        close();
        input.value = item.text;
        input.focus();
        onPick(item.text);
    }

    input.addEventListener('input', (e) => {
        if (e.isComposing) return;
        clearTimeout(timer);
        timer = setTimeout(() => load(input.value), DEBOUNCE_MS);
    });

    input.addEventListener('keydown', (e) => {
        if (e.isComposing) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!isOpen()) {
                if (items.length && input.value.trim().length >= MIN_CHARS) {
                    list.hidden = false;
                    input.setAttribute('aria-expanded', 'true');
                } else {
                    return;
                }
            }
            e.preventDefault();
            // Cycle through -1 (nothing selected, input keeps focus) and every item.
            const step = e.key === 'ArrowDown' ? 1 : -1;
            setActive(((active + 1 + step + items.length + 1) % (items.length + 1)) - 1);
        } else if (e.key === 'Enter' && isOpen() && active >= 0) {
            e.preventDefault();
            pick(active);
        } else if (e.key === 'Escape' && isOpen()) {
            e.preventDefault();
            close();
        } else if (e.key === 'Tab') {
            close();
        }
    });

    // mousedown (not click) so the input keeps focus and blur does not close the list first.
    list.addEventListener('mousedown', (e) => e.preventDefault());
    list.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (li) pick([...list.children].indexOf(li));
    });
    list.addEventListener('mousemove', (e) => {
        const li = e.target.closest('li');
        if (li) setActive([...list.children].indexOf(li));
    });

    input.addEventListener('blur', close);

    return { close };
}
