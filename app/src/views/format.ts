const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

/**
 * Format a WordPress date for display.
 *
 * Deliberately not `Intl.DateTimeFormat`. The date arrives as ISO 8601 with no
 * timezone, so `new Date()` would interpret it in the server's timezone while
 * the browser interprets it in the visitor's, and a post written at 09:00 could
 * render as one date on the server and another in the browser. React reports
 * that as a hydration mismatch, which is a confusing way to learn about a
 * timezone assumption. Parsing the parts and formatting them by hand is
 * deterministic in both places.
 */
export function formatDate(value: string | null): string | null {
  if (value === null) {
    return null;
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

  if (!match) {
    return value;
  }

  const [, year, month, day] = match;
  const name = MONTHS[Number(month) - 1];

  return name === undefined ? value : `${Number(day)} ${name} ${year}`;
}
