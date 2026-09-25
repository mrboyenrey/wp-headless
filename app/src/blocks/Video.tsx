import type { BlockOf } from './schema';

/**
 * VideoBlock -> a player, with the caption the editor wrote.
 *
 * The playback flags are passed through rather than decided here, so what plays
 * is what the editor configured. `controls={false}` renders no attribute at all,
 * which is exactly what the block meant.
 *
 * `playsInline` is the one exception, and it is hard-coded on purpose. WordPress
 * sets it by default and never surfaces it in the block settings, so there is no
 * editorial intent to carry: there is nothing for an editor to have chosen.
 * Emitting it unconditionally is what stops iOS handing the video to a
 * full-screen player the moment it starts.
 *
 * Unlike the image blocks, no box is reserved. An uploaded image has known
 * dimensions; a video added by address has none, and forcing a 16:9 frame would
 * letterbox portrait footage. `preload="metadata"` is the compromise: the
 * browser learns the intrinsic size without pulling down the file.
 */
export function Video({ block }: { block: BlockOf<'VideoBlock'> }) {
  const { src, posterUrl, caption, controls, autoplay, loop, muted } = block;

  return (
    <section className="mx-auto max-w-4xl px-6 py-16">
      <figure>
        <video
          src={src}
          poster={posterUrl ?? undefined}
          controls={controls}
          autoPlay={autoplay}
          loop={loop}
          muted={muted}
          playsInline
          preload="metadata"
          className="w-full rounded-xl bg-slate-950"
        />

        {caption ? (
          <figcaption className="mt-3 text-center font-mono text-xs uppercase tracking-widest text-slate-500">
            {caption}
          </figcaption>
        ) : null}
      </figure>
    </section>
  );
}
