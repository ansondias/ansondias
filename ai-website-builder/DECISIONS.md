# Decisions

## Key choices

### 1. Facts first, prose second
The core design choice. Nothing goes from a source straight onto the website. Every source is turned into
**atomic facts with a verbatim quote as evidence**, and the site is built only from facts marked `confirmed`.
This one structure handles the golden rule and most of the edge cases:

- *Never invent:* each fact's quote is checked in PHP against the source text, and its value must appear in
  the quote. A hallucinated "Free parking" whose quote isn't in the document is dropped before it is saved.
- *Disagreement:* comparing two sources means comparing two values for the same key, which is easy to do
  reliably in code. Comparing two blocks of prose is not.
- *Transparency:* the owner sees every fact, the source it came from and the exact quote, and can fix or
  remove any of them.

### 2. The AI writes as little as possible
Contact details, hours, prices, highlights and the photo gallery are **laid out from facts by plain PHP**.
The AI cannot get a phone number or a price wrong because it never writes one. It writes only the headline,
the About text and the service descriptions, and:
- it receives only the confirmed fact list, never the raw documents,
- service names must match facts (an invented "Hair spa" is dropped),
- `FactGuard` removes any sentence with a number, email, link or marketing claim ("best", "award",
  "years of experience", "certified"…) that the facts don't back, and the owner is shown what was removed.

The prompt can be ignored by the model, but this PHP check still runs, so a made-up claim can't get through.

### 3. Ask, don't guess, with blocking and non-blocking questions
- **Blocking** (building or publishing waits): *which of these businesses is yours* and *your sources disagree*.
  Publishing wrong contact details is worse than publishing later.
- **Non-blocking:** *confirm this detail we only found on the web*, *we couldn't read your Instagram, paste it
  here*, and *what are your opening hours?* The site is built without these details until the owner answers.
- Web-search-only details are lower-trust (they are the search model's summary, not a quote), so they are
  held until the owner confirms them.
- When the same business name appears more than once, the app only picks one automatically if a **hard
  identifier** matches exactly one candidate: the owner's own website, a phone number from their own
  files, or their Google listing. A matching name alone is never enough.

### 4. Defence in depth against prompt injection
No single filter stops injection, so there are five layers: a pattern scanner (it removes the text and
reports it to the owner, it doesn't silently drop it), dropping hidden HTML, a fenced
`<untrusted_document>` with a strict JSON output schema, a writer that never sees raw documents, and the
output guard. The last two do most of the work. Even if the scanner misses an injection, the worst it can
do is plant a "fact" in the extractor's output, where it is shown with its source for the owner to review.

### 5. Resumable pipeline on a queue
Reading files, searching and writing take 20–60 seconds and depend on an external AI that can fail. The build
is a queued job split into checkpointed steps (`SitePipeline`), and every step saves to MySQL. A failure
anywhere leaves the site in a `failed` stage with the reason. **Try again** resumes from the last
checkpoint, and **Build without AI** produces a fact-only site from structured data (JSON-LD, `tel:` links,
Places) and the owner's own words. The UI polls a small status endpoint, so there are no websockets to run.

### 6. Gemini over REST, no SDK
One small client (`GeminiClient`) handles JSON-schema output, Google Search grounding, vision and retries
with backoff, and logs every call to `ai_runs`. Without an SDK, the whole request is easy to read in one file
and easy to fake in tests. `AiClient` is an interface, so another provider can be added with one class.
The base URL and model are configurable.

### 7. Honest scraping
The app identifies itself, reads only public pages, and treats login walls, 403/429/999 and JavaScript-only
shells as **blocked** instead of pretending it read them. It does not try to get around blocks (no headless
login, no rotating proxies). For Google listings, the right tool is the Places API: it is optional, and when
there is no key the owner is asked to paste the details. URLs are checked against private and metadata IP
ranges, including after redirects (SSRF).

### 8. Boring frontend
Blade and two hand-written CSS files: no Node build, no SPA. The builder is forms and links, which suits
"edit, regenerate, publish". The public site is server-rendered HTML that works without JavaScript, is
mobile-friendly, and has two styles and an accent colour (which the AI suggests and the owner can change).

## What I cut

- **User accounts and multi-tenancy.** It's a single-owner tool. An optional `BUILDER_PASSWORD` (HTTP basic
  auth) protects the builder when it's deployed, and published pages stay public.
- **Custom domains / static export.** Sites are published at `/s/{slug}` on the app's own domain.
- **Multi-page sites, blog, contact forms, booking.** One strong landing page covers most small businesses.
- **Reviews and testimonials.** Pulling real reviews needs the Places API and attribution rules, and
  writing them would break the golden rule, so the site has none.
- **XLSX parsing.** CSV, PDF, DOCX and photos cover price lists. Excel files can be saved as CSV.
- **Headless-browser scraping** of JavaScript-heavy sites: too heavy to run, and still blocked by social networks.
- **Multilingual copy.** Fact matching is Unicode-aware, so Hindi and Marathi price lists match their
  evidence, but the writer outputs English.
- **Image editing and logo detection.** Photos are shown as uploaded. Price-list photos are read, not shown.

## What's next

1. **Accounts and teams**, so an agency can manage many businesses, plus email or OTP sign-in for owners.
2. **Custom domains and static export** (render the published page to HTML and host it on a CDN).
3. **A better identity step:** show a map pin for each candidate, and use the Places photos and reviews (with
   attribution) once the owner confirms which business is theirs.
4. **Per-fact owner confirmation for high-stakes fields** in bulk ("Here are 14 prices we found, tick the
   correct ones"), plus price-list diffing when a new file is uploaded.
5. **Stronger injection detection:** a small classifier to complement the patterns, and scanning PDF text
   by colour and size (white or tiny text) instead of only by content.
6. **More AI providers** behind `AiClient` (OpenAI-compatible, local models), with automatic fallback when one is down.
7. **Analytics and SEO:** JSON-LD `LocalBusiness` on published pages (built from facts), a sitemap, and
   Open Graph images.
8. **A section library and drag-and-drop ordering**, with more themes.
