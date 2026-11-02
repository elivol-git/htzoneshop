# htzone · Catalog search

A small RTL shop front for searching a 1,000-product catalog. It finds products by exact name (`iPhone 16`, `Dyson`), by Hebrew, English or mixed text (`אייפון`, `air fryer`), and by need (`מתנה למישהו שאוהב לבשל`, `משהו שיעזור לנקות שערות של כלב מהספה`).

**Live:** `http://<elastic-ip>` (see [docs/DEPLOY.md](docs/DEPLOY.md))

**Stack:** PHP 8.4, Laravel 13, MySQL 8.4 (RDS), Docker (nginx + php-fpm), Hugging Face Inference API (`intfloat/multilingual-e5-large`), AWS EC2 + RDS + Secrets Manager.

## Running locally

```bash
cp .env.example .env              # optional: set HF_TOKEN=hf_... for semantic search
docker compose up -d --build      # app on http://localhost:8080, MySQL on :33061
docker compose exec app php artisan catalog:embed   # one-time: embeds 1000 products (needs HF_TOKEN)
docker compose exec app php artisan search:eval     # prints top results for the sample queries
```

The container migrates the database and imports the CSV on boot. Without `HF_TOKEN`, the site still works in keyword-only mode and shows a notice in the UI.

Run the tests with `php artisan test`, or without a local PHP install:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php vendor/bin/phpunit
```

## How search works

```
query ─┬─▶ normalize (lowercase, strip niqqud/punctuation, stopwords)
       ├─▶ keyword leg: tokens + Hebrew-prefix variants (מהספה→ספה) + synonym groups
       │      (אייפון ↔ iphone ↔ "apple סמארטפון", שואב ↔ vacuum …)
       │      → MySQL FULLTEXT, ngram parser (Hebrew-safe substring matching)
       ├─▶ semantic leg: multilingual-e5 query embedding (cached in DB)
       │      → cosine vs. 1000 product vectors (in-memory, ~5 ms)
       │      → keep hits ≥ absolute floor and close to the best hit
       └─▶ Reciprocal Rank Fusion + boost when every query word is in the product name
```

Why this design:
- **The two legs cover each other's weak spots.** Embeddings understand intent ("someone who loves cooking" → pans, knives, mixers) but are fuzzy on exact names. FULLTEXT is exact but doesn't know that אייפון = iPhone. RRF merges the two rankings without tuning score scales against each other.
- **Hebrew without a stemmer.** MySQL's `ngram` FULLTEXT parser (bigrams) matches substrings, so `לכלב` contains `כלב`. Query tokens with glued prefixes (`מהספה`) are reduced only to variants that actually appear in the catalog vocabulary.
- **The catalog has hidden English.** Placeholder image URLs carry an English label (`?text=robot%20vacuum`). The importer adds that label to the search text and to the embedding text, which helps English queries against Hebrew rows.
- **Graceful degradation.** If Hugging Face is down or slow (5 s timeout), the API returns keyword results with `degraded: true`, and the UI says so. Query embeddings are cached in the `query_embeddings` table, so repeated queries make no API call.
- **No-results stays meaningful.** Semantic search always returns *something*, so semantic-only hits must clear an absolute similarity floor and fall within a window of the best hit. A gibberish query therefore returns the empty state.
- **Scale path.** A brute-force vector scan is fine for about 20k products. Beyond that, move the vectors to OpenSearch k-NN, pgvector, or MySQL HeatWave vector search.

### Data notes
- The CSV has **no price column**. `catalog:import` generates a deterministic price per product within a realistic range for its subcategory (`config/catalog.php`). The same CSV always yields the same prices, and a real `price` column would be used automatically if one is added.
- The catalog has **no product literally named iPhone**: Apple phones are listed as `סמארטפון … | Apple`. `iPhone 16` and `אייפון` therefore reach them through the synonym group, and the bare number `16` is down-weighted so it doesn't match "16GB" laptops.

## Project map

| Path | What |
|------|------|
| `app/Services/Search/SearchService.php` | hybrid orchestration, RRF, name boost, degradation |
| `app/Services/Search/KeywordSearch.php` | FULLTEXT (MySQL) / LIKE (sqlite tests) retrieval |
| `app/Services/Search/QueryExpander.php`, `TextNormalizer.php` | Hebrew/English normalization, prefixes, synonyms |
| `app/Services/Search/HuggingFaceEmbedder.php`, `VectorIndex.php` | embeddings client + in-memory cosine index |
| `app/Console/Commands/` | `catalog:import`, `catalog:embed`, `search:eval` |
| `config/search.php` | thresholds, RRF weights, stopwords, synonym groups |
| `resources/views/search.blade.php`, `resources/js/app.js`, `resources/css/app.css` | UI (RTL, loading, empty and error states) |
| `Dockerfile`, `docker/`, `compose*.yml`, `deploy.sh` | container + deployment |

## How Claude was used

I worked with Claude Code (Claude Opus) as a pair programmer for the whole build:

1. **Planning.** I gave Claude the assignment brief, and in plan mode it asked about the embedding provider (I chose Hugging Face hosted), how to provision infrastructure (I chose manual console steps plus docs), and where the CSV would come from. It then wrote the architecture plan above.
2. **Data inspection before coding.** Claude profiled the CSV first: 1000 rows and 80 subcategories, no prices, no literal "iPhone", English labels in image URLs. Those findings shaped the price generator, the synonym groups and the image-label trick.
3. **Implementation.** Claude scaffolded Laravel in a Composer container (there is no local PHP) and wrote the search services, commands, Docker setup, UI and docs.
4. **Verification. I didn't trust any output unverified:**
   - PHPUnit suite (`tests/`): normalizer and expander unit tests, plus API feature tests with a deterministic fake embedder covering Hebrew queries, transliteration, English→Hebrew, HF outage fallback, the embedding cache, validation and the empty state.
   - The tests caught real bugs: multi-word synonyms were matched as adjacent phrases (fixed to "all words, any order"), and a vocabulary query was missing its `id` column.
   - `php artisan search:eval` runs the brief's sample queries against real MySQL and the real model; I reviewed the top results by hand after each change. One finding from this: `iPhone 16` ranked a "16GB" laptop first, which led to down-weighting bare numbers.
   - The UI was checked manually for the loading, empty, error (DB stopped) and degraded (no token) states.

## Open items

- HTTPS and a domain name (Caddy plus Let's Encrypt is a small addition).
- CI/CD: GitHub Actions that runs the tests and deploys over SSH or SSM.
- Real product images and prices. The CSV ships placeholders only.
- Search analytics (zero-result queries) and a growing synonym list based on them.
- An offline relevance metric (a labelled query set with nDCG) instead of eyeballing `search:eval`.
