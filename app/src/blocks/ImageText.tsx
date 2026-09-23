import type { BlockOf } from './schema';

/**
 * ImageTextBlock -> two columns, image beside prose.
 *
 * Which side the image occupies is decided by `mediaPosition`, already narrowed
 * by the schema to 'left' or 'right'.
 *
 * The order is set in the DOM rather than with CSS `order`, deliberately. CSS
 * order changes what is seen without changing what is read, so a screen reader
 * would announce the image before the heading it belongs to. Swapping the
 * children keeps the two in agreement.
 */
export function ImageText({ block }: { block: BlockOf<'ImageTextBlock'> }) {
  const { heading, bodyHtml, image, mediaPosition } = block;

  const prose = (
    <div>
      {heading ? (
        <h2 className="font-display text-2xl font-semibold tracking-tight text-white sm:text-3xl">
          {heading}
        </h2>
      ) : null}

      {bodyHtml ? (
        <div
          className="mt-4 space-y-4 text-base leading-relaxed text-slate-300 [&_a]:text-brand-400 [&_a]:underline [&_a]:underline-offset-4 [&_strong]:font-semibold [&_strong]:text-white"
          dangerouslySetInnerHTML={{ __html: bodyHtml }}
        />
      ) : null}
    </div>
  );

  const figure = image ? (
    <img
      src={image.url}
      alt={image.alt ?? ''}
      width={image.width ?? undefined}
      height={image.height ?? undefined}
      loading="lazy"
      className="w-full rounded-xl object-cover"
    />
  ) : null;

  return (
    <section className="mx-auto max-w-6xl px-6 py-16">
      {/* Without an image there is nothing to sit beside, so the grid collapses
          to a single column rather than leaving a gap. */}
      <div className={`grid items-center gap-10 ${image ? 'md:grid-cols-2' : ''}`}>
        {mediaPosition === 'left' ? (
          <>
            {figure}
            {prose}
          </>
        ) : (
          <>
            {prose}
            {figure}
          </>
        )}
      </div>
    </section>
  );
}
