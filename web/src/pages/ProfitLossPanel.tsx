import { useState, useEffect } from 'react'
import { X } from 'lucide-react'
import {
  api,
  ApiError,
  type ProfitAndLossReport,
  type ProfitAndLossLine,
  type ProfitAndLossLineDetail,
  type TechnicianDuesReport,
  type TechnicianDue,
  type Money,
  type SavingsCenterBalance,
  type SavingsDetail,
} from '../lib/api'

/** For the one client-computed total (a sum across branch balances) that has no `Money` from the server. */
function formatPaise(paise: number): string {
  const sign = paise < 0 ? '-' : ''
  const rupees = (Math.abs(paise) / 100).toFixed(2)

  return `${sign}₹${rupees}`
}

function monthBounds(date: Date): { start: string; end: string } {
  const year = date.getFullYear()
  const month = date.getMonth()
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

  return { start: iso(new Date(year, month, 1)), end: iso(new Date(year, month + 1, 0)) }
}

/**
 * Income, expenses and what the service centre kept — plus the two "who
 * still owes whom" positions that a P&L total alone cannot answer.
 *
 * The margin numbers come straight from `ticket_charges`, the same frozen
 * rows an invoice or a payout run would claim, scoped to tickets closed in
 * the period. Company money only becomes real once a ticket is closed and
 * submitted, which is exactly when a charge line freezes — so "closed in
 * the period" is the right period boundary, not "invoiced in the period"
 * or "paid in the period".
 *
 * Technicians here are not on a fixed billing cycle the way companies
 * are — they are paid on demand — so the dues table below answers "what
 * would it cost to settle up with each of them right now" rather than
 * "what does this period's run owe them".
 */
const PROFIT_LOSS_TABS: { key: 'pnl' | 'dues' | 'cash'; label: string }[] = [
  { key: 'pnl', label: 'Profit & Loss' },
  { key: 'dues', label: 'Technician Dues' },
  { key: 'cash', label: 'Cash Balance' },
]

export function ProfitLossPanel() {
  const [tab, setTab] = useState<'pnl' | 'dues' | 'cash'>('pnl')
  const [period, setPeriod] = useState(() => monthBounds(new Date()))
  const [report, setReport] = useState<ProfitAndLossReport | null>(null)
  const [dues, setDues] = useState<TechnicianDuesReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [openLine, setOpenLine] = useState<ProfitAndLossLine | null>(null)

  const load = async (p: { start: string; end: string }) => {
    setLoading(true)
    setError(null)
    try {
      const [pnl, techDues] = await Promise.all([
        api.getProfitAndLoss(p.start, p.end),
        api.listTechnicianDues(),
      ])
      setReport(pnl)
      setDues(techDues)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'The profit & loss position could not be loaded.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load(period)
    // Only on mount — changing the date inputs waits for "Apply" so a
    // half-typed date does not fire a request per keystroke.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const income = report?.income.total.paise ?? 0
  const expenses = report?.expenses.total.paise ?? 0
  const netPositive = income - expenses >= 0

  const incomeLines = (report?.breakdown ?? []).filter((l) => l.is_inflow)
  const expenseLines = (report?.breakdown ?? []).filter((l) => !l.is_inflow)

  const techRows = (dues?.technicians ?? []).filter(
    (t) => t.total_due.paise !== 0 || t.paid.paise !== 0 || t.payout_count > 0,
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
          Profit &amp; Loss
        </h1>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Every income and expense recorded against closed tickets, what each technician is owed,
          and what the service centre actually has in hand.
        </p>
      </div>

      <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
        {PROFIT_LOSS_TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
              tab === t.key
                ? 'bg-brand-600 text-white'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'pnl' && (
        <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-end">
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
              Period from
            </span>
            <input
              type="date"
              value={period.start}
              onChange={(e) => setPeriod((p) => ({ ...p, start: e.target.value }))}
              className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
              to
            </span>
            <input
              type="date"
              value={period.end}
              onChange={(e) => setPeriod((p) => ({ ...p, end: e.target.value }))}
              className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </label>
          <div className="flex gap-1.5">
            <button
              onClick={() => {
                const bounds = monthBounds(new Date())
                setPeriod(bounds)
                void load(bounds)
              }}
              className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
              This month
            </button>
            <button
              onClick={() => {
                const d = new Date()
                const bounds = monthBounds(new Date(d.getFullYear(), d.getMonth() - 1, 1))
                setPeriod(bounds)
                void load(bounds)
              }}
              className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
              Last month
            </button>
            <button
              onClick={() => void load(period)}
              disabled={loading}
              className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
            >
              {loading ? 'Loading…' : 'Apply'}
            </button>
          </div>
        </div>
      </div>

      {error !== null && (
        <div className="rounded-xl bg-rose-50 p-4 text-sm font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
          {error}
        </div>
      )}

      {report !== null && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Income" amount={report.income.total} hint="Billed to companies + collected from customers" tone="emerald" />
            <StatTile label="Expenses" amount={report.expenses.total} hint="Royalty owed back + paid to technicians" tone="rose" />
            <StatTile
              label="Net margin"
              amount={report.net_margin}
              hint="What the service centre kept"
              tone={netPositive ? 'emerald' : 'rose'}
              emphasise
            />
            <StatTile
              label="Tickets closed"
              amount={null}
              rawValue={report.ticket_count}
              hint={`${report.period_start} to ${report.period_end}`}
              tone="slate"
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <LedgerTable title="Income" lines={incomeLines} tone="emerald" total={report.income.total} onSelect={setOpenLine} />
            <LedgerTable title="Expenses" lines={expenseLines} tone="rose" total={report.expenses.total} onSelect={setOpenLine} />
          </div>

          {report.ticket_count === 0 && (
            <div className="rounded-xl bg-slate-50 p-4 text-xs text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
              No tickets closed in this period yet, so there is nothing to show. Try a different
              date range.
            </div>
          )}
        </>
      )}

      {openLine !== null && (
        <ProfitAndLossDetailModal
          line={openLine}
          periodStart={report?.period_start ?? period.start}
          periodEnd={report?.period_end ?? period.end}
          onClose={() => setOpenLine(null)}
        />
      )}
        </div>
      )}

      {/* Technician dues — the outflow side that has no fixed billing
          cycle, so it is not covered by any period picker above. */}
      {tab === 'dues' && (
      <div className="space-y-3">
        <div>
          <h2 className="text-lg font-bold text-slate-900 dark:text-white">Pending payments to technicians</h2>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            What each technician is owed right now, whether or not a payout has been raised
            {dues !== null && ` · as of ${dues.as_of}`}. Raise a payout from{' '}
            <span className="font-medium">Invoicing &amp; Payouts</span> to pay it out.
          </p>
        </div>

        {dues !== null && (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Unclaimed work" amount={dues.totals.unclaimed} hint="Closed, no payout raised yet" tone="amber" />
            <StatTile label="Draft payouts" amount={dues.totals.draft} hint="Raised, not yet approved" tone="slate" />
            <StatTile label="Approved, unpaid" amount={dues.totals.approved} hint="Approved, waiting to be paid out" tone="indigo" />
            <StatTile label="Total owed" amount={dues.totals.total_due} hint="Everything not yet paid" tone="rose" emphasise />
          </div>
        )}

        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          {loading && dues === null ? (
            <div className="p-8 text-center text-sm text-slate-500">Loading technician dues…</div>
          ) : techRows.length === 0 ? (
            <div className="p-8 text-center text-sm text-slate-500">
              Nothing outstanding and nothing paid to any technician yet.
            </div>
          ) : (
            <table className="w-full min-w-[48rem] text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-3">Technician</th>
                  <th className="px-4 py-3 text-right">Unclaimed</th>
                  <th className="px-4 py-3 text-right">Draft</th>
                  <th className="px-4 py-3 text-right">Approved</th>
                  <th className="px-4 py-3 text-right">Total owed</th>
                  <th className="px-4 py-3 text-right">Paid</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                {techRows.map((row: TechnicianDue) => (
                  <tr key={row.technician.id}>
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-900 dark:text-white">{row.technician.name}</div>
                      <div className="text-[11px] text-slate-500">
                        {row.payout_count} payout{row.payout_count === 1 ? '' : 's'}
                        {row.pending_payout_count > 0 && ` · ${row.pending_payout_count} pending`}
                      </div>
                    </td>
                    <Amount value={row.unclaimed} tone={row.unclaimed.paise > 0 ? 'amber' : undefined} />
                    <Amount value={row.draft} />
                    <Amount value={row.approved} />
                    <td className="px-4 py-3 text-right tabular-nums font-bold text-slate-900 dark:text-white">
                      {row.total_due.formatted}
                    </td>
                    <Amount value={row.paid} tone="emerald" />
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
      )}

      {tab === 'cash' && <SavingsSection />}
    </div>
  )
}

/**
 * The service centre's own cash position, by branch.
 *
 * A different number from everything above it on this page: the P&L and
 * the technician dues both recognise money the moment a ticket closes or
 * a payout is raised, whether or not it has actually been collected or
 * paid. This only moves when cash genuinely does — an invoice payment
 * landing, a payout actually being paid, or a desk correction — which is
 * what makes it the answer to "can we actually afford this withdrawal
 * right now".
 */
function SavingsSection() {
  const [balances, setBalances] = useState<SavingsCenterBalance[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [openCenter, setOpenCenter] = useState<SavingsCenterBalance | null>(null)

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      setBalances(await api.listSavingsBalances())
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'The cash position could not be loaded.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const total = (balances ?? []).reduce((sum, row) => sum + row.balance.paise, 0)

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-lg font-bold text-slate-900 dark:text-white">Service centre cash balance</h2>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            What each branch actually has in hand — moves only when cash really does: an invoice
            payment landing, a technician payout being paid, or a correction. Click a branch for its
            ledger.
          </p>
        </div>
        <button
          onClick={() => void load()}
          disabled={loading}
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          {loading ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {error !== null && (
        <div className="rounded-xl bg-rose-50 p-4 text-sm font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
          {error}
        </div>
      )}

      {balances !== null && (
        <>
          <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-slate-900 dark:text-white">
                Total cash across all branches
              </span>
              <span
                className={`tabular-nums text-2xl font-bold ${
                  total < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-slate-900 dark:text-white'
                }`}
              >
                {formatPaise(total)}
              </span>
            </div>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {balances.map((row) => (
              <button
                key={row.service_center.id}
                onClick={() => setOpenCenter(row)}
                className="rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800"
              >
                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                  {row.service_center.name}
                </p>
                <p
                  className={`mt-1 tabular-nums text-xl font-bold ${
                    row.balance.paise < 0
                      ? 'text-rose-700 dark:text-rose-400'
                      : 'text-emerald-700 dark:text-emerald-400'
                  }`}
                >
                  {row.balance.formatted}
                </p>
                <p className="mt-1 text-[11px] text-slate-500">{row.service_center.code} · view ledger</p>
              </button>
            ))}
          </div>
        </>
      )}

      {openCenter !== null && (
        <SavingsLedgerModal
          center={openCenter}
          onClose={() => setOpenCenter(null)}
          onChanged={() => void load()}
        />
      )}
    </div>
  )
}

function SavingsLedgerModal({
  center,
  onClose,
  onChanged,
}: {
  center: SavingsCenterBalance
  onClose: () => void
  onChanged: () => void
}) {
  const [detail, setDetail] = useState<SavingsDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [adjustAmount, setAdjustAmount] = useState('')
  const [adjustReason, setAdjustReason] = useState('')
  const [saving, setSaving] = useState(false)

  const reload = async () => {
    setDetail(await api.getSavingsDetail(center.service_center.id))
  }

  useEffect(() => {
    void (async () => {
      try {
        await reload()
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'The ledger could not be loaded.')
      }
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [center.service_center.id])

  const submitAdjustment = async () => {
    if (adjustAmount.trim() === '' || adjustReason.trim() === '') {
      setError('A signed amount and a reason are both required.')

      return
    }

    setSaving(true)
    setError(null)
    try {
      await api.adjustSavings(center.service_center.id, adjustAmount.trim(), adjustReason.trim())
      setAdjustAmount('')
      setAdjustReason('')
      await reload()
      onChanged()
    } catch (err) {
      setError(
        err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The adjustment could not be saved.',
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between border-b border-slate-200 pb-4 dark:border-slate-800">
          <div>
            <h3 className="text-xl font-bold text-slate-900 dark:text-white">{center.service_center.name}</h3>
            <p className="text-xs text-slate-500">Current balance: {detail?.balance.formatted ?? center.balance.formatted}</p>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {error !== null && (
          <div className="my-4 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
            {error}
          </div>
        )}

        <div className="my-4 space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Correction</p>
          <div className="flex flex-wrap items-center gap-2">
            <input
              value={adjustAmount}
              onChange={(e) => setAdjustAmount(e.target.value)}
              placeholder="Amount ₹ (negative to debit)"
              className="w-48 rounded-lg border border-slate-300 px-2 py-1.5 text-xs tabular-nums dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <input
              value={adjustReason}
              onChange={(e) => setAdjustReason(e.target.value)}
              placeholder="Reason"
              className="min-w-48 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <button
              onClick={() => void submitAdjustment()}
              disabled={saving}
              className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
            >
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
          <p className="text-[11px] text-slate-500">
            For whatever a real cash event does not already cover — every other entry below is
            written automatically.
          </p>
        </div>

        <div className="my-4 max-h-80 space-y-1.5 overflow-y-auto">
          {detail === null ? (
            <p className="p-6 text-center text-sm text-slate-500">Loading ledger…</p>
          ) : detail.ledger.length === 0 ? (
            <p className="p-6 text-center text-sm text-slate-500">No cash movements recorded yet.</p>
          ) : (
            detail.ledger.map((entry) => (
              <div
                key={entry.id}
                className="flex items-center justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2 text-xs dark:border-slate-800"
              >
                <div className="min-w-0">
                  <p className="truncate font-medium text-slate-900 dark:text-white">{entry.description}</p>
                  <p className="text-[11px] text-slate-500">
                    {entry.created} · {entry.source_type.replace('_', ' ')}
                    {entry.created_by !== null && ` · ${entry.created_by}`}
                  </p>
                </div>
                <div className="shrink-0 text-right">
                  <p
                    className={`tabular-nums font-semibold ${
                      entry.entry_type === 'credit'
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : 'text-rose-700 dark:text-rose-400'
                    }`}
                  >
                    {entry.entry_type === 'credit' ? '+' : '−'}
                    {entry.amount.formatted}
                  </p>
                  <p className="text-[11px] text-slate-500">bal {entry.balance_after.formatted}</p>
                </div>
              </div>
            ))
          )}
        </div>

        <div className="flex justify-end border-t border-slate-200 pt-4 dark:border-slate-800">
          <button
            onClick={onClose}
            className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  )
}

function LedgerTable({
  title,
  lines,
  tone,
  total,
  onSelect,
}: {
  title: string
  lines: ProfitAndLossLine[]
  tone: 'emerald' | 'rose'
  total: Money
  onSelect: (line: ProfitAndLossLine) => void
}) {
  const toneClass = tone === 'emerald' ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'

  return (
    <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
        <h3 className="text-sm font-bold text-slate-900 dark:text-white">{title}</h3>
      </div>
      {lines.length === 0 ? (
        <p className="p-6 text-center text-xs text-slate-500">Nothing on this side for the period.</p>
      ) : (
        <table className="w-full text-left text-sm">
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {lines.map((line) => (
              <tr
                key={`${line.ledger}-${line.line_type}`}
                onClick={() => onSelect(line)}
                className="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50"
              >
                <td className="px-4 py-2.5">
                  <div className="text-slate-900 dark:text-white">{line.line_type_label}</div>
                  <div className="text-[11px] text-slate-500">
                    {line.ledger_label} · {line.ticket_count} ticket{line.ticket_count === 1 ? '' : 's'} · view
                    tickets
                  </div>
                </td>
                <td className="px-4 py-2.5 text-right tabular-nums font-medium text-slate-700 dark:text-slate-300">
                  {line.amount.formatted}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="flex items-baseline justify-between border-t border-slate-200 px-4 py-3 dark:border-slate-800">
        <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total {title.toLowerCase()}</span>
        <span className={`tabular-nums text-lg font-bold ${toneClass}`}>{total.formatted}</span>
      </div>
    </div>
  )
}

/**
 * Which tickets make up one row of the breakdown — the same drill-down an
 * invoice or a payout gives from its total down to the frozen lines behind
 * it, applied to the P&L summary.
 */
function ProfitAndLossDetailModal({
  line,
  periodStart,
  periodEnd,
  onClose,
}: {
  line: ProfitAndLossLine
  periodStart: string
  periodEnd: string
  onClose: () => void
}) {
  const [detail, setDetail] = useState<ProfitAndLossLineDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    void (async () => {
      try {
        setDetail(await api.getProfitAndLossDetail(line.ledger, line.line_type, periodStart, periodEnd))
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'The ticket detail could not be loaded.')
      }
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [line.ledger, line.line_type, periodStart, periodEnd])

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between border-b border-slate-200 pb-4 dark:border-slate-800">
          <div>
            <h3 className="text-xl font-bold text-slate-900 dark:text-white">{line.line_type_label}</h3>
            <p className="text-xs text-slate-500">
              {line.ledger_label} · {periodStart} to {periodEnd} · {detail?.total.formatted ?? line.amount.formatted}
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {error !== null && (
          <div className="my-4 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
            {error}
          </div>
        )}

        <div className="my-4 max-h-96 space-y-3 overflow-y-auto">
          {detail === null && error === null ? (
            <p className="p-6 text-center text-sm text-slate-500">Loading tickets…</p>
          ) : detail !== null && detail.tickets.length === 0 ? (
            <p className="p-6 text-center text-sm text-slate-500">No tickets behind this line.</p>
          ) : (
            detail?.tickets.map((group) => (
              <div
                key={group.ticket_id ?? 'unattributed'}
                className="rounded-xl border border-slate-200 dark:border-slate-800"
              >
                <div className="flex items-baseline justify-between border-b border-slate-200 bg-slate-50 px-4 py-2 dark:border-slate-800 dark:bg-slate-800/40">
                  <span className="text-sm font-semibold text-slate-900 dark:text-white">{group.ticket_no}</span>
                  <span className="tabular-nums text-sm font-semibold text-slate-700 dark:text-slate-300">
                    {group.subtotal.formatted}
                  </span>
                </div>
                <div className="divide-y divide-slate-100 dark:divide-slate-800">
                  {group.lines.map((l) => (
                    <div key={l.id} className="flex items-start justify-between gap-3 px-4 py-2.5 text-xs">
                      <p className="text-slate-600 dark:text-slate-300">{l.description}</p>
                      <p className="shrink-0 tabular-nums font-medium text-slate-800 dark:text-slate-200">
                        {l.amount.formatted}
                      </p>
                    </div>
                  ))}
                </div>
              </div>
            ))
          )}
        </div>

        <div className="flex justify-end border-t border-slate-200 pt-4 dark:border-slate-800">
          <button
            onClick={onClose}
            className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  )
}

function Amount({ value, tone }: { value: Money; tone?: 'rose' | 'emerald' | 'amber' }) {
  const colour =
    value.paise === 0
      ? 'text-slate-400'
      : tone === 'rose'
        ? 'font-semibold text-rose-700 dark:text-rose-400'
        : tone === 'emerald'
          ? 'font-medium text-emerald-700 dark:text-emerald-400'
          : tone === 'amber'
            ? 'font-semibold text-amber-700 dark:text-amber-400'
            : 'text-slate-700 dark:text-slate-300'

  return <td className={`px-4 py-3 text-right tabular-nums ${colour}`}>{value.formatted}</td>
}

function StatTile({
  label,
  amount,
  rawValue,
  hint,
  tone,
  emphasise,
}: {
  label: string
  amount: Money | null
  rawValue?: number
  hint: string
  tone: 'amber' | 'slate' | 'indigo' | 'rose' | 'emerald'
  emphasise?: boolean
}) {
  const accent = {
    amber: 'text-amber-700 dark:text-amber-400',
    slate: 'text-slate-700 dark:text-slate-300',
    indigo: 'text-indigo-700 dark:text-indigo-400',
    rose: 'text-rose-700 dark:text-rose-400',
    emerald: 'text-emerald-700 dark:text-emerald-400',
  }[tone]

  return (
    <div
      className={`rounded-2xl border bg-white p-4 dark:bg-slate-900 ${
        emphasise
          ? 'border-2 border-current ' + accent
          : 'border-slate-200 dark:border-slate-800'
      }`}
    >
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 tabular-nums text-xl font-bold ${accent}`}>
        {amount !== null ? amount.formatted : rawValue}
      </p>
      <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
    </div>
  )
}
