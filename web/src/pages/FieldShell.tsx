import { useAuth } from '../lib/auth'
import { AppFooter } from '../components/AppFooter'

/**
 * The field shell.
 *
 * Mobile-first and deliberately not the desk layout scaled down. A
 * technician uses this one-handed, outdoors, on a weak connection, so it
 * shows one job at a time with large targets and almost no typing.
 *
 * The job list itself needs the ticket module, which is not built yet —
 * so rather than render fake jobs, this says plainly what is missing.
 */
export function FieldShell() {
  const { user, logout } = useAuth()

  return (
    <div className="flex min-h-full flex-col bg-slate-100 dark:bg-slate-950">
      {/* Sticky header: on a phone, the identity and sign-out must stay
          reachable without scrolling back up. */}
      <header className="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center gap-3">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            {user?.name?.charAt(0) ?? 'T'}
          </div>
          <div className="min-w-0 flex-1">
            <div className="truncate font-semibold leading-tight">{user?.name}</div>
            <div className="truncate text-xs text-slate-500 dark:text-slate-400">
              {user?.service_center?.name ?? 'Field technician'}
            </div>
          </div>
          <button
            onClick={() => void logout()}
            className="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium dark:border-slate-700"
          >
            Sign out
          </button>
        </div>
      </header>

      <main className="flex-1 space-y-4 p-4">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Today&rsquo;s jobs</h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            {new Date().toLocaleDateString('en-IN', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
            })}
          </p>
        </div>

        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center dark:border-slate-700 dark:bg-slate-900">
          <p className="font-medium">No jobs yet</p>
          <p className="mx-auto mt-2 max-w-xs text-sm text-slate-500 dark:text-slate-400">
            Ticket assignment is not built yet, so there is nothing to show
            here. This screen will list your jobs ordered by how much SLA
            time is left on each.
          </p>
        </div>

        {/*
          The closure flow, described rather than mocked. Every step here
          exists because the money depends on it: the incentive structure
          pays for a fast close, which is a standing reason to backdate one.
        */}
        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="mb-3 font-semibold">The closure flow, once built</h2>
          <ol className="space-y-3 text-sm">
            {[
              ['Check in', 'Location captured and timestamped by the server, never by the phone.'],
              ['Diagnose', 'Coded symptom and resolution from a list — no free typing.'],
              ['Parts', 'Spares used, priced at cost plus the agreed 10–15% margin.'],
              ['Evidence', 'Serial plate and working-unit photos, compressed before upload.'],
              ['Sign-off', 'Customer OTP or signature, then cash collected if out of warranty.'],
            ].map(([title, detail], i) => (
              <li key={title} className="flex gap-3">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                  {i + 1}
                </span>
                <span>
                  <span className="font-medium">{title}.</span>{' '}
                  <span className="text-slate-600 dark:text-slate-400">{detail}</span>
                </span>
              </li>
            ))}
          </ol>
        </section>
      </main>

      <AppFooter />
    </div>
  )
}
