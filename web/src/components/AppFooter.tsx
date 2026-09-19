/**
 * The commit id comes from `__APP_COMMIT__`, baked in at build time by
 * `vite.config.ts` — it identifies exactly what is deployed, not what the
 * developer's working tree happens to be when someone opens this in a
 * browser later. In the production Docker image that value is CI's
 * `GIT_COMMIT` build-arg (`.git` isn't in that build context, so it can't
 * come from a `git rev-parse` run inside the container); a local
 * `npm run dev`/`build` falls back to shelling out to git directly.
 */
export function AppFooter() {
  return (
    <footer className="shrink-0 border-t border-slate-200 bg-white px-4 py-2 text-center text-[11px] text-slate-400 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-600">
      All right reserved, Grand Electronoics and Home appliances thamarassery, 2026 · build {__APP_COMMIT__}
    </footer>
  )
}
