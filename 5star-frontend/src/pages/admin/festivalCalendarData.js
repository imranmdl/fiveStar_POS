/**
 * Shared festival data for the Festival Calendar page (and, in future, a
 * Dashboard upcoming-festival reminder) — one list, so the two stay in sync.
 *
 * Ported verbatim from admin/assets/festival-calendar.js. Do not re-derive
 * this list; port changes back from that file if it changes.
 *
 * DATES ARE BEST-EFFORT, NOT AUTHORITATIVE. Hindu festival dates are lunar and
 * shift every year (2026 additionally carries an extra lunar month, Adhik
 * Maas, pushing most dates later than usual); Islamic festival dates depend on
 * moon sighting and can move by a day even within India. Treat every date
 * here as a starting point to confirm, not a fact — which is why the Festival
 * Calendar page lets staff edit it before it's relied on.
 */

export const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December'];

// How long after the festival date its gift page stays live before it is
// automatically out of season.
export const DEFAULT_CLOSE_AFTER_DAYS = 5;

export const FESTIVALS = [
  { slug: 'makar-sankranti-gifts', title: 'Makar Sankranti / Pongal', month: 1, note: 'Harvest festival — til, gud, khichdi', date2026: '2026-01-14', confidence: 'confirmed' },
  { slug: 'vasant-panchami-gifts', title: 'Vasant Panchami', month: 1, note: 'Saraswati puja, the start of spring', date2026: '2026-01-23', confidence: 'estimate' },
  { slug: 'maha-shivratri-gifts', title: 'Maha Shivratri', month: 2, note: 'A night of fasting and prayer to Shiva', date2026: '2026-02-15', confidence: 'estimate' },
  { slug: 'holi-gifts', title: 'Holi', month: 3, note: 'Colours, gujiya and thandai', date2026: '2026-03-04', confidence: 'confirmed' },
  { slug: 'ugadi-gudi-padwa-gifts', title: 'Ugadi / Gudi Padwa', month: 3, note: 'New year in the Deccan and Maharashtra', date2026: '2026-03-19', confidence: 'estimate' },
  { slug: 'ram-navami-gifts', title: 'Ram Navami', month: 3, note: 'Jayanti — Lord Rama’s birth', date2026: '2026-03-26', confidence: 'confirmed' },
  { slug: 'mahavir-jayanti-gifts', title: 'Mahavir Jayanti', month: 3, note: 'Jayanti — Lord Mahavira’s birth', date2026: '2026-03-31', confidence: 'estimate' },
  { slug: 'hanuman-jayanti-gifts', title: 'Hanuman Jayanti', month: 4, note: 'Jayanti — Lord Hanuman’s birth', date2026: '2026-04-01', confidence: 'confirmed' },
  { slug: 'akshaya-tritiya-gifts', title: 'Akshaya Tritiya', month: 4, note: 'An auspicious day for new beginnings', date2026: '2026-04-19', confidence: 'estimate' },
  { slug: 'buddha-purnima-gifts', title: 'Buddha Purnima', month: 5, note: 'Jayanti — the Buddha’s birth', date2026: '2026-05-01', confidence: 'estimate' },
  { slug: 'rath-yatra-gifts', title: 'Rath Yatra', month: 6, note: 'Lord Jagannath’s chariot festival', date2026: '2026-06-16', confidence: 'estimate' },
  { slug: 'guru-purnima-gifts', title: 'Guru Purnima', month: 7, note: 'Honouring teachers and gurus', date2026: '2026-07-29', confidence: 'estimate' },
  { slug: 'raksha-bandhan-gifts', title: 'Raksha Bandhan', month: 8, note: 'The rakhi thread, and the mithai that goes with it', date2026: '2026-08-28', confidence: 'confirmed' },
  { slug: 'onam-gifts', title: 'Onam', month: 8, note: 'Kerala’s harvest festival and the Onasadya feast', date2026: '2026-08-26', confidence: 'estimate' },
  { slug: 'janmashtami-gifts', title: 'Janmashtami', month: 9, note: 'Jayanti — Krishna’s birth', date2026: '2026-09-04', confidence: 'estimate' },
  { slug: 'ganesh-chaturthi-gifts', title: 'Ganesh Chaturthi', month: 9, note: 'Modak, sheera and ten days of celebration', date2026: '2026-09-14', confidence: 'confirmed' },
  { slug: 'navratri-dussehra-gifts', title: 'Navratri & Dussehra', month: 10, note: 'Nine nights of fasting, then Dussehra', date2026: '2026-10-20', confidence: 'confirmed' },
  { slug: 'karva-chauth-gifts', title: 'Karva Chauth', month: 10, note: 'A day of fasting for a spouse’s long life', date2026: '2026-10-29', confidence: 'estimate' },
  { slug: 'diwali-gifts', title: 'Diwali', month: 11, note: 'The festival of lights', date2026: '2026-11-08', confidence: 'confirmed' },
  { slug: 'bhai-dooj-gifts', title: 'Bhai Dooj', month: 11, note: 'Closes the Diwali season, celebrating siblings', date2026: '2026-11-10', confidence: 'estimate' },
  { slug: 'chhath-puja-gifts', title: 'Chhath Puja', month: 11, note: 'A fast to the sun god, major in Bihar and UP', date2026: '2026-11-15', confidence: 'estimate' },
  { slug: 'guru-nanak-jayanti-gifts', title: 'Guru Nanak Jayanti', month: 11, note: 'Jayanti — Guru Nanak’s birth', date2026: '2026-11-24', confidence: 'estimate' },
  { slug: 'christmas-new-year-gifts', title: 'Christmas & New Year', month: 12, note: 'Plum cake, mulled drinks and the year-end', date2026: '2026-12-25', closeAfterDays: 12, confidence: 'confirmed' },
  { slug: 'eid-gifts', title: 'Eid al-Fitr', month: null, note: 'Ends the month of Ramzan — moon-sighting dependent', date2026: '2026-03-20', confidence: 'uncertain' },
  { slug: 'eid-al-adha-gifts', title: 'Eid al-Adha (Bakrid)', month: null, note: 'The festival of sacrifice — moon-sighting dependent', date2026: '2026-05-27', confidence: 'uncertain' },
];

/** Adds `days` to a 'YYYY-MM-DD' date string, returning the same shape. */
export function addDays(dateStr, days) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Adds `years` to a 'YYYY-MM-DD' date string. Used only as a rough placeholder for
 * "roughly this time next year" — Hindu/Islamic dates do not actually move by
 * exact multiples of a year, so this is a starting guess to correct, not a fact. */
export function addYears(dateStr, years) {
  const [y, m, d] = dateStr.split('-').map(Number);
  return `${y + years}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/**
 * A continuous, forward-scrolling list: today's month first, running through
 * the rest of this year, then straight on into next year's January, February
 * and so on — rather than snapping back to January once December ends. A
 * festival whose date has already gone by this year drops to its slot about a
 * year from now instead of sitting at the top under a stale date, so the page
 * always reads top-to-bottom as "what's coming up," not "the calendar year."
 *
 * Each festival gets a `cycleDate`: this year's date if it hasn't happened
 * yet, otherwise next year's (estimated — see addYears()).
 *
 * @return Array<[monthLabel: string, festivals: Array<festival & {cycleDate}>]>
 */
export function cycleGroups(referenceDate = new Date()) {
  const todayStr = referenceDate.toISOString().slice(0, 10);

  const withCycleDate = FESTIVALS.map((f) => ({
    ...f,
    cycleDate: f.date2026 >= todayStr ? f.date2026 : addYears(f.date2026, 1),
  })).sort((a, b) => a.cycleDate.localeCompare(b.cycleDate));

  const groups = [];
  let currentKey = null;

  for (const festival of withCycleDate) {
    const [year, month] = festival.cycleDate.split('-');
    const key = `${year}-${month}`;

    if (key !== currentKey) {
      currentKey = key;
      groups.push([`${MONTHS[Number(month)]} ${year}`, []]);
    }

    groups[groups.length - 1][1].push(festival);
  }

  return groups;
}

/**
 * Festivals whose (best-known) date falls within the next `withinDays` days,
 * paired with the live/admin collection summary for that slug if one exists.
 *
 * @param {Object<string, object>} bySlug   admin collection summaries, keyed by slug
 * @param {number} withinDays
 * @param {Date} today
 */
export function upcoming(bySlug, withinDays, today = new Date()) {
  const todayStr = today.toISOString().slice(0, 10);
  const horizon = new Date(today);
  horizon.setDate(horizon.getDate() + withinDays);
  const horizonStr = horizon.toISOString().slice(0, 10);

  return FESTIVALS
    .filter((f) => f.date2026 && f.date2026 >= todayStr && f.date2026 <= horizonStr)
    .map((f) => ({ festival: f, collection: bySlug[f.slug] || null }))
    .sort((a, b) => a.festival.date2026.localeCompare(b.festival.date2026));
}
