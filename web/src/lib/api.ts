/**
 * The one place that talks to the backend.
 *
 * Auth is a same-origin session cookie, not a token. Nothing is kept in
 * localStorage: the session cookie is HttpOnly and SameSite=Lax, so it is
 * unreadable by script — including script injected through XSS, which is
 * exactly what a token in localStorage would not survive.
 *
 * The only thing JavaScript reads is the CSRF token, which is not a
 * credential on its own.
 */

const API_BASE = import.meta.env.VITE_API_BASE ?? '/api'

const CSRF_COOKIE = 'gvsCsrfToken'
const CSRF_HEADER = 'X-CSRF-Token'

/** Shape of every successful response. */
export interface ApiEnvelope<T> {
  data: T
  meta?: Record<string, unknown>
}

/** Shape of every failure. */
export interface ApiErrorBody {
  code: string
  message: string
  /** Per-field messages, ready to hand straight to react-hook-form. */
  fields?: Record<string, string[]>
  detail?: unknown
}

export class ApiError extends Error {
  // Declared explicitly rather than as constructor parameter properties:
  // the project builds with `erasableSyntaxOnly`, which forbids syntax
  // that TypeScript would have to emit code for.
  readonly status: number
  readonly body: ApiErrorBody

  constructor(status: number, body: ApiErrorBody) {
    super(body.message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }

  get isUnauthenticated(): boolean {
    return this.status === 401
  }

  get fields(): Record<string, string[]> {
    return this.body.fields ?? {}
  }
}

/**
 * Read the CSRF token Cake set on us.
 *
 * The cookie value is URL-ENCODED — its base64 padding arrives as `%3D`.
 * Echoing it back without decoding fails the token check on every single
 * POST, with an error message that points at CSRF rather than at encoding.
 * This one line is the whole reason this function exists.
 */
function csrfToken(): string | null {
  const match = document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${CSRF_COOKIE}=`))

  if (!match) return null

  return decodeURIComponent(match.slice(CSRF_COOKIE.length + 1))
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, signal } = options

  // Cleared up front, so a response without meta can never be read as the
  // previous call's.
  lastMeta = undefined

  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  // Cake only checks the token on state-changing verbs.
  if (method !== 'GET') {
    const token = csrfToken()
    if (token) headers[CSRF_HEADER] = token
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    // Sends and accepts the session cookie. Same-origin, so no CORS.
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  })

  // 204 and friends have nothing to parse.
  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  let parsed: unknown = null

  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      // A non-JSON body from an API means something upstream failed badly
      // — a PHP fatal, or a proxy error page. Surface it as such rather
      // than letting a JSON.parse exception bubble up.
      throw new ApiError(response.status, {
        code: 'invalid_response',
        message:
          response.status >= 500
            ? 'The server returned an error. Please try again.'
            : 'The server returned an unexpected response.',
      })
    }
  }

  if (!response.ok) {
    const error = (parsed as { error?: ApiErrorBody })?.error
    throw new ApiError(
      response.status,
      error ?? { code: 'request_failed', message: `Request failed (${response.status}).` },
    )
  }

  lastMeta = (parsed as ApiEnvelope<T>).meta

  return (parsed as ApiEnvelope<T>).data
}

/**
 * The same call, keeping `meta`.
 *
 * Most endpoints put nothing there and `request` is right to drop it. The
 * stock reports are the exception: their totals are computed over the
 * whole result, not the page, so re-deriving them on the client from the
 * rows it happens to be holding would quietly report a different number.
 */
async function requestEnvelope<T, M = Record<string, unknown>>(
  path: string,
  options: RequestOptions = {},
): Promise<{ data: T; meta: M }> {
  const data = await request<T>(path, options)

  return { data, meta: (lastMeta ?? {}) as M }
}

/**
 * Meta from the most recent response.
 *
 * A module-level handoff rather than a second fetch path, so `request`
 * stays the only place that speaks to the network and there is one set of
 * error semantics rather than two. Safe because JavaScript is
 * single-threaded and this is read immediately after the await that set
 * it, with no suspension point in between.
 */
let lastMeta: Record<string, unknown> | undefined

/**
 * Build `?a=1&b=2`, dropping anything unset.
 *
 * Undefined keys are dropped rather than sent empty because the API reads
 * a missing filter as "all" and an empty one as a value — `company_id=`
 * would be a request for company zero.
 */
function queryString(params?: Record<string, string | number | boolean | undefined>): string {
  if (!params) return ''

  const pairs = Object.entries(params).filter(
    (entry): entry is [string, string | number | boolean] => entry[1] !== undefined && entry[1] !== '',
  )

  return pairs.length === 0
    ? ''
    : '?' + new URLSearchParams(pairs.map(([key, value]) => [key, String(value)])).toString()
}

/* ------------------------------------------------------------------ */
/* Types                                                               */
/* ------------------------------------------------------------------ */

/**
 * Money always crosses the wire in all three forms.
 *
 * `paise` is the only one to compute with — it is an exact integer, so
 * the client never does currency arithmetic in floating point. The other
 * two are for display.
 */
export interface Money {
  paise: number
  rupees: string
  formatted: string
}

export interface CurrentUser {
  id: number
  name: string
  email: string
  phone: string | null
  role: { code: string; name: string | null }
  service_center: { id: number; code: string; name: string } | null
  must_change_password: boolean
  /** Server decides which shell this person sees — it is an authorisation
   *  question, so the client does not infer it from the role string. */
  landing: string
  permissions: string[] | Record<string, unknown>
}

/* ------------------------------------------------------------------ */
/* Endpoints                                                           */
/* ------------------------------------------------------------------ */

export const api = {
  /** Called once on boot so the first POST (the login) has a token. */
  csrf: () => request<{ csrf_cookie: string; csrf_header: string }>('/auth/csrf'),

  me: (signal?: AbortSignal) => request<CurrentUser>('/auth/me', { signal }),

  login: (email: string, password: string) =>
    request<CurrentUser>('/auth/login', { method: 'POST', body: { email, password } }),

  logout: () => request<{ logged_out: boolean }>('/auth/logout', { method: 'POST' }),

  health: () => request<{ status: string; app: string; time: string }>('/health'),

  ratePreview: (payload: Record<string, unknown>) =>
    request<RatePreview>('/rates/preview', { method: 'POST', body: payload }),

  getDashboardStats: () => request<DashboardStats>('/tickets/dashboard-stats'),

  ticketOptions: (companyId?: number) =>
    request<TicketOptions>('/tickets/options' + (companyId ? `?company_id=${companyId}` : '')),

  listTickets: (params?: Record<string, string>) =>
    request<Ticket[]>('/tickets' + (params ? '?' + new URLSearchParams(params).toString() : '')),

  getTicket: (id: string | number) => request<Ticket>(`/tickets/${id}`),

  createTicket: (payload: Record<string, unknown>) =>
    request<Ticket>('/tickets', { method: 'POST', body: payload }),

  updateTicket: (id: number, payload: Record<string, unknown>) =>
    request<Ticket>(`/tickets/${id}`, { method: 'PUT', body: payload }),

  assignTicket: (id: number, technicianId: number) =>
    request<Ticket>(`/tickets/${id}/assign`, { method: 'POST', body: { technician_id: technicianId } }),

  checkinTicket: (id: number, coords?: { latitude?: string; longitude?: string }) =>
    request<Ticket>(`/tickets/${id}/checkin`, { method: 'POST', body: coords }),

  checkoutTicket: (id: number, data?: { latitude?: string; longitude?: string; travel_km?: number }) =>
    request<Ticket>(`/tickets/${id}/checkout`, { method: 'POST', body: data }),

  sendTicketOtp: (id: number) =>
    request<{ message: string; otp_demo_code: string }>(`/tickets/${id}/otp/send`, { method: 'POST' }),

  verifyTicketOtp: (id: number, otp: string) =>
    request<{ verified: boolean }>(`/tickets/${id}/otp/verify`, { method: 'POST', body: { otp } }),

  listTicketAttachments: (id: number) =>
    request<TicketAttachment[]>(`/tickets/${id}/attachments`),

  /**
   * Uploads the actual bytes. Multipart, so `request()` is bypassed — it
   * JSON-encodes every body, and a File cannot survive that.
   */
  uploadTicketAttachment: async (id: number, kind: string, file: File) => {
    const form = new FormData()
    form.append('kind', kind)
    form.append('file', file)

    const token = csrfToken()
    const response = await fetch(`${API_BASE}/tickets/${id}/attachments`, {
      method: 'POST',
      // No Content-Type header on purpose: the browser has to set it
      // itself so it can append the multipart boundary.
      headers: token ? { Accept: 'application/json', [CSRF_HEADER]: token } : { Accept: 'application/json' },
      credentials: 'same-origin',
      body: form,
    })

    const parsed = await response.json().catch(() => null)

    if (!response.ok) {
      throw new ApiError(
        response.status,
        (parsed as { error?: ApiErrorBody })?.error
          ?? { code: 'upload_failed', message: `Upload failed (${response.status}).` },
      )
    }

    return (parsed as ApiEnvelope<{ attachment_id: number }>).data
  },

  /**
   * Fit a part on a job.
   *
   * `spare_part_id`, not a part number — the server prices this against
   * the company's catalogue and its agreed margin band, and a part it
   * cannot identify is refused rather than guessed at.
   *
   * `received_at` is the date on the company's challan. Without it the
   * clause 10 clock has nothing to run from, and the part ages invisibly.
   */
  addTicketSpare: (
    id: number,
    spare: {
      spare_part_id: number
      quantity: number
      serial_no?: string
      margin_pct?: string
      is_defective_return?: boolean
      received_at?: string
      notes?: string
    },
  ) => request<AddSpareResult>(`/tickets/${id}/spares`, { method: 'POST', body: spare }),

  /**
   * Withdraw a part recorded in error, putting the stock back where it
   * came from. Replacing one is this followed by `addTicketSpare`.
   *
   * Refused once the ticket's charges are frozen — the line has been
   * billed by then, and the correction is an adjustment instead.
   */
  removeTicketSpare: (id: number, spareId: number) =>
    request<Ticket>(`/tickets/${id}/spares/${spareId}`, { method: 'DELETE' }),

  returnTicketSpare: (id: number, spareId: number, reference?: string) =>
    request<unknown>(`/tickets/${id}/spares/${spareId}/return`, {
      method: 'POST',
      body: reference ? { reference } : undefined,
    }),

  // ---- Spare stock ----
  // The ledger side: what a centre holds, where it is, and how long it
  // has been held. Fitting a part is a ticket action and lives above.

  spareCatalogue: (params?: { company_id?: number; service_center_id?: number; include_inactive?: boolean; q?: string }) =>
    request<SparePartOption[]>('/spares/catalogue' + queryString(params)),

  createSparePart: (payload: Record<string, unknown>) =>
    request<Record<string, unknown>>('/spares/catalogue', { method: 'POST', body: payload }),

  spareStock: (params?: { company_id?: number; service_center_id?: number }) =>
    requestEnvelope<SpareStockRow[], SpareStockSummary>('/spares/stock' + queryString(params)),

  spareHoldings: (params?: { service_center_id?: number }) =>
    request<TechnicianHolding[]>('/spares/holdings' + queryString(params)),

  spareMovements: (sparePartId: number, params?: { service_center_id?: number; limit?: number }) =>
    request<SpareMovement[]>(`/spares/${sparePartId}/movements` + queryString(params)),

  /** Clause 10 against stock still in our possession. */
  spareAgeing: (params?: { company_id?: number; service_center_id?: number; within_days?: number }) =>
    requestEnvelope<SpareAgeingLot[], SpareAgeingTotals>('/spares/ageing' + queryString(params)),

  receiveSpares: (payload: {
    spare_part_id: number
    service_center_id: number
    quantity: number
    reference?: string
    /** The challan date. Defaulting this to today gives away days we are owed. */
    received_at?: string
    unit_cost_paise?: number
  }) => request<{ movement_id: number }>('/spares/receive', { method: 'POST', body: payload }),

  issueSpares: (payload: {
    spare_part_id: number
    service_center_id: number
    technician_id: number
    quantity: number
    ticket_id?: number
  }) => request<{ movement_ids: number[] }>('/spares/issue', { method: 'POST', body: payload }),

  returnGoodSpares: (payload: {
    spare_part_id: number
    service_center_id: number
    technician_id: number
    quantity: number
  }) => request<{ movement_ids: number[] }>('/spares/return-good', { method: 'POST', body: payload }),

  writeOffSpares: (payload: {
    spare_part_id: number
    service_center_id: number
    quantity: number
    reason: string
    technician_id?: number
  }) => request<{ movement_id: number }>('/spares/write-off', { method: 'POST', body: payload }),

  /** A physical count. What is stored is the difference it revealed. */
  countSpares: (payload: {
    spare_part_id: number
    service_center_id: number
    counted_quantity: number
    reason: string
    technician_id?: number
  }) => request<{ movement_id: number; delta: number }>('/spares/count', { method: 'POST', body: payload }),

  /** Clause 9 settles per consignment, so both ends of it are batches. */
  listDefectiveReturns: (params?: { company_id?: number; within_days?: number }) =>
    request<DefectiveReturnDue[]>('/spares/defective-returns' + queryString(params)),

  sendDefectiveBatch: (ticketSpareIds: number[], reference: string) =>
    request<{ returned: number[]; skipped: Record<string, string>; reference: string }>(
      '/spares/defective-returns',
      { method: 'POST', body: { ticket_spare_ids: ticketSpareIds, reference } },
    ),

  recordDefectiveCredit: (payload: { reference?: string; ticket_spare_id?: number; credited_at?: string }) =>
    request<{ credited: number }>('/spares/defective-credits', { method: 'POST', body: payload }),

  closeTicket: (
    id: number,
    closure: {
      resolution_id?: number
      diagnosis?: string
      closure_notes?: string
      travel_km?: number
      /**
       * A manual service charge, in rupees, for work the rate card cannot
       * price. Requires a reason — it prints on the invoice line. The payer
       * is only needed when the card had no matching item at all, since
       * otherwise it is inherited from the item the override replaces.
       */
      override_base_amount?: string
      override_reason?: string
      override_payer?: 'company' | 'customer'
    },
  ) => request<Ticket>(`/tickets/${id}/close`, { method: 'POST', body: closure }),

  /** What the job would earn if it closed now. Pass the same `override_*`
   *  values the close call will carry to see what they actually produce. */
  previewTicket: (id: number, override?: Record<string, string>) =>
    request<{ pricing: Record<string, unknown>; outstanding_requirements: Record<string, string[]> }>(
      `/tickets/${id}/preview` + (override && Object.keys(override).length
        ? '?' + new URLSearchParams(override).toString()
        : ''),
    ),

  /** Where the ticket is and where it can legally go next. */
  getTicketStatusOptions: (id: number) =>
    request<{ status: string; available: string[]; action_only: string[] }>(`/tickets/${id}/status`),

  /** Set the status directly. Closing and holds remain their own actions. */
  changeTicketStatus: (id: number, to: string, notes?: string) =>
    request<Ticket>(`/tickets/${id}/status`, { method: 'POST', body: { to, notes } }),

  /** Stops the SLA clock, but only if the reason is flagged as pausing it. */
  holdTicket: (id: number, holdReasonId: number, notes?: string) =>
    request<Ticket>(`/tickets/${id}/hold`, {
      method: 'POST',
      body: { hold_reason_id: holdReasonId, notes },
    }),

  releaseTicket: (id: number, resumeStatus?: string) =>
    request<Ticket>(`/tickets/${id}/release`, {
      method: 'POST',
      body: { resume_status: resumeStatus },
    }),

  getTicketCharges: (id: number) => request<TicketLedger>(`/tickets/${id}/charges`),

  /** Records extra work agreed on an open job — a BOQ line. Part of the bill
   *  being assembled, so it does not freeze the ticket. Refused once the
   *  charges are frozen, where the correction is an adjustment instead. */
  addTicketServiceLine: (
    id: number,
    line: { ledger: string; amount: string; description: string; notes?: string },
  ) =>
    request<{ charge_id: number; line: RatePreviewLine; totals: Record<string, Money> }>(
      `/tickets/${id}/charges`,
      { method: 'POST', body: line },
    ),

  /** Corrects a frozen ticket by appending. A negative `amount` reduces the
   *  ledger, which is how a conceded dispute is recorded. */
  addTicketAdjustment: (
    id: number,
    adjustment: { ledger: string; amount: string; reason: string; notes?: string },
  ) =>
    request<{ charge_id: number; line: RatePreviewLine; totals: Record<string, Money> }>(
      `/tickets/${id}/adjustments`,
      { method: 'POST', body: adjustment },
    ),

  removeTicketCharge: (id: number, chargeId: number) =>
    request<Ticket>(`/tickets/${id}/charges/${chargeId}`, { method: 'DELETE' }),

  listTicketComments: (id: number, visibility?: string) =>
    request<TicketComment[]>(
      `/tickets/${id}/comments` + (visibility ? `?visibility=${visibility}` : ''),
    ),

  addTicketComment: (id: number, body: string, visibility: 'internal' | 'company' | 'customer' = 'internal') =>
    request<{ event_id: number }>(`/tickets/${id}/comments`, {
      method: 'POST',
      body: { body, visibility },
    }),

  listInvoices: (companyId?: number) =>
    request<CompanyInvoice[]>('/invoices' + (companyId ? `?company_id=${companyId}` : '')),

  /**
   * What a run would raise, without raising it.
   *
   * Worth calling before generate: the run refuses an empty period with a
   * 422, and "nothing to invoice" is far more useful shown as a zero
   * beside the period picker than as an error after the click.
   */
  previewInvoice: (companyId: number, periodStart: string, periodEnd: string) =>
    request<InvoicePreview>(
      `/invoices/preview?company_id=${companyId}&period_start=${periodStart}&period_end=${periodEnd}`,
    ),

  /**
   * Raise a draft invoice for a company over a period.
   *
   * The period is always sent explicitly. The server falls back to LAST
   * month when it is omitted, which silently skips everything closed this
   * month — the reason freshly closed tickets appeared to vanish.
   */
  generateInvoice: (companyId: number, periodStart: string, periodEnd: string) =>
    request<CompanyInvoice>('/invoices/generate', {
      method: 'POST',
      body: { company_id: companyId, period_start: periodStart, period_end: periodEnd },
    }),

  /** Serve a draft. Clause 11 recognises email only, so one is required. */
  sendInvoice: (id: number, email: string, messageId?: string) =>
    request<CompanyInvoice>(`/invoices/${id}/send`, {
      method: 'POST',
      body: { email, message_id: messageId ?? null },
    }),

  /**
   * Record money received against an invoice.
   *
   * Paise, not rupees — the caller converts, because this is the one
   * number in the app that must never round.
   */
  recordInvoicePayment: (id: number, amountPaise: number, reference?: string) =>
    request<{ ok: boolean; paid_paise: number; status: string }>(`/invoices/${id}/payment`, {
      method: 'POST',
      body: { amount_paise: amountPaise, reference: reference ?? null },
    }),

  listReceivables: (asOf?: string) =>
    request<ReceivablesReport>('/receivables' + (asOf ? `?as_of=${asOf}` : '')),

  listTechnicianDues: (asOf?: string) =>
    request<TechnicianDuesReport>('/technician-dues' + (asOf ? `?as_of=${asOf}` : '')),

  /** Defaults to last calendar month when no period is given. */
  getProfitAndLoss: (periodStart?: string, periodEnd?: string) =>
    request<ProfitAndLossReport>(
      '/reports/profit-loss' +
        (periodStart && periodEnd ? `?period_start=${periodStart}&period_end=${periodEnd}` : ''),
    ),

  listSavingsBalances: () => request<SavingsCenterBalance[]>('/service-centers/savings'),

  getSavingsDetail: (serviceCenterId: number) =>
    request<SavingsDetail>(`/service-centers/${serviceCenterId}/savings`),

  /** A signed rupee string: positive credits the balance, negative debits it. */
  adjustSavings: (serviceCenterId: number, amount: string, description: string) =>
    request<SavingsEntry>(`/service-centers/${serviceCenterId}/savings/adjust`, {
      method: 'POST',
      body: { amount, description },
    }),

  getMyWallet: () => request<WalletSummary>('/wallet/me'),

  /** Raises a draft payout over everything unclaimed to date. Desk still approves and pays it. */
  withdrawMyWallet: () =>
    request<{ payout_id: number; payout_no: string }>('/wallet/me/withdraw', { method: 'POST' }),

  /** The total broken into buckets, and every line behind it. */
  getInvoice: (id: number) => request<CompanyInvoiceDetail>(`/invoices/${id}`),

  /**
   * Restate one line on a draft invoice.
   *
   * `amount` is a signed rupee string, not paise — the desk types what the
   * line should bill. The frozen ticket charge behind it is untouched; the
   * amount it was raised at comes back as `original_amount`.
   */
  overrideInvoiceLine: (id: number, lineId: number, amount: string, reason: string) =>
    request<CompanyInvoiceDetail>(`/invoices/${id}/lines/${lineId}`, {
      method: 'PATCH',
      body: { amount, reason },
    }),

  resetInvoiceLine: (id: number, lineId: number) =>
    request<CompanyInvoiceDetail>(`/invoices/${id}/lines/${lineId}`, { method: 'DELETE' }),

  listPayouts: () => request<TechnicianPayout[]>('/payouts'),

  generatePayout: (technicianId: number) =>
    request<TechnicianPayout>('/payouts/generate', { method: 'POST', body: { technician_id: technicianId } }),

  getPayout: (id: number) => request<TechnicianPayoutDetail>(`/payouts/${id}`),

  /** Draft → approved. A separate decision from paying it. */
  approvePayout: (id: number) =>
    request<TechnicianPayout>(`/payouts/${id}/approve`, { method: 'POST' }),

  /**
   * Approved → paid, recording how the money actually moved.
   *
   * The technician is paid by us out of our margin, never by the company,
   * so the method and reference are the only trace the payment leaves.
   */
  payPayout: (id: number, method: string, reference?: string) =>
    request<TechnicianPayout>(`/payouts/${id}/pay`, {
      method: 'POST',
      body: { method, reference: reference ?? null },
    }),

  overridePayoutLine: (id: number, lineId: number, amount: string, reason: string) =>
    request<TechnicianPayoutDetail>(`/payouts/${id}/lines/${lineId}`, {
      method: 'PATCH',
      body: { amount, reason },
    }),

  resetPayoutLine: (id: number, lineId: number) =>
    request<TechnicianPayoutDetail>(`/payouts/${id}/lines/${lineId}`, { method: 'DELETE' }),

  // ---- Users Management ----
  listUsers: (params?: Record<string, string>) =>
    request<UserItem[]>('/users' + (params ? '?' + new URLSearchParams(params).toString() : '')),
  createUser: (payload: Record<string, unknown>) =>
    request<UserItem>('/users', { method: 'POST', body: payload }),
  updateUser: (id: number, payload: Record<string, unknown>) =>
    request<UserItem>(`/users/${id}`, { method: 'PUT', body: payload }),
  toggleUserStatus: (id: number) =>
    request<{ id: number; is_active: boolean }>(`/users/${id}`, { method: 'DELETE' }),

  // ---- Groups & Permissions (Roles) ----
  listRoles: () => request<RoleItem[]>('/roles'),
  getPermissionsCatalog: () =>
    request<Record<string, { label: string; permissions: Array<{ code: string; name: string; description: string }> }>>('/roles/permissions-catalog'),
  createRole: (payload: Record<string, unknown>) =>
    request<RoleItem>('/roles', { method: 'POST', body: payload }),
  updateRole: (id: number, payload: Record<string, unknown>) =>
    request<RoleItem>(`/roles/${id}`, { method: 'PUT', body: payload }),
  deleteRole: (id: number) => request<{ id: number; deleted: boolean }>(`/roles/${id}`, { method: 'DELETE' }),

  // ---- Companies ----
  listCompanies: () => request<CompanyItem[]>('/companies'),
  getCompany: (id: number) => request<Record<string, unknown>>(`/companies/${id}`),
  createCompany: (payload: Record<string, unknown>) =>
    request<CompanyItem>('/companies', { method: 'POST', body: payload }),
  updateCompany: (id: number, payload: Record<string, unknown>) =>
    request<CompanyItem>(`/companies/${id}`, { method: 'PUT', body: payload }),
  deleteCompany: (id: number) =>
    request<{ id: number; deleted?: boolean; is_active?: boolean; message?: string }>(`/companies/${id}`, { method: 'DELETE' }),
  updateCompanySettings: (id: number, settings: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/companies/${id}/settings`, { method: 'PUT', body: { settings } }),

  // ---- Rate Cards ----
  listRateCards: (companyId: number) => request<Record<string, unknown>[]>(`/companies/${companyId}/rate-cards`),
  getRateCard: (companyId: number, cardId: number) => request<Record<string, unknown>>(`/companies/${companyId}/rate-cards/${cardId}`),
  createRateCard: (companyId: number, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/companies/${companyId}/rate-cards`, { method: 'POST', body: payload }),
  addRateCardItem: (companyId: number, cardId: number, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/companies/${companyId}/rate-cards/${cardId}/items`, { method: 'POST', body: payload }),
  addSlaRule: (companyId: number, cardId: number, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/companies/${companyId}/rate-cards/${cardId}/sla-rules`, { method: 'POST', body: payload }),
  deleteRateCardItem: (companyId: number, cardId: number, itemId: number) =>
    request<{ deleted: boolean }>(`/companies/${companyId}/rate-cards/${cardId}/items/${itemId}`, { method: 'DELETE' }),
  deleteSlaRule: (companyId: number, cardId: number, ruleId: number) =>
    request<{ deleted: boolean }>(`/companies/${companyId}/rate-cards/${cardId}/sla-rules/${ruleId}`, { method: 'DELETE' }),
  publishRateCard: (companyId: number, cardId: number, ignoreWarnings?: string[]) =>
    request<Record<string, unknown>>(`/companies/${companyId}/rate-cards/${cardId}/publish`, { method: 'POST', body: { ignore_warnings: ignoreWarnings } }),

  // ---- Products & Appliances ----
  listProductCategories: () => request<ProductCategoryItem[]>('/product-categories'),
  createProductCategory: (payload: Record<string, unknown>) =>
    request<ProductCategoryItem>('/product-categories', { method: 'POST', body: payload }),
  updateProductCategory: (id: number, payload: Record<string, unknown>) =>
    request<ProductCategoryItem>(`/product-categories/${id}`, { method: 'PUT', body: payload }),
  listBrands: (params?: Record<string, string>) =>
    request<BrandItem[]>('/brands' + (params ? '?' + new URLSearchParams(params).toString() : '')),
  createBrand: (payload: Record<string, unknown>) =>
    request<BrandItem>('/brands', { method: 'POST', body: payload }),
  updateBrand: (id: number, payload: Record<string, unknown>) =>
    request<BrandItem>(`/brands/${id}`, { method: 'PUT', body: payload }),
  listProducts: (params?: Record<string, string>) =>
    request<ProductItem[]>('/products' + (params ? '?' + new URLSearchParams(params).toString() : '')),
  createProduct: (payload: Record<string, unknown>) =>
    request<ProductItem>('/products', { method: 'POST', body: payload }),
  updateProduct: (id: number, payload: Record<string, unknown>) =>
    request<ProductItem>(`/products/${id}`, { method: 'PUT', body: payload }),
  toggleProductStatus: (id: number) =>
    request<{ id: number; is_active: boolean }>(`/products/${id}`, { method: 'DELETE' }),

  // ---- Technicians ----
  listTechnicians: (params?: Record<string, string>) =>
    request<TechnicianItem[]>('/technicians' + (params ? '?' + new URLSearchParams(params).toString() : '')),
  createTechnician: (payload: Record<string, unknown>) =>
    request<TechnicianItem>('/technicians', { method: 'POST', body: payload }),
  updateTechnician: (id: number, payload: Record<string, unknown>) =>
    request<TechnicianItem>(`/technicians/${id}`, { method: 'PUT', body: payload }),
  toggleTechnicianStatus: (id: number) =>
    request<{ id: number; is_active: boolean }>(`/technicians/${id}`, { method: 'DELETE' }),

  // ---- Master Lists ----
  listMasterLists: () => request<Record<string, unknown[]>>('/master-lists'),
  addMasterListItem: (list: string, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/master-lists/${list}`, { method: 'POST', body: payload }),
  updateMasterListItem: (list: string, id: number, payload: Record<string, unknown>) =>
    request<Record<string, unknown>>(`/master-lists/${list}/${id}`, { method: 'PUT', body: payload }),
  toggleMasterListItem: (list: string, id: number) =>
    request<Record<string, unknown>>(`/master-lists/${list}/${id}`, { method: 'DELETE' }),

  // ---- App Settings (portal-wide timezone & date/time format) ----
  getAppSettings: () => requestEnvelope<AppSettingsItem, AppSettingsMeta>('/app-settings'),
  updateAppSettings: (payload: Record<string, unknown>) =>
    request<AppSettingsItem>('/app-settings', { method: 'PUT', body: payload }),
}

export interface UserItem {
  id: number
  name: string
  email: string
  phone: string | null
  role_id: number
  service_center_id: number | null
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  role?: { id: number; code: string; name: string; permissions?: string[] }
  service_center?: { id: number; code: string; name: string } | null
}

export interface TechnicianItem {
  id: number
  user_id: number | null
  service_center_id: number
  code: string
  name: string
  phone: string
  alt_phone: string | null
  email: string | null
  employment_type: string
  joined_on: string | null
  exited_on: string | null
  skills: string[] | null
  max_open_tickets: number
  is_active: boolean
  notes: string | null
  service_center?: { id: number; code: string; name: string }
  user?: { id: number; name: string; email: string } | null
}

export interface RoleItem {
  id: number
  code: string
  name: string
  description: string | null
  permissions: string[]
  is_system: boolean
  user_count?: number
}

export interface CompanyItem {
  id: number
  code: string
  name: string
  legal_name: string | null
  is_active: boolean
  logo_path: string | null
  onboarded_on: string | null
}

export interface ProductCategoryItem {
  id: number
  code: string
  name: string
  is_sized: boolean
  sort_order: number
  is_active: boolean
}

export interface ProductItem {
  id: number
  company_id: number
  product_category_id: number
  model_no: string
  name: string | null
  size_inch: string | null
  warranty_months: number | null
  panel_warranty_months: number | null
  is_active: boolean
  company?: CompanyItem
  product_category?: ProductCategoryItem
}

export interface BrandItem {
  id: number
  company_id: number
  code: string
  name: string
  is_active: boolean
  company?: CompanyItem
}

export interface StateItem {
  id: number
  code: string
  name: string
  is_active: boolean
}

export interface CompanyInvoice {
  id: number
  invoice_no: string
  period_start: string
  period_end: string
  status: string
  total_paise: number
  paid_paise: number
  due_at: string | null
  sent_at: string | null
  ticket_count: number
  created: string
  company?: OptionItem
  company_invoice_lines?: Array<{ id: number; description: string; amount_paise: number }>
}

/**
 * What one company owes, split by how far along it is.
 *
 * The four stages are deliberately not summed into one figure on the
 * wire. `unbilled` is closed work no invoice has claimed — it is money
 * earned, but the company has never been told about it, so it is chased
 * by running a billing cycle, not by ringing accounts. `awaiting` is the
 * only bucket that is a debt, and the only one the credit limit measures.
 */
export interface CompanyReceivable {
  company: { id: number; code: string; name: string; accounts_email: string | null }
  /** Frozen ticket charges with no invoice claiming them. */
  unbilled: Money
  unbilled_ticket_count: number
  /** Raised but not served — still ours to restate. */
  draft: Money
  /** Served and unpaid. */
  awaiting: Money
  /** The part of `awaiting` past its due date. */
  overdue: Money
  received: Money
  /** unbilled + draft + awaiting. */
  total_due: Money
  ageing: { not_due: Money; d1_30: Money; d31_60: Money; d60_plus: Money }
  oldest_overdue_due_at: string | null
  invoice_count: number
  open_invoice_count: number
  credit_limit: Money
  headroom: Money
  over_limit: boolean
}

export interface ReceivablesReport {
  as_of: string
  companies: CompanyReceivable[]
  totals: {
    unbilled_ticket_count: number
    unbilled: Money
    draft: Money
    awaiting: Money
    overdue: Money
    received: Money
    total_due: Money
  }
}

/**
 * One row of the P&L breakdown — one charge line type on one ledger,
 * summed across every ticket closed in the period.
 */
export interface ProfitAndLossLine {
  ledger: string
  ledger_label: string
  is_inflow: boolean
  line_type: string
  line_type_label: string
  amount: Money
  ticket_count: number
}

/**
 * Income, expenses and what was kept, for tickets closed in a period.
 *
 * Built straight from the frozen charge ledger — the same rows an invoice
 * or a payout run would claim — so it never disagrees with either.
 */
export interface ProfitAndLossReport {
  period_start: string
  period_end: string
  ticket_count: number
  income: {
    company_receivable: Money
    customer_collection: Money
    total: Money
  }
  expenses: {
    company_payable: Money
    technician_payable: Money
    total: Money
  }
  net_margin: Money
  breakdown: ProfitAndLossLine[]
}

/**
 * What one technician is owed, split by how far along it is towards being
 * paid — mirrors `CompanyReceivable` on the other side of the ledger.
 */
export interface TechnicianDue {
  technician: { id: number; code: string; name: string }
  /** Frozen work not yet claimed by any payout run. */
  unclaimed: Money
  unclaimed_ticket_count: number
  draft: Money
  approved: Money
  /** unclaimed + draft + approved — what it would cost to settle up. */
  total_due: Money
  paid: Money
  payout_count: number
  pending_payout_count: number
}

export interface TechnicianDuesReport {
  as_of: string
  technicians: TechnicianDue[]
  totals: {
    unclaimed_ticket_count: number
    unclaimed: Money
    draft: Money
    approved: Money
    total_due: Money
    paid: Money
  }
}

/**
 * The service centre's own cash position — a different figure from the
 * P&L. This only moves on a real cash event: an invoice payment landing,
 * a technician payout actually being paid, or a desk correction. A
 * ticket closing moves the P&L and not this.
 */
export interface SavingsCenterBalance {
  service_center: { id: number; code: string; name: string }
  balance: Money
}

export interface SavingsEntry {
  id: number
  entry_type: 'credit' | 'debit'
  source_type: string
  source_id: number | null
  amount: Money
  balance_after: Money
  description: string
  created: string
  created_by: string | null
}

export interface SavingsDetail {
  service_center_id: number
  balance: Money
  ledger: SavingsEntry[]
}

/** One technician's own dues — the same row an admin's dues table shows. */
export interface WalletSummary {
  due: TechnicianDue & { as_of: string }
  history: Array<{
    id: number
    payout_no: string
    status: string
    period_start: string
    period_end: string
    net: Money
    paid_at: string | null
    created: string
  }>
}

export interface InvoicePreview {
  company_id: number
  period_start: string
  period_end: string
  totals: { total_paise: number }
  ticket_count: number
  line_count: number
}

export interface TechnicianPayout {
  id: number
  payout_no: string
  period_start: string
  period_end: string
  status: string
  total_payout_paise: number
  created: string
  technician?: { id: number; name: string; code: string }
  technician_payout_lines?: Array<{ id: number; description: string; amount_paise: number }>
}

/**
 * One line on an invoice or a payout.
 *
 * A settlement line is a COPY of a frozen ticket charge, not the charge
 * itself — which is what makes overriding one safe. `original_amount` is
 * what the run raised the line at and is null until somebody restates it,
 * so the pair together reads as "the ledger said this, we agreed that".
 */
export interface SettlementLine {
  id: number
  ticket_id: number | null
  ticket_no: string | null
  line_type: string
  type_label: string
  /** Null on payout lines — a payout only ever touches one book. */
  ledger: string | null
  ledger_label: string | null
  description: string
  quantity: string | null
  unit_amount: Money | null
  amount: Money
  is_overridden: boolean
  original_amount: Money | null
  override_reason: string | null
  overridden_at: string | null
  overridden_by: string | null
}

/** One component of the headline total, e.g. travel or the SLA incentive. */
export interface SettlementSplitBucket {
  key: string
  label: string
  /** -1 for the buckets that reduce the total, such as the company royalty. */
  sign: number
  amount: Money
  /** The bucket's contribution to the total, sign already applied. */
  effect: Money
}

export interface SettlementTicketGroup {
  ticket_id: number | null
  ticket_no: string
  subtotal: Money
  lines: SettlementLine[]
}

/** What both run types share, so one component can render either. */
export interface SettlementDetail {
  id: number
  status: string
  period_start: string
  period_end: string
  ticket_count: number
  /** Draft only. A sent invoice or an approved payout is a document
   *  somebody else is holding, so it stops being editable. */
  is_editable: boolean
  locked_reason: string | null
  split: SettlementSplitBucket[]
  totals: { total: Money; line_count: number }
  tickets: SettlementTicketGroup[]
}

export interface CompanyInvoiceDetail extends SettlementDetail {
  invoice_no: string
  company: { id: number; code: string; name: string } | null
  cycle_date: string | null
  due_at: string | null
  totals: { total: Money; paid: Money; balance: Money; line_count: number }
}

export interface PayoutMethodOption {
  value: string
  label: string
  /** What the reference should hold — UTR, UPI ID, or voucher number. */
  reference_label: string
}

export interface TechnicianPayoutDetail extends SettlementDetail {
  payout_no: string
  technician: { id: number; code: string; name: string } | null
  /**
   * How the money left our hands. A technician is paid by us out of the
   * margin, never by the company, so this is the payment's only trace.
   */
  payment: {
    method: string | null
    method_label: string | null
    reference: string | null
    paid_at: string | null
    approved_at: string | null
  }
  can_approve: boolean
  can_pay: boolean
  payment_methods: PayoutMethodOption[]
}

export interface OptionItem {
  id: number
  code: string
  name: string
  [key: string]: unknown
}

export interface TicketOptions {
  companies: OptionItem[]
  service_centers: OptionItem[]
  brands: OptionItem[]
  product_categories: OptionItem[]
  job_types: OptionItem[]
  symptoms: Array<OptionItem & { requires_video_proof?: boolean }>
  districts: OptionItem[]
  states: OptionItem[]
  technicians: Array<{ id: number; code: string; name: string; phone: string; service_center_id: number }>
  /** How a job can end. `is_billable: false` closes it with an empty ledger. */
  resolutions: Array<OptionItem & { requires_spare: boolean; is_billable: boolean }>
  /** Why the clock stopped. Only `pauses_sla: true` actually stops it. */
  hold_reasons: Array<OptionItem & { pauses_sla: boolean; requires_company_notice: boolean }>
  warranty_scopes: Array<{ code: string; name: string }>
  priorities: Array<{ code: string; name: string }>
  requirements?: Record<string, unknown>
}

export interface CustomerData {
  id?: number
  name: string
  phone: string
  alt_phone?: string
  email?: string
  address_line1?: string
  address_line2?: string
  landmark?: string
  city?: string
  /** Free text as typed at intake, when nobody matched it to a master row. */
  district?: string
  district_id?: number
  /**
   * The matched master district, contained on the single-ticket read only.
   * `_ref` because `customers` has its own `district` string column and Cake
   * would otherwise overwrite one with the other — see CustomersTable.
   */
  district_ref?: OptionItem
  pincode?: string
}

export interface TicketEventItem {
  id: number
  ticket_id: number
  event_type: string
  from_status: string | null
  to_status: string | null
  description: string | null
  occurred_at: string
}

/**
 * One part fitted on a job, as the ticket carries it.
 *
 * Money stays in paise the way the row stores it; the two clause dates
 * travel with the line because they are what the desk is answerable for
 * — `defective_return_due_at` is clause 9's deadline for sending the old
 * unit back, `billing_due_at` clause 10's cutoff after which the part is
 * treated as billed to us.
 */
export interface TicketSpareItem {
  id: number
  spare_part_id: number
  quantity: number
  serial_no: string | null
  unit_cost_paise: number
  margin_pct: string
  unit_price_paise: number
  line_total_paise: number
  /** `customer` out of warranty, `company` under it. */
  charged_to: string
  is_defective_return: boolean
  defective_return_due_at: string | null
  defective_returned_at: string | null
  received_at: string | null
  billing_due_at: string | null
  notes: string | null
  created: string
  spare_part?: { id: number; part_no: string; name: string; is_serialized?: boolean }
}

export interface Ticket {
  id: number
  ticket_no: string
  company_id: number
  company_ticket_ref: string | null
  service_center_id: number
  customer_id: number
  model_no: string | null
  serial_no: string | null
  size_inch: string | null
  purchase_date: string | null
  warranty_scope: string
  job_type_id: number
  status: string
  priority: string
  /** The complaint in the customer's words. */
  reported_issue: string | null
  /** What the technician found, written at closure. */
  diagnosis: string | null
  closure_notes: string | null
  cancellation_reason: string | null
  /** A second visit for the same fault inside the repeat window. */
  is_repeat?: boolean
  reopened_count?: number
  assigned_technician_id: number | null
  assigned_at: string | null
  received_at: string
  first_contact_at: string | null
  contact_due_at: string | null
  visit_due_at: string | null
  close_due_at: string | null
  visited_at: string | null
  closed_at: string | null
  checkin_at: string | null
  closure_otp_verified_at: string | null
  customer?: CustomerData
  company?: OptionItem
  service_center?: OptionItem
  job_type?: OptionItem
  assigned_technician?: { id: number; code: string; name: string; phone: string } | null
  brand?: OptionItem
  brand_id?: number | null
  product_category?: OptionItem
  product_category_id?: number | null
  /** The catalogued fault picked at intake, beside the free-text complaint. */
  symptom?: OptionItem
  symptom_id?: number | null
  resolution?: OptionItem & { is_billable?: boolean }
  ticket_events?: TicketEventItem[]
  ticket_spares?: TicketSpareItem[]
}

export interface RatePreviewLine {
  type: string
  ledger: string
  description: string
  quantity: string | null
  amount: Money
}

/** One row of the frozen ledger, as `GET /tickets/{id}/charges` returns it. */
export interface TicketChargeLine {
  id: number
  type: string
  type_label: string | null
  ledger: string
  ledger_label: string
  description: string
  quantity: string | null
  amount: Money
  /** A correction appended after the freeze rather than part of the original. */
  is_adjustment: boolean
  /** The base amount was agreed by hand because the card could not price it. */
  is_manual_base: boolean
  settlement_status: string
  computed_at: string | null
  snapshot: Record<string, unknown>
}

export interface TicketLedger {
  lines: TicketChargeLine[]
  totals: Record<string, Money & { line_count?: number }>
  is_frozen: boolean
  /** Why the ledger is locked, or null while the ticket is still open.
   *  Without this a refused part or a refused closure could only report
   *  that it was refused, never that a closure an hour ago was the cause. */
  freeze: { frozen_at: string; actor: string | null; reason: string | null } | null
}

export interface TicketAttachment {
  id: number
  /** serial_plate | before | after | signature | spare | invoice | video | other */
  kind: string
  original_name: string | null
  mime_type: string | null
  size_bytes: number | null
  uploaded_at: string
  /** Streamed through the app, not a direct webroot link. */
  url: string
}

export interface TicketComment {
  id: number
  body: string
  /** internal | company | customer — who the note was written for. */
  visibility: string
  author_id: number | null
  author_name: string
  occurred_at: string
}

export interface RatePreview {
  rate_card_id: number
  resolved: {
    label: string
    amount: Money
    payer: string
    ledger: string
    size_band: string
  }
  sla: Record<string, unknown>
  matched_rules: Array<{ code: string; description: string; amount: Money }>
  lines: RatePreviewLine[]
  totals: Record<string, Money & { line_count?: number }>
}

/* ------------------------------------------------------------------ */
/* Spare stock                                                         */
/* ------------------------------------------------------------------ */

/** A catalogue row, with balances when a centre was named. */
export interface SparePartOption {
  id: number
  company_id: number
  company?: { id: number; name: string }
  part_no: string
  name: string
  description: string | null
  cost_paise: number
  mrp_paise: number | null
  is_serialized: boolean
  reorder_level: number
  is_active: boolean
  /** Present only when the request named a service centre. */
  on_shelf?: number
  on_hand?: number
}

/**
 * One part at one centre.
 *
 * `on_hand` is everything the centre owns; `on_shelf` is what can
 * actually be handed to the next job. The difference is in technicians'
 * bags — still ours, still ageing, and not available.
 */
export interface SpareStockRow {
  spare_part_id: number
  part_no: string
  part_name: string
  company_id: number
  company_name: string | null
  service_center_id: number
  service_center_name: string
  on_hand: number
  on_shelf: number
  with_technicians: number
  unit_cost_paise: number
  value_paise: number
  reorder_level: number
  below_reorder: boolean
  /** Stock went out that was never booked in. Always a data problem. */
  is_negative: boolean
  is_serialized: boolean
  last_movement_at: string | null
}

export interface SpareStockSummary {
  value_paise: number
  below_reorder: number
  negative: number
}

export interface TechnicianHolding {
  technician_id: number
  technician_name: string
  service_center_id: number
  spare_part_id: number
  part_no: string
  part_name: string
  quantity: number
  unit_cost_paise: number
  value_paise: number
}

export interface SpareMovement {
  id: number
  spare_part_id: number
  service_center_id: number
  technician_id: number | null
  ticket_id: number | null
  /** received | issued | consumed | returned_good | returned_defective |
   *  sent_to_company | written_off | adjustment */
  movement_type: string
  /** Signed: positive adds to the location named on the row. */
  quantity: number
  serial_no: string | null
  unit_cost_paise: number | null
  reference: string | null
  occurred_at: string
  notes: string | null
  service_center?: { id: number; name: string }
  technician?: { id: number; name: string } | null
  ticket?: { id: number; ticket_no: string } | null
}

/**
 * An open receipt: parts from one challan that are still with us.
 *
 * Clause 10 bills the company's stock to us once we have kept it past the
 * agreed window, so a lot past `due_at` is money already spent.
 */
export interface SpareAgeingLot {
  spare_part_id: number
  part_no: string
  part_name: string
  company_id: number
  service_center_id: number
  service_center_name: string
  quantity: number
  received_at: string
  reference: string | null
  unit_cost_paise: number
  value_paise: number
  age_days: number
  billing_days: number
  due_at: string
  /** Negative once the window has passed. */
  days_left: number
  is_overdue: boolean
  is_due_soon: boolean
}

export interface SpareAgeingTotals {
  units: number
  value_paise: number
  overdue_units: number
  overdue_value_paise: number
}

export interface DefectiveReturnDue {
  id: number
  quantity: number
  serial_no: string | null
  defective_return_due_at: string | null
  ticket_no: string
  company_id: number
  part_no: string
  part_name: string
}

export interface AddSpareResult {
  ticket_spare_id: number
  /** What the centre holds after the part came out. */
  stock_remaining: number
  /** Set when that figure went negative — nothing was booked in. */
  stock_warning: string | null
}

export interface DashboardStats {
  total_tickets: number
  open_tickets: number
  unassigned_tickets: number
  closed_today: number
  pending_spares: number
  by_status: Record<string, number>
  /**
   * Per-company ticket counts, split into the five reporting buckets and
   * ordered with the most open work first. `open` is `count` minus the two
   * terminal buckets, so the columns always add up to the total beside them.
   */
  by_company: Array<{
    company_id: number
    company_name: string
    company_code: string
    count: number
    open: number
    pending: number
    in_progress: number
    on_hold: number
    closed: number
    cancelled: number
  }>
  recent_events: Array<{
    id: number
    ticket_id: number
    ticket_no: string
    event_type: string
    description: string
    occurred_at: string
  }>
}

export interface AppSettingsItem {
  id: number
  logo_base64: string | null
  favicon_base64: string | null
  timezone: string
  date_format: string
  time_format: string
}

export interface AppSettingsMeta {
  timezones: string[]
  date_formats: string[]
  time_formats: string[]
}
