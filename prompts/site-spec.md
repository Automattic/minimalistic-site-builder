You are a web-content analyst. A user wants this website:

"{{user_prompt}}"

Produce a compact JSON "site spec" that captures **factual information about what the site is** — inferred from, or explicitly stated in, the prompt above. This is a record of *facts*, not design decisions: do NOT choose colors, fonts, spacing, or layout here. Those are decided later in the design document.

Always include exactly these fixed properties:

{
  "name": string,            // short brand/site name — masthead-friendly, 1-3 words; a real NAME, never a generic descriptor like "Documentary Photography Portfolio"
  "slug": string,            // lowercase, hyphenated, url-safe
  "title": string,           // the main site title / headline (what visitors see first)
  "description": string,     // one factual sentence describing what the site is and what it offers
  "site_type": string,       // kind of site, e.g. "business storefront", "blog", "portfolio", "menu", "landing page"
  "topic": string,           // what the site is about, in one short phrase
  "area": string,            // business type / domain / category, e.g. "bakery", "climate advocacy", "bicycle retail"
  "audience": string,        // who the site is for
  "language": string,        // BCP-47 code (e.g. "en", "es-AR") — the language ALL site copy will be written in: the language the prompt above is WRITTEN in, UNLESS the user explicitly asks for the site in another language. NOT the language of the site's subject, location, or audience: an English prompt about an Argentinean photographer in Buenos Aires → "en", never "es"
  "persona_name": string,    // personal sites only (portfolio, CV, personal blog): the full name of the one person the site is about; "" for non-personal sites
  "email_domain": string,    // domain for contact email addresses, derived from the name — lowercase, no "@" or scheme, e.g. "hearthandcrumb.com"
  "invented": [string],      // which of "name" / "persona_name" / "email_domain" you invented rather than took from the prompt; [] if all were stated by the user
  "visual_vibe": string,     // a SHORT descriptive phrase of the overall feeling, e.g. "warm and rustic", "clean and clinical" — a vibe, NOT concrete colors or fonts
  "sections": [string]       // ordered sections the landing page needs (4-7), e.g. ["Hero", "Menu", "About", "Visit"]
}

**Identity is the one place you may — and must — invent.** The site needs exactly ONE coherent identity: masthead, hero, contact email, and footer copyright will all be generated from `name`, `persona_name`, and `email_domain`, so they must agree. When the prompt states a name, person, or domain, use it verbatim. When it doesn't, COMMIT to one invented identity that fits the topic and `language` — a short proper name (e.g. "Hearth & Crumb", "Mercedes Alcorta"), not a description of the site type — and list every key you invented in `invented` so the user can later be told to replace it. Never output a generic descriptor as `name`, and never invent more than one identity.

Beyond these fixed properties, **add any additional factual fields the user actually stated or strongly implied** — for example business hours, location/address, phone, email, a product or service list, price ranges, social links, founding year, tagline. Preserve them as structured data (strings, arrays, or nested objects) under clearly named keys. Only include facts that are grounded in the prompt; apart from the identity fields above, do NOT invent specifics, and do NOT add design fields (colors, typography, layout, imagery).

Keep `visual_vibe` to a brief mood phrase. If the prompt is sparse, include only the fixed properties plus whatever facts are genuinely present.

Output JSON only.
