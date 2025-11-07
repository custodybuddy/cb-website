// CustodyBuddy Amazon link hardening: tagging, accessibility, compliance
// ---------------------------------------------------------------
// What this does for every <a class="amazon-link">:
// 1) Ensures target/rel are safe & SEO-friendly (sponsored, nofollow, noopener)
// 2) Adds your Associates tag on Amazon links if it's missing
// 3) Sets a helpful aria-label using the nearest product title
// 4) Appends a visible "(paid link)" note if not present
// Notes:
// - Prefer full Amazon URLs or SiteStripe snippets. Avoid amzn.to shortlinks;
//   query params (like ?tag=...) usually won't carry through those redirects.

document.addEventListener('DOMContentLoaded', () => {
  'use strict';

  // ---- CONFIG -------------------------------------------------
  const CONFIG = {
    TAG: 'custodybudd0c-20',       // your Amazon.ca Associates tag
    LOCALE_PARAM: 'en_CA',         // language param for amazon.ca links
    ADD_REL: ['sponsored', 'nofollow', 'noopener'], // safe default rel values
    MODIFY_ONLY_AMAZON: true       // don't touch non-Amazon domains
  };

  // ---- HELPERS ------------------------------------------------
  const isAmazonHost = (host) => /\bamazon\.[a-z.]+$/i.test(host) || /^www\.amazon\.[a-z.]+$/i.test(host);
  const isAmazonShort = (host) => /\bamzn\.to$/i.test(host);

  const ensureRelValues = (el, values) => {
    const current = (el.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
    const set = new Set(current);
    values.forEach(v => set.add(v));
    el.setAttribute('rel', Array.from(set).join(' '));
  };

  const findNearestTitle = (anchor) => {
    // Look up to the nearest card <li> and grab an <h3>
    const card = anchor.closest('li, .product-card');
    const h = card ? card.querySelector('h3') : null;
    const title = h ? h.textContent.trim().replace(/\s+/g, ' ') : '';
    return title;
  };

  const ensurePaidLinkNote = (anchor) => {
    // If immediate sibling span.paid doesn't exist, add one
    const parent = anchor.parentElement;
    if (!parent) return;
    const hasPaid = Array.from(parent.childNodes).some(
      n => n.nodeType === 1 && n.classList && n.classList.contains('paid')
    );
    if (!hasPaid) {
      const span = document.createElement('span');
      span.className = 'paid';
      span.textContent = '(paid link)';
      // space + span for nice separation if needed
      parent.insertBefore(document.createTextNode(' '), anchor.nextSibling);
      parent.insertBefore(span, anchor.nextSibling.nextSibling);
    }
  };

  const setIfMissing = (urlObj, name, value) => {
    if (!urlObj.searchParams.get(name)) {
      urlObj.searchParams.set(name, value);
    }
  };

  // ---- MAIN ---------------------------------------------------
  document.querySelectorAll('a.amazon-link').forEach(a => {
    // target + rel hardening
    a.setAttribute('target', '_blank');
    ensureRelValues(a, CONFIG.ADD_REL);

    // Skip if no href
    const raw = a.getAttribute('href');
    if (!raw) return;

    // Try URL parsing
    let u;
    try {
      u = new URL(raw, window.location.origin);
    } catch {
      // invalid URL—do nothing
      return;
    }

    // Respect domain rules
    const host = u.host.toLowerCase();
    if (CONFIG.MODIFY_ONLY_AMAZON && !isAmazonHost(host)) {
      return; // leave non-Amazon links untouched
    }
    if (isAmazonShort(host)) {
      // Can't reliably append ?tag=... to amzn.to shortlinks.
      // Leave as-is, but warn in console to swap to a full Amazon URL or SiteStripe link.
      console.warn('[Amazon link]', 'Use full Amazon URL or SiteStripe instead of amzn.to for proper tagging:', raw);
      return;
    }

    // Add Associates tag if missing (works across amazon.* TLDs)
    setIfMissing(u, 'tag', CONFIG.TAG);

    // Optional: set locale for amazon.ca
    if (host.endsWith('.amazon.ca')) {
      setIfMissing(u, 'language', CONFIG.LOCALE_PARAM);
    }

    // Commit href if we changed anything
    a.href = u.toString();

    // Accessibility: aria-label with title + "(paid link)"
    if (!a.hasAttribute('aria-label')) {
      const title = findNearestTitle(a);
      const label = title
        ? `View ${title} on Amazon (paid link)`
        : 'View on Amazon (paid link)';
      a.setAttribute('aria-label', label);
    }

    // Ensure visible "(paid link)" marker next to the anchor
    ensurePaidLinkNote(a);

    // Mark as processed to keep idempotent behavior
    a.dataset.amazonCleaned = 'true';
  });
});
