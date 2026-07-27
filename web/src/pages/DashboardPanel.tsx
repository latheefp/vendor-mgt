import { useState, useEffect } from 'react'
import { api, type DashboardStats } from '../lib/api'

interface DashboardPanelProps {
  onNavigate: (section: 'tickets' | 'spares' | 'invoicing' | 'pricing' | 'settings', tab?: string) => void
}

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

  const byStatus = stats?.by_status ?? {
    received: 0,
    assigned: 0,
    in_progress: 0,
    on_hold: 0,
    closed: 0,
    cancelled: 0,
  }

  const total = stats?.total_tickets || 1

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
          className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          <span>↻</span> Refresh Live Data
        </button>
      </div>

      {/* Top Metric Cards (KPI Grid) */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Open Tickets
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
              📋
            </span>
          </div>
          <div className="mt-3 text-3xl font-extrabold text-slate-900 dark:text-white">
            {stats?.open_tickets ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {byStatus.in_progress} active in-progress
          </p>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Unassigned Jobs
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
              ⏳
            </span>
          </div>
          <div className="mt-3 text-3xl font-extrabold text-amber-600 dark:text-amber-400">
            {stats?.unassigned_tickets ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Awaiting technician assignment
          </p>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Closed Today
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
              ✓
            </span>
          </div>
          <div className="mt-3 text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">
            {stats?.closed_today ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Completed jobs today
          </p>
        </div>

        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Pending Defective Spares
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300">
              📦
            </span>
          </div>
          <div className="mt-3 text-3xl font-extrabold text-purple-600 dark:text-purple-400">
            {stats?.pending_spares ?? 0}
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Clause 9 returns due to principal
          </p>
        </div>
      </div>

      {/* Main Grid: Ticket Statuses & Vendor Distribution */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Ticket Status Breakdown */}
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 lg:col-span-2">
          <h2 className="text-base font-bold text-slate-900 dark:text-white">
            Ticket Pipeline & Status Breakdown
          </h2>
          <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
            Distribution of {stats?.total_tickets ?? 0} total tickets across lifecycle stages.
          </p>

          <div className="space-y-4">
            <div>
              <div className="mb-1 flex justify-between text-xs font-medium">
                <span className="text-slate-700 dark:text-slate-300">Received (Intake)</span>
                <span className="font-semibold text-blue-600">{byStatus.received} ({Math.round((byStatus.received / total) * 100)}%)</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div className="h-full bg-blue-500" style={{ width: `${(byStatus.received / total) * 100}%` }} />
              </div>
            </div>

            <div>
              <div className="mb-1 flex justify-between text-xs font-medium">
                <span className="text-slate-700 dark:text-slate-300">Assigned</span>
                <span className="font-semibold text-indigo-600">{byStatus.assigned} ({Math.round((byStatus.assigned / total) * 100)}%)</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div className="h-full bg-indigo-500" style={{ width: `${(byStatus.assigned / total) * 100}%` }} />
              </div>
            </div>

            <div>
              <div className="mb-1 flex justify-between text-xs font-medium">
                <span className="text-slate-700 dark:text-slate-300">In Progress (Field Work)</span>
                <span className="font-semibold text-amber-600">{byStatus.in_progress} ({Math.round((byStatus.in_progress / total) * 100)}%)</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div className="h-full bg-amber-500" style={{ width: `${(byStatus.in_progress / total) * 100}%` }} />
              </div>
            </div>

            <div>
              <div className="mb-1 flex justify-between text-xs font-medium">
                <span className="text-slate-700 dark:text-slate-300">On Hold (Customer / Parts)</span>
                <span className="font-semibold text-purple-600">{byStatus.on_hold} ({Math.round((byStatus.on_hold / total) * 100)}%)</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div className="h-full bg-purple-500" style={{ width: `${(byStatus.on_hold / total) * 100}%` }} />
              </div>
            </div>

            <div>
              <div className="mb-1 flex justify-between text-xs font-medium">
                <span className="text-slate-700 dark:text-slate-300">Closed (Verified & Billed)</span>
                <span className="font-semibold text-emerald-600">{byStatus.closed} ({Math.round((byStatus.closed / total) * 100)}%)</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div className="h-full bg-emerald-500" style={{ width: `${(byStatus.closed / total) * 100}%` }} />
              </div>
            </div>
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

        {/* Vendor Job Distribution */}
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 className="text-base font-bold text-slate-900 dark:text-white">
            Vendor Distribution
          </h2>
          <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
            Job volume by principal company
          </p>

          <div className="space-y-3">
            {stats?.by_vendor && stats.by_vendor.length > 0 ? (
              stats.by_vendor.map((v) => (
                <div
                  key={v.vendor_id}
                  className="flex items-center justify-between rounded-xl border border-slate-100 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-800/40"
                >
                  <span className="text-sm font-semibold text-slate-900 dark:text-white">
                    {v.vendor_name}
                  </span>
                  <span className="rounded-full bg-brand-100 px-2.5 py-1 text-xs font-bold text-brand-800 dark:bg-brand-950/60 dark:text-brand-300">
                    {v.count} jobs
                  </span>
                </div>
              ))
            ) : (
              <div className="py-6 text-center text-xs text-slate-500">No vendor distribution data</div>
            )}
          </div>

          <div className="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
            <button
              onClick={() => onNavigate('settings', 'rate-cards')}
              className="w-full rounded-lg border border-slate-300 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              Manage Rate Cards & Vendors
            </button>
          </div>
        </div>
      </div>

      {/* Recent Event Audit Stream */}
      <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-1 text-base font-bold text-slate-900 dark:text-white">
          Live Operational Activity Stream
        </h2>
        <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
          Recent audit log events across ticket intake, assignment, check-ins, and part usage.
        </p>

        <div className="divide-y divide-slate-100 dark:divide-slate-800">
          {stats?.recent_events && stats.recent_events.length > 0 ? (
            stats.recent_events.map((ev) => (
              <div key={ev.id} className="flex items-center justify-between py-3">
                <div className="flex items-center gap-3">
                  <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-mono font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    #{ev.ticket_no}
                  </span>
                  <div>
                    <p className="text-xs font-medium text-slate-900 dark:text-white">
                      {ev.description || ev.event_type}
                    </p>
                    <span className="text-[10px] text-slate-500 uppercase tracking-wider">
                      Event: {ev.event_type}
                    </span>
                  </div>
                </div>
                <span className="text-xs text-slate-400">
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
