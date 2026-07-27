import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'

/**
 * Sign in.
 *
 * Deliberately plain. This screen is used at 7am in a service centre and
 * from a phone at the roadside, so it needs large targets, a visible
 * error, and nothing else competing for attention.
 */
export function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setBusy(true)

    try {
      await login(email.trim(), password)
      // No redirect here: the router re-renders on the new identity and
      // sends this person to the shell the SERVER chose for them.
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : 'Could not reach the server. Check your connection and try again.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center bg-slate-100 p-4 dark:bg-slate-950">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-2xl font-bold text-white shadow-lg shadow-brand-600/20">
            G
          </div>
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
            Grand VendorService
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Multi-brand warranty service management
          </p>
        </div>

        <form
          onSubmit={onSubmit}
          className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900"
        >
          <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
              Email
            </span>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoFocus
              autoComplete="username"
              placeholder="you@grandservice.in"
              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
          </label>

          <label className="mt-4 block">
            <span className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
              Password
            </span>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 pr-16 text-slate-900 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
              />
              {/* Typing a password on a phone keyboard in sunlight is
                  error-prone; letting people check it prevents most of the
                  failed attempts that would otherwise trip the lockout. */}
              <button
                type="button"
                onClick={() => setShowPassword((v) => !v)}
                className="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
              >
                {showPassword ? 'Hide' : 'Show'}
              </button>
            </div>
          </label>

          {error && (
            <p
              role="alert"
              className="mt-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300"
            >
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={busy}
            className="mt-6 w-full rounded-lg bg-brand-600 px-4 py-2.5 font-medium text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/40 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        {/*
          Development credentials, shown only in dev builds. Vite strips
          this branch entirely from a production bundle.
        */}
        {import.meta.env.DEV && (
          <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs dark:border-amber-900/50 dark:bg-amber-950/30">
            <p className="mb-2 font-semibold text-amber-900 dark:text-amber-200">
              Development sign-ins
            </p>
            <ul className="space-y-1 text-amber-800 dark:text-amber-300/90">
              {[
                ['admin@grandservice.in', 'Administrator'],
                ['desk@grandservice.in', 'Service desk'],
                ['accounts@grandservice.in', 'Accounts'],
                ['rajeev@grandservice.in', 'Field technician'],
              ].map(([addr, role]) => (
                <li key={addr} className="flex items-center justify-between gap-2">
                  <button
                    type="button"
                    onClick={() => {
                      setEmail(addr)
                      setPassword('Password123!')
                    }}
                    className="font-mono underline-offset-2 hover:underline"
                  >
                    {addr}
                  </button>
                  <span className="shrink-0 opacity-70">{role}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 font-mono text-amber-700 dark:text-amber-400">
              Password123!
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
