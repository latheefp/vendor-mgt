import { useState, type ComponentType } from 'react'
import {
  Banknote,
  Building2,
  ChevronDown,
  ChevronRight,
  ClipboardList,
  Globe,
  LayoutDashboard,
  ListTree,
  Lock,
  LogOut,
  Menu,
  Package,
  PanelLeftClose,
  PanelLeftOpen,
  Receipt,
  Settings,
  Tag,
  Ticket,
  TrendingUp,
  Users,
  Wrench,
  X,
} from 'lucide-react'
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
  | 'rate-cards'
  | 'products'
  | 'master-lists'
  | 'configurations'

/**
 * One sidebar row. The active state reads as a marked-off entry in a job
 * list — a left rail plus a lightly tinted row — rather than a filled
 * pill, so a long, dense nav doesn't turn into a strip of buttons.
 */
function NavItem({
  icon: Icon,
  label,
  active,
  collapsed,
  onClick,
}: {
  icon: ComponentType<{ className?: string }>
  label: string
  active: boolean
  collapsed: boolean
  onClick: () => void
}) {
  return (
    <button
      onClick={onClick}
      title={collapsed ? label : undefined}
      className={`flex w-full items-center gap-3 border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors ${
        active
          ? 'border-brand-400 bg-white/[0.06] text-white'
          : 'border-transparent text-ink-400 hover:bg-white/[0.04] hover:text-white'
      }`}
    >
      <Icon className={`h-[18px] w-[18px] shrink-0 ${active ? 'text-brand-400' : ''}`} />
      {!collapsed && <span className="truncate">{label}</span>}
    </button>
  )
}

function NavGroupLabel({ children, collapsed }: { children: string; collapsed: boolean }) {
  if (collapsed) return null
  return <div className="mb-2 px-3 text-[11px] font-semibold text-ink-600">{children}</div>
}

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

  const settingsItems: { tab: SettingsTab; icon: ComponentType<{ className?: string }>; label: string }[] = [
    { tab: 'users', icon: Users, label: 'Users & Accounts' },
    { tab: 'technicians', icon: Wrench, label: 'Technicians' },
    { tab: 'roles', icon: Lock, label: 'Groups & Permissions' },
    { tab: 'rate-cards', icon: ClipboardList, label: 'Rate Cards & SLA' },
    { tab: 'products', icon: Building2, label: 'Companies & Products' },
    { tab: 'master-lists', icon: ListTree, label: 'Master Lists' },
    { tab: 'configurations', icon: Globe, label: 'Configurations' },
  ]

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
        className={`fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col bg-ink-950 text-ink-400 transition-transform duration-300 md:static md:z-auto md:translate-x-0 md:transition-all ${
          mobileNavOpen ? 'translate-x-0' : ''
        } ${sidebarCollapsed ? 'md:w-[72px]' : 'md:w-64'}`}
      >
        {/* Sidebar Header / Brand Logo */}
        <div className="flex h-16 items-center justify-between border-b border-white/10 px-4">
          <div className="flex items-center gap-3 overflow-hidden">
            {logo ? (
              <img
                src={logo}
                alt="Portal logo"
                className="h-9 w-9 shrink-0 rounded-lg object-contain"
              />
            ) : (
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 font-bold text-white">
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
            className="hidden h-7 w-7 shrink-0 items-center justify-center rounded-md text-ink-400 hover:bg-white/10 hover:text-white md:flex"
            title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {sidebarCollapsed ? <PanelLeftOpen className="h-4 w-4" /> : <PanelLeftClose className="h-4 w-4" />}
          </button>
          <button
            onClick={() => setMobileNavOpen(false)}
            className="flex h-7 w-7 items-center justify-center rounded-md text-ink-400 hover:bg-white/10 hover:text-white md:hidden"
            title="Close menu"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Sidebar Navigation Items */}
        <nav className="flex-1 space-y-6 overflow-y-auto py-4">
          <div>
            <NavGroupLabel collapsed={sidebarCollapsed}>Main</NavGroupLabel>
            <NavItem
              icon={LayoutDashboard}
              label="Executive Dashboard"
              active={section === 'dashboard'}
              collapsed={sidebarCollapsed}
              onClick={() => handleNavigate('dashboard')}
            />
          </div>

          <div>
            <NavGroupLabel collapsed={sidebarCollapsed}>Operations</NavGroupLabel>
            <div className="space-y-0.5">
              <NavItem
                icon={Ticket}
                label="Tickets & Intake"
                active={section === 'tickets'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('tickets')}
              />
              <NavItem
                icon={Package}
                label="Spare Stock"
                active={section === 'spares'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('spares')}
              />
            </div>
          </div>

          <div>
            <NavGroupLabel collapsed={sidebarCollapsed}>Financials</NavGroupLabel>
            <div className="space-y-0.5">
              <NavItem
                icon={Receipt}
                label="Invoicing & Payouts"
                active={section === 'invoicing'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('invoicing')}
              />
              <NavItem
                icon={Banknote}
                label="Receivables"
                active={section === 'receivables'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('receivables')}
              />
              <NavItem
                icon={TrendingUp}
                label="Profit & Loss"
                active={section === 'profit-loss'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('profit-loss')}
              />
              <NavItem
                icon={Tag}
                label="Rate Preview"
                active={section === 'pricing'}
                collapsed={sidebarCollapsed}
                onClick={() => handleNavigate('pricing')}
              />
            </div>
          </div>

          <div>
            <NavGroupLabel collapsed={sidebarCollapsed}>Administration</NavGroupLabel>
            <button
              onClick={() => {
                setSection('settings')
                if (!sidebarCollapsed) {
                  setSettingsSubmenuOpen(!settingsSubmenuOpen)
                }
              }}
              title={sidebarCollapsed ? 'System Settings' : undefined}
              className={`flex w-full items-center justify-between border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors ${
                section === 'settings'
                  ? 'border-brand-400 bg-white/[0.06] text-white'
                  : 'border-transparent text-ink-400 hover:bg-white/[0.04] hover:text-white'
              }`}
            >
              <div className="flex items-center gap-3">
                <Settings className={`h-[18px] w-[18px] shrink-0 ${section === 'settings' ? 'text-brand-400' : ''}`} />
                {!sidebarCollapsed && <span>System Settings</span>}
              </div>
              {!sidebarCollapsed &&
                (settingsSubmenuOpen ? (
                  <ChevronDown className="h-3.5 w-3.5 text-ink-600" />
                ) : (
                  <ChevronRight className="h-3.5 w-3.5 text-ink-600" />
                ))}
            </button>

            {/* Submenus for Settings */}
            {!sidebarCollapsed && settingsSubmenuOpen && (
              <div className="ml-[21px] mt-1 space-y-0.5 border-l border-white/10 pl-3">
                {settingsItems.map(({ tab, icon: Icon, label }) => (
                  <button
                    key={tab}
                    onClick={() => handleNavigate('settings', tab)}
                    className={`flex w-full items-center gap-2.5 rounded-md py-1.5 pl-2 pr-2.5 text-left text-xs font-medium transition-colors ${
                      section === 'settings' && settingsTab === tab
                        ? 'bg-white/[0.06] text-brand-400'
                        : 'text-ink-400 hover:bg-white/[0.04] hover:text-white'
                    }`}
                  >
                    <Icon className="h-3.5 w-3.5 shrink-0" />
                    <span>{label}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </nav>

        {/* Sidebar Footer / User Profile */}
        <div className="border-t border-white/10 p-3">
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 font-semibold text-white">
              {user?.name ? user.name[0].toUpperCase() : 'U'}
            </div>
            {!sidebarCollapsed && (
              <div className="flex-1 overflow-hidden">
                <div className="truncate text-xs font-semibold text-white">{user?.name}</div>
                <div className="truncate text-[10px] text-ink-500">
                  {user?.role?.name}
                  {user?.service_center && ` · ${user.service_center.name}`}
                </div>
              </div>
            )}
            {!sidebarCollapsed && (
              <button
                onClick={() => void logout()}
                className="rounded-md p-1.5 text-ink-500 hover:bg-white/10 hover:text-rose-400"
                title="Sign out"
              >
                <LogOut className="h-4 w-4" />
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
              <Menu className="h-5 w-5" />
            </button>
            <span className="hidden shrink-0 text-xs font-semibold text-brand-600 dark:text-brand-400 sm:inline">
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
