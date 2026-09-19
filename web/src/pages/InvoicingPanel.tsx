import { useState, useEffect } from 'react'
import {
  api,
  ApiError,
  type SettlementDetail,
  type SettlementLine,
  type TechnicianPayout,
  type TechnicianPayoutDetail,
  type CompanyInvoice,
  type CompanyInvoiceDetail,
  type CompanyItem,
  type InvoicePreview,
} from '../lib/api'

/** First and last day of the month containing `date`, as `YYYY-MM-DD`. */
function monthBounds(date: Date): { start: string; end: string } {
  const year = date.getFullYear()
  const month = date.getMonth()
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

  return { start: iso(new Date(year, month, 1)), end: iso(new Date(year, month + 1, 0)) }
}

/**
 * Which run a detail view belongs to.
 *
 * The two settlement runs are the same shape on the wire and read the
 * same way on screen, so one component renders both and this is the only
 * thing it needs to know to call the right endpoints.
 */
type RunKind = 'invoice' | 'payout'

export function InvoicingPanel() {
  const [invoices, setInvoices] = useState<CompanyInvoice[]>([])
  const [payouts, setPayouts] = useState<TechnicianPayout[]>([])
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState<'invoices' | 'payouts'>('invoices')
  const [generating, setGenerating] = useState(false)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)

  // The opened run. `kind` decides which API the detail view writes back
  // through; `detail` is null only while the first fetch is in flight.
  const [openRun, setOpenRun] = useState<{ kind: RunKind; id: number } | null>(null)
  const [detail, setDetail] = useState<CompanyInvoiceDetail | TechnicianPayoutDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  // Which company and which period the next run covers.
  //
  // Both are explicit and both default to *this* month. The server falls
  // back to LAST month when a period is omitted, so a run fired without
  // one silently skips everything closed since the 1st — which looked, on
  // the ticket screen, like closing a job produced no invoice at all.
  const [companies, setCompanies] = useState<CompanyItem[]>([])
  const [companyId, setCompanyId] = useState<number | null>(null)
  const [period, setPeriod] = useState(() => monthBounds(new Date()))
  const [preview, setPreview] = useState<InvoicePreview | null>(null)
  const [previewing, setPreviewing] = useState(false)

  useEffect(() => {
    loadData()
  }, [])

  useEffect(() => {
    void (async () => {
      try {
        const list = await api.listCompanies()
        const active = list.filter((c) => c.is_active)
        setCompanies(active)
        setCompanyId((current) => current ?? active[0]?.id ?? null)
      } catch (err) {
        console.error('Failed to load companies', err)
      }
    })()
  }, [])

  // What the run would raise, shown before it is fired. An empty period is
  // a 422 from generate, and a zero here says the same thing without
  // looking like a failure.
  useEffect(() => {
    if (companyId === null || period.start === '' || period.end === '') {
      setPreview(null)

      return
    }

    let cancelled = false
    setPreviewing(true)

    void (async () => {
      try {
        const result = await api.previewInvoice(companyId, period.start, period.end)
        if (!cancelled) setPreview(result)
      } catch {
        if (!cancelled) setPreview(null)
      } finally {
        if (!cancelled) setPreviewing(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [companyId, period.start, period.end])

  const loadData = async () => {
    setLoading(true)
    try {
      const [invList, payList] = await Promise.all([api.listInvoices(), api.listPayouts()])
      setInvoices(invList)
      setPayouts(payList)
    } catch (err) {
      console.error('Failed to load invoicing data', err)
    } finally {
      setLoading(false)
    }
  }

  const openDetail = async (kind: RunKind, id: number) => {
    setOpenRun({ kind, id })
    setDetail(null)
    setDetailLoading(true)
    setMessage(null)

    try {
      setDetail(kind === 'invoice' ? await api.getInvoice(id) : await api.getPayout(id))
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError ? err.message : 'The breakdown could not be loaded.',
      })
      setOpenRun(null)
    } finally {
      setDetailLoading(false)
    }
  }

  const closeDetail = () => {
    setOpenRun(null)
    setDetail(null)
  }

  /**
   * Both writes return the whole run back, recomputed. Taking the server's
   * copy rather than patching the line in place is what keeps the header
   * totals honest — they are re-derived from the lines server-side, and a
   * local edit would show a total that no longer matches its own parts.
   */
  const applyLineChange = async (
    change: () => Promise<CompanyInvoiceDetail | TechnicianPayoutDetail>,
    successText: string,
  ) => {
    const updated = await change()
    setDetail(updated)
    setMessage({ type: 'success', text: successText })
    // The row behind the modal shows the total that just moved.
    void loadData()
  }

  const handleGenerateInvoice = async () => {
    if (companyId === null) {
      setMessage({ type: 'error', text: 'Select the company to invoice.' })

      return
    }

    setGenerating(true)
    setMessage(null)
    try {
      const created = await api.generateInvoice(companyId, period.start, period.end)
      setMessage({
        type: 'success',
        text: `Company Invoice #${created.invoice_no} generated for ₹${(created.total_paise / 100).toFixed(2)}`,
      })
      setPreview(null)
      void loadData()
    } catch (err) {
      // "Nothing is waiting to be invoiced" comes back per-field and says
      // far more than the envelope's generic message.
      setMessage({
        type: 'error',
        text:
          err instanceof ApiError
            ? Object.values(err.fields).flat().join(' ') || err.message
            : 'Failed to generate invoice',
      })
    } finally {
      setGenerating(false)
    }
  }

  const handleGeneratePayout = async () => {
    setGenerating(true)
    setMessage(null)
    try {
      const created = await api.generatePayout(1)
      setMessage({
        type: 'success',
        text: `Technician Payout #${created.payout_no} generated for ₹${(created.total_payout_paise / 100).toFixed(2)}`,
      })
      void loadData()
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to generate payout' })
    } finally {
      setGenerating(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            Invoicing & Technician Payout Runs
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            Generate company receivable statements and calculate technician payout settlements with frozen SLA ledger lines.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setActiveTab('invoices')}
            className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
              activeTab === 'invoices'
                ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700'
                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
            }`}
          >
            Company Invoices ({invoices.length})
          </button>
          <button
            onClick={() => setActiveTab('payouts')}
            className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
              activeTab === 'payouts'
                ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700'
                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
            }`}
          >
            Technician Payouts ({payouts.length})
          </button>
        </div>
      </div>

      {message && (
        <div
          className={`rounded-xl p-4 text-sm font-medium ${
            message.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
              : 'bg-rose-50 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
          }`}
        >
          {message.text}
        </div>
      )}

      {/* COMPANY INVOICES */}
      {activeTab === 'invoices' && (
        <div className="space-y-4">
          <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div>
              <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                Company Receivable Statements
              </h2>
              <p className="text-xs text-slate-500">
                Sweeps up every frozen charge line for a company whose ticket closed inside the
                period, and claims it onto one invoice.
              </p>
            </div>

            <div className="flex flex-wrap items-end gap-3">
              <label className="flex flex-col gap-1">
                <span className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                  Company
                </span>
                <select
                  value={companyId ?? ''}
                  onChange={(e) => setCompanyId(e.target.value === '' ? null : Number(e.target.value))}
                  className="rounded-lg border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {companies.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </label>

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
                  onClick={() => setPeriod(monthBounds(new Date()))}
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  This month
                </button>
                <button
                  onClick={() => {
                    const d = new Date()

                    setPeriod(monthBounds(new Date(d.getFullYear(), d.getMonth() - 1, 1)))
                  }}
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  Last month
                </button>
              </div>

              <button
                onClick={handleGenerateInvoice}
                disabled={generating || companyId === null || preview?.line_count === 0}
                className="ml-auto rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
              >
                {generating ? 'Generating Run…' : '+ Generate Invoice Run'}
              </button>
            </div>

            {/* What the run will pick up, before it is fired. */}
            <div className="rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/40">
              {previewing ? (
                <span className="text-slate-500">Checking what is waiting…</span>
              ) : preview === null ? (
                <span className="text-slate-500">Pick a company and a period.</span>
              ) : preview.line_count === 0 ? (
                <span className="text-slate-500">
                  Nothing is waiting to be invoiced in this period. Charges are claimed by the
                  period a ticket <strong>closed</strong> in — widen the dates if a job closed
                  outside them.
                </span>
              ) : (
                <span className="text-slate-700 dark:text-slate-300">
                  Ready to bill{' '}
                  <strong className="tabular-nums">
                    ₹{(preview.totals.total_paise / 100).toFixed(2)}
                  </strong>{' '}
                  across {preview.ticket_count} ticket{preview.ticket_count === 1 ? '' : 's'} (
                  {preview.line_count} line{preview.line_count === 1 ? '' : 's'}).
                </span>
              )}
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {loading ? (
              <div className="p-8 text-center text-sm text-slate-500">Loading invoices...</div>
            ) : invoices.length === 0 ? (
              <div className="p-8 text-center text-sm text-slate-500">
                No company invoice runs generated yet. Click <strong>Generate New Company Invoice Run</strong> above.
              </div>
            ) : (
              <div className="overflow-x-auto">
              <table className="w-full min-w-[42rem] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Invoice #</th>
                    <th className="px-4 py-3">Company</th>
                    <th className="px-4 py-3">Billing Period</th>
                    <th className="px-4 py-3">Tickets Included</th>
                    <th className="px-4 py-3">Total Amount</th>
                    <th className="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                  {invoices.map((inv) => (
                    <tr
                      key={inv.id}
                      onClick={() => void openDetail('invoice', inv.id)}
                      className="cursor-pointer hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td className="px-4 py-3 font-semibold text-brand-600 underline-offset-2 hover:underline dark:text-brand-400">
                        {inv.invoice_no}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                        {inv.company?.name}
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600 dark:text-slate-400">
                        {inv.period_start} to {inv.period_end}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                        {inv.ticket_count} tickets
                      </td>
                      <td className="px-4 py-3 font-bold text-emerald-600 dark:text-emerald-400">
                        ₹{(inv.total_paise / 100).toFixed(2)}
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-block rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 capitalize">
                          {inv.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* TECHNICIAN PAYOUTS */}
      {activeTab === 'payouts' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div>
              <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                Technician Payout Runs
              </h2>
              <p className="text-xs text-slate-500">
                Monthly contractor & employee payout settlement with SLA bonus and travel allowances.
              </p>
            </div>
            <button
              onClick={handleGeneratePayout}
              disabled={generating}
              className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
            >
              {generating ? 'Generating Run...' : '+ Generate Technician Payout Run'}
            </button>
          </div>

          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {loading ? (
              <div className="p-8 text-center text-sm text-slate-500">Loading payouts...</div>
            ) : payouts.length === 0 ? (
              <div className="p-8 text-center text-sm text-slate-500">
                No technician payout runs generated yet. Click <strong>Generate Technician Payout Run</strong> above.
              </div>
            ) : (
              <div className="overflow-x-auto">
              <table className="w-full min-w-[38rem] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Payout #</th>
                    <th className="px-4 py-3">Technician</th>
                    <th className="px-4 py-3">Period</th>
                    <th className="px-4 py-3">Payout Amount</th>
                    <th className="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                  {payouts.map((pay) => (
                    <tr
                      key={pay.id}
                      onClick={() => void openDetail('payout', pay.id)}
                      className="cursor-pointer hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td className="px-4 py-3 font-semibold text-indigo-600 underline-offset-2 hover:underline dark:text-indigo-400">
                        {pay.payout_no}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                        {pay.technician?.name} ({pay.technician?.code})
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600 dark:text-slate-400">
                        {pay.period_start} to {pay.period_end}
                      </td>
                      <td className="px-4 py-3 font-bold text-emerald-600 dark:text-emerald-400">
                        ₹{(pay.total_payout_paise / 100).toFixed(2)}
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-block rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300 capitalize">
                          {pay.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            )}
          </div>
        </div>
      )}

      {openRun && (
        <SettlementDetailModal
          kind={openRun.kind}
          runId={openRun.id}
          detail={detail}
          loading={detailLoading}
          onClose={closeDetail}
          onApply={applyLineChange}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Detail view                                                         */
/* ------------------------------------------------------------------ */

interface SettlementDetailModalProps {
  kind: RunKind
  runId: number
  detail: CompanyInvoiceDetail | TechnicianPayoutDetail | null
  loading: boolean
  onClose: () => void
  onApply: (
    change: () => Promise<CompanyInvoiceDetail | TechnicianPayoutDetail>,
    successText: string,
  ) => Promise<void>
}

/**
 * Narrowing helper — the two run types differ only in their identifiers.
 *
 * Discriminates on the payload rather than on `kind` so the narrowing is
 * a fact about the data in hand, not a promise about which call produced it.
 */
function isInvoice(detail: SettlementDetail): detail is CompanyInvoiceDetail {
  return 'invoice_no' in detail
}

function SettlementDetailModal({
  kind,
  runId,
  detail,
  loading,
  onClose,
  onApply,
}: SettlementDetailModalProps) {
  // Which line is open for editing, and the two values being edited. One
  // line at a time on purpose: each override carries its own reason, and a
  // form that batched them would invite one reason for several changes.
  const [editingLineId, setEditingLineId] = useState<number | null>(null)
  const [draftAmount, setDraftAmount] = useState('')
  const [draftReason, setDraftReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [lineError, setLineError] = useState<string | null>(null)

  const startEditing = (line: SettlementLine) => {
    setEditingLineId(line.id)
    // Seeded with the current amount so a small correction is a small edit.
    setDraftAmount(line.amount.rupees)
    setDraftReason(line.override_reason ?? '')
    setLineError(null)
  }

  const cancelEditing = () => {
    setEditingLineId(null)
    setLineError(null)
  }

  const runChange = async (
    change: () => Promise<CompanyInvoiceDetail | TechnicianPayoutDetail>,
    successText: string,
  ) => {
    setSaving(true)
    setLineError(null)
    try {
      await onApply(change, successText)
      setEditingLineId(null)
    } catch (err) {
      // The API answers a rejected override per-field — an unparseable
      // amount, a missing reason, an invoice already sent. Those messages
      // say more than a generic failure, so they are shown verbatim.
      setLineError(
        err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The line could not be saved.',
      )
    } finally {
      setSaving(false)
    }
  }

  const saveOverride = (lineId: number) =>
    runChange(
      () =>
        kind === 'invoice'
          ? api.overrideInvoiceLine(runId, lineId, draftAmount, draftReason)
          : api.overridePayoutLine(runId, lineId, draftAmount, draftReason),
      'Line updated. The run total has been recalculated.',
    )

  const revertOverride = (lineId: number) =>
    runChange(
      () =>
        kind === 'invoice'
          ? api.resetInvoiceLine(runId, lineId)
          : api.resetPayoutLine(runId, lineId),
      'Override removed. The line is back to the amount the run raised.',
    )

  const title = detail === null
    ? 'Loading…'
    : isInvoice(detail)
      ? detail.invoice_no
      : detail.payout_no

  const subject = detail === null
    ? null
    : isInvoice(detail)
      ? detail.company?.name ?? null
      : detail.technician === null
        ? null
        : `${detail.technician.name} (${detail.technician.code})`

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between border-b border-slate-200 pb-4 dark:border-slate-800">
          <div>
            <h3 className="text-xl font-bold text-slate-900 dark:text-white">{title}</h3>
            {detail !== null && (
              <p className="text-xs text-slate-500">
                {subject} · {detail.period_start} to {detail.period_end} · {detail.ticket_count} ticket
                {detail.ticket_count === 1 ? '' : 's'} · <span className="capitalize">{detail.status}</span>
              </p>
            )}
          </div>
          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            ✕
          </button>
        </div>

        {loading || detail === null ? (
          <div className="p-8 text-center text-sm text-slate-500">Loading the breakdown…</div>
        ) : (
          <>
            {/* What the total is made of. A single figure is what an
                argument with a company starts from; this is the answer. */}
            <div className="my-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/40">
              <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                Amount split
              </h4>

              <div className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                {detail.split.map((bucket) => (
                  <div key={bucket.key} className="flex items-baseline justify-between gap-2">
                    <span className="text-slate-600 dark:text-slate-400">
                      {bucket.label}
                      {bucket.sign < 0 && (
                        <span className="ml-1 text-[10px] uppercase text-slate-400">deducted</span>
                      )}
                    </span>
                    <span className="tabular-nums font-medium text-slate-900 dark:text-white">
                      {bucket.sign < 0 && !bucket.amount.rupees.startsWith('-') && bucket.amount.paise !== 0 && '−'}
                      {bucket.amount.formatted}
                    </span>
                  </div>
                ))}
              </div>

              <div className="mt-3 space-y-1 border-t border-slate-200 pt-3 dark:border-slate-700">
                <div className="flex items-baseline justify-between">
                  <span className="text-sm font-semibold text-slate-900 dark:text-white">Total</span>
                  <span className="tabular-nums text-lg font-bold text-emerald-600 dark:text-emerald-400">
                    {detail.totals.total.formatted}
                  </span>
                </div>

                {isInvoice(detail) && detail.totals.paid.paise !== 0 && (
                  <>
                    <div className="flex items-baseline justify-between text-xs text-slate-600 dark:text-slate-400">
                      <span>Paid</span>
                      <span className="tabular-nums">{detail.totals.paid.formatted}</span>
                    </div>
                    <div className="flex items-baseline justify-between text-xs font-medium text-slate-800 dark:text-slate-200">
                      <span>Balance</span>
                      <span className="tabular-nums">{detail.totals.balance.formatted}</span>
                    </div>
                  </>
                )}
              </div>
            </div>

            {!detail.is_editable && detail.locked_reason !== null && (
              <div className="my-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                {detail.locked_reason}
              </div>
            )}

            {!isInvoice(detail) && (
              <PayoutSettlement
                detail={detail}
                onSettled={(text) => void onApply(() => api.getPayout(runId), text)}
              />
            )}

            {lineError !== null && (
              <div className="my-4 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
                {lineError}
              </div>
            )}

            {/* The lines themselves, under the job that produced them. */}
            <div className="my-4 space-y-4">
              {detail.tickets.map((group) => (
                <div
                  key={group.ticket_id ?? 'unattributed'}
                  className="rounded-xl border border-slate-200 dark:border-slate-800"
                >
                  <div className="flex items-baseline justify-between border-b border-slate-200 bg-slate-50 px-4 py-2 dark:border-slate-800 dark:bg-slate-800/40">
                    <span className="text-sm font-semibold text-slate-900 dark:text-white">
                      {group.ticket_no}
                    </span>
                    <span className="tabular-nums text-sm font-semibold text-slate-700 dark:text-slate-300">
                      {group.subtotal.formatted}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-100 dark:divide-slate-800">
                    {group.lines.map((line) => (
                      <div key={line.id} className="px-4 py-3">
                        {editingLineId === line.id ? (
                          <div className="space-y-2">
                            <p className="text-xs font-medium text-slate-700 dark:text-slate-300">
                              {line.type_label}
                            </p>
                            <p className="text-[11px] text-slate-500">{line.description}</p>

                            <div className="flex flex-wrap items-center gap-2">
                              <input
                                value={draftAmount}
                                onChange={(e) => setDraftAmount(e.target.value)}
                                placeholder="450.00"
                                className="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-xs tabular-nums dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                              />
                              <input
                                value={draftReason}
                                onChange={(e) => setDraftReason(e.target.value)}
                                placeholder="Reason — why this line bills a different amount"
                                className="min-w-40 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                              />
                              <button
                                onClick={() => void saveOverride(line.id)}
                                disabled={saving || draftAmount.trim() === '' || draftReason.trim() === ''}
                                className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                              >
                                {saving ? 'Saving…' : 'Save'}
                              </button>
                              <button
                                onClick={cancelEditing}
                                disabled={saving}
                                className="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-50 dark:text-slate-300 dark:hover:bg-slate-800"
                              >
                                Cancel
                              </button>
                            </div>

                            <p className="text-[11px] text-slate-500">
                              The frozen ticket charge is not touched — this changes what the
                              {kind === 'invoice' ? ' invoice' : ' payout'} bills, and keeps the original
                              beside it.
                            </p>
                          </div>
                        ) : (
                          <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                              <p className="text-xs font-medium text-slate-800 dark:text-slate-200">
                                {line.type_label}
                                {line.is_overridden && (
                                  <span className="ml-1.5 rounded bg-amber-200 px-1 text-[10px] font-semibold text-amber-900 dark:bg-amber-800 dark:text-amber-100">
                                    overridden
                                  </span>
                                )}
                              </p>
                              <p className="text-[11px] text-slate-500">{line.description}</p>

                              {line.quantity !== null && line.unit_amount !== null && (
                                <p className="text-[11px] text-slate-400">
                                  {line.quantity} × {line.unit_amount.formatted}
                                </p>
                              )}

                              {line.is_overridden && (
                                <p className="mt-1 text-[11px] text-amber-700 dark:text-amber-300">
                                  Was {line.original_amount?.formatted} · {line.override_reason}
                                  {line.overridden_by !== null && ` — ${line.overridden_by}`}
                                </p>
                              )}
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                              <span className="tabular-nums text-sm font-semibold text-slate-900 dark:text-white">
                                {line.amount.formatted}
                              </span>

                              {detail.is_editable && (
                                <>
                                  <button
                                    onClick={() => startEditing(line)}
                                    className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                  >
                                    Edit
                                  </button>
                                  {line.is_overridden && (
                                    <button
                                      onClick={() => void revertOverride(line.id)}
                                      disabled={saving}
                                      className="rounded-lg px-2 py-1 text-[11px] font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-50 dark:hover:bg-slate-800"
                                    >
                                      Revert
                                    </button>
                                  )}
                                </>
                              )}
                            </div>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            <div className="flex justify-end border-t border-slate-200 pt-4 dark:border-slate-800">
              <button
                onClick={onClose}
                className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
              >
                Close
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Paying the technician                                               */
/* ------------------------------------------------------------------ */

/**
 * Approving a payout, then recording how it was actually paid.
 *
 * The technician is paid by US — out of the margin between what the
 * company is invoiced and what the technician earned. The company never
 * pays a technician directly, which is why `technician_payable` is its own
 * ledger and why nothing here touches the invoice side.
 *
 * That also means the payment leaves no external trace of its own. An
 * invoice has an email and a Message-ID behind it; cash handed over has
 * nothing but the method and reference captured here, so both are asked
 * for at the moment of payment rather than reconstructed later.
 *
 * Approval and payment are deliberately two clicks by two people: paying
 * an unapproved run removes the only control on this ledger, and the API
 * refuses it.
 */
function PayoutSettlement({
  detail,
  onSettled,
}: {
  detail: TechnicianPayoutDetail
  onSettled: (message: string) => void
}) {
  const [method, setMethod] = useState<string>(
    detail.payment_methods[0]?.value ?? 'bank_transfer',
  )
  const [reference, setReference] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const selected = detail.payment_methods.find((m) => m.value === method) ?? null

  const run = async (action: () => Promise<unknown>, successText: string) => {
    setBusy(true)
    setError(null)
    try {
      await action()
      onSettled(successText)
    } catch (err) {
      setError(
        err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'That could not be saved.',
      )
    } finally {
      setBusy(false)
    }
  }

  if (detail.payment.paid_at !== null) {
    return (
      <div className="my-4 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
        Paid {detail.payment.paid_at} by <strong>{detail.payment.method_label}</strong>
        {detail.payment.reference !== null && ` · ref ${detail.payment.reference}`}. Settled from
        our margin — the company is invoiced separately.
      </div>
    )
  }

  if (!detail.can_approve && !detail.can_pay) return null

  return (
    <div className="my-4 space-y-2 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-400">
        {detail.can_approve ? 'Approve this payout' : 'Record the payment'}
      </p>

      {error !== null && (
        <p className="rounded-lg bg-rose-50 p-2 text-[11px] font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
          {error}
        </p>
      )}

      {detail.can_approve ? (
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() =>
              void run(
                () => api.approvePayout(detail.id),
                'Payout approved. It can now be paid.',
              )
            }
            disabled={busy}
            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
          >
            {busy ? 'Approving…' : 'Approve'}
          </button>
          <span className="text-[11px] text-slate-500">
            Approval is a separate decision from paying — an unapproved run cannot be paid.
          </span>
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <select
              value={method}
              onChange={(e) => {
                setMethod(e.target.value)
                setReference('')
              }}
              className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              {detail.payment_methods.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>

            <input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder={selected?.reference_label ?? 'Reference'}
              className="min-w-40 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />

            <button
              onClick={() =>
                void run(
                  () =>
                    api.payPayout(
                      detail.id,
                      method,
                      reference.trim() === '' ? undefined : reference.trim(),
                    ),
                  'Payout marked paid.',
                )
              }
              disabled={busy}
              className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
            >
              {busy ? 'Saving…' : `Mark paid — ${detail.totals.total.formatted}`}
            </button>
          </div>
          <p className="text-[11px] text-slate-500">
            Paid by us from the margin, not by the company. The {selected?.reference_label.toLowerCase() ?? 'reference'} is
            the only trace this payment leaves.
          </p>
        </>
      )}
    </div>
  )
}
