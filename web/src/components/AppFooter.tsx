/**
 * The commit id comes from `__APP_COMMIT__`, baked in at build time by
 * `vite.config.ts` (`git rev-parse --short HEAD`) — it identifies exactly
 * what is deployed, not what the developer's working tree happens to be
 * when someone opens this in a browser later.
 */
export function AppFooter() {
  return (
    <footer className="shrink-0 border-t border-slate-200 bg-white px-4 py-2 text-center text-[11px] text-slate-400 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-600">
      All right reserved, Grand Electronoics and Home appliances thamarassery, 2026 · build {__APP_COMMIT__}
    </footer>
  )
}
