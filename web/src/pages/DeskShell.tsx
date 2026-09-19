import { useState } from 'react'
import { useAuth } from '../lib/auth'
import { AppFooter } from '../components/AppFooter'
import { DashboardPanel } from './DashboardPanel'
import { RatePreviewPanel } from './RatePreviewPanel'
import { TicketsPanel } from './TicketsPanel'
import { InvoicingPanel } from './InvoicingPanel'
import { ReceivablesPanel } from './ReceivablesPanel'
import { ProfitLossPanel } from './ProfitLossPanel'
import { SparesPanel } from './SparesPanel'
import { SettingsPanel } from './SettingsPanel'

type Section =
  | 'dashboard'
  | 'tickets'
  | 'spares'
  | 'invoicing'
  | 'receivables'
  | 'profit-loss'
  | 'pricing'
  | 'settings'
type SettingsTab =
  | 'users'
  | 'technicians'
  | 'roles'
  | 'companies'
  | 'rate-cards'
  | 'products'
  | 'master-lists'
  | 'configurations'

export function DeskShell({ logo }: { logo?: string | null }) {
  const { user, logout } = useAuth()
  const [section, setSection] = useState<Section>('dashboard')
  const [settingsTab, setSettingsTab] = useState<SettingsTab>('users')
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const [settingsSubmenuOpen, setSettingsSubmenuOpen] = useState(true)

  const handleNavigate = (targetSection: Section, tab?: string) => {
    setSection(targetSection)
    if (tab && targetSection === 'settings') {
      setSettingsTab(tab as SettingsTab)
    }
    setMobileNavOpen(false)
  }

  return (
    <div className="flex h-screen w-screen overflow-hidden bg-slate-100 dark:bg-slate-950">
      {/* Backdrop for the mobile nav drawer */}
      {mobileNavOpen && (
        <div
          className="fixed inset-0 z-20 bg-black/50 md:hidden"
          onClick={() => setMobileNavOpen(false)}
        />
      )}

      {/* LEFT SIDEBAR NAVIGATION
          Off-canvas drawer below md; a normal collapsible column at md+. */}
      <aside
        className={`fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-slate-900 text-slate-300 transition-transform duration-300 dark:border-slate-800 dark:bg-slate-900 md:static md:z-auto md:translate-x-0 md:transition-all ${
          mobileNavOpen ? 'translate-x-0' : ''
        } ${sidebarCollapsed ? 'md:w-20' : 'md:w-64'}`}
      >
        {/* Sidebar Header / Brand Logo */}
        <div className="flex h-16 items-center justify-between border-b border-slate-800 px-4">
          <div className="flex items-center gap-3 overflow-hidden">
            {logo ? (
              <img
                src={logo}
                alt="Portal logo"
                className="h-9 w-9 shrink-0 rounded-xl object-contain shadow-md"
              />
            ) : (
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 font-bold text-white shadow-md">
                G
              </div>
            )}
            {!sidebarCollapsed && (
              <span className="truncate font-semibold tracking-tight text-white">
                Grand VendorService
              </span>
            )}
          </div>
          <button
            onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
            className="hidden h-7 w-7 items-center justify-center rounded-lg bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-white md:flex"
            title={sidebarCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'}
          >
            {sidebarCollapsed ? '▶' : '◀'}
          </button>
          <button
            onClick={() => setMobileNavOpen(false)}
            className="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-white md:hidden"
            title="Close menu"
          >
            ✕
          </button>
        </div>

        {/* Sidebar Navigation Items */}
        <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
          {/* Main Dashboard Section */}
          <div>
            {!sidebarCollapsed && (
              <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Main
              </div>
            )}
            <button
              onClick={() => handleNavigate('dashboard')}
              className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                section === 'dashboard'
                  ? 'bg-brand-600 text-white shadow-sm'
                  : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
              }`}
              title="Overview Dashboard"
            >
              <span className="text-base">📊</span>
              {!sidebarCollapsed && <span>Executive Dashboard</span>}
            </button>
          </div>

          {/* Operations Section */}
          <div>
            {!sidebarCollapsed && (
              <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Operations
              </div>
            )}
            <div className="space-y-1">
              <button
                onClick={() => handleNavigate('tickets')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'tickets'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="Tickets & Intake"
              >
                <span className="text-base">🎫</span>
                {!sidebarCollapsed && <span>Tickets & Intake</span>}
              </button>

              <button
                onClick={() => handleNavigate('spares')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'spares'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="Spare Stock Ledger"
              >
                <span className="text-base">📦</span>
                {!sidebarCollapsed && <span>Spare Stock</span>}
              </button>
            </div>
          </div>

          {/* Financials Section */}
          <div>
            {!sidebarCollapsed && (
              <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Financials
              </div>
            )}
            <div className="space-y-1">
              <button
                onClick={() => handleNavigate('invoicing')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'invoicing'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="Invoicing & Payouts"
              >
                <span className="text-base">💰</span>
                {!sidebarCollapsed && <span>Invoicing & Payouts</span>}
              </button>

              <button
                onClick={() => handleNavigate('receivables')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'receivables'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="What each company owes and what has been received"
              >
                <span className="text-base">📥</span>
                {!sidebarCollapsed && <span>Receivables</span>}
              </button>

              <button
                onClick={() => handleNavigate('profit-loss')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'profit-loss'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="Income, expenses and what was kept"
              >
                <span className="text-base">📈</span>
                {!sidebarCollapsed && <span>Profit &amp; Loss</span>}
              </button>

              <button
                onClick={() => handleNavigate('pricing')}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                  section === 'pricing'
                    ? 'bg-brand-600 text-white shadow-sm'
                    : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
                }`}
                title="Rate Preview Engine"
              >
                <span className="text-base">🏷️</span>
                {!sidebarCollapsed && <span>Rate Preview</span>}
              </button>
            </div>
          </div>

          {/* Administration & System Settings with Submenus */}
          <div>
            {!sidebarCollapsed && (
              <div className="mb-2 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Administration
              </div>
            )}
            <button
              onClick={() => {
                setSection('settings')
                if (!sidebarCollapsed) {
                  setSettingsSubmenuOpen(!settingsSubmenuOpen)
                }
              }}
              className={`flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                section === 'settings'
                  ? 'bg-brand-600 text-white shadow-sm'
                  : 'text-slate-400 hover:bg-slate-800/80 hover:text-white'
              }`}
              title="System Settings"
            >
              <div className="flex items-center gap-3">
                <span className="text-base">⚙️</span>
                {!sidebarCollapsed && <span>System Settings</span>}
              </div>
              {!sidebarCollapsed && (
                <span className="text-xs text-slate-400">{settingsSubmenuOpen ? '▼' : '▶'}</span>
              )}
            </button>

            {/* Submenus for Settings */}
            {!sidebarCollapsed && settingsSubmenuOpen && (
              <div className="ml-4 mt-1.5 border-l border-slate-800 pl-3 space-y-1">
                <button
                  onClick={() => handleNavigate('settings', 'users')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'users'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">👤</span>
                  <span>Users & Accounts</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'technicians')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'technicians'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🛠️</span>
                  <span>Technicians</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'roles')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'roles'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🔐</span>
                  <span>Groups & Permissions</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'companies')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'companies'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🏢</span>
                  <span>Companies</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'rate-cards')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'rate-cards'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">📋</span>
                  <span>Rate Cards & SLA</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'products')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'products'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🖥️</span>
                  <span>Products & Appliances</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'master-lists')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'master-lists'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🗂️</span>
                  <span>Master Lists</span>
                </button>

                <button
                  onClick={() => handleNavigate('settings', 'configurations')}
                  className={`flex w-full items-center gap-2 text-left rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                    section === 'settings' && settingsTab === 'configurations'
                      ? 'bg-slate-800 text-brand-400 font-semibold'
                      : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'
                  }`}
                >
                  <span className="text-sm">🌐</span>
                  <span>Configurations</span>
                </button>
              </div>
            )}
          </div>
        </nav>

        {/* Sidebar Footer / User Profile */}
        <div className="border-t border-slate-800 p-3">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-800 font-semibold text-slate-200">
              {user?.name ? user.name[0].toUpperCase() : 'U'}
            </div>
            {!sidebarCollapsed && (
              <div className="flex-1 overflow-hidden">
                <div className="truncate text-xs font-semibold text-white">{user?.name}</div>
                <div className="truncate text-[10px] text-slate-400">
                  {user?.role?.name}
                  {user?.service_center && ` · ${user.service_center.name}`}
                </div>
              </div>
            )}
            {!sidebarCollapsed && (
              <button
                onClick={() => void logout()}
                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-rose-400"
                title="Sign out"
              >
                🚪
              </button>
            )}
          </div>
        </div>
      </aside>

      {/* RIGHT MAIN WORKSPACE */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Top Header Bar */}
        <header className="flex h-14 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:h-16 sm:px-6">
          <div className="flex min-w-0 items-center gap-2 sm:gap-3">
            <button
              onClick={() => setMobileNavOpen(true)}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 md:hidden"
              title="Open menu"
            >
              ☰
            </button>
            <span className="hidden shrink-0 text-xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400 sm:inline">
              Grand VendorService
            </span>
            <span className="hidden text-slate-300 dark:text-slate-700 sm:inline">/</span>
            <span className="truncate text-sm font-semibold capitalize text-slate-900 dark:text-white">
              {section === 'dashboard'
                ? 'Executive Overview'
                : section === 'settings'
                ? `System Settings · ${settingsTab.replace('-', ' ')}`
                : section.replace('-', ' ')}
            </span>
          </div>

          <div className="flex shrink-0 items-center gap-2 sm:gap-4">
            <div className="hidden text-right lg:block">
              <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
                {new Date().toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
              </span>
            </div>
            <button
              onClick={() => void logout()}
              className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 sm:px-3"
            >
              Sign out
            </button>
          </div>
        </header>

        {/* Main Content Area */}
        <main className="flex-1 overflow-y-auto bg-slate-100/60 p-3 dark:bg-slate-950 sm:p-6">
          {section === 'dashboard' ? (
            <DashboardPanel onNavigate={handleNavigate} />
          ) : section === 'tickets' ? (
            <TicketsPanel />
          ) : section === 'spares' ? (
            <SparesPanel />
          ) : section === 'invoicing' ? (
            <InvoicingPanel />
          ) : section === 'receivables' ? (
            <ReceivablesPanel />
          ) : section === 'profit-loss' ? (
            <ProfitLossPanel />
          ) : section === 'settings' ? (
            <SettingsPanel initialTab={settingsTab} />
          ) : (
            <RatePreviewPanel />
          )}
        </main>

        <AppFooter />
      </div>
    </div>
  )
}
