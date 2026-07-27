import { useState } from 'react'
import { ApiError, api, type RatePreview } from '../lib/api'

/**
 * "What will this job earn?"
 *
 * Runs the real pricing engine against the real rate card — the same code
 * path that will later produce the frozen charge lines at closure. That
 * matters: a figure quoted here and a figure invoiced later can never
 * disagree, because they are computed by the same thing.
 */
export function RatePreviewPanel() {
  const [form, setForm] = useState({
    job_type: 'Service',
    warranty_scope: 'in_warranty',
    size_inch: '55',
    hours_to_close: '30',
    travel_km: '42',
    technician_flat_rupees: '250',
  })
  const [result, setResult] = useState<RatePreview | null>(null)
  const [error, setError] = useState<{ message: string; action?: string } | null>(null)
  const [busy, setBusy] = useState(false)

  async function run() {
    setBusy(true)
    setError(null)
    setResult(null)

    // Anchor the SLA clock to now, then derive the closure time from the
    // requested duration — so the incentive bands behave exactly as they
    // will on a real ticket.
    const received = new Date()
    const closed = new Date(received.getTime() + Number(form.hours_to_close) * 3_600_000)
    const fmt = (d: Date) =>
      `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
        d.getDate(),
      ).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(
        d.getMinutes(),
      ).padStart(2, '0')}:00`

    try {
      setResult(
        await api.ratePreview({
          vendor_id: 1,
          job_type: form.job_type,
          warranty_scope: form.warranty_scope,
          size_inch: Number(form.size_inch),
          received_at: fmt(received),
          closed_at: fmt(closed),
          travel_km: Number(form.travel_km),
          technician_flat_rupees: Number(form.technician_flat_rupees),
        }),
      )
    } catch (err) {
      if (err instanceof ApiError) {
        const detail = err.body.detail as { action_required?: string } | undefined
        setError({ message: err.message, action: detail?.action_required })
      } else {
        setError({ message: 'Could not reach the server.' })
      }
    } finally {
      setBusy(false)
    }
  }

  const field =
    'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950'

  return (
    <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 className="mb-4 font-semibold">Price a job</h2>

        <div className="space-y-3">
          <Labelled label="Complaint type">
            {/* The vendor's own wording. The backend maps it through
                vendor_job_type_aliases, so "Service" and "Breakdown" both
                resolve without a code change. */}
            <select
              value={form.job_type}
              onChange={(e) => setForm({ ...form, job_type: e.target.value })}
              className={field}
            >
              <option>Service</option>
              <option>Installation</option>
              <option>Demo</option>
              <option>Exchange</option>
              <option>Panel Replacement</option>
            </select>
          </Labelled>

          <Labelled label="Warranty scope">
            <select
              value={form.warranty_scope}
              onChange={(e) => setForm({ ...form, warranty_scope: e.target.value })}
              className={field}
            >
              <option value="in_warranty">In warranty</option>
              <option value="out_of_warranty">Out of warranty</option>
              <option value="not_applicable">Not applicable</option>
            </select>
          </Labelled>

          <div className="grid grid-cols-2 gap-3">
            <Labelled label="Screen size (in)">
              <input
                type="number"
                value={form.size_inch}
                onChange={(e) => setForm({ ...form, size_inch: e.target.value })}
                className={field}
              />
            </Labelled>
            <Labelled label="Hours to close">
              <input
                type="number"
                value={form.hours_to_close}
                onChange={(e) => setForm({ ...form, hours_to_close: e.target.value })}
                className={field}
              />
            </Labelled>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Labelled label="Distance (km)">
              <input
                type="number"
                value={form.travel_km}
                onChange={(e) => setForm({ ...form, travel_km: e.target.value })}
                className={field}
              />
            </Labelled>
            <Labelled label="Technician flat (₹)">
              <input
                type="number"
                value={form.technician_flat_rupees}
                onChange={(e) =>
                  setForm({ ...form, technician_flat_rupees: e.target.value })
                }
                className={field}
              />
            </Labelled>
          </div>

          <button
            onClick={() => void run()}
            disabled={busy}
            className="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
          >
            {busy ? 'Calculating…' : 'Calculate'}
          </button>

          <p className="text-xs text-slate-500 dark:text-slate-400">
            Try 60″ out of warranty to see the engine refuse a rate the
            agreement never set.
          </p>
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        {error && (
          <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
            <p className="font-medium text-amber-900 dark:text-amber-200">
              No agreed rate
            </p>
            <p className="mt-1 text-sm text-amber-800 dark:text-amber-300/90">
              {error.message}
            </p>
            {error.action && (
              <p className="mt-2 text-sm font-medium text-amber-900 dark:text-amber-200">
                {error.action}
              </p>
            )}
          </div>
        )}

        {!error && !result && (
          <p className="py-16 text-center text-sm text-slate-500">
            Set the job details and calculate.
          </p>
        )}

        {result && (
          <>
            <div className="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-slate-200 pb-4 dark:border-slate-800">
              <span className="font-semibold">{result.resolved.label}</span>
              <span className="text-xs text-slate-500">
                band {result.resolved.size_band} · paid by {result.resolved.payer}
              </span>
            </div>

            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="pb-2 font-medium">Ledger</th>
                  <th className="pb-2 font-medium">Line</th>
                  <th className="pb-2 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {result.lines.map((line, i) => (
                  <tr key={i}>
                    <td className="py-2 pr-3 align-top text-xs text-slate-500">
                      {line.ledger.replace(/_/g, ' ')}
                    </td>
                    <td className="py-2 pr-3">{line.description}</td>
                    <td
                      className={`tabular py-2 text-right font-medium ${
                        line.amount.paise < 0
                          ? 'text-red-600 dark:text-red-400'
                          : 'text-slate-900 dark:text-slate-100'
                      }`}
                    >
                      {line.amount.formatted}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <dl className="mt-5 space-y-1.5 border-t border-slate-200 pt-4 text-sm dark:border-slate-800">
              {(
                [
                  ['vendor_receivable', 'Billed to vendor'],
                  ['customer_collection', 'Collected from customer'],
                  ['vendor_payable', 'Royalty owed to vendor'],
                  ['technician_payable', 'Paid to technician'],
                ] as const
              ).map(([key, label]) =>
                result.totals[key]?.paise ? (
                  <div key={key} className="flex justify-between">
                    <dt className="text-slate-600 dark:text-slate-400">{label}</dt>
                    <dd className="tabular font-medium">
                      {result.totals[key].formatted}
                    </dd>
                  </div>
                ) : null,
              )}
              <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold dark:border-slate-800">
                <dt>Gross margin</dt>
                <dd className="tabular text-emerald-600 dark:text-emerald-400">
                  {result.totals.gross_margin?.formatted}
                </dd>
              </div>
            </dl>
          </>
        )}
      </section>
    </div>
  )
}

function Labelled({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
        {label}
      </span>
      {children}
    </label>
  )
}
