# Kaleta roadmap

What is planned next. Dates are not promised; releases ship when they are tested.

## 1.2 – languages for the EU and beyond (released 25 September 2026)

- Around forty site languages; visitor texts translated into Czech, English, German, French, Spanish, Italian, Polish and
  Slovak, other languages fall back to English with dates in their own format.
- System addresses in the site language (`/news`, `/search`), old addresses redirect permanently.
- Site language chosen in the installer; imprint (Impressum) template and company fields.
- Language switcher and light / dark / device-based look with a switcher for visitors.
- Admin: distinct menu icons, chart tooltips, GitHub Sponsors link in the footer.
- 1.2.1: related content on item pages (a Collection list filtered by the shown item's field, without the item itself).
- 1.2.2: MCP accepts objects and arrays sent as JSON text (item values and builds were dropped or refused); breadcrumbs
  mark only the current page.
- 1.2.3: MCP reports unknown parameters and never resets the menu on unreadable items; Escape closes submenus.
- 1.2.4: tidy submenus and a centred mega menu panel; Escape works on every page with a submenu.
- 1.3.1: the mobile menu scrolls when it is taller than the screen; an automatic pop-up waits until the visitor closes the menu.
- 1.3.2: collections in several languages – a translated item keeps its address, a detail template per language, breadcrumbs to the translated section page; builder canvases without site pop-ups.
- 1.3.3: translating with Claude – a page translation starts as a copy of the original build, texts only for translation, the header and footer of a new language start as a copy.
- 1.3.4: a language is offered to visitors (switcher, hreflang, sitemap) only once its home page is published; MCP reads "true"/"false" sent as text correctly and reports collection item keys the collection does not have.
- 1.3.5: reading a site part over MCP creates nothing, the page list shows language addresses, and the template header loads the logo on language versions.
- 1.3.6 (security release): {{field}} values in Custom HTML are escaped, MCP tools check the user's sections, fixes from an admin review (admin language, news addresses, pop-up settings, spacing).
- 1.3.7: Language switcher element (for example in the footer), first visit in the browser's language, language filters in admin lists.

## 1.3 – pop-ups, newsletter services and a clearer Site appearance (released 26 September 2026)

### Pop-up builder

Today a pop-up is an element inside one page. 1.3 turns pop-ups into site-wide pieces built in the builder, like the
header and footer:

- **Types:** centred modal, slide-in from a corner, top or bottom bar, full screen.
- **Triggers:** after N seconds, after scrolling N %, exit intent, click on a link or button (`#popup-name`), inactivity,
  after N page views in a visit.
- **Where it shows:** all pages, selected pages, collection item pages or news, a language version, device
  (desktop / phone), date range, visitors coming from a campaign (`utm_*`) or a referring site.
- **How often:** once per visit, once per N days, until closed, never again after a form in it was sent – remembered
  in the visitor's browser, no cookies.
- **Library:** ready-made pop-ups – newsletter sign-up, lead magnet with a form, announcement bar, discount, event.
- **Accessibility:** focus kept inside, Esc closes, reduced motion respected, no pop-up covers the cookie bar.
- **Results:** views, closes and conversions (form sent) per pop-up, counted cookie-free like the site statistics.
- Claude can create and change pop-ups over MCP like other site parts.

### Site appearance, clearer

- One set of words everywhere: a **starter site** is content plus a style (chosen at installation), a **style** is a
  ready set of colours, fonts and corner radius, a **theme** is only the legacy custom PHP theme.
- Tabs instead of one long form: Style, Colours and readability, Fonts and sizes, Shapes, Dark mode, Brand, Import
  and export (W3C design tokens), with the live preview kept.
- No more styles are added on their own – a custom look is made in the design system or by Claude from the brand;
  a new style comes only together with a new starter site.

### Newsletter

Two steps: the first in 1.3, the second in 1.5.

1. **Subscribers sent to the mailing service the site already uses**. After the double opt-in the address goes to
   Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing (API key and list in the admin), or to any service through the
   existing webhook (Make, Zapier). Unsubscribing in Kaleta removes the address there too. Deliverability, bounces and
   spam rules stay with the specialist service.
2. **1.5 – a minimal built-in mailing for small lists** – “send the latest news to subscribers”, see 1.5 below.

## 1.4 – English identifiers in the code base (1.4.0 and 1.4.1 released 28 September 2026)

The code moves from Czech names to English so contributors can read it. Nothing changes for sites: stored data, build
JSON, CSS hooks of the public site, public templates, MCP tools and old admin links keep working. Terms and the list of
what stays are in [docs/glossary.md](glossary.md).

Done in 1.4.0:

1. Preparation: the glossary, `tools/rename.php` (renames by PHP tokens, refuses name collisions), old class names as
   aliases, `tools/test-update.sh` (every change is tested as an update from the previous release) and a browser test.
2. Tools and tests.
3. PHP classes, functions, constants and variables (`Kaleta\Builder`, `Admin\Modules`…); release packages carry the
   previous release's class files for the update request.
4. Admin and installer templates, admin and site scripts (`tools/rename-js.mjs`).
5. Admin URLs `admin.php?module=pages&action=edit`, old URLs redirected, permissions migrated.

Done in 1.4.1:

6. Settings keys in English (migration 0026; old keys still accepted until 2.0).
7. UI source texts in English, Czech moved to a dictionary like the other languages; code comments in English.

Next, in 1.4.x:

8. Admin CSS classes and `data-*` attributes.

## Direction after 1.4

Decided by the owner on 28 September 2026:

- **Claude over MCP is the main way to work with a site.** Most of what the admin does – create, edit and delete – has to
  work over MCP too. Enquiries stay readable over MCP.
- **Themeless:** the look of a site comes only from the design system, shared classes, components and site parts. No
  PHP themes and no custom layouts.
- **Newsletters follow the design system** instead of being built in the builder.
- **Safe redesigns:** site-wide look changes get drafts, like pages.

Order: first make the MCP path complete, then make look changes safe, then make the site portable, then make it
findable, then remove what is left of the old ways. Each step uses the previous one.

## 1.5 – newsletter mailing

- **One newsletter template, styled by the design system** – colours, fonts, logo and corner radius come from the site's
  tokens; no e-mail builder. The newsletter has a subject, an intro text, the latest (or chosen) news items, a button and
  the company footer. One fixed renderer writes table-based HTML with inline styles and a plain-text part.
- Preview, test e-mail to yourself, send now or scheduled. Sending in batches through the mail queue, only through an
  SMTP relay set in Settings (Brevo, Amazon SES, Mailgun…); without a relay the feature stays off.
- Background jobs run on visits, so a low-traffic site would stall a send: sending is refused unless the cron (`/ulohy`)
  ran recently, and Health shows its last run.
- One-click unsubscribe (`List-Unsubscribe`, RFC 8058), no open tracking; a send log with the date and the count only.
- Claude: `draft_newsletter` and `send_test_newsletter`; the real send only on an explicit request and with the publish
  permission, like publishing.

## 1.6 – Claude can do most of what the admin does

1. **Delete and restore over MCP:** news, collection items, collections, categories and tags, redirects, pop-ups, media
   and header or footer variants; restore a page or news item from the trash; mark an enquiry handled or delete it.
   Collection items get a trash like pages. Destructive tools run only on an explicit request.
2. **Components and saved sections:** `list_components`, `save_component`; sections saved by people in the admin show
   up in `builder_schema` and `insert_section`. `update_category`.
3. **Enquiries stay available to Claude:** every read is written to the change log, and the /claude page, the FAQ, the
   guide and the privacy policy template say so.
4. **Context for Claude:** `site_info` returns the extensions, the languages (with their published state) and the cron
   state; every tool carries MCP annotations (read-only, destructive, idempotent), so clients know what to confirm.
5. **English build vocabulary over MCP:** build JSON keys and element types are English at the MCP boundary (`type`,
   `content`, `style`, `children`, `form`…). Input accepts both the English and the Czech form, output is English.
   Stored builds do not change.
6. **Parity guard:** `tools/test.sh` drives MCP by the English names, and a unit test fails when an admin write action
   has neither an MCP tool nor an explicit “admin only” entry (users, roles, keys, updates and backups stay admin only).
7. **Themeless:** custom PHP layouts are removed – no site uses one. Front templates are no longer overridable, the
   layout choice and `site_info.sablona` go away. An update stops with a clear message if a site still has a layout set.

## 1.7 – safe redesigns

1. The design system, shared classes, components, the menu and header or footer variants get a **draft**, with publish,
   discard and the last 20 versions, like page builds.
2. **One signed preview of the whole site** with the draft look and all page drafts.
3. MCP look tools write to the draft by default; `publish_look` and `discard_look`. Site appearance in the admin gets the
   same Publish button.
4. The change log shows look changes before and after; the previous look is restored with one click.

## 1.8 – own and move your site

1. **Import of a Kaleta export:** “Start from an export” in the installer and Transfer → Import on an empty site; builds
   go through the same sanitising as any build, users and secrets are never carried. It serves host moves and agency
   starter kits at once.
2. **Backups include media:** an incremental media copy to the same FTPS or S3 target, a daily database backup when
   content changed, and one restore guide for both.
3. Page export carries its classes and components.
4. Signed webhooks (HMAC header) and a delivery log with retries.
5. The public REST API is marked deprecated: MCP is the way to integrate, webhooks carry events out, and the site export
   is the way out.

## 1.9 – findable: SEO and collections as landing pages

1. **Collection items as full pages:** SEO title, description, noindex, share image, versions and scheduled visibility.
2. **Site audit** in the admin and over MCP (`site_audit`): broken links across pages, builds, the menu and items, missing
   descriptions, duplicate titles, hidden pages in the menu, alt texts, the most frequent 404s.
3. Structured data per collection: Service, Person, Product/Offer, Event or FAQ.
4. The privacy policy template is generated from the enabled features – still a template with a disclaimer.
5. **Deprecation report in Health:** old class names, settings keys or helpers in custom code, pages using the old
   per-page pop-up element – a full release to react before 2.0.

## 2.0 – one clear system

Not new features – everything that exists is one English, builder-based system.

1. **Compatibility layers removed:** old class names, old admin URLs, old settings keys and old template helpers. The MCP
   `update_settings` keeps accepting old keys, so no connection breaks.
2. **One pop-up system:** per-page pop-up elements move to site pop-ups with a click trigger, then the element goes.
3. **The public REST API is removed** (deprecated in 1.8).
4. The hidden Czech MCP tool names stay – connections never break.

## Not planned

- A second e-mail renderer or an e-mail builder; campaign features (segments, automations, A/B tests, open tracking).
- Multisite, e-commerce, bookings, memberships, form logic, a visitor-facing AI chat, a headless or GraphQL API,
  real-time co-editing.
- More importers (Joomla, Drupal, Wix) – Claude rebuilds the site into the design system instead.
- More style presets, a plug-in API, approval workflows, PHP themes.

## Later

- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
- Right-to-left languages.
- Admin in more languages (German, Polish, French…) – possible now that the source texts are English.
