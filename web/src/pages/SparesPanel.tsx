import { useState, useEffect, useCallback } from 'react'
import { PackagePlus } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import type {
  DefectiveReturnDue,
  SparePartOption,
  SpareAgeingLot,
  SpareAgeingTotals,
  SpareStockRow,
  SpareStockSummary,
  TechnicianHolding,
} from '../lib/api'

/**
 * Spare stock.
 *
 * Four views of the same ledger, ordered by what costs money if nobody
 * looks:
 *
 *   Ageing      clause 10 — stock kept past the agreed window is billed to
 *               us. Overdue rows are money already gone; the ones with
 *               days left are the only place anything can still be done.
 *   Defectives  clause 9 — the old part goes back on a settlement cycle,
 *               and a missed cycle is a part we bought by accident.
 *   Stock       balances by centre, split shelf from bags, flagged against
 *               reorder levels.
 *   With techs  who is carrying what. Nobody keeps this on paper, and it
 *               is where the clause 10 exposure actually sits.
 *
 * The stock view leads with problems — negative balances first, then
 * anything at or below its reorder level. A list sorted by part number is
 * a reference nobody reads top-down.
 */

type Tab = 'ageing' | 'defectives' | 'stock' | 'holdings' | 'catalogue'

const rupees = (paise: number): string =>
  '₹' + (paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const shortDate = (value: string | null): string =>
  value ? new Date(value.replace(' ', 'T')).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: '2-digit' }) : '—'

export function SparesPanel() {
  const [tab, setTab] = useState<Tab>('stock')
  const [centreId, setCentreId] = useState('')
  const [centres, setCentres] = useState<Array<{ id: number; name: string }>>([])
  const [companies, setCompanies] = useState<Array<{ id: number; name: string }>>([])
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)

  // Modals
  const [addPartModalOpen, setAddPartModalOpen] = useState(false)
  const [editPartModalOpen, setEditPartModalOpen] = useState(false)
  const [receiveModalOpen, setReceiveModalOpen] = useState(false)
  const [issueModalOpen, setIssueModalOpen] = useState(false)
  // Bumped after a receipt so the stock balances behind the modal reload.
  const [stockVersion, setStockVersion] = useState(0)
  const [catalogueParts, setCatalogueParts] = useState<SparePartOption[]>([])
  const [technicians, setTechnicians] = useState<Array<{ id: number; name: string }>>([])

  // Form states
  const [newPartForm, setNewPartForm] = useState({
    company_id: '',
    part_no: '',
    name: '',
    cost_rupees: '',
    mrp_rupees: '',
    reorder_level: '5',
  })

  const [editPartForm, setEditPartForm] = useState({
    id: 0,
    company_id: '',
    part_no: '',
    name: '',
    cost_rupees: '',
    mrp_rupees: '',
    reorder_level: '5',
    is_active: true,
  })

  // `received_at` starts blank on purpose. Clause 10 counts from the day
  // the company shipped, and defaulting the field to today quietly hands
  // us days of exposure we were not owed.
  const [receiveForm, setReceiveForm] = useState({
    spare_part_id: '',
    service_center_id: '',
    quantity: '1',
    reference: '',
    received_at: '',
  })

  const [issueForm, setIssueForm] = useState({
    spare_part_id: '',
    service_center_id: '',
    technician_id: '',
    quantity: '1',
  })

  useEffect(() => {
    Promise.all([api.ticketOptions(), api.listCompanies()])
      .then(([options, companyList]) => {
        setCentres(options.service_centers ?? [])
        setCompanies(companyList ?? [])
        setTechnicians(options.technicians ?? [])

        if (options.service_centers?.length > 0) {
          setReceiveForm((prev) => ({ ...prev, service_center_id: String(options.service_centers[0].id) }))
          setIssueForm((prev) => ({ ...prev, service_center_id: String(options.service_centers[0].id) }))
        }
        if (options.technicians?.length > 0) {
          setIssueForm((prev) => ({ ...prev, technician_id: String(options.technicians[0].id) }))
        }
        if (companyList?.length > 0) {
          setNewPartForm((prev) => ({ ...prev, company_id: String(companyList[0].id) }))
        }
      })
      .catch(() => setMessage({ type: 'error', text: 'Failed to load options.' }))
  }, [])

  const loadCatalogue = useCallback(async () => {
    try {
      const list = await api.spareCatalogue({ include_inactive: true })
      setCatalogueParts(list)
      if (list.length > 0) {
        if (!receiveForm.spare_part_id) {
          setReceiveForm((prev) => ({ ...prev, spare_part_id: String(list[0].id) }))
        }
        if (!issueForm.spare_part_id) {
          setIssueForm((prev) => ({ ...prev, spare_part_id: String(list[0].id) }))
        }
      }
    } catch (e) {
      console.error(e)
    }
  }, [receiveForm.spare_part_id, issueForm.spare_part_id])

  useEffect(() => {
    void loadCatalogue()
  }, [loadCatalogue])

  const centre = centreId === '' ? undefined : Number(centreId)

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'stock', label: 'Stock on hand' },
    { id: 'catalogue', label: 'Part Catalogue' },
    { id: 'ageing', label: 'Ageing (clause 10)' },
    { id: 'defectives', label: 'Defective returns (clause 9)' },
    { id: 'holdings', label: 'With technicians' },
  ]

  const handleAddPartSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setMessage(null)
    try {
      await api.createSparePart({
        company_id: Number(newPartForm.company_id),
        part_no: newPartForm.part_no,
        name: newPartForm.name,
        cost_rupees: Number(newPartForm.cost_rupees),
        mrp_rupees: Number(newPartForm.mrp_rupees),
        reorder_level: Number(newPartForm.reorder_level),
      })
      setMessage({ type: 'success', text: `Spare Part "${newPartForm.part_no}" added to catalogue!` })
      setAddPartModalOpen(false)
      setNewPartForm({ company_id: companies[0]?.id ? String(companies[0].id) : '', part_no: '', name: '', cost_rupees: '', mrp_rupees: '', reorder_level: '5' })
      await loadCatalogue()
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to add spare part' })
    }
  }

  const openEditPart = (part: SparePartOption) => {
    setEditPartForm({
      id: part.id,
      company_id: String(part.company_id),
      part_no: part.part_no,
      name: part.name,
      cost_rupees: String(part.cost_paise / 100),
      mrp_rupees: part.mrp_paise !== null ? String(part.mrp_paise / 100) : '',
      reorder_level: String(part.reorder_level),
      is_active: part.is_active,
    })
    setEditPartModalOpen(true)
  }

  const handleEditPartSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setMessage(null)
    try {
      await api.updateSparePart(editPartForm.id, {
        company_id: Number(editPartForm.company_id),
        part_no: editPartForm.part_no,
        name: editPartForm.name,
        cost_rupees: Number(editPartForm.cost_rupees),
        mrp_rupees: Number(editPartForm.mrp_rupees),
        reorder_level: Number(editPartForm.reorder_level),
        is_active: editPartForm.is_active,
      })
      setMessage({ type: 'success', text: `Spare Part "${editPartForm.part_no}" updated.` })
      setEditPartModalOpen(false)
      await loadCatalogue()
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to update spare part' })
    }
  }

  const handleReceiveSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setMessage(null)
    try {
      await api.receiveSpares({
        spare_part_id: Number(receiveForm.spare_part_id),
        service_center_id: Number(receiveForm.service_center_id),
        quantity: Number(receiveForm.quantity),
        reference: receiveForm.reference || undefined,
        received_at: receiveForm.received_at || undefined,
      })
      setMessage({
        type: 'success',
        text: `${receiveForm.quantity} booked in${receiveForm.reference ? ` against ${receiveForm.reference}` : ''}.`,
      })
      setReceiveModalOpen(false)
      // The challan number and date belong to the challan just booked, not
      // to the next one. Carrying them over would stamp the wrong reference
      // on the following receipt.
      setReceiveForm((prev) => ({ ...prev, quantity: '1', reference: '', received_at: '' }))
      setTab('stock')
      setStockVersion((v) => v + 1)
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to record receipt' })
    }
  }

  const handleIssueSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setMessage(null)
    try {
      await api.issueSpares({
        spare_part_id: Number(issueForm.spare_part_id),
        service_center_id: Number(issueForm.service_center_id),
        technician_id: Number(issueForm.technician_id),
        quantity: Number(issueForm.quantity),
      })
      setMessage({ type: 'success', text: `Spare part issued to technician bag!` })
      setIssueModalOpen(false)
      setTab('holdings')
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to issue spare part' })
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Spare Stock & Inventory</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Track company stock receipts, catalogue parts, technician holdings, and Clause 9 & 10 ageing.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <button
            onClick={() => setReceiveModalOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
          >
            <PackagePlus className="h-3.5 w-3.5" /> Book in a Challan (Receive Stock)
          </button>
          <button
            onClick={() => setIssueModalOpen(true)}
            className="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            + Issue to Technician
          </button>
          <button
            onClick={() => setAddPartModalOpen(true)}
            className="rounded-lg bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
          >
            + Add Spare Part
          </button>

          <label className="text-xs">
            <span className="mb-1 block font-medium text-slate-600 dark:text-slate-400">Service centre</span>
            <select
              value={centreId}
              onChange={(e) => setCentreId(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              <option value="">All centres</option>
              {centres.map((centre) => (
                <option key={centre.id} value={centre.id}>
                  {centre.name}
                </option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {message && (
        <div
          className={`rounded-xl px-4 py-2.5 text-sm ${
            message.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
              : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
          }`}
        >
          {message.text}
        </div>
      )}

      <div className="flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
        {tabs.map((item) => (
          <button
            key={item.id}
            onClick={() => setTab(item.id)}
            className={`border-b-2 px-3 py-2 text-sm font-medium transition ${
              tab === item.id
                ? 'border-brand-600 text-brand-700 dark:text-brand-300'
                : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'ageing' && <AgeingTab centreId={centre} />}
      {tab === 'defectives' && <DefectivesTab onMessage={setMessage} />}
      {tab === 'stock' && (
        <StockTab centreId={centre} reloadKey={stockVersion} onReceive={() => setReceiveModalOpen(true)} />
      )}
      {tab === 'holdings' && <HoldingsTab centreId={centre} />}
      {tab === 'catalogue' && (
        <CatalogueTab
          parts={catalogueParts}
          companies={companies}
          onReceive={(partId) => { setReceiveForm(f => ({ ...f, spare_part_id: String(partId) })); setReceiveModalOpen(true); }}
          onEdit={openEditPart}
        />
      )}

      {/* ADD NEW SPARE PART MODAL */}
      {addPartModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Add New Spare Part to Catalogue
            </h3>
            <form onSubmit={handleAddPartSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Company *
                </label>
                <select
                  required
                  value={newPartForm.company_id}
                  onChange={(e) => setNewPartForm({ ...newPartForm, company_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Part Number (SKU) *
                </label>
                <input
                  required
                  type="text"
                  value={newPartForm.part_no}
                  onChange={(e) => setNewPartForm({ ...newPartForm, part_no: e.target.value })}
                  placeholder="e.g. DN-PANEL-55UHD"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Part Description / Name *
                </label>
                <input
                  required
                  type="text"
                  value={newPartForm.name}
                  onChange={(e) => setNewPartForm({ ...newPartForm, name: e.target.value })}
                  placeholder="e.g. 55-inch LED Display Panel"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Unit Cost (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={newPartForm.cost_rupees}
                    onChange={(e) => setNewPartForm({ ...newPartForm, cost_rupees: e.target.value })}
                    placeholder="2400.00"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    MRP (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={newPartForm.mrp_rupees}
                    onChange={(e) => setNewPartForm({ ...newPartForm, mrp_rupees: e.target.value })}
                    placeholder="3500.00"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Reorder Level (Units)
                </label>
                <input
                  type="number"
                  value={newPartForm.reorder_level}
                  onChange={(e) => setNewPartForm({ ...newPartForm, reorder_level: e.target.value })}
                  placeholder="5"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setAddPartModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-medium text-white hover:bg-brand-700"
                >
                  Save Spare Part
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* EDIT SPARE PART MODAL */}
      {editPartModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Edit Spare Part
            </h3>
            <form onSubmit={handleEditPartSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Company *
                </label>
                <select
                  required
                  value={editPartForm.company_id}
                  onChange={(e) => setEditPartForm({ ...editPartForm, company_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Part Number (SKU) *
                </label>
                <input
                  required
                  type="text"
                  value={editPartForm.part_no}
                  onChange={(e) => setEditPartForm({ ...editPartForm, part_no: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Part Description / Name *
                </label>
                <input
                  required
                  type="text"
                  value={editPartForm.name}
                  onChange={(e) => setEditPartForm({ ...editPartForm, name: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Unit Cost (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={editPartForm.cost_rupees}
                    onChange={(e) => setEditPartForm({ ...editPartForm, cost_rupees: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    MRP (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={editPartForm.mrp_rupees}
                    onChange={(e) => setEditPartForm({ ...editPartForm, mrp_rupees: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Reorder Level (Units)
                </label>
                <input
                  type="number"
                  value={editPartForm.reorder_level}
                  onChange={(e) => setEditPartForm({ ...editPartForm, reorder_level: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <label className="flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-400">
                <input
                  type="checkbox"
                  checked={editPartForm.is_active}
                  onChange={(e) => setEditPartForm({ ...editPartForm, is_active: e.target.checked })}
                  className="rounded border-slate-300 dark:border-slate-700"
                />
                Active (visible for issue &amp; receipt)
              </label>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setEditPartModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-medium text-white hover:bg-brand-700"
                >
                  Save Changes
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* RECEIVE STOCK CHALLAN MODAL */}
      {receiveModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Book Stock Receipt (Company Challan)
            </h3>
            <form onSubmit={handleReceiveSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Service Center *
                </label>
                <select
                  required
                  value={receiveForm.service_center_id}
                  onChange={(e) => setReceiveForm({ ...receiveForm, service_center_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {centres.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Select Spare Part *
                </label>
                <select
                  required
                  value={receiveForm.spare_part_id}
                  onChange={(e) => setReceiveForm({ ...receiveForm, spare_part_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {catalogueParts.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.part_no} — {p.name}
                      {p.company?.name ? ` · ${p.company.name}` : ''}
                    </option>
                  ))}
                </select>
                <span className="mt-1 block text-[10px] text-slate-500 dark:text-slate-400">
                  A part belongs to one company, and stock booked against the wrong one can never be
                  fitted on that company's tickets.
                </span>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Quantity Received *
                  </label>
                  <input
                    required
                    type="number"
                    min="1"
                    value={receiveForm.quantity}
                    onChange={(e) => setReceiveForm({ ...receiveForm, quantity: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Challan Date *
                  </label>
                  <input
                    required
                    type="date"
                    value={receiveForm.received_at}
                    onChange={(e) => setReceiveForm({ ...receiveForm, received_at: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Challan / Invoice Reference No.
                </label>
                <input
                  type="text"
                  value={receiveForm.reference}
                  onChange={(e) => setReceiveForm({ ...receiveForm, reference: e.target.value })}
                  placeholder="e.g. CHAL-882910"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setReceiveModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-medium text-white hover:bg-emerald-700"
                >
                  Record Receipt
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ISSUE STOCK TO TECHNICIAN MODAL */}
      {issueModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Issue Spare Part to Technician
            </h3>
            <form onSubmit={handleIssueSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Service Center *
                </label>
                <select
                  required
                  value={issueForm.service_center_id}
                  onChange={(e) => setIssueForm({ ...issueForm, service_center_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {centres.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Select Technician Assignee *
                </label>
                <select
                  required
                  value={issueForm.technician_id}
                  onChange={(e) => setIssueForm({ ...issueForm, technician_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {technicians.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Select Spare Part *
                </label>
                <select
                  required
                  value={issueForm.spare_part_id}
                  onChange={(e) => setIssueForm({ ...issueForm, spare_part_id: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  {catalogueParts.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.part_no} — {p.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Quantity to Issue *
                </label>
                <input
                  required
                  type="number"
                  min="1"
                  value={issueForm.quantity}
                  onChange={(e) => setIssueForm({ ...issueForm, quantity: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setIssueModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-700"
                >
                  Issue Part to Bag
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Spare Parts Catalogue Tab                                          */
/* ------------------------------------------------------------------ */

function CatalogueTab({
  parts,
  companies,
  onReceive,
  onEdit,
}: {
  parts: SparePartOption[]
  companies: Array<{ id: number; name: string }>
  onReceive: (partId: number) => void
  onEdit: (part: SparePartOption) => void
}) {
  const [search, setSearch] = useState('')
  const [selectedCompany, setSelectedCompany] = useState('')

  const filtered = parts.filter((p) => {
    if (selectedCompany && String(p.company_id) !== selectedCompany) return false
    if (search) {
      const q = search.toLowerCase()
      return p.part_no.toLowerCase().includes(q) || p.name.toLowerCase().includes(q)
    }
    return true
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search part number or name…"
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white w-64"
          />

          <select
            value={selectedCompany}
            onChange={(e) => setSelectedCompany(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
          >
            <option value="">All companies</option>
            {companies.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </div>

        <span className="text-xs text-slate-500">{filtered.length} parts registered</span>
      </div>

      <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table className="w-full min-w-[42rem] text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            <tr>
              <th className="px-4 py-3">Part Number</th>
              <th className="px-4 py-3">Description</th>
              <th className="px-4 py-3">Company</th>
              <th className="px-4 py-3">Unit Cost</th>
              <th className="px-4 py-3">MRP</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {filtered.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-xs text-slate-500">
                  No spare parts found in catalogue.
                </td>
              </tr>
            ) : (
              filtered.map((p) => (
                <tr key={p.id} className={`hover:bg-slate-50 dark:hover:bg-slate-800/40 ${p.is_active ? '' : 'opacity-50'}`}>
                  <td className="px-4 py-3 font-mono text-xs font-bold text-slate-900 dark:text-white">
                    {p.part_no}
                  </td>
                  <td className="px-4 py-3 text-slate-800 dark:text-slate-200 font-medium">
                    {p.name}
                    {!p.is_active && (
                      <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                        Inactive
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    {p.company?.name || `Company #${p.company_id}`}
                  </td>
                  <td className="px-4 py-3 font-semibold text-slate-900 dark:text-white">
                    {rupees(p.cost_paise)}
                  </td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    {p.mrp_paise !== null ? rupees(p.mrp_paise) : '—'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => onEdit(p)}
                        className="rounded bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => onReceive(p.id)}
                        className="rounded bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300"
                      >
                        + Book Receipt
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Clause 10 — stock we are still holding                              */
/* ------------------------------------------------------------------ */

function AgeingTab({ centreId }: { centreId?: number }) {
  const [lots, setLots] = useState<SpareAgeingLot[]>([])
  const [totals, setTotals] = useState<SpareAgeingTotals | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    api
      .spareAgeing({ service_center_id: centreId })
      .then(({ data, meta }) => {
        setLots(data)
        setTotals(meta)
      })
      .finally(() => setLoading(false))
  }, [centreId])

  if (loading) return <Empty>Loading…</Empty>

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Units held" value={String(totals?.units ?? 0)} />
        <Stat label="Value held" value={rupees(totals?.value_paise ?? 0)} />
        <Stat
          label="Past the window"
          value={String(totals?.overdue_units ?? 0)}
          tone={(totals?.overdue_units ?? 0) > 0 ? 'bad' : 'good'}
        />
        <Stat
          label="Already billed to us"
          value={rupees(totals?.overdue_value_paise ?? 0)}
          tone={(totals?.overdue_value_paise ?? 0) > 0 ? 'bad' : 'good'}
          hint="Clause 10 — kept past the agreed window"
        />
      </div>

      {lots.length === 0 ? (
        <Empty>
          Nothing on the shelf is ageing. Either every challan has been used, or none has been booked in —
          stock that was never received cannot be aged.
        </Empty>
      ) : (
        <Table
          head={['Part', 'Centre', 'Qty', 'Received', 'Challan', 'Age', 'Due', 'Value']}
          rows={lots.map((lot) => ({
            key: `${lot.spare_part_id}-${lot.service_center_id}-${lot.received_at}`,
            tone: lot.is_overdue ? 'bad' : lot.is_due_soon ? 'warn' : undefined,
            cells: [
              <span>
                <span className="font-medium">{lot.part_no}</span>
                <span className="block text-[11px] text-slate-500 dark:text-slate-400">{lot.part_name}</span>
              </span>,
              lot.service_center_name,
              String(lot.quantity),
              shortDate(lot.received_at),
              lot.reference ?? '—',
              `${lot.age_days}d`,
              lot.is_overdue ? (
                <span className="font-medium text-rose-600 dark:text-rose-400">
                  {Math.abs(lot.days_left)}d over
                </span>
              ) : (
                <span className={lot.is_due_soon ? 'font-medium text-amber-600 dark:text-amber-400' : ''}>
                  {lot.days_left}d left
                </span>
              ),
              rupees(lot.value_paise),
            ],
          }))}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Clause 9 — defectives going back                                    */
/* ------------------------------------------------------------------ */

function DefectivesTab({ onMessage }: { onMessage: (m: { type: 'success' | 'error'; text: string }) => void }) {
  const [due, setDue] = useState<DefectiveReturnDue[]>([])
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [reference, setReference] = useState('')
  const [creditReference, setCreditReference] = useState('')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    // A wide window on purpose. The cycle is short and the useful question
    // is "what goes in the next box", not "what is late".
    api
      .listDefectiveReturns({ within_days: 30 })
      .then(setDue)
      .finally(() => setLoading(false))
  }, [])

  useEffect(load, [load])

  const toggle = (id: number) => {
    const next = new Set(selected)
    next.has(id) ? next.delete(id) : next.add(id)
    setSelected(next)
  }

  const send = async () => {
    setSubmitting(true)
    try {
      const result = await api.sendDefectiveBatch([...selected], reference)
      const skipped = Object.keys(result.skipped).length

      onMessage({
        type: 'success',
        text: `${result.returned.length} sent under ${result.reference}.` +
          (skipped > 0 ? ` ${skipped} skipped — already gone back.` : ''),
      })

      setSelected(new Set())
      setReference('')
      load()
    } catch (error) {
      onMessage({
        type: 'error',
        text: error instanceof ApiError ? error.message : 'The batch could not be sent.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  const recordCredit = async () => {
    setSubmitting(true)
    try {
      const result = await api.recordDefectiveCredit({ reference: creditReference })
      onMessage({ type: 'success', text: `Credit recorded against ${result.credited} part(s).` })
      setCreditReference('')
      load()
    } catch (error) {
      onMessage({
        type: 'error',
        text: error instanceof ApiError ? error.message : 'The credit could not be recorded.',
      })
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Empty>Loading…</Empty>

  return (
    <div className="space-y-4">
      {/* Both ends of the cycle sit together. The credit note is what
          closes it, and separating them is how a return ends up sent but
          never reconciled. */}
      <div className="grid gap-3 lg:grid-cols-2">
        <Card title="Send a batch back">
          <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
            One docket covers the whole box, and the company's credit note will quote it back.
          </p>
          <div className="flex flex-wrap gap-2">
            <input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="Courier docket or challan no."
              className="min-w-[14rem] flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <button
              onClick={() => void send()}
              disabled={submitting || selected.size === 0 || reference.trim() === ''}
              className="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
            >
              Send {selected.size > 0 ? `${selected.size} part(s)` : 'selected'}
            </button>
          </div>
        </Card>

        <Card title="Record a credit note">
          <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
            Against the docket it quotes. Until this is recorded, every part in that box is still outstanding.
          </p>
          <div className="flex flex-wrap gap-2">
            <input
              value={creditReference}
              onChange={(e) => setCreditReference(e.target.value)}
              placeholder="Docket the credit quotes"
              className="min-w-[14rem] flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <button
              onClick={() => void recordCredit()}
              disabled={submitting || creditReference.trim() === ''}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              Record credit
            </button>
          </div>
        </Card>
      </div>

      {due.length === 0 ? (
        <Empty>No defectives are waiting to go back.</Empty>
      ) : (
        <Table
          head={['', 'Part', 'Ticket', 'Qty', 'Serial', 'Due back']}
          rows={due.map((item) => {
            const overdue = item.defective_return_due_at !== null
              && new Date(item.defective_return_due_at.replace(' ', 'T')) < new Date()

            return {
              key: String(item.id),
              tone: overdue ? 'bad' : undefined,
              cells: [
                <input type="checkbox" checked={selected.has(item.id)} onChange={() => toggle(item.id)} />,
                <span>
                  <span className="font-medium">{item.part_no}</span>
                  <span className="block text-[11px] text-slate-500 dark:text-slate-400">{item.part_name}</span>
                </span>,
                item.ticket_no,
                String(item.quantity),
                item.serial_no ?? '—',
                <span className={overdue ? 'font-medium text-rose-600 dark:text-rose-400' : ''}>
                  {shortDate(item.defective_return_due_at)}
                  {overdue ? ' · late' : ''}
                </span>,
              ],
            }
          })}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Balances                                                            */
/* ------------------------------------------------------------------ */

function StockTab({
  centreId,
  reloadKey,
  onReceive,
}: {
  centreId?: number
  /** Bumped by the page after a receipt is booked, to reload balances. */
  reloadKey: number
  onReceive: () => void
}) {
  const [rows, setRows] = useState<SpareStockRow[]>([])
  const [summary, setSummary] = useState<SpareStockSummary | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    api
      .spareStock({ service_center_id: centreId })
      .then(({ data, meta }) => {
        setRows(data)
        setSummary(meta)
      })
      .finally(() => setLoading(false))
  }, [centreId, reloadKey])

  useEffect(load, [load])

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label="Stock value" value={rupees(summary?.value_paise ?? 0)} />
        <Stat
          label="At or below reorder"
          value={String(summary?.below_reorder ?? 0)}
          tone={(summary?.below_reorder ?? 0) > 0 ? 'warn' : 'good'}
        />
        <Stat
          label="Negative balances"
          value={String(summary?.negative ?? 0)}
          tone={(summary?.negative ?? 0) > 0 ? 'bad' : 'good'}
          hint="Stock went out that was never booked in"
        />
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <button
          onClick={onReceive}
          className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700"
        >
          <PackagePlus className="h-4 w-4" /> Book in a challan
        </button>
        {centreId === undefined && (
          <span className="text-xs text-slate-500 dark:text-slate-400">
            Choose a centre above to see parts that have run to zero — those rows have no movements to list.
          </span>
        )}
      </div>

      {loading ? (
        <Empty>Loading…</Empty>
      ) : rows.length === 0 ? (
        <Empty>No stock movements recorded yet. Book a company challan in to start the ledger.</Empty>
      ) : (
        <Table
          head={['Part', 'Company', 'Centre', 'On shelf', 'With techs', 'On hand', 'Reorder', 'Value']}
          rows={rows.map((row) => ({
            key: `${row.spare_part_id}-${row.service_center_id}`,
            tone: row.is_negative ? 'bad' : row.below_reorder ? 'warn' : undefined,
            cells: [
              <span>
                <span className="font-medium">{row.part_no}</span>
                <span className="block text-[11px] text-slate-500 dark:text-slate-400">{row.part_name}</span>
              </span>,
              // Two companies can both stock a "43-inch backlight strip".
              // Without the company on the row the balance is ambiguous.
              row.company_name ?? '—',
              row.service_center_name,
              <span className={row.below_reorder ? 'font-medium text-amber-600 dark:text-amber-400' : ''}>
                {row.on_shelf}
              </span>,
              row.with_technicians === 0 ? '—' : String(row.with_technicians),
              <span className={row.is_negative ? 'font-medium text-rose-600 dark:text-rose-400' : ''}>
                {row.on_hand}
              </span>,
              String(row.reorder_level),
              rupees(row.value_paise),
            ],
          }))}
        />
      )}
    </div>
  )
}

function HoldingsTab({ centreId }: { centreId?: number }) {
  const [rows, setRows] = useState<TechnicianHolding[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    api.spareHoldings({ service_center_id: centreId }).then(setRows).finally(() => setLoading(false))
  }, [centreId])

  if (loading) return <Empty>Loading…</Empty>

  if (rows.length === 0) {
    return <Empty>No technician is carrying stock.</Empty>
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500 dark:text-slate-400">
        Still our stock and still ageing under clause 10. A negative figure means more was fitted than was ever
        issued — the ledger is missing an issue, not a part.
      </p>
      <Table
        head={['Technician', 'Part', 'Qty', 'Value']}
        rows={rows.map((row) => ({
          key: `${row.technician_id}-${row.spare_part_id}`,
          tone: row.quantity < 0 ? 'bad' : undefined,
          cells: [
            row.technician_name,
            <span>
              <span className="font-medium">{row.part_no}</span>
              <span className="block text-[11px] text-slate-500 dark:text-slate-400">{row.part_name}</span>
            </span>,
            String(row.quantity),
            rupees(row.value_paise),
          ],
        }))}
      />
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Presentation                                                        */
/* ------------------------------------------------------------------ */

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <h2 className="mb-2 text-sm font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function Stat({
  label,
  value,
  tone,
  hint,
}: {
  label: string
  value: string
  tone?: 'good' | 'warn' | 'bad'
  hint?: string
}) {
  const toneClass =
    tone === 'bad'
      ? 'text-rose-600 dark:text-rose-400'
      : tone === 'warn'
        ? 'text-amber-600 dark:text-amber-400'
        : tone === 'good'
          ? 'text-emerald-600 dark:text-emerald-400'
          : ''

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <div className="text-xs font-medium text-slate-500 dark:text-slate-400">{label}</div>
      <div className={`mt-1 text-xl font-semibold tabular-nums ${toneClass}`}>{value}</div>
      {hint && <div className="mt-1 text-[10px] text-slate-400 dark:text-slate-500">{hint}</div>}
    </div>
  )
}

function Empty({ children }: { children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
      {children}
    </div>
  )
}

interface TableRow {
  key: string
  tone?: 'warn' | 'bad'
  cells: React.ReactNode[]
}

function Table({ head, rows }: { head: string[]; rows: TableRow[] }) {
  return (
    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <table className="w-full text-sm">
        <thead className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
          <tr>
            {head.map((cell, index) => (
              <th key={index} className="px-3 py-2 font-medium">
                {cell}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((row) => (
            <tr
              key={row.key}
              className={
                row.tone === 'bad'
                  ? 'bg-rose-50/60 dark:bg-rose-950/20'
                  : row.tone === 'warn'
                    ? 'bg-amber-50/60 dark:bg-amber-950/20'
                    : ''
              }
            >
              {row.cells.map((cell, index) => (
                <td key={index} className="px-3 py-2 align-top tabular-nums">
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
