import { useState, useEffect } from 'react'
import { CheckCircle2, ClipboardList, PackageX, RefreshCw, UserX } from 'lucide-react'
import { api, type DashboardStats } from '../lib/api'

interface DashboardPanelProps {
  onNavigate: (section: 'tickets' | 'spares' | 'invoicing' | 'pricing' | 'settings', tab?: string) => void
}

/**
 * The five reporting buckets, in lifecycle order, mirroring
 * `TicketWorkflow::STATUS_BUCKETS` on the backend.
 *
 * Only the labels and colours live here — the grouping itself is the
 * server's, arriving pre-bucketed on `by_company`. Re-deriving it from raw
 * statuses in the client would give the dashboard its own opinion of what
 * "pending" means, and the two would part company the first time a status
 * is added.
 */
const BUCKETS = [
  { key: 'pending', label: 'Pending (queued)', bar: 'bg-blue-500', text: 'text-blue-600' },
  { key: 'in_progress', label: 'In progress (field work)', bar: 'bg-amber-500', text: 'text-amber-600' },
  { key: 'on_hold', label: 'On hold (customer / parts)', bar: 'bg-purple-500', text: 'text-purple-600' },
  { key: 'closed', label: 'Closed', bar: 'bg-emerald-500', text: 'text-emerald-600' },
  { key: 'cancelled', label: 'Cancelled / rejected', bar: 'bg-slate-400', text: 'text-slate-500' },
] as const

export function DashboardPanel({ onNavigate }: DashboardPanelProps) {
  const [stats, setStats] = useState<DashboardStats | null>(null)
  const [loading, setLoading] = useState(true)

  const loadStats = async () => {
    setLoading(true)
    try {
      const res = await api.getDashboardStats()
      setStats(res)
    } catch (err) {
      console.error('Failed to load dashboard stats', err)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadStats()
  }, [])

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center text-sm text-slate-500">
        Loading executive dashboard…
      </div>
    )
  }

  const companies = stats?.by_company ?? []

  // Every ticket carries a company (the column is NOT NULL), so summing the
  // per-company rows gives the same totals the whole-estate query would —
  // from one set of numbers, which is what stops the pipeline bars and the
  // table below them disagreeing on screen.
  const pipeline = BUCKETS.map((bucket) => ({
    ...bucket,
    count: companies.reduce((sum, company) => sum + company[bucket.key], 0),
  }))

  // Guarded against zero so an empty estate renders 0% rather than NaN.
  const total = stats?.total_tickets || 1
  const pct = (n: number) => Math.round((n / total) * 100)

  // The whole in-progress bucket, not the bare `in_progress` status: a job
  // that is on site or waiting on a part is just as much active field work.
  const inProgress = pipeline.find((b) => b.key === 'in_progress')?.count ?? 0

  return (
    <div className="space-y-6">
      {/* Header Banner */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            Executive Overview & Operations Dashboard
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            Real-time ticket statuses, SLA compliance metrics, spare inventory, and financial exposure.
          </p>
        </div>
        <button
          onClick={() => void loadStats()}
          className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          <RefreshCw className="h-3.5 w-3.5" /> Refresh live data
        </button>
      </div>

      {/* Summary strip: one ledger of the four headline counts, rather than
          four separate widget cards each carrying their own border and
          shadow. */}
      <div className="grid grid-cols-2 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
        <div className="p-5">
          <div className="flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
            <ClipboardList className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">Open tickets</span>
          </div>
          <div className="mt-2 text-3xl font-semibold tabular text-slate-900 dark:text-white">
            {stats?.open_tickets ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {inProgress} active in progress
          </p>
        </div>

        <div className="p-5">
          <div className="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
            <UserX className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">Unassigned jobs</span>
          </div>
          <div className="mt-2 text-3xl font-semibold tabular text-amber-600 dark:text-amber-400">
            {stats?.unassigned_tickets ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Awaiting technician assignment
          </p>
        </div>

        <div className="p-5">
          <div className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
            <CheckCircle2 className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">Closed today</span>
          </div>
          <div className="mt-2 text-3xl font-semibold tabular text-emerald-600 dark:text-emerald-400">
            {stats?.closed_today ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Completed jobs today
          </p>
        </div>

        <div className="p-5">
          <div className="flex items-center gap-1.5 text-purple-600 dark:text-purple-400">
            <PackageX className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">Pending defective spares</span>
          </div>
          <div className="mt-2 text-3xl font-semibold tabular text-purple-600 dark:text-purple-400">
            {stats?.pending_spares ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Clause 9 returns due to principal
          </p>
        </div>
      </div>

      {/* Main Grid: Ticket Statuses & Company Distribution */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Ticket Status Breakdown */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 lg:col-span-2">
          <h2 className="text-base font-bold text-slate-900 dark:text-white">
            Ticket Pipeline & Status Breakdown
          </h2>
          <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
            Distribution of {stats?.total_tickets ?? 0} total tickets across lifecycle stages.
          </p>

          <div className="space-y-4">
            {pipeline.map((bucket) => (
              <div key={bucket.key}>
                <div className="mb-1 flex justify-between text-xs font-medium">
                  <span className="text-slate-700 dark:text-slate-300">{bucket.label}</span>
                  <span className={`font-semibold ${bucket.text}`}>
                    {bucket.count} ({pct(bucket.count)}%)
                  </span>
                </div>
                <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                  <div className={`h-full ${bucket.bar}`} style={{ width: `${pct(bucket.count)}%` }} />
                </div>
              </div>
            ))}
          </div>

          <div className="mt-6 flex flex-wrap gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
            <button
              onClick={() => onNavigate('tickets')}
              className="rounded-lg bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-brand-700"
            >
              + Create Ticket Intake
            </button>
            <button
              onClick={() => onNavigate('tickets')}
              className="rounded-lg border border-slate-300 px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              View Ticket List
            </button>
          </div>
        </div>

        {/* Company Job Distribution */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-base font-bold text-slate-900 dark:text-white">
            Company Distribution
          </h2>
          <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
            Open work by principal company, busiest first
          </p>

          <div className="space-y-3">
            {companies.length > 0 ? (
              companies.map((company) => (
                <div
                  key={company.company_id}
                  className="rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40"
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                      {company.company_name}
                    </span>
                    {/* Open, not total: a company with 200 closed jobs and
                        nothing outstanding does not need attention today. */}
                    <span className="shrink-0 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-bold text-brand-800 dark:bg-brand-950/60 dark:text-brand-300">
                      {company.open} open
                    </span>
                  </div>

                  <dl className="mt-2.5 grid grid-cols-5 gap-1 text-center">
                    {BUCKETS.map((bucket) => (
                      <div key={bucket.key}>
                        <dt
                          className="truncate text-[10px] text-slate-500 dark:text-slate-400"
                          title={bucket.label}
                        >
                          {bucket.label.split(' ')[0]}
                        </dt>
                        <dd
                          className={`text-sm font-semibold tabular-nums ${
                            company[bucket.key] > 0
                              ? bucket.text
                              : 'text-slate-300 dark:text-slate-700'
                          }`}
                        >
                          {company[bucket.key]}
                        </dd>
                      </div>
                    ))}
                  </dl>

                  <button
                    onClick={() => onNavigate('tickets')}
                    className="mt-2 w-full rounded-lg border border-slate-200 py-1 text-[11px] font-medium text-slate-600 hover:bg-white dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                  >
                    View {company.count} {company.company_code} tickets
                  </button>
                </div>
              ))
            ) : (
              <div className="py-6 text-center text-xs text-slate-500">No company distribution data</div>
            )}
          </div>

          <div className="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
            <button
              onClick={() => onNavigate('settings', 'rate-cards')}
              className="w-full rounded-lg border border-slate-300 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              Manage Rate Cards & Companies
            </button>
          </div>
        </div>
      </div>

      {/* Recent Event Audit Stream */}
      <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-1 text-base font-bold text-slate-900 dark:text-white">
          Live Operational Activity Stream
        </h2>
        <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
          Recent audit log events across ticket intake, assignment, check-ins, and part usage.
        </p>

        <div className="divide-y divide-slate-100 dark:divide-slate-800">
          {stats?.recent_events && stats.recent_events.length > 0 ? (
            stats.recent_events.map((ev) => (
              <div key={ev.id} className="flex items-center justify-between gap-3 py-3">
                <div className="flex min-w-0 items-center gap-3">
                  <span className="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-xs font-mono font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    #{ev.ticket_no}
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-xs font-medium text-slate-900 dark:text-white">
                      {ev.description || ev.event_type}
                    </p>
                    <span className="text-[10px] text-slate-500 dark:text-slate-400">
                      {ev.event_type}
                    </span>
                  </div>
                </div>
                <span className="shrink-0 text-xs text-slate-400">
                  {new Date(ev.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            ))
          ) : (
            <div className="py-6 text-center text-xs text-slate-500">No recent events logged yet</div>
          )}
        </div>
      </div>
    </div>
  )
}
