/**
 * The WordPress connection.
 *
 * SERVER ONLY. This module reads `process.env`, which does not exist in the
 * browser, so it must never be imported by a component. Anything the browser
 * needs arrives as a prop, serialised into the document by the server. The
 * TypeScript `import type` used for `SiteContent` in components is what keeps
 * this file out of the client bundle.
 */

const WORDPRESS_URL = process.env.WP_GRAPHQL_URL ?? 'http://localhost:8080/graphql';

interface GraphQLResponse<T> {
  data?: T;
  errors?: { message: string }[];
}

/**
 * Run a query against WPGraphQL.
 *
 * Returns the raw `data` payload without validation. Validation is the caller's
 * job, because only the caller knows which schema applies: the page query and
 * the navigation query answer to different shapes.
 */
export async function wpQuery<T>(
  query: string,
  variables: Record<string, unknown> = {},
): Promise<T> {
  const response = await fetch(WORDPRESS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, variables }),
  });

  if (!response.ok) {
    throw new Error(`WordPress returned HTTP ${response.status} for ${WORDPRESS_URL}`);
  }

  const payload = (await response.json()) as GraphQLResponse<T>;

  // GraphQL answers with a 200 even when the query failed, so the `errors`
  // array has to be checked explicitly or failures pass silently.
  if (payload.errors?.length) {
    throw new Error(`WordPress rejected the query: ${payload.errors.map((error) => error.message).join('; ')}`);
  }

  if (payload.data === undefined) {
    throw new Error('WordPress returned no data.');
  }

  return payload.data;
}
