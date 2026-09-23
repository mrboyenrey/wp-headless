import type { BlockOf } from './schema';

/**
 * HeroBlock -> a full-bleed cover.
 *
 * Props come in as the validated block and nothing else: this component has no
 * knowledge of WordPress, GraphQL or HTML parsing. Swapping the CMS would not
 * touch this file.
 */
export function Hero({ block }: { block: BlockOf<'HeroBlock'> }) {
  const { heading, subheading, image, overlayOpacity } = block;

  return (
    <section className="relative isolate flex min-h-[62vh] items-center justify-center overflow-hidden">
      {image ? (
        <img
          src={image.url}
          // An image with no alt text is decorative as far as this component is
          // concerned; an empty alt tells a screen reader to skip it, which is
          // the correct behaviour. Inventing a description would be worse.
          alt={image.alt ?? ''}
          width={image.width ?? undefined}
          height={image.height ?? undefined}
          // Deliberately eager: this is the first thing on the page, and
          // deferring it would mean watching an empty band instead.
          loading="eager"
          className="absolute inset-0 -z-20 h-full w-full object-cover"
        />
      ) : null}

      {/*
        The scrim is a separate layer rather than a colour on the text, so the
        contrast of the headline is set by the editor's dimRatio and stays
        predictable whatever image they pick.
      */}
      <div
        aria-hidden="true"
        className="absolute inset-0 -z-10 bg-slate-950"
        style={{ opacity: overlayOpacity / 100 }}
      />

      <div className="mx-auto max-w-3xl px-6 py-24 text-center">
        <h1 className="font-display text-4xl font-semibold tracking-tight text-white text-balance sm:text-6xl">
          {heading}
        </h1>

        {subheading ? (
          <p className="mt-6 text-lg text-slate-100 text-pretty sm:text-xl">{subheading}</p>
        ) : null}
      </div>
    </section>
  );
}
