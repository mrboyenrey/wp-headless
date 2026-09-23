/**
 * Constants shared by the server and the browser.
 *
 * Nothing in this file may read from `process.env`: it is imported by
 * components, so it ends up in the browser bundle, where `process` does not
 * exist. Server-only configuration lives in `wp/client.ts`, which is only ever
 * imported by the server entry point.
 */

export const SITE_NAME = 'Boien Reyes';
export const SITE_TAGLINE = 'Web developer, IT operations, and automation';
