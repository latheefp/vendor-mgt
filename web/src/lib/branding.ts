import { useEffect, useState } from 'react'
import { api } from './api'

/**
 * Portal branding (logo + favicon), set once in Settings → Configurations
 * → Logo & Favicon. Fetched here at the app root so every shell — login
 * screen, desk, field — can show the same mark instead of a hardcoded "G",
 * and so the browser tab's favicon updates without a page reload.
 *
 * GET /app-settings is allowed unauthenticated, so this resolves before
 * login too — the sign-in screen gets the same logo as the desk.
 */
export function useAppBranding(): { logo: string | null } {
  const [logo, setLogo] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    api
      .getAppSettings()
      .then(({ data }) => {
        if (cancelled) return
        setLogo(data.logo_base64)

        if (data.favicon_base64) {
          const link =
            document.querySelector<HTMLLinkElement>('link[rel="icon"]') ??
            document.head.appendChild(document.createElement('link'))
          link.rel = 'icon'
          link.href = data.favicon_base64
        }
      })
      .catch(() => {
        // No branding configured yet, or the request failed — the static
        // favicon and the "G" mark are a fine default either way.
      })

    return () => {
      cancelled = true
    }
  }, [])

  return { logo }
}
