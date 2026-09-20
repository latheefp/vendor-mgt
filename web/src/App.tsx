import { AuthProvider, useAuth } from './lib/auth'
import { useAppBranding } from './lib/branding'
import { LoginPage } from './pages/LoginPage'
import { DeskShell } from './pages/DeskShell'
import { FieldShell } from './pages/FieldShell'

/**
 * One application, two shells.
 *
 * Which shell someone sees comes from `user.landing`, which the SERVER
 * decides. That is on purpose: which surface a person may use is an
 * authorisation question, so the answer comes from the same place that
 * enforces it rather than from a role string the client interprets.
 */
function Root() {
  const { user, loading } = useAuth()
  const { logo } = useAppBranding()

  // Avoids flashing the login screen at someone who is already signed in.
  if (loading) {
    return (
      <div className="flex min-h-full items-center justify-center">
        <div className="flex items-center gap-3 text-sm text-slate-500">
          <span className="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600" />
          Loading…
        </div>
      </div>
    )
  }

  if (!user) {
    return <LoginPage logo={logo} />
  }

  // FieldShell's header is the technician's own initial, not portal
  // branding, so it has no logo prop to receive.
  return user.landing === '/field' ? <FieldShell /> : <DeskShell logo={logo} />
}

export default function App() {
  return (
    <AuthProvider>
      <Root />
    </AuthProvider>
  )
}
