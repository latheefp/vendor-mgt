import { useState, useEffect } from 'react'
import { useAuth } from '../lib/auth'
import { AppFooter } from '../components/AppFooter'
import { api, ApiError, type WalletSummary } from '../lib/api'

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

        <WalletCard />

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

/**
 * What this technician is owed, and the one action they can take on it
 * themselves.
 *
 * "Withdraw" does not move money on its own — there is no payment
 * gateway behind this app. It raises a draft payout over everything
 * unclaimed to date, exactly the way the desk's own "generate payout"
 * does, so the desk still has to approve it and record the actual bank
 * transfer. What this removes is having to ask someone to start that run.
 */
function WalletCard() {
  const [wallet, setWallet] = useState<WalletSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [withdrawing, setWithdrawing] = useState(false)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)

  const load = async () => {
    setLoading(true)
    try {
      setWallet(await api.getMyWallet())
    } catch {
      // Desk staff never see this screen, but a stray link should not
      // crash the page — it just leaves the card unable to load.
      setWallet(null)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const withdraw = async () => {
    setWithdrawing(true)
    setMessage(null)
    try {
      const result = await api.withdrawMyWallet()
      setMessage({
        type: 'success',
        text: `Withdrawal requested (${result.payout_no}). The desk still needs to approve and pay it.`,
      })
      await load()
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError ? err.message : 'The withdrawal could not be requested.',
      })
    } finally {
      setWithdrawing(false)
    }
  }

  if (loading) {
    return (
      <div className="rounded-2xl border border-slate-200 bg-white p-5 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">
        Loading your wallet…
      </div>
    )
  }

  if (wallet === null) {
    return null
  }

  const canWithdraw = wallet.due.total_due.paise > 0

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold">Your wallet</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            What you have earned and not yet been paid
          </p>
        </div>
        <div className="text-right">
          <p className="tabular-nums text-2xl font-bold text-emerald-600 dark:text-emerald-400">
            {wallet.due.total_due.formatted}
          </p>
          <p className="text-[11px] text-slate-500">total owed to you</p>
        </div>
      </div>

      <dl className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
        <div className="rounded-lg bg-slate-50 p-2 dark:bg-slate-800/50">
          <dt className="text-slate-500">Unclaimed</dt>
          <dd className="tabular-nums font-semibold text-slate-900 dark:text-white">
            {wallet.due.unclaimed.formatted}
          </dd>
        </div>
        <div className="rounded-lg bg-slate-50 p-2 dark:bg-slate-800/50">
          <dt className="text-slate-500">Draft</dt>
          <dd className="tabular-nums font-semibold text-slate-900 dark:text-white">
            {wallet.due.draft.formatted}
          </dd>
        </div>
        <div className="rounded-lg bg-slate-50 p-2 dark:bg-slate-800/50">
          <dt className="text-slate-500">Approved</dt>
          <dd className="tabular-nums font-semibold text-slate-900 dark:text-white">
            {wallet.due.approved.formatted}
          </dd>
        </div>
      </dl>

      {message !== null && (
        <div
          className={`mt-3 rounded-lg p-2.5 text-xs font-medium ${
            message.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
              : 'bg-rose-50 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
          }`}
        >
          {message.text}
        </div>
      )}

      <button
        onClick={() => void withdraw()}
        disabled={!canWithdraw || withdrawing}
        className="mt-3 w-full rounded-xl bg-brand-600 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40"
      >
        {withdrawing ? 'Requesting…' : canWithdraw ? 'Withdraw' : 'Nothing to withdraw yet'}
      </button>

      {wallet.history.length > 0 && (
        <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
          <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            Recent payouts
          </p>
          <div className="space-y-1.5">
            {wallet.history.slice(0, 5).map((payout) => (
              <div key={payout.id} className="flex items-center justify-between text-xs">
                <div className="min-w-0">
                  <span className="font-medium text-slate-900 dark:text-white">{payout.payout_no}</span>{' '}
                  <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold capitalize text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {payout.status}
                  </span>
                </div>
                <span className="tabular-nums text-slate-700 dark:text-slate-300">{payout.net.formatted}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </section>
  )
}
