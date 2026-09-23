import { SITE_NAME } from '../config';

export function SiteFooter() {
  return (
    <footer className="border-t border-slate-800 bg-slate-950">
      <div className="mx-auto flex max-w-6xl flex-col gap-2 px-6 py-8 text-sm text-slate-400 sm:flex-row sm:items-center sm:justify-between">
        <p>
          {SITE_NAME} &middot; content in WordPress, rendered by React
        </p>
        <p className="font-mono text-xs">
          Edit any page in <a href="http://localhost:8080/wp-admin" className="text-brand-400 underline-offset-4 hover:underline">wp-admin</a> and reload
        </p>
      </div>
    </footer>
  );
}
