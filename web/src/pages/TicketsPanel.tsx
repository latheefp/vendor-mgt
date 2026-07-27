import { useState, useEffect } from 'react'
import {
  api,
  ApiError,
  type Ticket,
  type TicketAttachment,
  type TicketComment,
  type TicketEventItem,
  type TicketLedger,
  type TicketOptions,
} from '../lib/api'
import { FitSparePanel } from '../components/FitSparePanel'

export function TicketsPanel() {
  const [tickets, setTickets] = useState<Ticket[]>([])
  const [options, setOptions] = useState<TicketOptions | null>(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  // Active by default: closed and cancelled jobs are history, and a desk
  // that opens on all of them has to hunt for the work still owed.
  const [statusFilter, setStatusFilter] = useState<string>('active')
  const [activeTab, setActiveTab] = useState<'list' | 'create'>('list')
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null)
  const [assigningTechId, setAssigningTechId] = useState<number | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  // Kept apart from the intake form's errors: a refused closure is about
  // the ticket on screen, not about the form on the other tab.
  const [closeErrors, setCloseErrors] = useState<Record<string, string[]>>({})

  // Comments and the frozen ledger are fetched per ticket rather than
  // carried on the list rows — both are only ever read one ticket at a time.
  const [comments, setComments] = useState<TicketComment[]>([])
  const [commentBody, setCommentBody] = useState('')
  const [commentVisibility, setCommentVisibility] = useState<'internal' | 'vendor' | 'customer'>('internal')
  const [ledger, setLedger] = useState<TicketLedger | null>(null)
  const [statusMoves, setStatusMoves] = useState<string[]>([])
  const [holdReasonId, setHoldReasonId] = useState('')
  const [resolutionId, setResolutionId] = useState('')
  const [attachments, setAttachments] = useState<TicketAttachment[]>([])
  const [photoKind, setPhotoKind] = useState('after')
  const [adjustment, setAdjustment] = useState({
    ledger: 'vendor_receivable',
    amount: '',
    reason: '',
  })

  // Intake Form State
  const [formData, setFormData] = useState({
    vendor_id: '',
    service_center_id: '',
    job_type_id: '',
    warranty_scope: 'in_warranty',
    brand_id: '',
    product_category_id: '',
    model_no: '',
    serial_no: '',
    size_inch: '',
    purchase_date: '',
    symptom_id: '',
    reported_issue: '',
    priority: 'normal',
    vendor_ticket_ref: '',
    customer: {
      name: '',
      phone: '',
      alt_phone: '',
      address_line1: '',
      city: '',
      district_id: '',
      pincode: '',
    },
  })

  useEffect(() => {
    void loadOptions()
    void loadTickets()
  }, [])

  useEffect(() => {
    if (formData.vendor_id) {
      void loadOptions(Number(formData.vendor_id))
    }
  }, [formData.vendor_id])

  useEffect(() => {
    if (selectedTicket === null) {
      setComments([])
      setLedger(null)
      setStatusMoves([])
      return
    }
    void loadTicketDetail(selectedTicket.id)
  }, [selectedTicket?.id, selectedTicket?.status])

  // A refusal belongs to the ticket that produced it. Keyed on the id alone
  // so that closing a ticket — which changes the status, not the id — keeps
  // its own result on screen.
  useEffect(() => {
    if (selectedTicket === null) return
    setCloseErrors({})
    setMessage(null)
  }, [selectedTicket?.id])

  const loadOptions = async (vendorId?: number) => {
    try {
      const opts = await api.ticketOptions(vendorId)
      setOptions(opts)
      if (!vendorId && opts.vendors.length > 0 && opts.service_centers.length > 0 && opts.job_types.length > 0) {
        setFormData((prev) => ({
          ...prev,
          vendor_id: String(opts.vendors[0].id),
          service_center_id: String(opts.service_centers[0].id),
          job_type_id: String(opts.job_types[0].id),
          brand_id: opts.brands.length > 0 ? String(opts.brands[0].id) : '',
          product_category_id: opts.product_categories.length > 0 ? String(opts.product_categories[0].id) : '',
        }))
      }
    } catch (err) {
      console.error('Failed to load options', err)
    }
  }

  /**
   * `status` is passed explicitly by the dropdown: reading it from state
   * here would send the previous selection, because the setter has not
   * committed by the time the change handler calls this.
   */
  const loadTickets = async (status: string = statusFilter) => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (search) params.q = search
      if (status) params.status = status
      const list = await api.listTickets(params)
      setTickets(list)
    } catch (err) {
      console.error('Failed to load tickets', err)
    } finally {
      setLoading(false)
    }
  }

  /** Every side of the detail panel at once; none blocks the others. */
  const loadTicketDetail = async (ticketId: number) => {
    const [notes, book, moves, files] = await Promise.allSettled([
      api.listTicketComments(ticketId),
      api.getTicketCharges(ticketId),
      api.getTicketStatusOptions(ticketId),
      api.listTicketAttachments(ticketId),
    ])

    setComments(notes.status === 'fulfilled' ? notes.value : [])
    setLedger(book.status === 'fulfilled' ? book.value : null)
    setStatusMoves(moves.status === 'fulfilled' ? moves.value.available : [])
    setAttachments(files.status === 'fulfilled' ? files.value : [])
  }

  const handleUploadPhoto = async (file: File | undefined) => {
    if (selectedTicket === null || file === undefined) return

    setSubmitting(true)
    try {
      await api.uploadTicketAttachment(selectedTicket.id, photoKind, file)
      setAttachments(await api.listTicketAttachments(selectedTicket.id))
      setMessage({ type: 'success', text: `${file.name} attached.` })
    } catch (err) {
      // Size, type and duplicate-hash rejections all land here, and each
      // one names what was wrong with the specific file.
      setMessage({
        type: 'error',
        text: err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The file could not be uploaded.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  /** The server decides which moves are legal; this only sends one. */
  const handleChangeStatus = async (to: string) => {
    if (selectedTicket === null || to === '') return

    // The only status that writes a field of its own, so it is the only one
    // that has to ask for anything.
    let notes: string | undefined
    if (to === 'cancelled') {
      const reason = window.prompt('Why is this job being cancelled?')
      if (reason === null || reason.trim() === '') return
      notes = reason
    }

    setSubmitting(true)
    try {
      const updated = await api.changeTicketStatus(selectedTicket.id, to, notes)
      setSelectedTicket(updated)
      setMessage({ type: 'success', text: `Status changed to ${to.replace('_', ' ')}.` })
      void loadTickets()
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The status could not be changed.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  /**
   * Hold and release are their own endpoints rather than status edits: a
   * hold writes a ticket_holds row, and only a reason flagged `pauses_sla`
   * actually stops the clock. Being short-staffed is a real delay, but it
   * is ours, and excusing it is how an agreement gets terminated.
   */
  const handleHold = async () => {
    if (selectedTicket === null || holdReasonId === '') return

    setSubmitting(true)
    try {
      const updated = await api.holdTicket(selectedTicket.id, Number(holdReasonId))
      setSelectedTicket(updated)
      setHoldReasonId('')
      const reason = options?.hold_reasons?.find((r) => r.id === Number(holdReasonId))
      setMessage({
        type: 'success',
        text: reason?.pauses_sla === false
          ? `On hold: ${reason.name}. This reason does NOT stop the SLA clock.`
          : 'On hold. The SLA clock is paused.',
      })
      void loadTickets()
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The ticket could not be put on hold.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  const handleRelease = async () => {
    if (selectedTicket === null) return

    setSubmitting(true)
    try {
      const updated = await api.releaseTicket(selectedTicket.id)
      setSelectedTicket(updated)
      setMessage({ type: 'success', text: 'Hold released. The clock is running again.' })
      void loadTickets()
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError ? err.message : 'The hold could not be released.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  /**
   * Closing is the "resolve" step: the resolution decides whether the job
   * earned anything at all. "No fault found" and "Cancelled by customer"
   * close the ticket with an empty ledger rather than refusing to close.
   */
  const handleClose = async () => {
    if (selectedTicket === null || resolutionId === '') return

    setSubmitting(true)
    setCloseErrors({})
    try {
      const closed = await api.closeTicket(selectedTicket.id, {
        resolution_id: Number(resolutionId),
        diagnosis: commentBody.trim() || undefined,
      })
      // The status flips to "closed", which is what re-runs the detail load
      // and brings back the now-frozen ledger.
      setSelectedTicket(closed)
      setResolutionId('')
      setMessage({ type: 'success', text: `Ticket #${closed.ticket_no} closed. Charges frozen.` })
      void loadTickets()
    } catch (err) {
      // Evidence gaps and unpriceable work both land here, and both are
      // fixable by the desk — so show what the server actually said rather
      // than failing silently the way this button used to.
      if (!(err instanceof ApiError)) {
        setMessage({ type: 'error', text: 'The ticket could not be closed.' })
        return
      }

      setCloseErrors(err.fields)
      setMessage({
        type: 'error',
        text: Object.values(err.fields).flat().join(' ') || err.message,
      })

      // Not every rejection means nothing happened: work the rate card
      // cannot price closes the ticket and leaves the charges unfrozen, so
      // the panel would otherwise keep offering a close that already ran.
      const current = await api.getTicket(selectedTicket.id).catch(() => null)
      if (current !== null) {
        setSelectedTicket(current)
        void loadTickets()
      }
    } finally {
      setSubmitting(false)
    }
  }

  const handleAddComment = async () => {
    if (selectedTicket === null || commentBody.trim() === '') return

    setSubmitting(true)
    try {
      await api.addTicketComment(selectedTicket.id, commentBody, commentVisibility)
      setCommentBody('')
      setComments(await api.listTicketComments(selectedTicket.id))
      setMessage({ type: 'success', text: 'Comment added.' })
    } catch (err) {
      setMessage({
        type: 'error',
        text: err instanceof ApiError ? err.message : 'The comment could not be saved.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  const handleAddAdjustment = async () => {
    if (selectedTicket === null) return

    setSubmitting(true)
    try {
      await api.addTicketAdjustment(selectedTicket.id, adjustment)
      setAdjustment({ ledger: adjustment.ledger, amount: '', reason: '' })
      await loadTicketDetail(selectedTicket.id)
      setMessage({ type: 'success', text: 'Adjustment recorded. It will appear on the next invoice run.' })
    } catch (err) {
      // The 409 the API returns for an unfrozen ticket is advice, not a
      // failure — surface its wording rather than a generic message.
      setMessage({
        type: 'error',
        text: err instanceof ApiError
          ? Object.values(err.fields).flat().join(' ') || err.message
          : 'The adjustment could not be recorded.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault()
    void loadTickets()
  }

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    setMessage(null)
    setFieldErrors({})

    try {
      const payload = {
        vendor_id: Number(formData.vendor_id),
        service_center_id: Number(formData.service_center_id),
        job_type_id: Number(formData.job_type_id),
        warranty_scope: formData.warranty_scope,
        vendor_ticket_ref: formData.vendor_ticket_ref || null,
        brand_id: formData.brand_id ? Number(formData.brand_id) : null,
        product_category_id: formData.product_category_id ? Number(formData.product_category_id) : null,
        model_no: formData.model_no || null,
        serial_no: formData.serial_no || null,
        size_inch: formData.size_inch || null,
        purchase_date: formData.purchase_date || null,
        symptom_id: formData.symptom_id ? Number(formData.symptom_id) : null,
        reported_issue: formData.reported_issue || null,
        priority: formData.priority,
        customer: {
          name: formData.customer.name,
          phone: formData.customer.phone,
          alt_phone: formData.customer.alt_phone || null,
          address_line1: formData.customer.address_line1 || null,
          city: formData.customer.city || null,
          district_id: formData.customer.district_id ? Number(formData.customer.district_id) : null,
          pincode: formData.customer.pincode || null,
        },
      }

      const created = await api.createTicket(payload)
      setMessage({ type: 'success', text: `Ticket #${created.ticket_no} created successfully!` })
      setActiveTab('list')
      void loadTickets()
    } catch (err: any) {
      const fields: Record<string, string[]> = err.fields || {}
      setFieldErrors(fields)

      const fieldDetails = Object.entries(fields)
        .map(([k, v]) => `${k.replace('_', ' ')}: ${Array.isArray(v) ? v.join(', ') : v}`)
        .join(' | ')

      setMessage({
        type: 'error',
        text: fieldDetails ? `${err.message || 'Validation failed'} — ${fieldDetails}` : (err.message || 'Failed to create ticket'),
      })
    } finally {
      setSubmitting(false)
    }
  }

  const handleAssignTechnician = async (ticketId: number) => {
    if (!assigningTechId) return
    try {
      const updated = await api.assignTicket(ticketId, assigningTechId)
      setSelectedTicket(updated)
      setMessage({ type: 'success', text: `Assigned to technician successfully` })
      void loadTickets()
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to assign technician' })
    }
  }

  const statusBadge = (status: string) => {
    const map: Record<string, string> = {
      new: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
      assigned: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
      contacted: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
      scheduled: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
      visited: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
      in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
      awaiting_parts: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
      on_hold: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
      reopened: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
      closed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
      cancelled: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
      rejected: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    }
    return map[status] ?? 'bg-slate-100 text-slate-800'
  }

  /** Older events were stored without a description. Read the row instead. */
  const eventLabel = (ev: TicketEventItem) => {
    if (ev.description) return ev.description
    const label = (s: string) => s.replace(/_/g, ' ')
    if (ev.from_status && ev.to_status) {
      return `Status moved from ${label(ev.from_status)} to ${label(ev.to_status)}.`
    }
    return label(ev.event_type)
  }

  /**
   * One banner, rendered wherever the desk is actually looking.
   *
   * It used to live only in the page body. Every action fired from the
   * detail modal — closing, the OTP, recording a part — wrote its result
   * behind a `fixed inset-0` overlay, so a refused close was indistinguishable
   * from a dead button.
   */
  const notice = message !== null && (
    <div
      className={`rounded-xl p-4 text-sm font-medium ${
        message.type === 'success'
          ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
          : 'bg-rose-50 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
      }`}
    >
      {message.text}
    </div>
  )

  return (
    <div className="space-y-6">
      {/* Header controls & Tab Selector */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            Ticket Operations
          </h1>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            Intake new service requests, assign field technicians, and track SLA targets.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setActiveTab('list')}
            className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
              activeTab === 'list'
                ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700'
                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
            }`}
          >
            {statusFilter === 'active' ? 'Active' : 'Tickets'} ({tickets.length})
          </button>
          <button
            onClick={() => setActiveTab('create')}
            className={`rounded-xl px-4 py-2 text-sm font-medium transition ${
              activeTab === 'create'
                ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700'
                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
            }`}
          >
            + Create New Ticket
          </button>
        </div>
      </div>

      {/* The modal carries its own copy while it is open. */}
      {selectedTicket === null && notice}

      {/* CREATE TICKET INTAKE FORM */}
      {activeTab === 'create' && (
        <form
          onSubmit={handleCreateSubmit}
          className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"
        >
          <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
            1. Customer Details
          </h2>

          <div className="mb-6 grid gap-4 md:grid-cols-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Customer Name *
              </label>
              <input
                required
                type="text"
                value={formData.customer.name}
                onChange={(e) =>
                  setFormData((prev) => ({
                    ...prev,
                    customer: { ...prev.customer, name: e.target.value },
                  }))
                }
                placeholder="Full Name"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Phone Number *
              </label>
              <input
                required
                type="tel"
                value={formData.customer.phone}
                onChange={(e) =>
                  setFormData((prev) => ({
                    ...prev,
                    customer: { ...prev.customer, phone: e.target.value },
                  }))
                }
                placeholder="10 digit mobile number"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                District *
              </label>
              <select
                value={formData.customer.district_id}
                onChange={(e) =>
                  setFormData((prev) => ({
                    ...prev,
                    customer: { ...prev.customer, district_id: e.target.value },
                  }))
                }
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                <option value="">Select District</option>
                {options?.districts.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name} ({d.code})
                  </option>
                ))}
              </select>
            </div>

            <div className="md:col-span-2">
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Address
              </label>
              <input
                type="text"
                value={formData.customer.address_line1}
                onChange={(e) =>
                  setFormData((prev) => ({
                    ...prev,
                    customer: { ...prev.customer, address_line1: e.target.value },
                  }))
                }
                placeholder="House / Street / Landmark"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                City / Town
              </label>
              <input
                type="text"
                value={formData.customer.city}
                onChange={(e) =>
                  setFormData((prev) => ({
                    ...prev,
                    customer: { ...prev.customer, city: e.target.value },
                  }))
                }
                placeholder="City name"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>
          </div>

          <h2 className="mb-4 border-t border-slate-200 pt-4 text-lg font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
            2. Service & Product Details
          </h2>

          <div className="mb-6 grid gap-4 md:grid-cols-3">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Vendor / Principal *
              </label>
              <select
                value={formData.vendor_id}
                onChange={(e) => setFormData((prev) => ({ ...prev, vendor_id: e.target.value }))}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                {options?.vendors.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name} ({v.code})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Service Center *
              </label>
              <select
                value={formData.service_center_id}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, service_center_id: e.target.value }))
                }
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                {options?.service_centers.map((sc) => (
                  <option key={sc.id} value={sc.id}>
                    {sc.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Job Type *
              </label>
              <select
                value={formData.job_type_id}
                onChange={(e) => setFormData((prev) => ({ ...prev, job_type_id: e.target.value }))}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                {options?.job_types.map((jt) => (
                  <option key={jt.id} value={jt.id}>
                    {jt.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Warranty Scope *
              </label>
              <select
                value={formData.warranty_scope}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, warranty_scope: e.target.value }))
                }
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                {options?.warranty_scopes.map((ws) => (
                  <option key={ws.code} value={ws.code}>
                    {ws.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Brand
              </label>
              <select
                value={formData.brand_id}
                onChange={(e) => setFormData((prev) => ({ ...prev, brand_id: e.target.value }))}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                <option value="">Select Brand</option>
                {options?.brands.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Product Category
              </label>
              <select
                value={formData.product_category_id}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, product_category_id: e.target.value }))
                }
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                <option value="">Select Category</option>
                {options?.product_categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Model Number
              </label>
              <input
                type="text"
                value={formData.model_no}
                onChange={(e) => setFormData((prev) => ({ ...prev, model_no: e.target.value }))}
                placeholder="e.g. DN-55UHD"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Serial Number {options?.requirements?.['ticket.require_serial'] ? '*' : ''}
              </label>
              <input
                type="text"
                value={formData.serial_no}
                onChange={(e) => setFormData((prev) => ({ ...prev, serial_no: e.target.value }))}
                placeholder="Serial number"
                className={`w-full rounded-lg border px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:bg-slate-800 dark:text-white ${
                  fieldErrors['serial_no'] ? 'border-rose-500 bg-rose-50/50' : 'border-slate-300 dark:border-slate-700'
                }`}
              />
              {fieldErrors['serial_no'] && (
                <p className="mt-1 text-xs text-rose-600">{fieldErrors['serial_no'].join(', ')}</p>
              )}
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Purchase / Invoice Date {options?.requirements?.['ticket.require_bill_date'] ? '*' : ''}
              </label>
              <input
                type="date"
                value={formData.purchase_date}
                onChange={(e) => setFormData((prev) => ({ ...prev, purchase_date: e.target.value }))}
                className={`w-full rounded-lg border px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:bg-slate-800 dark:text-white ${
                  fieldErrors['purchase_date'] ? 'border-rose-500 bg-rose-50/50' : 'border-slate-300 dark:border-slate-700'
                }`}
              />
              {fieldErrors['purchase_date'] && (
                <p className="mt-1 text-xs text-rose-600">{fieldErrors['purchase_date'].join(', ')}</p>
              )}
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Screen Size (Inches)
              </label>
              <input
                type="number"
                value={formData.size_inch}
                onChange={(e) => setFormData((prev) => ({ ...prev, size_inch: e.target.value }))}
                placeholder="e.g. 55"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div className="md:col-span-2">
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Reported Issue / Fault Description
              </label>
              <textarea
                rows={2}
                value={formData.reported_issue}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, reported_issue: e.target.value }))
                }
                placeholder="Describe the complaint reported by customer"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                Priority
              </label>
              <select
                value={formData.priority}
                onChange={(e) => setFormData((prev) => ({ ...prev, priority: e.target.value }))}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                {options?.priorities.map((p) => (
                  <option key={p.code} value={p.code}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setActiveTab('list')}
              className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="rounded-xl bg-brand-600 px-6 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
            >
              {submitting ? 'Creating...' : 'Create Ticket'}
            </button>
          </div>
        </form>
      )}

      {/* TICKETS LIST TAB */}
      {activeTab === 'list' && (
        <div className="space-y-4">
          <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900">
            <form onSubmit={handleSearch} className="flex flex-1 items-center gap-2">
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search ticket #, customer name, phone, serial..."
                className="w-full max-w-md rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
              <button
                type="submit"
                className="rounded-xl bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600"
              >
                Search
              </button>
            </form>

            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value)
                void loadTickets(e.target.value)
              }}
              className="rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              {/* Active first, and selected: the two groups are what the
                  desk actually asks for. Individual statuses stay below
                  for the rarer "show me only the held jobs" question. */}
              <option value="active">Active (open jobs)</option>
              <option value="inactive">Closed &amp; cancelled</option>
              <option value="">All Statuses</option>
              <option value="new">New</option>
              <option value="assigned">Assigned</option>
              <option value="contacted">Contacted</option>
              <option value="scheduled">Scheduled</option>
              <option value="visited">Visited</option>
              <option value="in_progress">In Progress</option>
              <option value="awaiting_parts">Awaiting Parts</option>
              <option value="on_hold">On Hold</option>
              <option value="reopened">Reopened</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>

          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            {loading ? (
              <div className="p-8 text-center text-sm text-slate-500">Loading tickets...</div>
            ) : tickets.length === 0 ? (
              <div className="p-8 text-center text-sm text-slate-500">
                {statusFilter === 'active' ? (
                  <>
                    No open jobs. Choose <strong>All Statuses</strong> to see closed and
                    cancelled tickets.
                  </>
                ) : (
                  <>
                    No tickets found. Click <strong>+ Create New Ticket</strong> to get started.
                  </>
                )}
              </div>
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Ticket #</th>
                    <th className="px-4 py-3">Customer</th>
                    <th className="px-4 py-3">Product / Issue</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3">Assigned Tech</th>
                    <th className="px-4 py-3">Target SLA Due</th>
                    <th className="px-4 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                  {tickets.map((t) => (
                    <tr
                      key={t.id}
                      className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td className="px-4 py-3 font-semibold text-brand-600 dark:text-brand-400">
                        {t.ticket_no}
                        <div className="text-xs font-normal text-slate-500">
                          {t.vendor?.name} · {t.job_type?.name}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-900 dark:text-white">
                          {t.customer?.name}
                        </div>
                        <div className="text-xs text-slate-500">{t.customer?.phone}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="text-slate-800 dark:text-slate-200">
                          {t.model_no ? `${t.model_no} (${t.size_inch ? t.size_inch + '″' : ''})` : 'Product'}
                        </div>
                        <div className="truncate text-xs text-slate-500 max-w-[200px]">
                          {t.reported_issue || 'No issue description'}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={`inline-block rounded-md px-2 py-0.5 text-xs font-semibold capitalize ${statusBadge(
                            t.status,
                          )}`}
                        >
                          {t.status.replace('_', ' ')}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        {t.assigned_technician ? (
                          <div className="font-medium text-slate-800 dark:text-slate-200">
                            {t.assigned_technician.name}
                          </div>
                        ) : (
                          <span className="text-xs italic text-amber-600 dark:text-amber-400">
                            Unassigned
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600 dark:text-slate-400">
                        {t.visit_due_at ? (
                          <div>Visit: {new Date(t.visit_due_at).toLocaleString()}</div>
                        ) : (
                          '-'
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={async () => {
                            const full = await api.getTicket(t.id)
                            setSelectedTicket(full)
                          }}
                          className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                          View & Assign
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      {/* TICKET DETAILS & ASSIGNMENT MODAL */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          {/* Fixed shell: the header and the close action stay put, only the
              body scrolls — the modal grows tall once a ticket has history. */}
          <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900">
            <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-slate-800">
              <div>
                <h3 className="text-xl font-bold text-slate-900 dark:text-white">
                  Ticket #{selectedTicket.ticket_no}
                </h3>
                <p className="text-xs text-slate-500">
                  Received: {new Date(selectedTicket.received_at).toLocaleString()}
                </p>
              </div>
              <button
                onClick={() => setSelectedTicket(null)}
                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
              >
                ✕
              </button>
            </div>

            {/* Outside the scrolling body on purpose: the result of an action
                taken at the bottom of a tall modal has to be visible without
                scrolling back up to find it. */}
            {notice && <div className="shrink-0 px-6 pt-4">{notice}</div>}

            <div className="flex-1 overflow-y-auto px-6 py-2">
            <div className="my-4 grid gap-4 sm:grid-cols-2 text-sm">
              <div>
                <span className="text-xs text-slate-500">Customer</span>
                <p className="font-semibold text-slate-900 dark:text-white">
                  {selectedTicket.customer?.name} ({selectedTicket.customer?.phone})
                </p>
                <p className="text-xs text-slate-600 dark:text-slate-400">
                  {selectedTicket.customer?.address_line1}, {selectedTicket.customer?.city}
                </p>
              </div>

              <div>
                <span className="text-xs text-slate-500">Service Info</span>
                <p className="font-semibold text-slate-900 dark:text-white">
                  {selectedTicket.vendor?.name} · {selectedTicket.job_type?.name}
                </p>
                <p className="text-xs text-slate-600 dark:text-slate-400 capitalize">
                  Warranty: {selectedTicket.warranty_scope.replace('_', ' ')}
                </p>
              </div>

              <div>
                <span className="text-xs text-slate-500">Product Info</span>
                <p className="font-medium text-slate-800 dark:text-slate-200">
                  {selectedTicket.model_no || 'N/A'} (Serial: {selectedTicket.serial_no || 'N/A'})
                </p>
              </div>

              <div>
                <span className="text-xs text-slate-500">SLA Due Dates</span>
                <p className="text-xs text-slate-700 dark:text-slate-300">
                  Contact: {selectedTicket.contact_due_at ? new Date(selectedTicket.contact_due_at).toLocaleTimeString() : 'N/A'}
                  <br />
                  Visit: {selectedTicket.visit_due_at ? new Date(selectedTicket.visit_due_at).toLocaleString() : 'N/A'}
                </p>
              </div>
            </div>

            {/* Assign Technician Section */}
            <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4 dark:border-indigo-900/40 dark:bg-indigo-950/20">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-indigo-900 dark:text-indigo-200">
                Technician Assignment
              </h4>

              <div className="flex items-center gap-3">
                <select
                  onChange={(e) => setAssigningTechId(Number(e.target.value))}
                  defaultValue={selectedTicket.assigned_technician_id || ''}
                  className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  <option value="">Select Technician...</option>
                  {options?.technicians.map((tech) => (
                    <option key={tech.id} value={tech.id}>
                      {tech.name} ({tech.code}) - {tech.phone}
                    </option>
                  ))}
                </select>

                <button
                  onClick={() => handleAssignTechnician(selectedTicket.id)}
                  disabled={!assigningTechId}
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                >
                  Assign
                </button>
              </div>
            </div>

            {/* Evidence Capture & Actions Bar */}
            <div className="my-4 space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/40">
              <h4 className="text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                Lifecycle & Evidence Actions
              </h4>

              {/* Status as a plain edit. The buttons below still move it as a
                  side effect; this covers the moves that have no button. */}
              <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3 dark:border-slate-700">
                <span className="text-xs font-medium text-slate-600 dark:text-slate-400">Status</span>
                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold capitalize ${statusBadge(selectedTicket.status)}`}>
                  {selectedTicket.status.replace('_', ' ')}
                </span>
                <span className="text-xs text-slate-400">→</span>
                <select
                  value=""
                  disabled={submitting || statusMoves.length === 0}
                  onChange={(e) => void handleChangeStatus(e.target.value)}
                  className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs capitalize disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  <option value="">
                    {statusMoves.length === 0 ? 'No moves available' : 'Change status to…'}
                  </option>
                  {statusMoves.map((move) => (
                    <option key={move} value={move}>
                      {move.replace('_', ' ')}
                    </option>
                  ))}
                </select>
              </div>

              {/* Hold / release. Its own control because a hold writes a
                  ticket_holds row and pauses the SLA clock — a status edit
                  would do neither. */}
              {selectedTicket.status !== 'closed' && (
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3 dark:border-slate-700">
                  <span className="text-xs font-medium text-slate-600 dark:text-slate-400">SLA hold</span>
                  {selectedTicket.status === 'on_hold' ? (
                    <button
                      onClick={() => void handleRelease()}
                      disabled={submitting}
                      className="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700 disabled:opacity-50"
                    >
                      ▶ Release hold
                    </button>
                  ) : (
                    <>
                      <select
                        value={holdReasonId}
                        onChange={(e) => setHoldReasonId(e.target.value)}
                        className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                      >
                        <option value="">Reason…</option>
                        {options?.hold_reasons?.map((reason) => (
                          <option key={reason.id} value={reason.id}>
                            {reason.name}
                            {/* Named on the option itself: picking the wrong
                                one silently absorbs a penalty that was ours. */}
                            {reason.pauses_sla === false ? ' (does not pause SLA)' : ''}
                          </option>
                        ))}
                      </select>
                      <button
                        onClick={() => void handleHold()}
                        disabled={submitting || holdReasonId === ''}
                        className="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-purple-700 disabled:opacity-50"
                      >
                        ⏸ Put on hold
                      </button>
                    </>
                  )}
                </div>
              )}

              <div className="flex flex-wrap items-center gap-2">
                <button
                  onClick={async () => {
                    const res = await api.checkinTicket(selectedTicket.id, { latitude: '11.2588', longitude: '75.7804' })
                    setSelectedTicket(res)
                    setMessage({ type: 'success', text: 'Geo Check-In recorded (11.2588, 75.7804)' })
                    void loadTickets()
                  }}
                  className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-700"
                >
                  📍 Geo Check-In
                </button>

                <button
                  onClick={async () => {
                    const otpRes = await api.sendTicketOtp(selectedTicket.id)
                    setMessage({ type: 'success', text: `OTP Dispatched to Customer! Code: ${otpRes.otp_demo_code}` })
                    const verified = await api.verifyTicketOtp(selectedTicket.id, otpRes.otp_demo_code)
                    if (verified.verified) {
                      const updated = await api.getTicket(selectedTicket.id)
                      setSelectedTicket(updated)
                    }
                  }}
                  className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-blue-700"
                >
                  📱 Dispatch & Verify Customer OTP
                </button>

                <FitSparePanel
                  ticket={selectedTicket}
                  onFitted={async (text) => {
                    setMessage({ type: 'success', text })
                    // The commonest refusal is a resolution that wants a
                    // spare, and this is the act that answers it.
                    setCloseErrors({})
                    setSelectedTicket(await api.getTicket(selectedTicket.id))
                  }}
                  onError={(text) => setMessage({ type: 'error', text })}
                />

              </div>

              {/* Photographs. Not a condition of closing unless the company
                  turns that on in Settings, but they are what settles a
                  dispute, so the control sits with the other evidence. */}
              <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                <span className="text-xs font-medium text-slate-600 dark:text-slate-400">Photos</span>
                <select
                  value={photoKind}
                  onChange={(e) => setPhotoKind(e.target.value)}
                  className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  <option value="after">After</option>
                  <option value="before">Before</option>
                  <option value="serial_plate">Serial plate</option>
                  <option value="spare">Spare part</option>
                  <option value="signature">Signature</option>
                  <option value="invoice">Invoice</option>
                  <option value="video">Video</option>
                  <option value="other">Other</option>
                </select>
                <label className="cursor-pointer rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-teal-700">
                  📷 Upload
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/heic,video/mp4"
                    disabled={submitting}
                    className="hidden"
                    onChange={(e) => {
                      void handleUploadPhoto(e.target.files?.[0])
                      // Cleared so re-picking the same file fires onChange
                      // again after a failed upload.
                      e.target.value = ''
                    }}
                  />
                </label>

                {attachments.map((file) => (
                  <a
                    key={file.id}
                    href={file.url}
                    target="_blank"
                    rel="noreferrer"
                    title={`${file.kind} — ${file.original_name ?? 'file'}`}
                    className="group relative h-10 w-10 overflow-hidden rounded-lg border border-slate-300 dark:border-slate-700"
                  >
                    {file.mime_type?.startsWith('image/') ? (
                      <img src={file.url} alt={file.kind} className="h-full w-full object-cover" />
                    ) : (
                      <span className="flex h-full w-full items-center justify-center bg-slate-100 text-base dark:bg-slate-800">
                        🎬
                      </span>
                    )}
                  </a>
                ))}

                {attachments.length === 0 && (
                  <span className="text-[11px] text-slate-500">Nothing attached yet.</span>
                )}
              </div>

              {/* Resolve & close. The resolution is the "resolve" step and it
                  decides the money: a non-billable one closes the job with an
                  empty ledger rather than refusing to close. */}
              {selectedTicket.status !== 'closed' && (
                <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                  <span className="text-xs font-medium text-slate-600 dark:text-slate-400">Resolve</span>
                  <select
                    value={resolutionId}
                    onChange={(e) => {
                      setResolutionId(e.target.value)
                      // The old refusal was about the old resolution.
                      setCloseErrors({})
                    }}
                    className={`min-w-52 rounded-lg border px-2 py-1.5 text-xs dark:bg-slate-800 dark:text-white ${
                      closeErrors['resolution_id']
                        ? 'border-rose-500 bg-rose-50/50 dark:border-rose-500'
                        : 'border-slate-300 dark:border-slate-700'
                    }`}
                  >
                    <option value="">How was it resolved?</option>
                    {options?.resolutions?.map((res) => (
                      <option key={res.id} value={res.id}>
                        {res.name}
                        {res.is_billable === false ? ' (earns nothing)' : ''}
                      </option>
                    ))}
                  </select>
                  <button
                    onClick={() => void handleClose()}
                    disabled={submitting || resolutionId === ''}
                    className="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-black disabled:opacity-50 dark:bg-slate-700 dark:hover:bg-slate-600"
                  >
                    ✓ Close Ticket & Freeze Charges
                  </button>

                  {/* Every reason the server gave, next to the control that
                      caused it. Missing evidence, an unpriceable job and a
                      resolution that wants a spare all arrive keyed by field
                      and all are fixable without leaving this panel. */}
                  {Object.keys(closeErrors).length > 0 && (
                    <ul className="w-full space-y-1 rounded-lg bg-rose-50 px-3 py-2 text-[11px] text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                      {Object.entries(closeErrors).flatMap(([field, messages]) =>
                        messages.map((text, index) => <li key={`${field}-${index}`}>• {text}</li>),
                      )}
                    </ul>
                  )}
                </div>
              )}
            </div>

            {/* Frozen ledger, and the only sanctioned way to change it */}
            {ledger !== null && ledger.lines.length > 0 && (
              <div className="my-4 rounded-xl border border-amber-100 bg-amber-50/50 p-4 dark:border-amber-900/40 dark:bg-amber-950/20">
                <div className="mb-2 flex items-center justify-between">
                  <h4 className="text-xs font-semibold uppercase tracking-wider text-amber-900 dark:text-amber-200">
                    Charges {ledger.is_frozen && '· Frozen'}
                  </h4>
                  <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Margin {ledger.totals.gross_margin?.formatted}
                  </span>
                </div>

                <div className="mb-3 space-y-1 text-xs">
                  {ledger.lines.map((line) => (
                    <div key={line.id} className="flex items-baseline justify-between gap-2">
                      <span className="text-slate-700 dark:text-slate-300">
                        {line.description}
                        {line.is_adjustment && (
                          <span className="ml-1.5 rounded bg-amber-200 px-1 text-[10px] font-semibold text-amber-900 dark:bg-amber-800 dark:text-amber-100">
                            adjustment
                          </span>
                        )}
                        {line.is_manual_base && (
                          <span className="ml-1.5 rounded bg-orange-200 px-1 text-[10px] font-semibold text-orange-900 dark:bg-orange-800 dark:text-orange-100">
                            manual rate
                          </span>
                        )}
                      </span>
                      <span className="shrink-0 tabular-nums font-medium text-slate-900 dark:text-white">
                        {line.amount.formatted}
                        <span className="ml-1 text-[10px] font-normal text-slate-500">{line.ledger_label}</span>
                      </span>
                    </div>
                  ))}
                </div>

                {/* A frozen line is never edited — an invoice already sent
                    would silently change. Corrections append instead. */}
                <div className="border-t border-amber-200 pt-3 dark:border-amber-900/50">
                  <p className="mb-2 text-[11px] text-slate-600 dark:text-slate-400">
                    Frozen lines cannot be edited. Add a correction — use a negative amount to
                    credit the ledger.
                  </p>
                  <div className="flex flex-wrap items-center gap-2">
                    <select
                      value={adjustment.ledger}
                      onChange={(e) => setAdjustment({ ...adjustment, ledger: e.target.value })}
                      className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    >
                      <option value="vendor_receivable">Vendor receivable</option>
                      <option value="customer_collection">Customer collection</option>
                      <option value="vendor_payable">Payable to vendor</option>
                      <option value="technician_payable">Payable to technician</option>
                    </select>
                    <input
                      value={adjustment.amount}
                      onChange={(e) => setAdjustment({ ...adjustment, amount: e.target.value })}
                      placeholder="-150.00"
                      className="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-xs tabular-nums dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    />
                    <input
                      value={adjustment.reason}
                      onChange={(e) => setAdjustment({ ...adjustment, reason: e.target.value })}
                      placeholder="Reason (prints on the invoice)"
                      className="min-w-40 flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    />
                    <button
                      onClick={() => void handleAddAdjustment()}
                      disabled={submitting || adjustment.amount.trim() === '' || adjustment.reason.trim() === ''}
                      className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-amber-700 disabled:opacity-50"
                    >
                      Add adjustment
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Comments */}
            <div className="my-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                Comments
              </h4>

              <div className="flex flex-wrap items-start gap-2">
                <textarea
                  value={commentBody}
                  onChange={(e) => setCommentBody(e.target.value)}
                  rows={2}
                  placeholder="Add a note — customer said the fault is intermittent…"
                  className="min-w-48 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
                <div className="flex flex-col gap-2">
                  {/* Internal by default: a note shared with the company is
                      something we can be held to, so it is chosen. */}
                  <select
                    value={commentVisibility}
                    onChange={(e) => setCommentVisibility(e.target.value as typeof commentVisibility)}
                    className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="internal">Internal only</option>
                    <option value="vendor">Shared with company</option>
                    <option value="customer">Shared with customer</option>
                  </select>
                  <button
                    onClick={() => void handleAddComment()}
                    disabled={submitting || commentBody.trim() === ''}
                    className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
                  >
                    Comment
                  </button>
                </div>
              </div>

              {comments.length > 0 && (
                <div className="mt-3 max-h-40 space-y-2 overflow-y-auto border-t border-slate-200 pt-2 dark:border-slate-800">
                  {comments.map((note) => (
                    <div key={note.id} className="text-xs">
                      <div className="flex items-center justify-between text-[10px] text-slate-400">
                        <span className="font-semibold text-slate-600 dark:text-slate-400">
                          {note.author_name}
                          {note.visibility !== 'internal' && (
                            <span className="ml-1.5 rounded bg-blue-100 px-1 font-medium text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                              shared · {note.visibility}
                            </span>
                          )}
                        </span>
                        <span>{new Date(note.occurred_at).toLocaleString()}</span>
                      </div>
                      <p className="whitespace-pre-wrap text-slate-700 dark:text-slate-300">{note.body}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Audit Log */}
            {selectedTicket.ticket_events && selectedTicket.ticket_events.length > 0 && (
              <div className="mt-4 border-t border-slate-200 pt-3 dark:border-slate-800">
                <h4 className="mb-2 text-xs font-semibold text-slate-500">Activity History</h4>
                <div className="max-h-32 overflow-y-auto space-y-1.5 text-xs">
                  {selectedTicket.ticket_events.map((ev) => (
                    <div key={ev.id} className="flex items-center justify-between text-slate-600 dark:text-slate-400">
                      <span>• {eventLabel(ev)}</span>
                      <span className="text-[10px] text-slate-400">{new Date(ev.occurred_at).toLocaleString()}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            </div>

            <div className="flex shrink-0 justify-end border-t border-slate-200 px-6 py-4 dark:border-slate-800">
              <button
                onClick={() => setSelectedTicket(null)}
                className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                Close Window
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
