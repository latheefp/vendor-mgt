import { useState, useEffect } from 'react'
import { api, ApiError } from '../lib/api'
import type { SparePartOption, Ticket } from '../lib/api'

/**
 * Recording a part fitted on a job.
 *
 * The button this replaces posted a hardcoded part number, which the API
 * never read — it prices against the company's catalogue by id, so every
 * press was refused. What the desk actually needs to state is four
 * things, and each one is money:
 *
 *   which part      the catalogue row, which fixes the cost and the payer
 *   the challan     clause 10 counts from the day the company shipped it,
 *                   and without a date the part ages invisibly
 *   defective swap  clause 9 gives us 7 days to send the old one back
 *   the margin      clause 6's band, but only when the customer is paying
 *
 * The margin control is deliberately absent under warranty. The company
 * supplies the part and nobody is charged for it, so a margin field there
 * is an invitation to bill a warranty customer.
 */

interface FitSparePanelProps {
  ticket: Ticket
  onFitted: (message: string) => void
  onError: (message: string) => void
}

export function FitSparePanel({ ticket, onFitted, onError }: FitSparePanelProps) {
  const [open, setOpen] = useState(false)
  const [parts, setParts] = useState<SparePartOption[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const [partId, setPartId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [serialNo, setSerialNo] = useState('')
  const [marginPct, setMarginPct] = useState('')
  const [isDefectiveReturn, setIsDefectiveReturn] = useState(true)
  const [receivedAt, setReceivedAt] = useState('')
  const [notes, setNotes] = useState('')

  const chargedToCustomer = ticket.warranty_scope === 'out_of_warranty'
  const selected = parts.find((part) => String(part.id) === partId)

  useEffect(() => {
    if (!open) return

    setLoading(true)
    api
      .spareCatalogue({ company_id: ticket.company_id, service_center_id: ticket.service_center_id })
      .then(setParts)
      .catch(() => onError('The parts catalogue could not be loaded.'))
      .finally(() => setLoading(false))
    // The catalogue is per company and its balances are per centre, so a
    // ticket moving between either invalidates the list.
  }, [open, ticket.company_id, ticket.service_center_id, onError])

  const submit = async () => {
    setSubmitting(true)
    setFieldErrors({})

    try {
      const result = await api.addTicketSpare(ticket.id, {
        spare_part_id: Number(partId),
        quantity: Number(quantity) || 1,
        serial_no: serialNo || undefined,
        margin_pct: chargedToCustomer && marginPct ? marginPct : undefined,
        is_defective_return: isDefectiveReturn,
        received_at: receivedAt || undefined,
        notes: notes || undefined,
      })

      // The refusal path is the API's; this is the other kind of problem —
      // the part was fitted and recorded, but the ledger now says the
      // centre holds less than nothing of it. Worth saying out loud.
      onFitted(
        result.stock_warning
          ? `Part recorded. ${result.stock_warning}`
          : `Part recorded. ${result.stock_remaining} left at this centre.`,
      )

      setOpen(false)
      setPartId('')
      setQuantity('1')
      setSerialNo('')
      setNotes('')
      setReceivedAt('')
    } catch (error) {
      if (error instanceof ApiError) {
        setFieldErrors(error.fields)
        onError(error.message)
      } else {
        onError('The part could not be recorded.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700"
      >
        🧩 Fit Spare Part
      </button>
    )
  }

  return (
    <div className="w-full rounded-xl border border-purple-200 bg-purple-50/60 p-3 dark:border-purple-900 dark:bg-purple-950/30">
      <div className="mb-3 flex items-center justify-between">
        <span className="text-xs font-semibold uppercase tracking-wide text-purple-700 dark:text-purple-300">
          Fit spare part
        </span>
        <span className="text-[11px] text-slate-500 dark:text-slate-400">
          {chargedToCustomer ? 'Out of warranty — billed to the customer' : 'In warranty — supplied by the company'}
        </span>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="sm:col-span-2">
          <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Part</span>
          <select
            value={partId}
            onChange={(e) => setPartId(e.target.value)}
            disabled={loading}
            className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          >
            <option value="">{loading ? 'Loading catalogue…' : 'Choose a part…'}</option>
            {parts.map((part) => (
              <option key={part.id} value={part.id}>
                {part.part_no} — {part.name}
                {/* The shelf figure, not the total: what a technician is
                    carrying is spoken for and cannot be handed out again. */}
                {part.on_shelf !== undefined ? ` (${part.on_shelf} on shelf)` : ''}
              </option>
            ))}
          </select>
          <FieldError errors={fieldErrors.spare_part_id} />
        </label>

        <label>
          <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Quantity</span>
          <input
            type="number"
            min="1"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
          <FieldError errors={fieldErrors.quantity} />
        </label>

        <label>
          <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">
            Serial number{selected?.is_serialized ? '' : ' (optional)'}
          </span>
          <input
            value={serialNo}
            onChange={(e) => setSerialNo(e.target.value)}
            placeholder={selected?.is_serialized ? 'On the part label' : '—'}
            className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </label>

        <label>
          <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">
            Company challan date
          </span>
          <input
            type="date"
            value={receivedAt}
            onChange={(e) => setReceivedAt(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
          {/* Said plainly because leaving it blank costs real money and
              looks like nothing at the time. */}
          <span className="mt-1 block text-[10px] text-slate-500 dark:text-slate-400">
            When the company shipped it. Blank means this part never ages, and clause 10 bills us on day 31.
          </span>
        </label>

        {chargedToCustomer && (
          <label>
            <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Margin %</span>
            <input
              value={marginPct}
              onChange={(e) => setMarginPct(e.target.value)}
              placeholder="Agreed band applies"
              className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <span className="mt-1 block text-[10px] text-slate-500 dark:text-slate-400">
              Blank uses the floor of this company's agreed band. Outside it, the API refuses.
            </span>
            <FieldError errors={fieldErrors.margin_pct} />
          </label>
        )}

        <label className="flex items-start gap-2 sm:col-span-2">
          <input
            type="checkbox"
            checked={isDefectiveReturn}
            onChange={(e) => setIsDefectiveReturn(e.target.checked)}
            className="mt-0.5"
          />
          <span className="text-[11px] text-slate-600 dark:text-slate-400">
            The old part is coming back with the technician
            <span className="block text-[10px] text-slate-500 dark:text-slate-500">
              Starts the clause 9 clock — the defective goes back to the company on the next settlement.
            </span>
          </span>
        </label>

        <label className="sm:col-span-2">
          <span className="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Notes</span>
          <input
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          />
        </label>
      </div>

      {fieldErrors.ticket && <FieldError errors={fieldErrors.ticket} />}

      <div className="mt-3 flex items-center gap-2">
        <button
          onClick={() => void submit()}
          disabled={submitting || partId === ''}
          className="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700 disabled:opacity-50"
        >
          {submitting ? 'Recording…' : 'Record part'}
        </button>
        <button
          onClick={() => setOpen(false)}
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
        >
          Cancel
        </button>
        {selected && (
          <span className="text-[11px] text-slate-500 dark:text-slate-400">
            Cost ₹{(selected.cost_paise / 100).toFixed(2)} each
          </span>
        )}
      </div>
    </div>
  )
}

function FieldError({ errors }: { errors?: string[] }) {
  if (!errors?.length) return null

  return <span className="mt-1 block text-[10px] text-rose-600 dark:text-rose-400">{errors.join(' ')}</span>
}
