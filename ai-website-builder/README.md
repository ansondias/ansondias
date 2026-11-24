# AI Builds My Website

A business owner shares whatever they have: a name and city, social links, a Google Business listing,
a PDF price list, photos, or a few notes. The app reads all of it, searches the web, asks the owner
whenever it is unsure, writes a one-page website, and publishes it to a public URL.

Built with **Laravel 13 + MySQL**, Blade templates with plain CSS (no JS build step), and **Google Gemini**
(free API key) for reading, web search and writing.

> **Golden rule: never invent facts.** Every phone number, price and sentence on the site traces back to
> a source the owner can see, or to the owner's own answer. When sources disagree or something is
> uncertain, the app asks instead of guessing.

| 1. Inputs | 2. Questions when unsure | 3. Edit, regenerate | 4. Published |
|---|---|---|---|
| ![inputs](docs/screenshots/1-inputs.png) | ![questions](docs/screenshots/2-questions.png) | ![editor](docs/screenshots/3-editor.png) | ![site](docs/screenshots/4-published.png) |

## What it does

- **Reads everything:** PDFs (text layer, or Gemini vision for scanned PDFs), DOCX, TXT/CSV/MD, photos
  (Gemini vision transcribes price-list photos and writes alt text), websites (including schema.org JSON-LD
  and `tel:`/`mailto:` links), social pages, and Google Business listings (through the optional Places API).
- **Searches the web:** Gemini with Google Search grounding (or Google Places, if you add a key) finds the
  business online. If the search finds the owner's website, the app reads that too.
- **Builds facts, not prose:** every source becomes a list of facts (`phone`, `price_item`, `hours`, `service`…),
  each with a **verbatim quote as evidence**. The app checks each quote against the source in PHP and drops
  any fact whose quote isn't really there.
- **Writes the site:** contact, hours, prices, highlights and photos are laid out straight from facts with no AI.
  The AI writes only the headline, the About text and the service blurbs, using **only the confirmed fact list**.
  A guard then deletes any sentence that contains a number, link, email or claim ("best", "award-winning",
  "15 years of experience"…) that the facts don't back, and tells the owner what it removed.
- **Edit, regenerate, publish:** edit any section, regenerate one section with an instruction
  ("shorter", "more formal"), reorder or hide sections, restore earlier versions, change the accent colour
  and style, then publish to `/s/{slug}`. Unpublishing takes the page down.
- **Everything lives in MySQL:** sites, sources (with extracted text), facts and their evidence, questions and
  answers, sections, section history and an AI call log.

## Edge cases

| Situation | What happens |
|---|---|
| **Several businesses share the name** | The web search lists each match. If a hard identifier (the owner's website, a phone number from their files, or their Google listing) matches exactly one result, the app picks it and says why. Otherwise it asks "Which one is yours?" and offers "None of these". Building waits for the answer. |
| **Sources disagree** (two phone numbers, two prices for one item) | Facts are grouped by meaning (`+91 98200 12345` = `098200-12345`). If two different values remain, they are held back and the owner chooses: one of them, "show both", or a typed value. Publishing is blocked until they answer. Details found only by web search are never shown until the owner confirms them. |
| **Social sites block scraping** | Login walls, 403/429/999 responses and empty JavaScript-only pages are detected. The source is marked *blocked*, never silently "read". The owner gets an optional prompt to paste their bio or details instead. The link itself still appears on the site. |
| **Instructions hidden in files** | (1) A scanner removes sentences that address an AI ("ignore previous instructions", "note to the AI", `SYSTEM:`…) and zero-width characters, and shows the owner what it removed. (2) Hidden HTML (`display:none`) is dropped. (3) Documents are fenced as `<untrusted_document>` and the extractor can only output a fixed JSON schema with verbatim evidence. (4) The website writer never sees raw documents, only facts. (5) The output guard catches any claim that still gets through. |
| **The AI is down** | Calls retry with backoff on 429/5xx, then stop cleanly. The build stops at a saved checkpoint and shows the reason, with **Try again** and **Build without AI** buttons. Building without AI uses structured data and the owner's own words. Regenerating a section while the AI is down leaves the section unchanged and shows an error. Every call is logged in `ai_runs`. |
| **Missing basics** | No phone, address, hours or services? The app asks the owner (optional questions) rather than leaving gaps it might fill with guesses. |
| **Unsafe links** | URLs that point at localhost, private networks or cloud metadata addresses (SSRF) are refused, including through redirects. |

## Setup

Requirements: PHP 8.3+ (with `pdo_mysql`, `mbstring`, `dom`, `zip`, `fileinfo`), Composer, MySQL 8 (or MariaDB 10.6+).

```bash
cd ai-website-builder
composer install
cp .env.example .env
php artisan key:generate
```

Create the database and fill in `.env`:

```bash
mysql -uroot -e "CREATE DATABASE ai_website_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```dotenv
DB_DATABASE=ai_website_builder
DB_USERNAME=root
DB_PASSWORD=

GEMINI_API_KEY=your-key-here        # free: https://aistudio.google.com/apikey
GOOGLE_PLACES_API_KEY=              # optional, reads Google Business listings properly
BUILDER_PASSWORD=                   # optional, protects the builder UI when deployed
```

```bash
php artisan migrate
```

Run the web server and the queue worker in two terminals. The worker runs the build in the background,
and the page shows live progress.

```bash
php artisan serve
```

```bash
php artisan queue:work --timeout=600
```

Open http://127.0.0.1:8000. If you'd rather not run a worker, set `QUEUE_CONNECTION=sync`. The build then
runs inside the request, and the page waits for it to finish.

No API keys are in the repository. `.env` is git-ignored, and `.env.example` only lists the variable names.

### Tests

```bash
php artisan test
```

The tests fake Gemini at the HTTP layer, so they need no key and no network. They cover the full flow
(ambiguous name, phone conflict, injected instructions, invented sentences removed, publish), an AI outage
followed by "build without AI", a login-walled Instagram link, auto-identification by phone number,
regenerate with history and restore, owner edits, and a price-list photo with an injected instruction.
They run on SQLite in memory. The same migrations were also run against MySQL 8.

### Trying the edge cases

`samples/` has a price-list PDF with a hidden white-text injection, and a notes file whose phone number
differs from the PDF's. Upload both to see the injection report and the conflict question.

## How it works

```
 inputs ──► SourceIngestor ──► InjectionScanner ──► FactExtractor ──► WebResearcher ──► FactReconciler ──► SiteBuilder ──► publish
 (links,     (UrlFetcher,        (strip & report       (JSON-LD/tel: +     (search, pick the     (cluster values,     (fact-based sections
  files,      FileExtractor,      AI-directed text)     Gemini w/ verbatim   right business,      ask on conflict,     + AI prose from facts
  notes)      GooglePlaces)                              evidence check)      read its website)    confirm web-only)    + FactGuard)
```

`App\Services\SitePipeline` runs these steps as a queued job (`App\Jobs\ProcessSite`). Each step saves its
result before the next one starts, so the job can stop for a question or an outage and resume later
without redoing work.

```
app/
  Services/Ai/            GeminiClient (REST, JSON schema output, search grounding, retries, logging)
  Services/Ingest/        UrlFetcher, FileExtractor, SourceIngestor
  Services/Safety/        InjectionScanner
  Services/Facts/         StructuredDataExtractor, FactExtractor, FactReconciler, Normalize
  Services/Research/      WebResearcher, GooglePlaces
  Services/Builder/       SiteBuilder, FactGuard
  Services/               SitePipeline, QuestionAnswerer
  Http/Controllers/       Site, Question, Fact, Section, Publish
resources/views/          builder UI (sites/*) and the public site template (public/*)
database/migrations/      sites, sources, facts, questions, sections, section_revisions, ai_runs
```

### Data model (MySQL)

- `sites`: one per website, with build stage, chosen identity, slug, theme and `published_at`.
- `sources`: every input: kind, URL or file path, read status, extracted text, injection findings.
- `facts`: key, value, price, verbatim evidence, source, status (`confirmed`, `conflict` or `rejected`).
- `questions`: identity, conflict, confirm, missing and blocked-source questions, with options and answers.
- `sections` and `section_revisions`: site content as JSON, where it came from (`ai`, `template` or `owner`), and full history.
- `ai_runs`: every AI call, with its purpose, model, latency, outcome and error.

See [DECISIONS.md](DECISIONS.md) for the reasoning, what was cut and what's next.
