import { useState } from 'react'
import { api, ApiError } from '../lib/api'
import type { Ticket, TicketSpareItem } from '../lib/api'

/**
 * The parts already on this job.
 *
 * They were only ever in the activity trail, which is a stream of
 * sentences ordered by time — fine for "what happened", useless for "what
 * is in this set". A desk about to fit a second board, or about to close,
 * needs the standing list: what is on, at whose cost, and which clause
 * clocks are running against it.
 *
 * Removal is here rather than in the fitting form because it is the same
 * question — this is the line, and this is how it comes off. Replacing a
 * part is remove then fit again: two facts, one wrong entry withdrawn and
 * one part fitted, rather than a swap that would have to invent a stock
 * movement that never happened.
 */

interface FittedSparesProps {
  ticket: Ticket
  onChanged: (ticket: Ticket, message: string) => void
  onError: (message: string) => void
}

const rupees = (paise: number) => `₹${(paise / 100).toFixed(2)}`

const shortDate = (value: string) =>
  new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })

export function FittedSpares({ ticket, onChanged, onError }: FittedSparesProps) {
  const [removingId, setRemovingId] = useState<number | null>(null)

  const spares = ticket.ticket_spares ?? []

  if (spares.length === 0) return null

  const remove = async (spare: TicketSpareItem) => {
    const label = spare.spare_part?.name ?? `part #${spare.spare_part_id}`

    if (!window.confirm(`Take ${label} x${spare.quantity} off this job and put it back into stock?`)) {
      return
    }

    setRemovingId(spare.id)

    try {
      const updated = await api.removeTicketSpare(ticket.id, spare.id)
      onChanged(updated, `${label} removed and returned to stock.`)
    } catch (error) {
      onError(error instanceof ApiError ? error.message : 'The part could not be removed.')
    } finally {
      setRemovingId(null)
    }
  }

  const total = spares
    .filter((spare) => spare.charged_to === 'customer')
    .reduce((sum, spare) => sum + spare.line_total_paise, 0)

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900/40">
      <div className="mb-2 flex items-center justify-between">
        <span className="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
          Parts fitted ({spares.length})
        </span>
        {/* Only the customer's side is summed. In-warranty parts are
            supplied by the company and carry an obligation rather than a
            price, so adding them here would state a bill nobody owes. */}
        {total > 0 && (
          <span className="text-[11px] text-slate-500 dark:text-slate-400">
            Billed to customer: <span className="font-medium">{rupees(total)}</span>
          </span>
        )}
      </div>

      <ul className="space-y-2">
        {spares.map((spare) => (
          <li
            key={spare.id}
            className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-700"
          >
            <div className="min-w-0">
              <div className="font-medium text-slate-800 dark:text-slate-100">
                {spare.spare_part?.part_no ? `${spare.spare_part.part_no} — ` : ''}
                {spare.spare_part?.name ?? `Part #${spare.spare_part_id}`}
                <span className="ml-1 text-slate-500 dark:text-slate-400">x{spare.quantity}</span>
              </div>

              <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                <span
                  className={
                    spare.charged_to === 'customer'
                      ? 'rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                      : 'rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                  }
                >
                  {spare.charged_to === 'customer'
                    ? `Customer · ${rupees(spare.line_total_paise)} @ ${spare.margin_pct}%`
                    : 'Company · under warranty'}
                </span>

                {spare.serial_no && <span>Serial {spare.serial_no}</span>}

                {/* Clause 9. Named on the line because the deadline is
                    ours to miss and nothing else on this screen says so. */}
                {spare.is_defective_return && (
                  <span
                    className={
                      spare.defective_returned_at
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-rose-600 dark:text-rose-400'
                    }
                  >
                    {spare.defective_returned_at
                      ? `Defective sent back ${shortDate(spare.defective_returned_at)}`
                      : spare.defective_return_due_at
                        ? `Defective due back ${shortDate(spare.defective_return_due_at)}`
                        : 'Defective swap'}
                  </span>
                )}

                {/* Clause 10: no challan date means nothing to age against,
                    and the part quietly becomes our cost. */}
                {spare.received_at === null ? (
                  <span className="text-amber-600 dark:text-amber-400">No challan date</span>
                ) : (
                  spare.billing_due_at && <span>Billable to us from {shortDate(spare.billing_due_at)}</span>
                )}
              </div>

              {spare.notes && <div className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{spare.notes}</div>}
            </div>

            <button
              onClick={() => void remove(spare)}
              disabled={removingId !== null}
              className="shrink-0 rounded-lg border border-rose-200 px-2 py-1 text-[11px] font-medium text-rose-600 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950/40"
            >
              {removingId === spare.id ? 'Removing…' : 'Remove'}
            </button>
          </li>
        ))}
      </ul>

      <p className="mt-2 text-[10px] text-slate-500 dark:text-slate-500">
        Fitted the wrong part? Remove it — the stock goes back where it came from — then record the right one. Once the
        ticket is closed and its charges are frozen, the correction is an adjustment instead.
      </p>
    </div>
  )
}
