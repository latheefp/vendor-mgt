import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { ApiError, api, type CurrentUser } from './api'

/**
 * Who is signed in.
 *
 * The server is the only authority here. On boot we ask `/auth/me` rather
 * than trusting anything cached on the client, because the session cookie
 * can expire, be revoked, or belong to an account that has since been
 * deactivated — and the client has no way to know any of that.
 */

interface AuthState {
  user: CurrentUser | null
  /** True until the first /auth/me settles, so we can avoid flashing the
   *  login screen at someone who is already signed in. */
  loading: boolean
  login: (email: string, password: string) => Promise<CurrentUser>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const controller = new AbortController()

    async function bootstrap() {
      try {
        // Ensures the CSRF cookie exists before the login POST needs it.
        await api.csrf()
      } catch {
        // Not fatal: login will simply fail loudly if the token is missing.
      }

      try {
        setUser(await api.me(controller.signal))
      } catch (error) {
        // A 401 here is the normal "not signed in" case, not a problem.
        if (!(error instanceof ApiError && error.isUnauthenticated)) {
          console.error('Could not determine session state', error)
        }
        setUser(null)
      } finally {
        setLoading(false)
      }
    }

    void bootstrap()
    return () => controller.abort()
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const signedIn = await api.login(email, password)
    setUser(signedIn)
    return signedIn
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } finally {
      // Clear locally even if the request failed — whatever happened, this
      // person intends to be signed out of this browser.
      setUser(null)
    }
  }, [])

  const value = useMemo<AuthState>(
    () => ({ user, loading, login, logout }),
    [user, loading, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (context === null) {
    throw new Error('useAuth must be used inside an <AuthProvider>')
  }
  return context
}
