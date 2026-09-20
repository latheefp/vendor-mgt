import { useState, useEffect, useRef } from 'react'
import { api } from '../lib/api'
import type {
  UserItem,
  RoleItem,
  CompanyItem,
  ProductCategoryItem,
  ProductItem,
  BrandItem,
  StateItem,
  OptionItem,
  TechnicianItem,
  AppSettingsItem,
  AppSettingsMeta,
} from '../lib/api'

type SettingsTab =
  | 'users'
  | 'technicians'
  | 'roles'
  | 'rate-cards'
  | 'products'
  | 'master-lists'
  | 'configurations'

const SETTINGS_TAB_META: Record<SettingsTab, { title: string; description: string }> = {
  users: {
    title: 'Users & Accounts',
    description: 'Manage user accounts, roles, and service center assignments.',
  },
  technicians: {
    title: 'Technicians',
    description: 'Manage field technicians, their skills, and service center assignments.',
  },
  roles: {
    title: 'Groups & Permissions',
    description: 'Configure security groups and granular access control rules.',
  },
  'rate-cards': {
    title: 'Rate Cards & SLA',
    description: 'Manage pricing rate cards and service level agreements.',
  },
  products: {
    title: 'Companies & Products',
    description: 'Manage client companies, brands, appliance categories, and the product models catalogue.',
  },
  'master-lists': {
    title: 'Master Lists',
    description: 'Manage shared reference lists used across the system.',
  },
  configurations: {
    title: 'Configurations',
    description: 'Portal-wide timezone and date/time display conventions.',
  },
}

export function SettingsPanel({ initialTab = 'users' }: { initialTab?: SettingsTab }) {
  const [activeTab, setActiveTab] = useState<SettingsTab>(initialTab)

  useEffect(() => {
    if (initialTab) {
      setActiveTab(initialTab)
    }
  }, [initialTab])

  const meta = SETTINGS_TAB_META[activeTab]

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="border-b border-slate-200 pb-4 dark:border-slate-800">
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{meta.title}</h1>
        <p className="text-sm text-slate-500 dark:text-slate-400">{meta.description}</p>
      </div>

      {/* Tab Panels */}
      {activeTab === 'users' && <UsersTab />}
      {activeTab === 'technicians' && <TechniciansTab />}
      {activeTab === 'roles' && <RolesTab />}
      {activeTab === 'rate-cards' && <RateCardsTab />}
      {activeTab === 'products' && <ProductsTab />}
      {activeTab === 'master-lists' && <MasterListsTab />}
      {activeTab === 'configurations' && <ConfigurationsTab />}
    </div>
  )
}

/* ==================================================================== */
/* 1. USERS MANAGEMENT TAB                                              */
/* ==================================================================== */
function UsersTab() {
  const [users, setUsers] = useState<UserItem[]>([])
  const [roles, setRoles] = useState<RoleItem[]>([])
  const [serviceCenters, setServiceCenters] = useState<Array<{ id: number; name: string }>>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [selectedRole, setSelectedRole] = useState('')

  // Modal State
  const [modalOpen, setModalOpen] = useState(false)
  const [editingUser, setEditingUser] = useState<UserItem | null>(null)
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    role_id: '',
    service_center_id: '',
    is_active: true,
  })
  const [saving, setSaving] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const [uRes, rRes, optRes] = await Promise.all([
        api.listUsers({ search, role_id: selectedRole }),
        api.listRoles(),
        api.ticketOptions(),
      ])
      setUsers(uRes)
      setRoles(rRes)
      setServiceCenters(optRes.service_centers)
    } catch (e) {
      console.error('Failed to load users', e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [search, selectedRole])

  const openAddModal = () => {
    setEditingUser(null)
    setFormData({
      name: '',
      email: '',
      phone: '',
      password: 'Password123!',
      role_id: roles[0]?.id ? String(roles[0].id) : '',
      service_center_id: serviceCenters[0]?.id ? String(serviceCenters[0].id) : '',
      is_active: true,
    })
    setErrorMsg('')
    setModalOpen(true)
  }

  const openEditModal = (user: UserItem) => {
    setEditingUser(user)
    setFormData({
      name: user.name,
      email: user.email,
      phone: user.phone || '',
      password: '',
      role_id: String(user.role_id),
      service_center_id: user.service_center_id ? String(user.service_center_id) : '',
      is_active: user.is_active,
    })
    setErrorMsg('')
    setModalOpen(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')

    try {
      const payload: Record<string, unknown> = {
        name: formData.name,
        email: formData.email,
        phone: formData.phone || null,
        role_id: parseInt(formData.role_id),
        service_center_id: formData.service_center_id ? parseInt(formData.service_center_id) : null,
        is_active: formData.is_active,
      }

      if (formData.password) {
        payload.password = formData.password
      }

      if (editingUser) {
        await api.updateUser(editingUser.id, payload)
      } else {
        await api.createUser(payload)
      }

      setModalOpen(false)
      await loadData()
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : 'Failed to save user')
    } finally {
      setSaving(false)
    }
  }

  const handleToggleStatus = async (user: UserItem) => {
    try {
      await api.toggleUserStatus(user.id)
      await loadData()
    } catch (err) {
      console.error(err)
    }
  }

  return (
    <div className="space-y-4">
      {/* Controls Bar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 items-center gap-3">
          <input
            type="text"
            placeholder="Search by name, email, phone…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
          />

          <select
            value={selectedRole}
            onChange={(e) => setSelectedRole(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            <option value="">All Roles</option>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
        </div>

        <button
          onClick={openAddModal}
          className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
        >
          <span>+ Add User</span>
        </button>
      </div>

      {/* Users Table */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            <tr>
              <th className="px-4 py-3">User Name</th>
              <th className="px-4 py-3">Contact</th>
              <th className="px-4 py-3">Group / Role</th>
              <th className="px-4 py-3">Service Center</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {loading ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                  Loading users…
                </td>
              </tr>
            ) : users.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                  No users found.
                </td>
              </tr>
            ) : (
              users.map((u) => (
                <tr key={u.id} className="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                    {u.name}
                    {u.must_change_password && (
                      <span className="ml-2 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        Must Change Password
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    <div>{u.email}</div>
                    {u.phone && <div className="text-xs text-slate-400">{u.phone}</div>}
                  </td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                      {u.role?.name || 'Unassigned'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    {u.service_center?.name || 'Global / None'}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                        u.is_active
                          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                          : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'
                      }`}
                    >
                      <span
                        className={`h-1.5 w-1.5 rounded-full ${
                          u.is_active ? 'bg-emerald-500' : 'bg-rose-500'
                        }`}
                      />
                      {u.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => openEditModal(u)}
                        className="rounded px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-950/40"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => void handleToggleStatus(u)}
                        className="rounded px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                      >
                        {u.is_active ? 'Deactivate' : 'Activate'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* User Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">
              {editingUser ? 'Edit User' : 'Create New User'}
            </h3>

            {errorMsg && (
              <div className="mb-4 rounded-lg bg-rose-50 p-3 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                {errorMsg}
              </div>
            )}

            <form onSubmit={handleSave} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Full Name
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Email Address
                </label>
                <input
                  type="email"
                  required
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Phone Number
                </label>
                <input
                  type="text"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  {editingUser ? 'Password (leave blank to keep existing)' : 'Password'}
                </label>
                <input
                  type="password"
                  required={!editingUser}
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Group / Role
                  </label>
                  <select
                    value={formData.role_id}
                    onChange={(e) => setFormData({ ...formData, role_id: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    {roles.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Service Center
                  </label>
                  <select
                    value={formData.service_center_id}
                    onChange={(e) => setFormData({ ...formData, service_center_id: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    <option value="">Global / All</option>
                    {serviceCenters.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="flex items-center gap-2 pt-2">
                <input
                  type="checkbox"
                  id="user-active"
                  checked={formData.is_active}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                  className="h-4 w-4 rounded border-slate-300 text-brand-600"
                />
                <label htmlFor="user-active" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                  Account is Active
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-50"
                >
                  {saving ? 'Saving…' : 'Save User'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 1b. TECHNICIANS TAB                                                  */
/* ==================================================================== */
function TechniciansTab() {
  const [technicians, setTechnicians] = useState<TechnicianItem[]>([])
  const [serviceCenters, setServiceCenters] = useState<OptionItem[]>([])
  const [jobTypes, setJobTypes] = useState<OptionItem[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [selectedCenter, setSelectedCenter] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editingTechnician, setEditingTechnician] = useState<TechnicianItem | null>(null)
  const [formData, setFormData] = useState({
    code: '',
    name: '',
    phone: '',
    alt_phone: '',
    email: '',
    service_center_id: '',
    employment_type: 'contractor',
    max_open_tickets: '10',
    skills: [] as string[],
    is_active: true,
  })
  const [saving, setSaving] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const [tRes, optRes] = await Promise.all([
        api.listTechnicians({ search, service_center_id: selectedCenter }),
        api.ticketOptions(),
      ])
      setTechnicians(tRes)
      setServiceCenters(optRes.service_centers)
      setJobTypes(optRes.job_types)
    } catch (e) {
      console.error('Failed to load technicians', e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [search, selectedCenter])

  const openAddModal = () => {
    setEditingTechnician(null)
    setFormData({
      code: '',
      name: '',
      phone: '',
      alt_phone: '',
      email: '',
      service_center_id: serviceCenters[0]?.id ? String(serviceCenters[0].id) : '',
      employment_type: 'contractor',
      max_open_tickets: '10',
      skills: [],
      is_active: true,
    })
    setErrorMsg('')
    setModalOpen(true)
  }

  const openEditModal = (technician: TechnicianItem) => {
    setEditingTechnician(technician)
    setFormData({
      code: technician.code,
      name: technician.name,
      phone: technician.phone,
      alt_phone: technician.alt_phone || '',
      email: technician.email || '',
      service_center_id: String(technician.service_center_id),
      employment_type: technician.employment_type,
      max_open_tickets: String(technician.max_open_tickets),
      skills: technician.skills || [],
      is_active: technician.is_active,
    })
    setErrorMsg('')
    setModalOpen(true)
  }

  const toggleSkill = (code: string) => {
    setFormData((prev) => ({
      ...prev,
      skills: prev.skills.includes(code)
        ? prev.skills.filter((s) => s !== code)
        : [...prev.skills, code],
    }))
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')

    try {
      const payload: Record<string, unknown> = {
        code: formData.code,
        name: formData.name,
        phone: formData.phone,
        alt_phone: formData.alt_phone || null,
        email: formData.email || null,
        service_center_id: parseInt(formData.service_center_id),
        employment_type: formData.employment_type,
        max_open_tickets: parseInt(formData.max_open_tickets) || 0,
        skills: formData.skills,
        is_active: formData.is_active,
      }

      if (editingTechnician) {
        await api.updateTechnician(editingTechnician.id, payload)
      } else {
        await api.createTechnician(payload)
      }

      setModalOpen(false)
      await loadData()
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : 'Failed to save technician')
    } finally {
      setSaving(false)
    }
  }

  const handleToggleStatus = async (technician: TechnicianItem) => {
    try {
      await api.toggleTechnicianStatus(technician.id)
      await loadData()
    } catch (err) {
      console.error(err)
    }
  }

  return (
    <div className="space-y-4">
      {/* Controls Bar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 items-center gap-3">
          <input
            type="text"
            placeholder="Search by name, code, phone…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full max-w-xs rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
          />

          <select
            value={selectedCenter}
            onChange={(e) => setSelectedCenter(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            <option value="">All Service Centers</option>
            {serviceCenters.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>

        <button
          onClick={openAddModal}
          className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
        >
          <span>+ Add Technician</span>
        </button>
      </div>

      {/* Technicians Table */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
            <tr>
              <th className="px-4 py-3">Code</th>
              <th className="px-4 py-3">Name</th>
              <th className="px-4 py-3">Contact</th>
              <th className="px-4 py-3">Service Center</th>
              <th className="px-4 py-3">Employment</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                  Loading technicians…
                </td>
              </tr>
            ) : technicians.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                  No technicians found.
                </td>
              </tr>
            ) : (
              technicians.map((t) => (
                <tr key={t.id} className="transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                  <td className="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">{t.code}</td>
                  <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{t.name}</td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    <div>{t.phone}</div>
                    {t.email && <div className="text-xs text-slate-400">{t.email}</div>}
                  </td>
                  <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                    {t.service_center?.name || '—'}
                  </td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-medium capitalize text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                      {t.employment_type}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                        t.is_active
                          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                          : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'
                      }`}
                    >
                      <span
                        className={`h-1.5 w-1.5 rounded-full ${
                          t.is_active ? 'bg-emerald-500' : 'bg-rose-500'
                        }`}
                      />
                      {t.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => openEditModal(t)}
                        className="rounded px-2 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-950/40"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => void handleToggleStatus(t)}
                        className="rounded px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                      >
                        {t.is_active ? 'Deactivate' : 'Activate'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Technician Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">
              {editingTechnician ? 'Edit Technician' : 'Add New Technician'}
            </h3>

            {errorMsg && (
              <div className="mb-4 rounded-lg bg-rose-50 p-3 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                {errorMsg}
              </div>
            )}

            <form onSubmit={handleSave} className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Technician Code
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.code}
                    onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Full Name
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Phone Number
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Alternate Phone
                  </label>
                  <input
                    type="text"
                    value={formData.alt_phone}
                    onChange={(e) => setFormData({ ...formData, alt_phone: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Email Address
                </label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Service Center
                  </label>
                  <select
                    required
                    value={formData.service_center_id}
                    onChange={(e) => setFormData({ ...formData, service_center_id: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    <option value="" disabled>
                      Select…
                    </option>
                    {serviceCenters.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Employment Type
                  </label>
                  <select
                    value={formData.employment_type}
                    onChange={(e) => setFormData({ ...formData, employment_type: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    <option value="contractor">Contractor</option>
                    <option value="employee">Employee</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Max Open Tickets
                </label>
                <input
                  type="number"
                  min={0}
                  required
                  value={formData.max_open_tickets}
                  onChange={(e) => setFormData({ ...formData, max_open_tickets: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              {jobTypes.length > 0 && (
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Skills (job types this technician can be assigned)
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {jobTypes.map((jt) => (
                      <button
                        type="button"
                        key={jt.code}
                        onClick={() => toggleSkill(jt.code)}
                        className={`rounded-full border px-3 py-1 text-xs font-medium transition ${
                          formData.skills.includes(jt.code)
                            ? 'border-brand-600 bg-brand-50 text-brand-700 dark:border-brand-400 dark:bg-brand-950/40 dark:text-brand-300'
                            : 'border-slate-300 text-slate-600 hover:border-slate-400 dark:border-slate-700 dark:text-slate-400'
                        }`}
                      >
                        {jt.name}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              <div className="flex items-center gap-2 pt-2">
                <input
                  type="checkbox"
                  id="technician-active"
                  checked={formData.is_active}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                  className="h-4 w-4 rounded border-slate-300 text-brand-600"
                />
                <label htmlFor="technician-active" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                  Technician is Active
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-50"
                >
                  {saving ? 'Saving…' : 'Save Technician'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 2. GROUPS & PERMISSIONS TAB                                         */
/* ==================================================================== */
function RolesTab() {
  const [roles, setRoles] = useState<RoleItem[]>([])
  const [catalog, setCatalog] = useState<Record<string, { label: string; permissions: Array<{ code: string; name: string; description: string }> }>>({})
  const [loading, setLoading] = useState(true)

  const [modalOpen, setModalOpen] = useState(false)
  const [editingRole, setEditingRole] = useState<RoleItem | null>(null)
  const [roleCode, setRoleCode] = useState('')
  const [roleName, setRoleName] = useState('')
  const [roleDesc, setRoleDesc] = useState('')
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const [rRes, cRes] = await Promise.all([api.listRoles(), api.getPermissionsCatalog()])
      setRoles(rRes)
      setCatalog(cRes)
    } catch (e) {
      console.error('Failed to load roles', e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  const openAddModal = () => {
    setEditingRole(null)
    setRoleCode('')
    setRoleName('')
    setRoleDesc('')
    setSelectedPermissions([])
    setErrorMsg('')
    setModalOpen(true)
  }

  const openEditModal = (role: RoleItem) => {
    setEditingRole(role)
    setRoleCode(role.code)
    setRoleName(role.name)
    setRoleDesc(role.description || '')
    setSelectedPermissions(Array.isArray(role.permissions) ? role.permissions : [])
    setErrorMsg('')
    setModalOpen(true)
  }

  const togglePermission = (permCode: string) => {
    setSelectedPermissions((prev) =>
      prev.includes(permCode) ? prev.filter((p) => p !== permCode) : [...prev, permCode]
    )
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')

    try {
      const payload = {
        code: roleCode.toLowerCase().replace(/\s+/g, '_'),
        name: roleName,
        description: roleDesc,
        permissions: selectedPermissions,
      }

      if (editingRole) {
        await api.updateRole(editingRole.id, payload)
      } else {
        await api.createRole(payload)
      }

      setModalOpen(false)
      await loadData()
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : 'Failed to save group role')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (role: RoleItem) => {
    if (!confirm(`Are you sure you want to delete role "${role.name}"?`)) return
    try {
      await api.deleteRole(role.id)
      await loadData()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Could not delete role')
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-600 dark:text-slate-400">
          Configure security groups and granular access control rules.
        </p>
        <button
          onClick={openAddModal}
          className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
        >
          <span>+ Create Group / Role</span>
        </button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {loading ? (
          <div className="col-span-full py-8 text-center text-slate-500">Loading roles…</div>
        ) : (
          roles.map((r) => (
            <div
              key={r.id}
              className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md dark:border-slate-800 dark:bg-slate-900"
            >
              <div>
                <div className="flex items-start justify-between gap-2">
                  <h3 className="font-bold text-slate-900 dark:text-white">{r.name}</h3>
                  {r.is_system ? (
                    <span className="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                      System Group
                    </span>
                  ) : (
                    <span className="rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                      Custom Group
                    </span>
                  )}
                </div>
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                  {r.description || 'No description provided.'}
                </p>

                <div className="mt-4 flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-400">
                  <span>Assigned Users: {r.user_count ?? 0}</span>
                  <span>•</span>
                  <span>
                    Permissions:{' '}
                    {Array.isArray(r.permissions)
                      ? r.permissions.includes('*')
                        ? 'Full Access (*)'
                        : `${r.permissions.length} granted`
                      : 'None'}
                  </span>
                </div>
              </div>

              <div className="mt-5 flex items-center justify-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                <button
                  onClick={() => openEditModal(r)}
                  className="rounded px-3 py-1 text-xs font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-950/40"
                >
                  Edit Permissions
                </button>
                {!r.is_system && (
                  <button
                    onClick={() => void handleDelete(r)}
                    className="rounded px-3 py-1 text-xs font-medium text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                  >
                    Delete
                  </button>
                )}
              </div>
            </div>
          ))
        )}
      </div>

      {/* Role / Permissions Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">
              {editingRole ? `Edit Role (${editingRole.name})` : 'Create New Group / Role'}
            </h3>

            {errorMsg && (
              <div className="mb-4 rounded-lg bg-rose-50 p-3 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                {errorMsg}
              </div>
            )}

            <form onSubmit={handleSave} className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Group Code
                  </label>
                  <input
                    type="text"
                    required
                    disabled={editingRole?.is_system}
                    value={roleCode}
                    onChange={(e) => setRoleCode(e.target.value)}
                    placeholder="e.g. operations_mgr"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 disabled:opacity-60"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Display Name
                  </label>
                  <input
                    type="text"
                    required
                    value={roleName}
                    onChange={(e) => setRoleName(e.target.value)}
                    placeholder="e.g. Operations Manager"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Description
                </label>
                <input
                  type="text"
                  value={roleDesc}
                  onChange={(e) => setRoleDesc(e.target.value)}
                  placeholder="Summary of responsibilities and scope"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              {/* Permissions Matrix Checklist */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-2">
                  Permissions Matrix
                </label>

                <div className="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                  {Object.entries(catalog).map(([catKey, catVal]) => (
                    <div key={catKey} className="space-y-2">
                      <h4 className="text-xs font-bold uppercase tracking-wider text-brand-600 dark:text-brand-400">
                        {catVal.label}
                      </h4>
                      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {catVal.permissions.map((p) => {
                          const isChecked = selectedPermissions.includes(p.code)
                          return (
                            <label
                              key={p.code}
                              className={`flex items-start gap-2.5 rounded-lg border p-2.5 transition cursor-pointer ${
                                isChecked
                                  ? 'border-brand-500 bg-brand-50/50 dark:border-brand-500 dark:bg-brand-950/30'
                                  : 'border-slate-200 bg-white hover:border-slate-300 dark:border-slate-800 dark:bg-slate-900'
                              }`}
                            >
                              <input
                                type="checkbox"
                                checked={isChecked}
                                onChange={() => togglePermission(p.code)}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600"
                              />
                              <div>
                                <div className="text-xs font-semibold text-slate-900 dark:text-white">
                                  {p.name}
                                </div>
                                <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                  {p.description}
                                </div>
                              </div>
                            </label>
                          )
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-slate-200 dark:border-slate-800">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-50"
                >
                  {saving ? 'Saving…' : 'Save Group & Permissions'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 3. COMPANIES MANAGEMENT TAB                                            */
/* ==================================================================== */
function CompaniesTab() {
  const [companies, setCompanies] = useState<CompanyItem[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedCompany, setSelectedCompany] = useState<CompanyItem | null>(null)
  const [companyDetails, setCompanyDetails] = useState<Record<string, unknown> | null>(null)
  const [districts, setDistricts] = useState<OptionItem[]>([])
  const [serviceCenters, setServiceCenters] = useState<OptionItem[]>([])

  const [modalOpen, setModalOpen] = useState(false)
  const [editingCompany, setEditingCompany] = useState<CompanyItem | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [legalName, setLegalName] = useState('')
  const [saving, setSaving] = useState(false)

  const loadCompanies = async () => {
    setLoading(true)
    try {
      const companyRes = await api.listCompanies()
      setCompanies(companyRes)
      if (companyRes.length > 0 && !selectedCompany) {
        setSelectedCompany(companyRes[0])
      }
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadCompanies()
    void api
      .ticketOptions()
      .then((opts) => {
        setDistricts(opts.districts)
        setServiceCenters(opts.service_centers)
      })
      .catch((e) => console.error(e))
  }, [])

  useEffect(() => {
    if (!selectedCompany) return
    const fetchDetails = async () => {
      try {
        const details = await api.getCompany(selectedCompany.id)
        setCompanyDetails(details)
      } catch (e) {
        console.error(e)
      }
    }
    void fetchDetails()
  }, [selectedCompany])

  const openAddModal = () => {
    setEditingCompany(null)
    setCode('')
    setName('')
    setLegalName('')
    setModalOpen(true)
  }

  const openEditModal = (company: CompanyItem) => {
    setEditingCompany(company)
    setCode(company.code)
    setName(company.name)
    setLegalName(company.legal_name || '')
    setModalOpen(true)
  }

  const handleSaveCompany = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editingCompany) {
        await api.updateCompany(editingCompany.id, { code, name, legal_name: legalName || null })
      } else {
        await api.createCompany({ code, name, legal_name: legalName || null, is_active: true })
      }
      setModalOpen(false)
      setCode('')
      setName('')
      setLegalName('')
      setEditingCompany(null)
      await loadCompanies()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to save company')
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteCompany = async (company: CompanyItem) => {
    if (!confirm(`Are you sure you want to delete / deactivate company "${company.name}"?`)) return
    try {
      const result = await api.deleteCompany(company.id)
      if (result.message) {
        alert(result.message)
      }
      if (selectedCompany?.id === company.id) {
        setSelectedCompany(null)
      }
      await loadCompanies()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Could not delete company')
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      {/* Company Selector Column */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Companies</h2>
          <button
            onClick={openAddModal}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
          >
            + New Company
          </button>
        </div>

        <div className="space-y-2">
          {loading ? (
            <div className="py-4 text-center text-xs text-slate-500">Loading companies…</div>
          ) : (
            companies.map((company) => (
              <button
                key={company.id}
                onClick={() => setSelectedCompany(company)}
                className={`w-full rounded-xl border p-4 text-left transition ${
                  selectedCompany?.id === company.id
                    ? 'border-brand-600 bg-brand-50/50 shadow-sm dark:border-brand-500 dark:bg-brand-950/30'
                    : 'border-slate-200 bg-white hover:border-slate-300 dark:border-slate-800 dark:bg-slate-900'
                }`}
              >
                <div className="flex items-center justify-between">
                  <div className="font-bold text-slate-900 dark:text-white">{company.name}</div>
                  <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-mono font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {company.code}
                  </span>
                </div>
                <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                  {company.legal_name || 'No legal name specified'}
                </div>
                <div className="mt-2 text-[11px] text-slate-400">
                  Onboarded: {company.onboarded_on || 'N/A'}
                </div>
              </button>
            ))
          )}
        </div>
      </div>

      {/* Company Profile & Configuration View */}
      <div className="lg:col-span-2">
        {selectedCompany ? (
          <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
              <div>
                <h3 className="text-xl font-bold text-slate-900 dark:text-white">{selectedCompany.name}</h3>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Legal entity: {selectedCompany.legal_name || selectedCompany.name}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => openEditModal(selectedCompany)}
                  className="rounded-lg border border-brand-500 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-100 dark:border-brand-700 dark:bg-brand-950/50 dark:text-brand-300"
                >
                  ✏️ Edit
                </button>
                <button
                  onClick={() => void handleDeleteCompany(selectedCompany)}
                  className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300"
                >
                  🗑️ Delete
                </button>
                <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                  Active Agreement
                </span>
              </div>
            </div>

            {/* Terms & Active Rate Card Summary */}
            {companyDetails && (
              <>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                    <div className="text-xs text-slate-500">Commercial Agreement Terms</div>
                    <div className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                      Status: {(companyDetails.agreement as any)?.status || 'Active'}
                    </div>
                    <div className="mt-1 text-xs text-slate-600 dark:text-slate-400">
                      Royalty Rate: {(companyDetails.agreement as any)?.company_royalty_pct ?? '10'}%
                    </div>
                    <div className="text-xs text-slate-600 dark:text-slate-400">
                      Travel Rate: Rs.{(companyDetails.agreement as any)?.travel_rate_per_km_rupees ?? '3'}/km
                    </div>
                  </div>

                  <div className="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                    <div className="text-xs text-slate-500">Active Rate Card</div>
                    <div className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                      {(companyDetails.active_rate_card as any)?.name || 'Default Rate Card'}
                    </div>
                    <div className="mt-1 text-xs text-slate-600 dark:text-slate-400">
                      Version: v{(companyDetails.active_rate_card as any)?.version ?? '1'}
                    </div>
                    <div className="text-xs text-slate-600 dark:text-slate-400">
                      Effective From: {(companyDetails.active_rate_card as any)?.effective_from || 'Immediate'}
                    </div>
                  </div>
                </div>

                {/* Operational Dials & Evidence Rules */}
                <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-3">
                    Operational Policy & Mandatory Evidence Rules
                  </h4>
                  <div className="grid gap-3 sm:grid-cols-2">
                    {[
                      { key: 'closure.require_photo', label: '📷 Mandatory Photo at Closure', desc: 'Technician must attach job photo before closing ticket.' },
                      { key: 'closure.require_customer_signature', label: '✍️ Mandatory Customer Signature', desc: 'Customer must sign on technician screen before closure.' },
                      { key: 'closure.require_customer_otp', label: '🔑 Mandatory Customer OTP Code', desc: 'Customer must confirm job via SMS OTP code at closure.' },
                      { key: 'ticket.require_serial_no', label: '🔢 Mandatory Unit Serial Number', desc: 'Intake form enforces unit serial number.' },
                      { key: 'ticket.require_bill_date', label: '📅 Mandatory Purchase / Bill Date', desc: 'Intake form enforces purchase date.' },
                      { key: 'assignment.enforce_technician_rules', label: '🛠️ Enforce Technician Assignment Rules', desc: 'Block assigning a technician outside their skills or over their open-job limit. Turn off to allow any technician to be assigned freely.' },
                    ].map((setting) => {
                      const settingsMap = (companyDetails.settings as Record<string, any>) || {}
                      const currentVal = Boolean(settingsMap[setting.key]?.value ?? settingsMap[setting.key] ?? false)
                      return (
                        <label
                          key={setting.key}
                          className={`flex items-start justify-between gap-3 rounded-lg border p-3 cursor-pointer transition ${
                            currentVal
                              ? 'border-brand-500 bg-brand-50/50 dark:border-brand-500 dark:bg-brand-950/30'
                              : 'border-slate-200 bg-white hover:border-slate-300 dark:border-slate-800 dark:bg-slate-900'
                          }`}
                        >
                          <div>
                            <div className="text-xs font-bold text-slate-900 dark:text-white">{setting.label}</div>
                            <div className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{setting.desc}</div>
                          </div>
                          <input
                            type="checkbox"
                            checked={currentVal}
                            onChange={async () => {
                              try {
                                await api.updateCompanySettings(selectedCompany.id, { [setting.key]: !currentVal })
                                const updated = await api.getCompany(selectedCompany.id)
                                setCompanyDetails(updated)
                              } catch (err: unknown) {
                                alert(err instanceof Error ? err.message : 'Could not update setting')
                              }
                            }}
                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                          />
                        </label>
                      )
                    })}
                  </div>

                  <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                      💰 Default Basic Service Charge (₹)
                    </label>
                    <p className="text-[11px] text-slate-500 mb-2">
                      Basic closure charge automatically billed for any case under this company (e.g. ₹400 for Dianora).
                    </p>
                    <div className="flex items-center gap-2 max-w-xs">
                      <input
                        type="number"
                        defaultValue={Number(
                          ((companyDetails.settings as Record<string, any>)?.[
                            'closure.default_service_charge'
                          ]?.value ??
                            (companyDetails.settings as Record<string, any>)?.[
                              'closure.default_service_charge'
                            ] ??
                            400),
                        )}
                        onBlur={async (e) => {
                          const val = Number(e.target.value) || 0
                          try {
                            await api.updateCompanySettings(selectedCompany.id, {
                              'closure.default_service_charge': val,
                            })
                            const updated = await api.getCompany(selectedCompany.id)
                            setCompanyDetails(updated)
                          } catch (err: unknown) {
                            alert('Could not update default service charge')
                          }
                        }}
                        className="w-32 rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                      />
                      <span className="text-xs font-semibold text-slate-600 dark:text-slate-400">INR</span>
                    </div>
                  </div>

                  <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                      📍 Default Intake District & Service Center
                    </label>
                    <p className="text-[11px] text-slate-500 mb-2">
                      Pre-selected on a new ticket for this company until the desk changes it.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2 max-w-xl">
                      <select
                        defaultValue={String(
                          (companyDetails.settings as Record<string, any>)?.[
                            'ticket.default_district_code'
                          ]?.value ?? 'KKD',
                        )}
                        onChange={async (e) => {
                          try {
                            await api.updateCompanySettings(selectedCompany.id, {
                              'ticket.default_district_code': e.target.value,
                            })
                            const updated = await api.getCompany(selectedCompany.id)
                            setCompanyDetails(updated)
                          } catch (err: unknown) {
                            alert('Could not update default district')
                          }
                        }}
                        className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                      >
                        {districts.map((d) => (
                          <option key={d.code} value={d.code}>
                            {d.name} ({d.code})
                          </option>
                        ))}
                      </select>
                      <select
                        defaultValue={String(
                          (companyDetails.settings as Record<string, any>)?.[
                            'ticket.default_service_center_code'
                          ]?.value ?? 'THA',
                        )}
                        onChange={async (e) => {
                          try {
                            await api.updateCompanySettings(selectedCompany.id, {
                              'ticket.default_service_center_code': e.target.value,
                            })
                            const updated = await api.getCompany(selectedCompany.id)
                            setCompanyDetails(updated)
                          } catch (err: unknown) {
                            alert('Could not update default service center')
                          }
                        }}
                        className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                      >
                        {serviceCenters.map((sc) => (
                          <option key={sc.code} value={sc.code}>
                            {sc.name}
                          </option>
                        ))}
                      </select>
                    </div>
                  </div>
                </div>
              </>
            )}
          </div>
        ) : (
          <div className="flex h-64 items-center justify-center rounded-2xl border border-dashed border-slate-300 text-slate-400 dark:border-slate-800">
            Select a company to view details
          </div>
        )}
      </div>

      {/* Add / Edit Company Modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">
              {editingCompany ? `Edit Company (${editingCompany.code})` : 'Add Company'}
            </h3>
            <form onSubmit={handleSaveCompany} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Company Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. DIANORA"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Company Name
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Dianora Appliances India"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Legal Registered Name
                </label>
                <input
                  type="text"
                  placeholder="e.g. Dianora Private Limited"
                  value={legalName}
                  onChange={(e) => setLegalName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? (editingCompany ? 'Updating…' : 'Creating…') : (editingCompany ? 'Update Company' : 'Create Company')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 4. RATE CARDS & SLA TAB                                              */
/* ==================================================================== */
const SCOPE_LABELS: Record<string, string> = {
  in_warranty: 'In warranty',
  out_of_warranty: 'Out of warranty',
  not_applicable: 'Any',
}

/**
 * An SLA rule's window, in the wording the agreement used.
 *
 * The comparator decides which thresholds are meaningful: `lte` has only
 * an upper bound, `gt` only a lower one, `between` both. Printing the
 * unused one reads as a rule that fires on a range it does not.
 */
function slaWindow(rule: any): string {
  const from = rule.threshold_from_hours === null ? null : Number(rule.threshold_from_hours)
  const to = rule.threshold_to_hours === null ? null : Number(rule.threshold_to_hours)

  switch (rule.comparator) {
    case 'lte':
      return `within ${to}h`
    case 'gt':
      return `after ${from}h`
    case 'between':
      return `after ${from}h, within ${to}h`
    default:
      return rule.comparator
  }
}

function RateCardsTab() {
  const [companies, setCompanies] = useState<CompanyItem[]>([])
  const [selectedCompanyId, setSelectedCompanyId] = useState<number | null>(null)
  const [rateCards, setRateCards] = useState<any[]>([])
  const [detail, setDetail] = useState<any | null>(null)
  const [loading, setLoading] = useState(true)
  const [jobTypes, setJobTypes] = useState<OptionItem[]>([])
  const [productCategories, setProductCategories] = useState<any[]>([])

  // Modal States
  const [createModalOpen, setCreateModalOpen] = useState(false)
  const [itemModalOpen, setItemModalOpen] = useState(false)
  const [slaModalOpen, setSlaModalOpen] = useState(false)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [importingItems, setImportingItems] = useState(false)
  const [mergingDuplicates, setMergingDuplicates] = useState(false)
  // Rate card item ids from the most recent CSV upload, so the table can
  // highlight exactly the rows that upload just added.
  const [justImportedIds, setJustImportedIds] = useState<Set<number>>(new Set())
  // Skipped/failed rows from the most recent CSV upload. Duplicates are
  // shown struck through — they were never written, this is just showing
  // why — everything else as a plain validation error.
  const [importIssues, setImportIssues] = useState<{ row: number; message: string; duplicate: boolean }[]>([])
  const csvInputRef = useRef<HTMLInputElement>(null)

  // Create Card Form
  const [cardForm, setCardForm] = useState({
    name: '',
    effective_from: new Date().toISOString().split('T')[0],
    clone_from_active: true,
  })

  // Rate Item Form
  const [itemForm, setItemForm] = useState({
    job_type_id: '',
    // '' means the line prices every appliance, which is how the original
    // Dianora TV bands were written. Naming a category makes the line more
    // specific, and RateResolver prefers the more specific match.
    product_category_id: '',
    warranty_scope: 'in_warranty',
    label: '',
    size_min_inch: '',
    size_max_inch: '',
    amount_rupees: '',
    payer: 'company',
  })

  // SLA Rule Form
  const [slaForm, setSlaForm] = useState({
    metric: 'hours_to_close',
    rule_kind: 'bonus',
    comparator: 'lte',
    threshold_from_hours: '',
    threshold_to_hours: '48',
    amount_rupees: '',
    payer: 'company',
    label: '',
  })

  const card = detail?.card ?? null
  const items: any[] = detail?.items ?? []
  const slaRules: any[] = detail?.sla_rules ?? []
  // Only present for a draft card — the specific reasons it can't publish
  // yet (a zero-priced line, a size gap, a missing warranty scope...).
  const draftProblems: string[] = detail?.problems ?? []

  // Only a screen has inches. A washing machine priced into a 24"-43" band
  // is a line no ticket can ever match — which is exactly how an
  // out-of-warranty washing machine job ends up closed but unpriceable.
  const selectedCategory = productCategories.find(
    (c) => String(c.id) === itemForm.product_category_id,
  )
  const sizeBanded = !selectedCategory || Boolean(Number(selectedCategory.is_sized))

  // Matches the "Size gap for <job_type_code> (<scope>): nothing priced
  // between X" and Y"." message bandProblems() on the backend produces —
  // parsed back apart so a gap in the list can jump straight to a
  // prefilled Add Rate Item form instead of making someone retype it.
  const sizeGapPattern = /^Size gap for (\S+) \(([a-z_]+)\): nothing priced between (\d+)" and (\d+)"\.$/

  const openAddItemForGap = (problem: string) => {
    const match = problem.match(sizeGapPattern)
    if (!match) return
    const [, code, scope, min, max] = match
    const jobType = jobTypes.find((jt) => jt.code === code)
    setItemForm((prev) => ({
      ...prev,
      job_type_id: jobType ? String(jobType.id) : prev.job_type_id,
      product_category_id: '',
      warranty_scope: scope,
      label: '',
      size_min_inch: min,
      size_max_inch: max,
      amount_rupees: '',
    }))
    setItemModalOpen(true)
  }

  useEffect(() => {
    const fetchCompaniesAndOptions = async () => {
      try {
        const [companyList, options] = await Promise.all([
          api.listCompanies(),
          api.ticketOptions(),
        ])
        setCompanies(companyList)
        if (options?.job_types) {
          setJobTypes(options.job_types)
          if (options.job_types.length > 0) {
            setItemForm((prev) => ({ ...prev, job_type_id: String(options.job_types[0].id) }))
          }
        }
        if (options?.product_categories) {
          setProductCategories(options.product_categories)
        }
        if (companyList.length > 0) {
          setSelectedCompanyId(companyList[0].id)
        }
      } catch (e) {
        console.error(e)
      }
    }
    void fetchCompaniesAndOptions()
  }, [])

  const reloadRateCards = async (companyId: number) => {
    setLoading(true)
    try {
      const cards = await api.listRateCards(companyId)
      setRateCards(cards)
      if (cards.length > 0) {
        const firstId = Number((cards[0] as { id: number }).id)
        setDetail(await api.getRateCard(companyId, firstId))
      } else {
        setDetail(null)
      }
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (!selectedCompanyId) return
    void reloadRateCards(selectedCompanyId)
  }, [selectedCompanyId])

  const handleSelectCard = async (cardId: number) => {
    if (!selectedCompanyId) return
    setJustImportedIds(new Set())
    setImportIssues([])
    try {
      setDetail(await api.getRateCard(selectedCompanyId, cardId))
    } catch (e) {
      console.error(e)
    }
  }

  const handleCreateCardSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedCompanyId) return
    setMessage(null)

    try {
      const activeCard = rateCards.find((c: any) => c.status === 'active')
      const payload: Record<string, unknown> = {
        name: cardForm.name || `Rate Card v${rateCards.length + 1}`,
        effective_from: cardForm.effective_from,
      }
      if (cardForm.clone_from_active && activeCard) {
        payload.clone_from = activeCard.id
      }

      const res = await api.createRateCard(selectedCompanyId, payload)
      setMessage({ type: 'success', text: 'New draft rate card version created successfully!' })
      setCreateModalOpen(false)
      await reloadRateCards(selectedCompanyId)
      if (res?.rate_card_id) {
        await handleSelectCard(Number(res.rate_card_id))
      }
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to create rate card version' })
    }
  }

  const handleAddItemSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedCompanyId || !card) return
    setMessage(null)

    try {
      const payload = {
        job_type_id: Number(itemForm.job_type_id),
        product_category_id: itemForm.product_category_id
          ? Number(itemForm.product_category_id)
          : null,
        warranty_scope: itemForm.warranty_scope,
        label: itemForm.label || undefined,
        // An appliance with no screen carries no band. Sending one anyway
        // produces a line the resolver can never match, because a banded
        // item refuses a ticket whose size is unknown.
        size_min_inch: sizeBanded && itemForm.size_min_inch ? Number(itemForm.size_min_inch) : null,
        size_max_inch: sizeBanded && itemForm.size_max_inch ? Number(itemForm.size_max_inch) : null,
        amount_paise: Math.round(Number(itemForm.amount_rupees) * 100),
        payer: itemForm.payer,
      }

      await api.addRateCardItem(selectedCompanyId, card.id, payload)
      setMessage({ type: 'success', text: 'Priced line added to rate card draft!' })
      setItemModalOpen(false)
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to add rate card line' })
    }
  }

  const handleDownloadTemplate = async () => {
    if (!selectedCompanyId || !card) return
    try {
      await api.downloadRateCardItemsTemplate(selectedCompanyId, card.id)
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to download the CSV template' })
    }
  }

  const handleCsvFileSelected = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    // Cleared up front so picking the same file twice in a row still fires
    // a change event.
    e.target.value = ''
    if (!file || !selectedCompanyId || !card) return

    setMessage(null)
    setJustImportedIds(new Set())
    setImportIssues([])
    setImportingItems(true)
    try {
      const result = await api.importRateCardItems(selectedCompanyId, card.id, file)
      const otherErrors = result.errors.filter((err) => !err.duplicate)

      const parts = [`Imported ${result.created_count} rate item(s), highlighted below.`]
      if (result.duplicate_count > 0) {
        parts.push(`${result.duplicate_count} duplicate row(s) already on the card were skipped — see below.`)
      }
      if (otherErrors.length > 0) {
        parts.push(`${otherErrors.length} row(s) failed — see below.`)
      }
      setMessage({ type: otherErrors.length > 0 ? 'error' : 'success', text: parts.join(' ') })
      setImportIssues(result.errors)
      setJustImportedIds(new Set(result.rate_card_item_ids))
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to import the CSV file' })
    } finally {
      setImportingItems(false)
    }
  }

  const handleMergeDuplicates = async () => {
    if (!selectedCompanyId || !card) return
    setMessage(null)
    setMergingDuplicates(true)
    try {
      const result = await api.mergeRateCardItemDuplicates(selectedCompanyId, card.id)
      const parts = [
        result.merged_groups > 0
          ? `Merged ${result.merged_groups} duplicate group(s), removing ${result.removed.length} redundant line(s).`
          : 'No mergeable duplicates found.',
      ]
      if (result.conflicts.length > 0) {
        parts.push(
          `${result.conflicts.length} group(s) share a job type, scope and size band but quote different ` +
            `amounts or payers, so they were left as-is for you to review.`,
        )
      }
      setMessage({ type: result.conflicts.length > 0 ? 'error' : 'success', text: parts.join(' ') })
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to merge duplicate rate items' })
    } finally {
      setMergingDuplicates(false)
    }
  }

  const handleAddSlaSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedCompanyId || !card) return
    setMessage(null)

    try {
      const payload = {
        metric: slaForm.metric,
        rule_kind: slaForm.rule_kind,
        comparator: slaForm.comparator,
        threshold_from_hours: slaForm.threshold_from_hours ? Number(slaForm.threshold_from_hours) : null,
        threshold_to_hours: slaForm.threshold_to_hours ? Number(slaForm.threshold_to_hours) : null,
        amount_paise: Math.round(Number(slaForm.amount_rupees) * 100),
        payer: slaForm.payer,
        label: slaForm.label || `${slaForm.rule_kind === 'bonus' ? 'Bonus' : 'Penalty'} rule`,
      }

      await api.addSlaRule(selectedCompanyId, card.id, payload)
      setMessage({ type: 'success', text: 'SLA rule added to draft card!' })
      setSlaModalOpen(false)
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to add SLA rule' })
    }
  }

  const handlePublishCard = async (ignoreWarnings: string[] = []) => {
    if (!selectedCompanyId || !card) return

    const confirmText = ignoreWarnings.length > 0
      ? `Publish "${card.name || 'Rate Card'}" (v${card.version}) anyway, ignoring ${ignoreWarnings.length} warning${ignoreWarnings.length > 1 ? 's' : ''}? Any ticket that falls in an unpriced gap will be unbillable until a later version covers it.`
      : `Are you sure you want to publish "${card.name || 'Rate Card'}" (v${card.version})? Once published, this card becomes immutable.`
    if (!confirm(confirmText)) return
    setMessage(null)

    try {
      await api.publishRateCard(selectedCompanyId, card.id, ignoreWarnings)
      setMessage({ type: 'success', text: `Rate Card v${card.version} published and active!` })
      await reloadRateCards(selectedCompanyId)
    } catch (err: any) {
      // The generic top-level message ("has problems...") is the same for
      // every draft; the actual reasons are the point, so surface those.
      const problems = (err?.body?.detail?.problems ?? []) as string[]
      const text = problems.length > 0
        ? `Cannot publish yet: ${problems.join(' ')}`
        : err.message || 'Failed to publish rate card'
      setMessage({ type: 'error', text })
      // The failed attempt is itself the freshest read of what's wrong —
      // no need to wait for the next reload to show it in the panel below.
      setDetail((prev: any) => (prev ? { ...prev, problems } : prev))
    }
  }

  const handleDeleteRateCard = async () => {
    if (!selectedCompanyId || !card) return
    if (!confirm(`Delete "${card.name || 'Rate Card'}" (v${card.version})? This removes its priced lines and SLA rules too — it cannot be undone.`)) return
    setMessage(null)

    try {
      await api.deleteRateCard(selectedCompanyId, card.id)
      setMessage({ type: 'success', text: `Rate Card v${card.version} deleted` })
      await reloadRateCards(selectedCompanyId)
    } catch (err: any) {
      setMessage({ type: 'error', text: err.message || 'Failed to delete rate card' })
    }
  }

  const handleDeleteItem = async (itemId: number) => {
    if (!selectedCompanyId || !card) return
    if (!confirm('Remove this priced line item from the draft rate card?')) return
    try {
      await api.deleteRateCardItem(selectedCompanyId, card.id, itemId)
      setMessage({ type: 'success', text: 'Item removed from draft rate card' })
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (e: any) {
      setMessage({ type: 'error', text: e.message || 'Failed to remove rate item' })
    }
  }

  const handleDeleteSlaRule = async (ruleId: number) => {
    if (!selectedCompanyId || !card) return
    if (!confirm('Remove this SLA rule from the draft rate card?')) return
    try {
      await api.deleteSlaRule(selectedCompanyId, card.id, ruleId)
      setMessage({ type: 'success', text: 'SLA rule removed from draft rate card' })
      setDetail(await api.getRateCard(selectedCompanyId, card.id))
    } catch (e: any) {
      setMessage({ type: 'error', text: e.message || 'Failed to remove SLA rule' })
    }
  }

  return (
    <div className="space-y-6">
      {message && (
        <div
          className={`rounded-xl p-4 text-sm font-medium ${
            message.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
              : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
          }`}
        >
          {message.text}
        </div>
      )}

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-3">
          <label className="text-xs font-medium text-slate-600 dark:text-slate-400">Select Company:</label>
          <select
            value={selectedCompanyId || ''}
            onChange={(e) => setSelectedCompanyId(parseInt(e.target.value))}
            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
          >
            {companies.map((company) => (
              <option key={company.id} value={company.id}>
                {company.name} ({company.code})
              </option>
            ))}
          </select>

          {rateCards.length > 0 && (
            <select
              value={card?.id || ''}
              onChange={(e) => void handleSelectCard(parseInt(e.target.value))}
              className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
            >
              {rateCards.map((c: any) => (
                <option key={c.id} value={c.id}>
                  {c.name || `Version ${c.version}`} ({c.status})
                </option>
              ))}
            </select>
          )}
        </div>

        <button
          onClick={() => {
            setCardForm({
              name: `Rate Card v${rateCards.length + 1}`,
              effective_from: new Date().toISOString().split('T')[0],
              clone_from_active: true,
            })
            setCreateModalOpen(true)
          }}
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
        >
          + Create Rate Card Version
        </button>
      </div>

      {loading ? (
        <div className="py-8 text-center text-sm text-slate-500">Loading rate cards…</div>
      ) : card ? (
        <div className="space-y-6">
          {/* Card Overview Banner */}
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                  {card.name || 'Rate Card'} (v{card.version})
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Effective From: {card.effective_from || 'Immediate'} • Status:{' '}
                  <span
                    className={`font-semibold capitalize ${
                      card.status === 'active'
                        ? 'text-emerald-600'
                        : card.status === 'draft'
                        ? 'text-amber-600'
                        : 'text-slate-500'
                    }`}
                  >
                    {card.status}
                  </span>
                </p>
              </div>
              <div className="flex items-center gap-3">
                {card.status === 'draft' && (
                  <>
                    <button
                      onClick={handleDeleteRateCard}
                      className="rounded-lg border border-rose-300 px-4 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-800 dark:text-rose-400 dark:hover:bg-rose-950/40"
                    >
                      Delete Draft
                    </button>
                    <button
                      onClick={() => void handlePublishCard()}
                      className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700"
                    >
                      Publish Rate Card
                    </button>
                  </>
                )}
                {card.status === 'active' && (
                  <span className="rounded-lg bg-emerald-100 px-3 py-1.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300">
                    Active Card (Immutable)
                  </span>
                )}
              </div>
            </div>

            {card.status === 'draft' && draftProblems.length > 0 && (
              <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                <p className="mb-1.5 font-semibold">
                  Not publishable yet — {draftProblems.length} problem{draftProblems.length > 1 ? 's' : ''} to resolve:
                </p>
                <ul className="list-inside list-disc space-y-1">
                  {draftProblems.map((problem, i) => (
                    <li key={i} className="flex flex-wrap items-center justify-between gap-2">
                      <span>{problem}</span>
                      {sizeGapPattern.test(problem) && (
                        <button
                          onClick={() => openAddItemForGap(problem)}
                          className="shrink-0 whitespace-nowrap rounded border border-amber-400 px-2 py-0.5 text-[11px] font-semibold text-amber-900 transition hover:bg-amber-100 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900/40"
                        >
                          + Price this gap
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
                <button
                  onClick={() => void handlePublishCard(draftProblems)}
                  className="mt-3 rounded-lg border border-amber-400 px-3 py-1.5 text-xs font-semibold text-amber-900 transition hover:bg-amber-100 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900/40"
                >
                  Publish Anyway (ignore these warnings)
                </button>
              </div>
            )}
          </div>

          {/* Rate Items Table */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                Rate Card Items ({items.length})
              </h4>
              {card.status === 'draft' && (
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    onClick={handleDownloadTemplate}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                  >
                    Download CSV Template
                  </button>
                  <input
                    ref={csvInputRef}
                    type="file"
                    accept=".csv,text/csv"
                    className="hidden"
                    onChange={handleCsvFileSelected}
                  />
                  <button
                    onClick={() => csvInputRef.current?.click()}
                    disabled={importingItems}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                  >
                    {importingItems ? 'Uploading…' : 'Upload CSV'}
                  </button>
                  <button
                    onClick={() => {
                      if (
                        confirm(
                          'Merge duplicate rate items? Lines that share a job type, warranty scope and size ' +
                            'band, and agree on amount and payer, will be collapsed into one — keeping the ' +
                            'more specific (appliance-tagged) line and removing the rest.',
                        )
                      ) {
                        void handleMergeDuplicates()
                      }
                    }}
                    disabled={mergingDuplicates}
                    className="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-50 disabled:opacity-50 dark:border-amber-800 dark:text-amber-400 dark:hover:bg-amber-950/40"
                  >
                    {mergingDuplicates ? 'Merging…' : 'Merge Duplicates'}
                  </button>
                  <button
                    onClick={() => setItemModalOpen(true)}
                    className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                  >
                    + Add Rate Item
                  </button>
                </div>
              )}
            </div>

            {importIssues.length > 0 && (
              <div className="rounded-xl border border-slate-200 bg-white p-3 text-xs dark:border-slate-800 dark:bg-slate-900">
                <p className="mb-2 font-semibold text-slate-700 dark:text-slate-300">
                  Rows skipped from the last CSV upload
                </p>
                <ul className="space-y-1.5">
                  {importIssues.map((issue, i) => (
                    <li
                      key={i}
                      className={`flex items-start gap-2 rounded-md px-2 py-1.5 ${
                        issue.duplicate
                          ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-300'
                          : 'bg-rose-50 text-rose-800 dark:bg-rose-950/30 dark:text-rose-300'
                      }`}
                    >
                      <span
                        className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${
                          issue.duplicate
                            ? 'bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-200'
                            : 'bg-rose-200 text-rose-900 dark:bg-rose-900 dark:text-rose-200'
                        }`}
                      >
                        {issue.duplicate ? 'Duplicate' : 'Failed'}
                      </span>
                      <span className={issue.duplicate ? 'line-through decoration-2' : ''}>
                        Row {issue.row}: {issue.message}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Job Type / Label</th>
                    <th className="px-4 py-3">Appliance</th>
                    <th className="px-4 py-3">Warranty Scope</th>
                    <th className="px-4 py-3">Size Band</th>
                    <th className="px-4 py-3">Amount</th>
                    <th className="px-4 py-3">Billed To</th>
                    {card.status === 'draft' && <th className="px-4 py-3 text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {items.length === 0 ? (
                    <tr>
                      <td colSpan={card.status === 'draft' ? 7 : 6} className="px-4 py-6 text-center text-xs text-slate-500">
                        No rate items in this draft. Click "+ Add Rate Item" above.
                      </td>
                    </tr>
                  ) : (
                    items.map((item: any) => {
                      const justImported = justImportedIds.has(item.id)
                      return (
                        <tr
                          key={item.id}
                          className={
                            justImported
                              ? 'bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:hover:bg-emerald-950/50'
                              : 'hover:bg-slate-50 dark:hover:bg-slate-800/40'
                          }
                        >
                          <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                            <span className="flex items-center gap-2">
                              {item.label || item.job_type?.name || '—'}
                              {justImported && (
                                <span className="rounded bg-emerald-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-900 dark:bg-emerald-900 dark:text-emerald-200">
                                  New
                                </span>
                              )}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {item.product_category?.name ?? 'All'}
                          </td>
                          <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {SCOPE_LABELS[item.warranty_scope] ?? item.warranty_scope}
                          </td>
                          <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {item.size_min_inch && item.size_max_inch
                              ? `${Number(item.size_min_inch)}″ – ${Number(item.size_max_inch)}″`
                              : 'Any'}
                          </td>
                          <td className="px-4 py-3 font-semibold text-emerald-600 dark:text-emerald-400">
                            ₹{(item.amount_paise / 100).toFixed(2)}
                          </td>
                          <td className="px-4 py-3 text-slate-700 dark:text-slate-300 capitalize">
                            {item.payer}
                          </td>
                          {card.status === 'draft' && (
                            <td className="px-4 py-3 text-right">
                              <button
                                onClick={() => void handleDeleteItem(item.id)}
                                className="text-xs font-medium text-rose-600 hover:text-rose-700 dark:text-rose-400"
                              >
                                Remove
                              </button>
                            </td>
                          )}
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* SLA Rules */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                SLA Rules ({slaRules.length})
              </h4>
              {card.status === 'draft' && (
                <button
                  onClick={() => setSlaModalOpen(true)}
                  className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >
                  + Add SLA Rule
                </button>
              )}
            </div>

            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                  <tr>
                    <th className="px-4 py-3">Rule</th>
                    <th className="px-4 py-3">Applies To</th>
                    <th className="px-4 py-3">Window</th>
                    <th className="px-4 py-3">Amount</th>
                    {card.status === 'draft' && <th className="px-4 py-3 text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {slaRules.length === 0 ? (
                    <tr>
                      <td colSpan={card.status === 'draft' ? 5 : 4} className="px-4 py-6 text-center text-xs text-slate-500">
                        No SLA rules defined. Click "+ Add SLA Rule" above.
                      </td>
                    </tr>
                  ) : (
                    slaRules.map((rule: any) => {
                      const isPenalty = rule.kind === 'penalty'
                      return (
                        <tr key={rule.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                          <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                            {rule.label}
                          </td>
                          <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {(rule.applies_to_job_types ?? ['all job types']).join(', ')}
                            {rule.warranty_scope ? ` · ${SCOPE_LABELS[rule.warranty_scope]}` : ''}
                          </td>
                          <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                            {slaWindow(rule)}
                          </td>
                          <td
                            className={`px-4 py-3 font-semibold ${
                              isPenalty
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-emerald-600 dark:text-emerald-400'
                            }`}
                          >
                            {isPenalty ? '−' : '+'}₹{(rule.amount_paise / 100).toFixed(2)}
                          </td>
                          {card.status === 'draft' && (
                            <td className="px-4 py-3 text-right">
                              <button
                                onClick={() => void handleDeleteSlaRule(rule.id)}
                                className="text-xs font-medium text-rose-600 hover:text-rose-700 dark:text-rose-400"
                              >
                                Remove
                              </button>
                            </td>
                          )}
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      ) : (
        <div className="rounded-2xl border border-dashed border-slate-300 p-8 text-center dark:border-slate-700">
          <p className="text-sm text-slate-600 dark:text-slate-400">
            No rate cards configured for this company yet.
          </p>
          <button
            onClick={() => setCreateModalOpen(true)}
            className="mt-4 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
          >
            + Create First Rate Card
          </button>
        </div>
      )}

      {/* CREATE RATE CARD MODAL */}
      {createModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Create New Rate Card Version
            </h3>
            <form onSubmit={handleCreateCardSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Rate Card Name *
                </label>
                <input
                  required
                  type="text"
                  value={cardForm.name}
                  onChange={(e) => setCardForm({ ...cardForm, name: e.target.value })}
                  placeholder="e.g. Rate Card v2"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Effective From *
                </label>
                <input
                  required
                  type="date"
                  value={cardForm.effective_from}
                  onChange={(e) => setCardForm({ ...cardForm, effective_from: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              {rateCards.some((c: any) => c.status === 'active') && (
                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="clone_checkbox"
                    checked={cardForm.clone_from_active}
                    onChange={(e) => setCardForm({ ...cardForm, clone_from_active: e.target.checked })}
                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  <label htmlFor="clone_checkbox" className="text-xs text-slate-700 dark:text-slate-300">
                    Copy items & SLA rules from current active card
                  </label>
                </div>
              )}

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setCreateModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-medium text-white hover:bg-brand-700"
                >
                  Create Draft Card
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ADD RATE ITEM MODAL */}
      {itemModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Add Price Line to Draft Card
            </h3>
            <form onSubmit={handleAddItemSubmit} className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Job Type *
                  </label>
                  <select
                    value={itemForm.job_type_id}
                    onChange={(e) => setItemForm({ ...itemForm, job_type_id: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    {jobTypes.map((jt) => (
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
                    value={itemForm.warranty_scope}
                    onChange={(e) => setItemForm({ ...itemForm, warranty_scope: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="in_warranty">In Warranty</option>
                    <option value="out_of_warranty">Out of Warranty</option>
                    <option value="not_applicable">Any / Not Applicable</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Appliance
                </label>
                <select
                  value={itemForm.product_category_id}
                  onChange={(e) =>
                    setItemForm({ ...itemForm, product_category_id: e.target.value })
                  }
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                >
                  <option value="">All appliances</option>
                  {productCategories.map((pc) => (
                    <option key={pc.id} value={pc.id}>
                      {pc.name}
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                  {itemForm.product_category_id
                    ? 'This line prices only this appliance, and wins over an “all appliances” line.'
                    : 'This line prices every appliance unless a more specific one exists.'}
                </p>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Label / Description
                </label>
                <input
                  type="text"
                  value={itemForm.label}
                  onChange={(e) => setItemForm({ ...itemForm, label: e.target.value })}
                  placeholder="e.g. 55-inch In-Warranty Service Rate"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              {sizeBanded ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                      Min Size (Inches)
                    </label>
                    <input
                      type="number"
                      value={itemForm.size_min_inch}
                      onChange={(e) => setItemForm({ ...itemForm, size_min_inch: e.target.value })}
                      placeholder="e.g. 45"
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    />
                  </div>

                  <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                      Max Size (Inches)
                    </label>
                    <input
                      type="number"
                      value={itemForm.size_max_inch}
                      onChange={(e) => setItemForm({ ...itemForm, size_max_inch: e.target.value })}
                      placeholder="e.g. 55"
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                    />
                  </div>
                </div>
              ) : (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                  {selectedCategory?.name} has no screen size, so this line is priced
                  flat with no size band.
                </p>
              )}

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Amount (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={itemForm.amount_rupees}
                    onChange={(e) => setItemForm({ ...itemForm, amount_rupees: e.target.value })}
                    placeholder="500.00"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Billed To *
                  </label>
                  <select
                    value={itemForm.payer}
                    onChange={(e) => setItemForm({ ...itemForm, payer: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="company">Company</option>
                    <option value="customer">Customer</option>
                    <option value="none">None</option>
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setItemModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-medium text-white hover:bg-brand-700"
                >
                  Save Rate Item
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ADD SLA RULE MODAL */}
      {slaModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <h3 className="mb-4 text-lg font-bold text-slate-900 dark:text-white">
              Add SLA Incentive / Penalty Rule
            </h3>
            <form onSubmit={handleAddSlaSubmit} className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                  Rule Label / Description *
                </label>
                <input
                  required
                  type="text"
                  value={slaForm.label}
                  onChange={(e) => setSlaForm({ ...slaForm, label: e.target.value })}
                  placeholder="e.g. Closed within 48 hours bonus"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Metric *
                  </label>
                  <select
                    value={slaForm.metric}
                    onChange={(e) => setSlaForm({ ...slaForm, metric: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="hours_to_contact">Hours to Contact</option>
                    <option value="hours_to_visit">Hours to Visit</option>
                    <option value="hours_to_close">Hours to Close</option>
                  </select>
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Rule Type *
                  </label>
                  <select
                    value={slaForm.rule_kind}
                    onChange={(e) => setSlaForm({ ...slaForm, rule_kind: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="bonus">Bonus (+)</option>
                    <option value="penalty">Penalty (−)</option>
                  </select>
                </div>
              </div>

              <div className="grid gap-3 sm:grid-cols-3">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Condition *
                  </label>
                  <select
                    value={slaForm.comparator}
                    onChange={(e) => setSlaForm({ ...slaForm, comparator: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="lte">Within (≤)</option>
                    <option value="gt">After (&gt;)</option>
                    <option value="between">Between</option>
                  </select>
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    From Hours
                  </label>
                  <input
                    type="number"
                    value={slaForm.threshold_from_hours}
                    onChange={(e) => setSlaForm({ ...slaForm, threshold_from_hours: e.target.value })}
                    placeholder="0"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    To Hours
                  </label>
                  <input
                    type="number"
                    value={slaForm.threshold_to_hours}
                    onChange={(e) => setSlaForm({ ...slaForm, threshold_to_hours: e.target.value })}
                    placeholder="48"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Amount (₹) *
                  </label>
                  <input
                    required
                    type="number"
                    step="0.01"
                    value={slaForm.amount_rupees}
                    onChange={(e) => setSlaForm({ ...slaForm, amount_rupees: e.target.value })}
                    placeholder="75.00"
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
                    Payer / Billed To *
                  </label>
                  <select
                    value={slaForm.payer}
                    onChange={(e) => setSlaForm({ ...slaForm, payer: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="company">Company</option>
                    <option value="customer">Customer</option>
                    <option value="none">None</option>
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setSlaModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-medium text-white hover:bg-brand-700"
                >
                  Save SLA Rule
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 5. PRODUCTS & APPLIANCES TAB                                         */
/* ==================================================================== */
const PRODUCTS_SUB_TABS: { key: 'company' | 'brands' | 'products' | 'categories'; label: string }[] = [
  { key: 'company', label: 'Company' },
  { key: 'brands', label: 'Brands' },
  { key: 'products', label: 'Product and Model' },
  { key: 'categories', label: 'Appliance Categories' },
]

function ProductsTab() {
  const [subTab, setSubTab] = useState<'company' | 'brands' | 'products' | 'categories'>('company')
  const [categories, setCategories] = useState<ProductCategoryItem[]>([])
  const [products, setProducts] = useState<ProductItem[]>([])
  const [brands, setBrands] = useState<BrandItem[]>([])
  const [companies, setCompanies] = useState<CompanyItem[]>([])
  const [loading, setLoading] = useState(true)

  const [catModalOpen, setCatModalOpen] = useState(false)
  const [catCode, setCatCode] = useState('')
  const [catName, setCatName] = useState('')
  const [catIsSized, setCatIsSized] = useState(false)

  const [brandModalOpen, setBrandModalOpen] = useState(false)
  const [brandCompanyId, setBrandCompanyId] = useState('')
  const [brandCode, setBrandCode] = useState('')
  const [brandName, setBrandName] = useState('')

  const [prodModalOpen, setProdModalOpen] = useState(false)
  const [prodCompanyId, setProdCompanyId] = useState('')
  const [prodCatId, setProdCatId] = useState('')
  const [modelNo, setModelNo] = useState('')
  const [modelName, setModelName] = useState('')
  const [sizeInch, setSizeInch] = useState('')
  const [warrantyMonths, setWarrantyMonths] = useState('36')
  const [saving, setSaving] = useState(false)

  const loadData = async () => {
    setLoading(true)
    try {
      const [cRes, pRes, brandRes, companyRes] = await Promise.all([
        api.listProductCategories(),
        api.listProducts(),
        api.listBrands(),
        api.listCompanies(),
      ])
      setCategories(cRes)
      setProducts(pRes)
      setBrands(brandRes)
      setCompanies(companyRes)
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  const handleAddCategory = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.createProductCategory({
        code: catCode.toLowerCase().replace(/\s+/g, '_'),
        name: catName,
        is_sized: catIsSized,
        sort_order: categories.length + 1,
        is_active: true,
      })
      setCatModalOpen(false)
      setCatCode('')
      setCatName('')
      await loadData()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to create category')
    } finally {
      setSaving(false)
    }
  }

  const handleAddBrand = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.createBrand({
        company_id: Number(brandCompanyId),
        code: brandCode.toLowerCase().replace(/\s+/g, '_'),
        name: brandName,
        is_active: true,
      })
      setBrandModalOpen(false)
      setBrandCode('')
      setBrandName('')
      await loadData()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to create brand')
    } finally {
      setSaving(false)
    }
  }

  const handleAddProduct = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.createProduct({
        company_id: parseInt(prodCompanyId),
        product_category_id: parseInt(prodCatId),
        model_no: modelNo,
        name: modelName || null,
        size_inch: sizeInch ? parseFloat(sizeInch) : null,
        warranty_months: warrantyMonths ? parseInt(warrantyMonths) : null,
        is_active: true,
      })
      setProdModalOpen(false)
      setModelNo('')
      setModelName('')
      await loadData()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to create product model')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      {/* Sub-tab navigation */}
      <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
        {PRODUCTS_SUB_TABS.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setSubTab(tab.key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
              subTab === tab.key
                ? 'bg-brand-600 text-white'
                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Company Sub-tab */}
      {subTab === 'company' && <CompaniesTab />}

      {/* Appliance Categories Section */}
      {subTab === 'categories' && (
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
              Appliance Categories ({categories.length})
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              High-level appliance types (TVs, Air Conditioners, Washing Machines, Fans, etc.)
            </p>
          </div>
          <button
            onClick={() => {
              setCatCode('')
              setCatName('')
              setCatIsSized(false)
              setCatModalOpen(true)
            }}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
          >
            + Add Appliance Category
          </button>
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {categories.map((c) => (
            <div
              key={c.id}
              className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900"
            >
              <div className="flex items-center justify-between">
                <span className="font-bold text-slate-900 dark:text-white">{c.name}</span>
                <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-mono text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                  {c.code}
                </span>
              </div>
              <div className="mt-2 text-xs text-slate-500">
                Size Banded Rate: {c.is_sized ? 'Yes (Inch Dimensions)' : 'No (Standard Flat)'}
              </div>
            </div>
          ))}
        </div>
      </div>
      )}

      {/* Brands Section */}
      {subTab === 'brands' && (
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
              Brands ({brands.length})
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Sub-brands a company sells under (e.g. Dianox under Dianora).
            </p>
          </div>
          <button
            onClick={() => {
              setBrandCompanyId(companies[0]?.id ? String(companies[0].id) : '')
              setBrandCode('')
              setBrandName('')
              setBrandModalOpen(true)
            }}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
          >
            + Add Brand
          </button>
        </div>

        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
              <tr>
                <th className="px-4 py-3">Code</th>
                <th className="px-4 py-3">Brand Name</th>
                <th className="px-4 py-3">Company</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {loading ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    Loading brands…
                  </td>
                </tr>
              ) : brands.length === 0 ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    No brands found.
                  </td>
                </tr>
              ) : (
                brands.map((b) => (
                  <tr key={b.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-white">
                      {b.code}
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                      {b.name}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                      {b.company?.name || '—'}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
      )}

      {/* Product Model Catalogue Section */}
      {subTab === 'products' && (
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
              Product Models Catalogue ({products.length})
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Specific manufacturer models, screen size dimensions, and warranty terms
            </p>
          </div>
          <button
            onClick={() => {
              setProdCompanyId(companies[0]?.id ? String(companies[0].id) : '')
              setProdCatId(categories[0]?.id ? String(categories[0].id) : '')
              setModelNo('')
              setModelName('')
              setSizeInch('')
              setProdModalOpen(true)
            }}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
          >
            + Add Product Model
          </button>
        </div>

        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
              <tr>
                <th className="px-4 py-3">Model Number</th>
                <th className="px-4 py-3">Model Description</th>
                <th className="px-4 py-3">Company</th>
                <th className="px-4 py-3">Appliance Category</th>
                <th className="px-4 py-3">Screen Size</th>
                <th className="px-4 py-3">Warranty</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {loading ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                    Loading product models…
                  </td>
                </tr>
              ) : products.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                    No product models found.
                  </td>
                </tr>
              ) : (
                products.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-3 font-mono font-bold text-slate-900 dark:text-white">
                      {p.model_no}
                    </td>
                    <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                      {p.name || '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                      {p.company?.name || 'Grand Company'}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                      {p.product_category?.name || 'LED TV'}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                      {p.size_inch ? `${p.size_inch}″` : '—'}
                    </td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                      {p.warranty_months ? `${p.warranty_months} Mo` : '—'}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
      )}

      {/* Add Appliance Category Modal */}
      {catModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">Add Appliance Category</h3>
            <form onSubmit={handleAddCategory} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Category Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. washing_machine"
                  value={catCode}
                  onChange={(e) => setCatCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Category Name
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Front Load Washing Machine"
                  value={catName}
                  onChange={(e) => setCatName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="flex items-center gap-2 pt-2">
                <input
                  type="checkbox"
                  id="cat-sized"
                  checked={catIsSized}
                  onChange={(e) => setCatIsSized(e.target.checked)}
                  className="h-4 w-4 rounded border-slate-300 text-brand-600"
                />
                <label htmlFor="cat-sized" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                  Rates depend on screen/unit size (Size Banded)
                </label>
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setCatModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Creating…' : 'Create Category'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add Brand Modal */}
      {brandModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">Add Brand</h3>
            <form onSubmit={handleAddBrand} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Company
                </label>
                <select
                  required
                  value={brandCompanyId}
                  onChange={(e) => setBrandCompanyId(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                >
                  <option value="" disabled>
                    Select…
                  </option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Brand Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. dianox"
                  value={brandCode}
                  onChange={(e) => setBrandCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Brand Name
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Dianox"
                  value={brandName}
                  onChange={(e) => setBrandName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setBrandModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || !brandCompanyId}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Creating…' : 'Create Brand'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add Product Modal */}
      {prodModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">Add Product Model</h3>
            <form onSubmit={handleAddProduct} className="space-y-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Company
                  </label>
                  <select
                    value={prodCompanyId}
                    onChange={(e) => setProdCompanyId(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    {companies.map((company) => (
                      <option key={company.id} value={company.id}>
                        {company.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Appliance Category
                  </label>
                  <select
                    value={prodCatId}
                    onChange={(e) => setProdCatId(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  >
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Model Number / Ref
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. LED-55UHD-SMART"
                  value={modelNo}
                  onChange={(e) => setModelNo(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Model Description
                </label>
                <input
                  type="text"
                  placeholder="e.g. 55-inch Ultra HD Smart LED TV"
                  value={modelName}
                  onChange={(e) => setModelName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Screen Size (Inches)
                  </label>
                  <input
                    type="number"
                    step="0.1"
                    placeholder="e.g. 55"
                    value={sizeInch}
                    onChange={(e) => setSizeInch(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                    Warranty (Months)
                  </label>
                  <input
                    type="number"
                    placeholder="36"
                    value={warrantyMonths}
                    onChange={(e) => setWarrantyMonths(e.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setProdModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Creating…' : 'Create Product Model'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 6. MASTER LISTS TAB                                                  */
/* ==================================================================== */

/**
 * States and districts on one page: a district cannot be added without
 * picking a state, so splitting them into separate tabs just meant
 * flipping back and forth to look up the state code first.
 */
function GeographySection({
  states,
  districts,
  loading,
  onChanged,
}: {
  states: StateItem[]
  districts: any[]
  loading: boolean
  onChanged: () => Promise<void>
}) {
  const [stateModalOpen, setStateModalOpen] = useState(false)
  const [stateCode, setStateCode] = useState('')
  const [stateName, setStateName] = useState('')

  const [districtModalOpen, setDistrictModalOpen] = useState(false)
  const [districtStateId, setDistrictStateId] = useState('')
  const [districtCode, setDistrictCode] = useState('')
  const [districtName, setDistrictName] = useState('')

  const [saving, setSaving] = useState(false)

  const handleAddState = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.addMasterListItem('states', {
        code: stateCode.toUpperCase().trim(),
        name: stateName,
        is_active: true,
      })
      setStateModalOpen(false)
      setStateCode('')
      setStateName('')
      await onChanged()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to add state')
    } finally {
      setSaving(false)
    }
  }

  const handleAddDistrict = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.addMasterListItem('districts', {
        state_id: Number(districtStateId),
        code: districtCode.toLowerCase().replace(/\s+/g, '_'),
        name: districtName,
        is_active: true,
      })
      setDistrictModalOpen(false)
      setDistrictCode('')
      setDistrictName('')
      await onChanged()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to add district')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-8">
      {/* States */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-base font-bold text-slate-900 dark:text-white">
              States ({states.length})
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              The states a district must belong to.
            </p>
          </div>
          <button
            onClick={() => {
              setStateCode('')
              setStateName('')
              setStateModalOpen(true)
            }}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
          >
            + Add State
          </button>
        </div>

        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
              <tr>
                <th className="px-4 py-3">Code</th>
                <th className="px-4 py-3">Name</th>
                <th className="px-4 py-3">Districts</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {loading ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    Loading states…
                  </td>
                </tr>
              ) : states.length === 0 ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    No states found.
                  </td>
                </tr>
              ) : (
                states.map((s) => (
                  <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-white">
                      {s.code}
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                      {s.name}
                    </td>
                    <td className="px-4 py-3 text-slate-500 dark:text-slate-400">
                      {districts.filter((d) => d.state_id === s.id).length}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Districts */}
      <div className="space-y-3 pt-4 border-t border-slate-200 dark:border-slate-800">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-base font-bold text-slate-900 dark:text-white">
              Districts ({districts.length})
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Customer addresses resolve to one of these.
            </p>
          </div>
          <button
            onClick={() => {
              setDistrictStateId(states[0]?.id ? String(states[0].id) : '')
              setDistrictCode('')
              setDistrictName('')
              setDistrictModalOpen(true)
            }}
            disabled={states.length === 0}
            className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700 disabled:opacity-50"
            title={states.length === 0 ? 'Add a state first' : undefined}
          >
            + Add District
          </button>
        </div>

        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
              <tr>
                <th className="px-4 py-3">Code</th>
                <th className="px-4 py-3">Name</th>
                <th className="px-4 py-3">State</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {loading ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    Loading districts…
                  </td>
                </tr>
              ) : districts.length === 0 ? (
                <tr>
                  <td colSpan={3} className="px-4 py-8 text-center text-slate-500">
                    No districts found.
                  </td>
                </tr>
              ) : (
                districts.map((d: any) => (
                  <tr key={d.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-white">
                      {d.code}
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                      {d.name}
                    </td>
                    <td className="px-4 py-3 text-slate-500 dark:text-slate-400">
                      {d.state?.name || '—'}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Add State Modal */}
      {stateModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">Add State</h3>
            <form onSubmit={handleAddState} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  State Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. KL"
                  value={stateCode}
                  onChange={(e) => setStateCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  State Name
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Kerala"
                  value={stateName}
                  onChange={(e) => setStateName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setStateModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Adding…' : 'Add State'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add District Modal */}
      {districtModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">Add District</h3>
            <form onSubmit={handleAddDistrict} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  State
                </label>
                <select
                  required
                  value={districtStateId}
                  onChange={(e) => setDistrictStateId(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                >
                  {states.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} ({s.code})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  District Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. kkd"
                  value={districtCode}
                  onChange={(e) => setDistrictCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  District Name
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Kozhikode"
                  value={districtName}
                  onChange={(e) => setDistrictName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setDistrictModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Adding…' : 'Add District'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

function MasterListsTab() {
  const [listsData, setListsData] = useState<Record<string, any[]>>({})
  const [loading, setLoading] = useState(true)
  const [selectedList, setSelectedList] = useState<
    'districts' | 'symptoms' | 'resolutions' | 'hold_reasons' | 'job_types' | 'service_centers'
  >('districts')

  const [modalOpen, setModalOpen] = useState(false)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [requiresVideoProof, setRequiresVideoProof] = useState(false)
  const [saving, setSaving] = useState(false)

  const loadLists = async () => {
    setLoading(true)
    try {
      const res = await api.listMasterLists()
      setListsData(res)
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadLists()
  }, [])

  const handleAddItem = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await api.addMasterListItem(selectedList, {
        code: code.toLowerCase().replace(/\s+/g, '_'),
        name: name,
        requires_video_proof: selectedList === 'symptoms' ? requiresVideoProof : false,
        is_active: true,
      })
      setModalOpen(false)
      setCode('')
      setName('')
      setRequiresVideoProof(false)
      await loadLists()
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to add item')
    } finally {
      setSaving(false)
    }
  }

  const items = listsData[selectedList] || []

  return (
    <div className="space-y-6">
      {/* Sub-tab pills */}
      <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
        {[
          { key: 'districts', label: 'Districts & Geography' },
          { key: 'symptoms', label: 'Coded Symptoms' },
          { key: 'resolutions', label: 'Resolutions' },
          { key: 'hold_reasons', label: 'Hold Reasons' },
          { key: 'job_types', label: 'Job Types' },
          { key: 'service_centers', label: 'Service Centers' },
        ].map((tab) => (
          <button
            key={tab.key}
            onClick={() => setSelectedList(tab.key as any)}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
              selectedList === tab.key
                ? 'bg-brand-600 text-white'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
            }`}
          >
            {tab.key === 'districts'
              ? `${tab.label} (${(listsData.states?.length || 0) + (listsData.districts?.length || 0)})`
              : `${tab.label} (${listsData[tab.key]?.length || 0})`}
          </button>
        ))}
      </div>

      {selectedList === 'districts' ? (
        <GeographySection
          states={(listsData.states as StateItem[]) || []}
          districts={listsData.districts || []}
          loading={loading}
          onChanged={loadLists}
        />
      ) : (
        <>
          <div className="flex items-center justify-between">
            <h3 className="text-base font-bold text-slate-900 dark:text-white capitalize">
              {selectedList.replace('_', ' ')} Master List
            </h3>
            <button
              onClick={() => {
                setCode('')
                setName('')
                setRequiresVideoProof(false)
                setModalOpen(true)
              }}
              className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-brand-700"
            >
              + Add New Item
            </button>
          </div>

          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                <tr>
                  <th className="px-4 py-3">Code</th>
                  <th className="px-4 py-3">Name / Label</th>
                  <th className="px-4 py-3">Details / Category</th>
                  {selectedList === 'symptoms' && <th className="px-4 py-3">Video Proof Rule</th>}
                  <th className="px-4 py-3">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {loading ? (
                  <tr>
                    <td colSpan={selectedList === 'symptoms' ? 5 : 4} className="px-4 py-8 text-center text-slate-500">
                      Loading master list items…
                    </td>
                  </tr>
                ) : items.length === 0 ? (
                  <tr>
                    <td colSpan={selectedList === 'symptoms' ? 5 : 4} className="px-4 py-8 text-center text-slate-500">
                      No items in this master list.
                    </td>
                  </tr>
                ) : (
                  items.map((item: any) => (
                    <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <td className="px-4 py-3 font-mono font-semibold text-slate-900 dark:text-white">
                        {item.code}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">
                        {item.name}
                      </td>
                      <td className="px-4 py-3 text-slate-500 dark:text-slate-400">
                        {item.product_category?.name || item.description || item.city || '—'}
                      </td>
                      {selectedList === 'symptoms' && (
                        <td className="px-4 py-3">
                          <button
                            onClick={async () => {
                              try {
                                await api.updateMasterListItem('symptoms', item.id, {
                                  requires_video_proof: !item.requires_video_proof,
                                })
                                await loadLists()
                              } catch (err: unknown) {
                                alert(err instanceof Error ? err.message : 'Could not update video proof requirement')
                              }
                            }}
                            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition cursor-pointer ${
                              item.requires_video_proof
                                ? 'bg-amber-100 text-amber-800 hover:bg-amber-200 dark:bg-amber-950/60 dark:text-amber-300'
                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'
                            }`}
                          >
                            {item.requires_video_proof ? '📹 Video Proof Mandatory' : '📷 Optional Video'}
                          </button>
                        </td>
                      )}
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                          Active
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </>
      )}

      {/* Add Master List Item Modal */}
      {selectedList !== 'districts' && modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900">
            <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-4">
              Add Item to {selectedList.replace('_', ' ')}
            </h3>
            <form onSubmit={handleAddItem} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Item Code
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. display_fault"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                  Item Name / Description
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Display Panel Lines Fault"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
                />
              </div>

              {selectedList === 'symptoms' && (
                <div className="flex items-center gap-2 pt-1">
                  <input
                    type="checkbox"
                    id="sym-video"
                    checked={requiresVideoProof}
                    onChange={(e) => setRequiresVideoProof(e.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  <label htmlFor="sym-video" className="text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                    📹 Require Video Proof for this symptom
                  </label>
                </div>
              )}

              <div className="flex justify-end gap-2 pt-4">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                  {saving ? 'Adding…' : 'Add Item'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

/* ==================================================================== */
/* 4. CONFIGURATIONS TAB (timezone & date/time format)                  */
/* ==================================================================== */
const DATE_FORMAT_LABELS: Record<string, string> = {
  'DD/MM/YYYY': 'DD/MM/YYYY (31/12/2026)',
  'MM/DD/YYYY': 'MM/DD/YYYY (12/31/2026)',
  'YYYY-MM-DD': 'YYYY-MM-DD (2026-12-31)',
}

const TIME_FORMAT_LABELS: Record<string, string> = {
  '12h': '12-hour (2:30 PM)',
  '24h': '24-hour (14:30)',
}

/**
 * Renders "now" the way the chosen combination would render every
 * timestamp in the desk, so a change here previews before it is saved
 * rather than only being checkable afterwards against a live ticket.
 */
function formatPreview(timezone: string, dateFormat: string, timeFormat: string): string {
  const now = new Date()
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: 'numeric',
    minute: '2-digit',
    hour12: timeFormat === '12h',
  }).formatToParts(now)

  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? ''
  const day = get('day')
  const month = get('month')
  const year = get('year')
  const hour = get('hour')
  const minute = get('minute')
  const dayPeriod = get('dayPeriod')

  const datePart =
    dateFormat === 'MM/DD/YYYY'
      ? `${month}/${day}/${year}`
      : dateFormat === 'YYYY-MM-DD'
        ? `${year}-${month}-${day}`
        : `${day}/${month}/${year}`

  const timePart = timeFormat === '12h' ? `${hour}:${minute} ${dayPeriod}` : `${hour}:${minute}`

  return `${datePart}, ${timePart}`
}

function ConfigurationsTab() {
  const [subTab, setSubTab] = useState<'general' | 'branding'>('general')

  return (
    <div className="space-y-4">
      <div className="flex gap-1 border-b border-slate-200 dark:border-slate-800">
        <button
          onClick={() => setSubTab('general')}
          className={`px-4 py-2 text-sm font-medium transition ${
            subTab === 'general'
              ? 'border-b-2 border-brand-600 text-brand-600 dark:text-brand-400'
              : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          General
        </button>
        <button
          onClick={() => setSubTab('branding')}
          className={`px-4 py-2 text-sm font-medium transition ${
            subTab === 'branding'
              ? 'border-b-2 border-brand-600 text-brand-600 dark:text-brand-400'
              : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'
          }`}
        >
          Logo &amp; Favicon
        </button>
      </div>

      {subTab === 'general' && <GeneralConfigurationsTab />}
      {subTab === 'branding' && <BrandingConfigurationsTab />}
    </div>
  )
}

/* ==================================================================== */
/* 8a. CONFIGURATIONS — GENERAL SUB-TAB                                 */
/* ==================================================================== */
function GeneralConfigurationsTab() {
  const [settings, setSettings] = useState<AppSettingsItem | null>(null)
  const [meta, setMeta] = useState<AppSettingsMeta>({ timezones: [], date_formats: [], time_formats: [] })
  const [loading, setLoading] = useState(true)
  const [timezone, setTimezone] = useState('')
  const [timezoneFilter, setTimezoneFilter] = useState('')
  const [dateFormat, setDateFormat] = useState('')
  const [timeFormat, setTimeFormat] = useState('')
  const [saving, setSaving] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')
  const [savedMsg, setSavedMsg] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const { data, meta } = await api.getAppSettings()
      setSettings(data)
      setMeta(meta)
      setTimezone(data.timezone)
      setDateFormat(data.date_format)
      setTimeFormat(data.time_format)
    } catch (e) {
      console.error('Failed to load app settings', e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  const filteredTimezones =
    timezoneFilter.trim() === ''
      ? meta.timezones
      : meta.timezones.filter((tz) => tz.toLowerCase().includes(timezoneFilter.trim().toLowerCase()))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSavedMsg('')

    try {
      const updated = await api.updateAppSettings({
        timezone,
        date_format: dateFormat,
        time_format: timeFormat,
      })
      setSettings(updated)
      setSavedMsg('Configuration saved.')
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : 'Failed to save configuration')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm text-slate-500">Loading configuration…</div>
  }

  const dirty =
    !!settings &&
    (timezone !== settings.timezone || dateFormat !== settings.date_format || timeFormat !== settings.time_format)

  return (
    <div className="max-w-xl space-y-4">
      <form
        onSubmit={handleSave}
        className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
      >
        {errorMsg && (
          <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
            {errorMsg}
          </div>
        )}
        {savedMsg && !dirty && (
          <div className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
            {savedMsg}
          </div>
        )}

        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Timezone</label>
          <input
            type="text"
            placeholder="Filter, e.g. Kolkata"
            value={timezoneFilter}
            onChange={(e) => setTimezoneFilter(e.target.value)}
            className="mb-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
          />
          <select
            value={timezone}
            onChange={(e) => setTimezone(e.target.value)}
            size={6}
            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
          >
            {!filteredTimezones.includes(timezone) && timezone && (
              <option value={timezone}>{timezone}</option>
            )}
            {filteredTimezones.map((tz) => (
              <option key={tz} value={tz}>
                {tz}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Date format</label>
            <select
              value={dateFormat}
              onChange={(e) => setDateFormat(e.target.value)}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
            >
              {meta.date_formats.map((fmt) => (
                <option key={fmt} value={fmt}>
                  {DATE_FORMAT_LABELS[fmt] ?? fmt}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Time format</label>
            <select
              value={timeFormat}
              onChange={(e) => setTimeFormat(e.target.value)}
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"
            >
              {meta.time_formats.map((fmt) => (
                <option key={fmt} value={fmt}>
                  {TIME_FORMAT_LABELS[fmt] ?? fmt}
                </option>
              ))}
            </select>
          </div>
        </div>

        {timezone && dateFormat && timeFormat && (
          <div className="rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-600 dark:bg-slate-800/50 dark:text-slate-400">
            Preview: <span className="font-semibold text-slate-900 dark:text-white">{formatPreview(timezone, dateFormat, timeFormat)}</span>
          </div>
        )}

        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
          <button
            type="submit"
            disabled={saving || !dirty}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save Configuration'}
          </button>
        </div>
      </form>
    </div>
  )
}

/* ==================================================================== */
/* 8b. CONFIGURATIONS — LOGO & FAVICON SUB-TAB                          */
/* ==================================================================== */
const MAX_LOGO_BYTES = 1 * 1024 * 1024
const MAX_FAVICON_BYTES = 256 * 1024

function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result))
    reader.onerror = () => reject(reader.error ?? new Error('Failed to read file'))
    reader.readAsDataURL(file)
  })
}

function BrandingConfigurationsTab() {
  const [settings, setSettings] = useState<AppSettingsItem | null>(null)
  const [loading, setLoading] = useState(true)
  const [logo, setLogo] = useState<string | null>(null)
  const [favicon, setFavicon] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [errorMsg, setErrorMsg] = useState('')
  const [savedMsg, setSavedMsg] = useState('')

  const loadData = async () => {
    setLoading(true)
    try {
      const { data } = await api.getAppSettings()
      setSettings(data)
      setLogo(data.logo_base64)
      setFavicon(data.favicon_base64)
    } catch (e) {
      console.error('Failed to load app settings', e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData()
  }, [])

  const handlePick = async (
    file: File | undefined,
    maxBytes: number,
    setValue: (value: string | null) => void
  ) => {
    if (!file) return
    setErrorMsg('')
    if (file.size > maxBytes) {
      setErrorMsg(`"${file.name}" is too large (max ${Math.round(maxBytes / 1024)} KB).`)
      return
    }
    try {
      setValue(await readFileAsDataUrl(file))
    } catch {
      setErrorMsg(`Could not read "${file.name}".`)
    }
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrorMsg('')
    setSavedMsg('')

    try {
      const updated = await api.updateAppSettings({
        logo_base64: logo,
        favicon_base64: favicon,
      })
      setSettings(updated)
      setSavedMsg('Branding saved.')
    } catch (err: unknown) {
      setErrorMsg(err instanceof Error ? err.message : 'Failed to save branding')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <div className="py-8 text-center text-sm text-slate-500">Loading branding…</div>
  }

  const dirty = !!settings && (logo !== settings.logo_base64 || favicon !== settings.favicon_base64)

  return (
    <div className="max-w-xl space-y-4">
      <form
        onSubmit={handleSave}
        className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
      >
        {errorMsg && (
          <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
            {errorMsg}
          </div>
        )}
        {savedMsg && !dirty && (
          <div className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
            {savedMsg}
          </div>
        )}

        {/* Logo */}
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
            Portal Logo
          </label>
          <p className="mb-2 text-xs text-slate-400">PNG, JPG, WebP, or SVG — up to 1 MB.</p>
          <div className="flex items-center gap-4">
            <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50">
              {logo ? (
                <img src={logo} alt="Logo preview" className="h-full w-full object-contain" />
              ) : (
                <span className="text-[10px] text-slate-400">No logo</span>
              )}
            </div>
            <div className="flex items-center gap-2">
              <label className="cursor-pointer rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-slate-400 dark:border-slate-700 dark:text-slate-300">
                Upload…
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  className="hidden"
                  onChange={(e) => {
                    void handlePick(e.target.files?.[0], MAX_LOGO_BYTES, setLogo)
                    e.target.value = ''
                  }}
                />
              </label>
              {logo && (
                <button
                  type="button"
                  onClick={() => setLogo(null)}
                  className="rounded-lg px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                >
                  Remove
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Favicon */}
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">
            Favicon
          </label>
          <p className="mb-2 text-xs text-slate-400">PNG, ICO, or SVG — up to 256 KB. Square images work best.</p>
          <div className="flex items-center gap-4">
            <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50">
              {favicon ? (
                <img src={favicon} alt="Favicon preview" className="h-8 w-8 object-contain" />
              ) : (
                <span className="text-[10px] text-slate-400">None</span>
              )}
            </div>
            <div className="flex items-center gap-2">
              <label className="cursor-pointer rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:border-slate-400 dark:border-slate-700 dark:text-slate-300">
                Upload…
                <input
                  type="file"
                  accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml"
                  className="hidden"
                  onChange={(e) => {
                    void handlePick(e.target.files?.[0], MAX_FAVICON_BYTES, setFavicon)
                    e.target.value = ''
                  }}
                />
              </label>
              {favicon && (
                <button
                  type="button"
                  onClick={() => setFavicon(null)}
                  className="rounded-lg px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                >
                  Remove
                </button>
              )}
            </div>
          </div>
        </div>

        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
          <button
            type="submit"
            disabled={saving || !dirty}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save Branding'}
          </button>
        </div>
      </form>
    </div>
  )
}
