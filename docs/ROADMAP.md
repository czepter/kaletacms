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
2. **1.5 – a minimal built-in mailing for small lists** – “send the latest news to subscribers”:
   - an e-mail editor in the same builder, with an e-mail-safe set of elements (section, one or two columns, heading,
     text, image, button, divider, news list) rendered to table-based HTML with inline styles from the design system;
   - preview on desktop and phone, test e-mail to yourself, send now or scheduled;
   - one-click unsubscribe (`List-Unsubscribe`, RFC 8058), plain-text part, sending in batches through the existing mail
     queue;
   - sending only through an SMTP relay (Brevo, Amazon SES, Mailgun…) set in Settings – shared-hosting `mail()` is not
     good enough for bulk mail; without a relay the feature stays off;
   - no open tracking by default (privacy); click counts optional.

   A full campaign tool (segments, automations, A/B tests) stays out of scope – that is what the connected services are for.

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

## 1.5 – newsletter mailing

The second newsletter step described above.

## Later

- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
- Right-to-left languages.
- “Related content” block across collections for hub-and-spoke sites.
