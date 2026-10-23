// Cloudflare Pages Function para `/c/<token>`: cuando quien pide la página es el rastreador
// de una app de chat (WhatsApp, Telegram, Discord...), devuelve el index.html de la SPA con
// el título, la descripción y la imagen de la tarjeta rellenos con el nombre del dueño y el
// número de juegos. Las personas con navegador reciben la SPA tal cual. Cualquier fallo
// (API caída, enlace caducado, token inválido) cae a la SPA sin etiquetas propias: la vista
// previa es un extra, nunca puede romper la página.
import {
  escapeHtml,
  isPreviewBot,
  pickLocale,
  previewTexts,
  type SharePreview,
} from '../../src/lib/sharePreview'

interface Env {
  /** Base de la API (con `/api`); por defecto la de producción. */
  API_URL?: string
}

const DEFAULT_API_URL = 'https://ludodex-api.onrender.com/api'
const API_TIMEOUT_MS = 4000

async function fetchPreview(env: Env, token: string): Promise<SharePreview | null> {
  try {
    const base = (env.API_URL ?? DEFAULT_API_URL).replace(/\/$/, '')
    const response = await fetch(
      `${base}/public/collections/${encodeURIComponent(token)}/preview`,
      { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(API_TIMEOUT_MS) },
    )
    if (!response.ok) return null
    const body = (await response.json()) as {
      data?: { owner_name?: string; games_count?: number }
    }
    if (typeof body.data?.owner_name !== 'string' || typeof body.data.games_count !== 'number') {
      return null
    }
    return { ownerName: body.data.owner_name, gamesCount: body.data.games_count }
  } catch {
    return null
  }
}

export const onRequestGet = async (context: {
  request: Request
  env: Env
  params: { token?: string | string[] }
  next: () => Promise<Response>
}): Promise<Response> => {
  const { request, env, params } = context
  if (!isPreviewBot(request.headers.get('user-agent'))) return context.next()

  const token = Array.isArray(params.token) ? params.token[0] : params.token
  const page = await context.next()
  if (!token || !page.ok || !(page.headers.get('content-type') ?? '').includes('text/html')) {
    return page
  }

  const preview = await fetchPreview(env, token)
  if (preview === null) return page

  const { title, description } = previewTexts(
    preview,
    pickLocale(request.headers.get('accept-language')),
  )
  const origin = new URL(request.url).origin
  const tags = [
    ['og:type', 'website'],
    ['og:site_name', 'LudoDex'],
    ['og:title', title],
    ['og:description', description],
    ['og:image', `${origin}/icons/icon-512.png`],
    ['og:url', request.url],
    ['twitter:card', 'summary'],
  ]
    .map(([key, value]) => `<meta property="${key}" content="${escapeHtml(value)}">`)
    .join('')

  // Cambia el título y la descripción por defecto y añade las etiquetas de la tarjeta.
  const rewritten = new HTMLRewriter()
    .on('title', {
      element(element) {
        element.setInnerContent(title)
      },
    })
    .on('meta[name="description"]', {
      element(element) {
        element.setAttribute('content', description)
      },
    })
    .on('head', {
      element(element) {
        element.append(tags, { html: true })
      },
    })
    .transform(page)

  const headers = new Headers(rewritten.headers)
  headers.set('X-Robots-Tag', 'noindex, nofollow')
  headers.set('Cache-Control', 'no-store')
  return new Response(rewritten.body, { status: rewritten.status, headers })
}
