import { useState, useEffect } from 'react'
import { X } from 'lucide-react'
import {
  api,
  ApiError,
  type CompanyInvoice,
  type CompanyReceivable,
  type ReceivablesReport,
  type Money,
} from '../lib/api'

/**
 * What every company owes, and what has come back.
 *
 * The page exists because an invoice list cannot answer the question. A
 * ticket that closes freezes its charges and stops there — no invoice is
 * raised until somebody runs a billing cycle for the period. Until then
 * the money is real, earned, and invisible on every screen we had. That
 * is the `unbilled` column, and it leads the table on purpose.
 *
 * The four stages are kept apart rather than summed, because each one
 * fails differently:
 *
 *   unbilled  we have not billed it   → run the cycle
 *   draft     we have not sent it     → send it
 *   awaiting  they have not paid it   → chase them
 *   overdue   they are late           → chase them harder
 *
 * A single "receivable" figure would tell you the total and nothing about
 * which of those four to do.
 */
export function ReceivablesPanel() {
  const [report, setReport] = useState<ReceivablesReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [openCompany, setOpenCompany] = useState<CompanyReceivable | null>(null)

  useEffect(() => {
    void load()
  }, [])

  const load = async () => {
    setLoading(true)
    setError(null)
    try {
      setReport(await api.listReceivables())
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'The receivables position could not be loaded.')
    } finally {
      setLoading(false)
    }
  }

  // Zero-everything companies are noise on a collections screen — they are
  // onboarded, not owing. Kept out unless they have history worth seeing.
  const rows = (report?.companies ?? []).filter(
    (c) => c.total_due.paise !== 0 || c.received.paise !== 0 || c.invoice_count > 0,
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            Company Receivables
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            What each company owes, how far along it is, and what has been received
            {report !== null && ` · as of ${report.as_of}`}.
          </p>
        </div>

        <button
          onClick={() => void load()}
          disabled={loading}
          className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          {loading ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {message !== null && (
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

      {error !== null && (
        <div className="rounded-xl bg-rose-50 p-4 text-sm font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
          {error}
        </div>
      )}

      {report !== null && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <StatTile
              label="Not yet invoiced"
              amount={report.totals.unbilled}
              hint={`${report.totals.unbilled_ticket_count} closed ticket${
                report.totals.unbilled_ticket_count === 1 ? '' : 's'
              } · run a cycle to bill`}
              tone="amber"
            />
            <StatTile
              label="Drafted, not sent"
              amount={report.totals.draft}
              hint="Raised — still to serve"
              tone="slate"
            />
            <StatTile
              label="Awaiting payment"
              amount={report.totals.awaiting}
              hint="Served and unpaid"
              tone="indigo"
            />
            <StatTile
              label="Overdue"
              amount={report.totals.overdue}
              hint="Past the agreed due date"
              tone="rose"
            />
            <StatTile
              label="Received"
              amount={report.totals.received}
              hint="Banked against invoices"
              tone="emerald"
            />
          </div>

          <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-slate-900 dark:text-white">
                Total outstanding across all companies
              </span>
              <span className="tabular-nums text-2xl font-bold text-slate-900 dark:text-white">
                {report.totals.total_due.formatted}
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-500">
              Everything earned and not yet in the bank — not yet invoiced, drafted, and
              awaiting payment added together.
            </p>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {loading ? (
              <div className="p-8 text-center text-sm text-slate-500">Loading the position…</div>
            ) : rows.length === 0 ? (
              <div className="p-8 text-center text-sm text-slate-500">
                Nothing outstanding and nothing received yet.
              </div>
            ) : (
              <table className="w-full min-w-[56rem] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Company</th>
                    <th className="px-4 py-3 text-right">Not invoiced</th>
                    <th className="px-4 py-3 text-right">Draft</th>
                    <th className="px-4 py-3 text-right">Awaiting</th>
                    <th className="px-4 py-3 text-right">Overdue</th>
                    <th className="px-4 py-3 text-right">Total due</th>
                    <th className="px-4 py-3 text-right">Received</th>
                    <th className="px-4 py-3">Credit</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                  {rows.map((row) => (
                    <tr
                      key={row.company.id}
                      onClick={() => setOpenCompany(row)}
                      className="cursor-pointer hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-900 dark:text-white">
                          {row.company.name}
                        </div>
                        <div className="text-[11px] text-slate-500">
                          {row.open_invoice_count} open invoice
                          {row.open_invoice_count === 1 ? '' : 's'}
                          {row.oldest_overdue_due_at !== null &&
                            ` · oldest due ${row.oldest_overdue_due_at}`}
                        </div>
                      </td>

                      <td className="px-4 py-3 text-right">
                        <span
                          className={`tabular-nums ${
                            row.unbilled.paise > 0
                              ? 'font-semibold text-amber-700 dark:text-amber-400'
                              : 'text-slate-400'
                          }`}
                        >
                          {row.unbilled.formatted}
                        </span>
                        {row.unbilled_ticket_count > 0 && (
                          <div className="text-[11px] text-amber-600 dark:text-amber-500">
                            {row.unbilled_ticket_count} ticket
                            {row.unbilled_ticket_count === 1 ? '' : 's'}
                          </div>
                        )}
                      </td>

                      <Amount value={row.draft} />
                      <Amount value={row.awaiting} />
                      <Amount value={row.overdue} tone="rose" />

                      <td className="px-4 py-3 text-right tabular-nums font-bold text-slate-900 dark:text-white">
                        {row.total_due.formatted}
                      </td>

                      <Amount value={row.received} tone="emerald" />

                      <td className="px-4 py-3">
                        {row.credit_limit.paise === 0 ? (
                          <span className="text-[11px] text-slate-400">no limit set</span>
                        ) : row.over_limit ? (
                          <span className="rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                            over limit
                          </span>
                        ) : (
                          <span className="text-[11px] text-slate-500">
                            {row.headroom.formatted} left of {row.credit_limit.formatted}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {report.totals.unbilled.paise > 0 && (
            <div className="rounded-xl bg-amber-50 p-4 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
              <strong>{report.totals.unbilled.formatted}</strong> of closed work has no invoice
              behind it. Closing a ticket freezes what it earned but does not bill it — raise the
              cycle for the period on <strong>Invoicing &amp; Payouts</strong> to turn it into an
              invoice.
            </div>
          )}
        </>
      )}

      {openCompany !== null && (
        <CompanyLedgerModal
          receivable={openCompany}
          onClose={() => setOpenCompany(null)}
          onChanged={(text) => {
            setMessage({ type: 'success', text })
            void load()
          }}
        />
      )}
    </div>
  )
}

function Amount({ value, tone }: { value: Money; tone?: 'rose' | 'emerald' }) {
  const colour =
    value.paise === 0
      ? 'text-slate-400'
      : tone === 'rose'
        ? 'font-semibold text-rose-700 dark:text-rose-400'
        : tone === 'emerald'
          ? 'font-medium text-emerald-700 dark:text-emerald-400'
          : 'text-slate-700 dark:text-slate-300'

  return <td className={`px-4 py-3 text-right tabular-nums ${colour}`}>{value.formatted}</td>
}

function StatTile({
  label,
  amount,
  hint,
  tone,
}: {
  label: string
  amount: Money
  hint: string
  tone: 'amber' | 'slate' | 'indigo' | 'rose' | 'emerald'
}) {
  const accent = {
    amber: 'text-amber-700 dark:text-amber-400',
    slate: 'text-slate-700 dark:text-slate-300',
    indigo: 'text-indigo-700 dark:text-indigo-400',
    rose: 'text-rose-700 dark:text-rose-400',
    emerald: 'text-emerald-700 dark:text-emerald-400',
  }[tone]

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 tabular-nums text-xl font-bold ${accent}`}>{amount.formatted}</p>
      <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* One company's invoices, and receipts against them                   */
/* ------------------------------------------------------------------ */

interface CompanyLedgerModalProps {
  receivable: CompanyReceivable
  onClose: () => void
  onChanged: (message: string) => void
}

/**
 * The invoices behind one company's balance, with the two actions that
 * move money: serving a draft, and recording what came back.
 */
function CompanyLedgerModal({ receivable, onClose, onChanged }: CompanyLedgerModalProps) {
  const [invoices, setInvoices] = useState<CompanyInvoice[] | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  // One invoice open for a receipt at a time — each payment carries its
  // own reference, and a form that batched them would invite one
  // reference standing for several transfers.
  const [payingId, setPayingId] = useState<number | null>(null)
  const [draftAmount, setDraftAmount] = useState('')
  const [draftReference, setDraftReference] = useState('')

  const [sendingId, setSendingId] = useState<number | null>(null)
  const [draftEmail, setDraftEmail] = useState('')

  useEffect(() => {
    void (async () => {
      try {
        setInvoices(await api.listInvoices(receivable.company.id))
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'The invoices could not be loaded.')
      }
    })()
  }, [receivable.company.id])

  const reload = async () => {
    setInvoices(await api.listInvoices(receivable.company.id))
  }

  const startPaying = (inv: CompanyInvoice) => {
    setPayingId(inv.id)
    setSendingId(null)
    setError(null)
    // Seeded with the full balance, because paid-in-full is the common
    // case and a part payment is a small edit away.
    setDraftAmount(((inv.total_paise - inv.paid_paise) / 100).toFixed(2))
    setDraftReference('')
  }

  const startSending = (inv: CompanyInvoice) => {
    setSendingId(inv.id)
    setPayingId(null)
    setError(null)
    setDraftEmail(receivable.company.accounts_email ?? '')
  }

  const recordPayment = async (invoiceId: number) => {
    // Parsed to paise here rather than sent as rupees: the server takes
    // an integer, and a float crossing the wire is where a rounded rupee
    // would come from.
    const rupees = Number(draftAmount)
    if (!Number.isFinite(rupees) || rupees <= 0) {
      setError('Enter the amount received, in rupees.')

      return
    }

    setBusyId(invoiceId)
    setError(null)
    try {
      const result = await api.recordInvoicePayment(
        invoiceId,
        Math.round(rupees * 100),
        draftReference.trim() === '' ? undefined : draftReference.trim(),
      )
      setPayingId(null)
      await reload()
      onChanged(
        result.status === 'paid'
          ? `Payment recorded. The invoice is settled in full.`
          : `Part payment recorded. The balance stays outstanding.`,
      )
    } catch (err) {
      setError(
        err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The payment could not be recorded.',
      )
    } finally {
      setBusyId(null)
    }
  }

  const sendInvoice = async (invoiceId: number) => {
    if (draftEmail.trim() === '') {
      setError('The agreement recognises email only, so an address is required.')

      return
    }

    setBusyId(invoiceId)
    setError(null)
    try {
      await api.sendInvoice(invoiceId, draftEmail.trim())
      setSendingId(null)
      await reload()
      onChanged('Invoice served. It is now awaiting payment.')
    } catch (err) {
      setError(
        err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The invoice could not be sent.',
      )
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-start justify-between border-b border-slate-200 pb-4 dark:border-slate-800">
          <div>
            <h3 className="text-xl font-bold text-slate-900 dark:text-white">
              {receivable.company.name}
            </h3>
            <p className="text-xs text-slate-500">
              {receivable.total_due.formatted} outstanding · {receivable.received.formatted} received
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {receivable.unbilled.paise > 0 && (
          <div className="my-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <strong>{receivable.unbilled.formatted}</strong> across{' '}
            {receivable.unbilled_ticket_count} closed ticket
            {receivable.unbilled_ticket_count === 1 ? '' : 's'} is not on any invoice yet. It will
            not appear below until a billing cycle covering those closure dates is run.
          </div>
        )}

        {/* Ageing, but only when something is actually late. */}
        {receivable.overdue.paise > 0 && (
          <div className="my-4 grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-3 text-xs sm:grid-cols-4 dark:border-slate-800">
            {(
              [
                ['Not due', receivable.ageing.not_due],
                ['1–30 days', receivable.ageing.d1_30],
                ['31–60 days', receivable.ageing.d31_60],
                ['60+ days', receivable.ageing.d60_plus],
              ] as const
            ).map(([label, amount]) => (
              <div key={label}>
                <p className="text-slate-500">{label}</p>
                <p className="tabular-nums font-semibold text-slate-900 dark:text-white">
                  {amount.formatted}
                </p>
              </div>
            ))}
          </div>
        )}

        {error !== null && (
          <div className="my-4 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
            {error}
          </div>
        )}

        <div className="my-4 space-y-2">
          {invoices === null ? (
            <p className="p-6 text-center text-sm text-slate-500">Loading invoices…</p>
          ) : invoices.length === 0 ? (
            <p className="p-6 text-center text-sm text-slate-500">
              No invoices raised for this company yet.
            </p>
          ) : (
            invoices.map((inv) => {
              const balance = inv.total_paise - inv.paid_paise
              const overdue =
                inv.due_at !== null &&
                balance > 0 &&
                inv.status !== 'draft' &&
                inv.due_at < new Date().toISOString().slice(0, 10)

              return (
                <div
                  key={inv.id}
                  className="rounded-xl border border-slate-200 p-3 dark:border-slate-800"
                >
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">
                        {inv.invoice_no}
                        <span className="ml-2 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold capitalize text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                          {inv.status.replace('_', ' ')}
                        </span>
                        {overdue && (
                          <span className="ml-1.5 rounded-md bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                            overdue
                          </span>
                        )}
                      </p>
                      <p className="text-[11px] text-slate-500">
                        {inv.period_start} to {inv.period_end} · {inv.ticket_count} ticket
                        {inv.ticket_count === 1 ? '' : 's'}
                        {inv.due_at !== null && ` · due ${inv.due_at}`}
                      </p>
                    </div>

                    <div className="shrink-0 text-right">
                      <p className="tabular-nums text-sm font-bold text-slate-900 dark:text-white">
                        ₹{(inv.total_paise / 100).toFixed(2)}
                      </p>
                      {inv.paid_paise > 0 && (
                        <p className="tabular-nums text-[11px] text-emerald-600 dark:text-emerald-400">
                          ₹{(inv.paid_paise / 100).toFixed(2)} received
                        </p>
                      )}
                      {balance > 0 && (
                        <p className="tabular-nums text-[11px] text-slate-500">
                          ₹{(balance / 100).toFixed(2)} pending
                        </p>
                      )}
                    </div>
                  </div>

                  <div className="mt-2 flex flex-wrap gap-2">
                    {inv.status === 'draft' && (
                      <button
                        onClick={() => startSending(inv)}
                        className="rounded-lg border border-slate-300 px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                      >
                        Send
                      </button>
                    )}
                    {balance > 0 && inv.status !== 'draft' && (
                      <button
                        onClick={() => startPaying(inv)}
                        className="rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-medium text-white shadow-sm hover:bg-emerald-700"
                      >
                        Record payment
                      </button>
                    )}
                  </div>

                  {sendingId === inv.id && (
                    <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-2 dark:border-slate-800">
                      <input
                        value={draftEmail}
                        onChange={(e) => setDraftEmail(e.target.value)}
                        placeholder="accounts@company.com"
                        className="min-w-48 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                      />
                      <button
                        onClick={() => void sendInvoice(inv.id)}
                        disabled={busyId === inv.id}
                        className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
                      >
                        {busyId === inv.id ? 'Sending…' : 'Confirm send'}
                      </button>
                      <button
                        onClick={() => setSendingId(null)}
                        className="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                      >
                        Cancel
                      </button>
                    </div>
                  )}

                  {payingId === inv.id && (
                    <div className="mt-2 space-y-2 border-t border-slate-200 pt-2 dark:border-slate-800">
                      <div className="flex flex-wrap items-center gap-2">
                        <input
                          value={draftAmount}
                          onChange={(e) => setDraftAmount(e.target.value)}
                          placeholder="Amount ₹"
                          className="w-28 rounded-lg border border-slate-300 px-2 py-1.5 text-xs tabular-nums dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                        />
                        <input
                          value={draftReference}
                          onChange={(e) => setDraftReference(e.target.value)}
                          placeholder="UTR / cheque no. / reference"
                          className="min-w-40 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                        />
                        <button
                          onClick={() => void recordPayment(inv.id)}
                          disabled={busyId === inv.id}
                          className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        >
                          {busyId === inv.id ? 'Saving…' : 'Save receipt'}
                        </button>
                        <button
                          onClick={() => setPayingId(null)}
                          className="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                          Cancel
                        </button>
                      </div>
                      <p className="text-[11px] text-slate-500">
                        Part payment is fine — the invoice stays open for the balance.
                      </p>
                    </div>
                  )}
                </div>
              )
            })
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
