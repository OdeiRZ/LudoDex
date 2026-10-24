import { createHash } from 'node:crypto'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// Política de seguridad de contenido (CSP) puesta como <meta> en el HTML del
// build, igual que en PequeDex: el hash del script en línea de index.html (el
// que aplica el tema antes de pintar) se calcula aquí, así que editarlo no rompe
// la política. Solo en el build: en desarrollo, Vite inyecta scripts en línea
// para el HMR.
//
//  - Scripts: solo los del propio origen y ese script en línea.
//  - connect-src: el propio origen y el API (el origen de VITE_API_URL).
//  - Imágenes: cualquier https (portadas de BGG y las URL que cada usuario pone
//    a sus juegos), data: y blob:, y el API (en local va por http).
//  - Estilos con 'unsafe-inline': Vue y la app ponen atributos `style`.
//
// `frame-ancestors` no vale en un <meta>: va como cabecera en public/_headers.
function contentSecurityPolicy(apiUrl: string): Plugin {
  return {
    name: 'ludodex-content-security-policy',
    apply: 'build',
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        const inlineScriptHashes = [
          ...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g),
        ].map(
          (match) =>
            `'sha256-${createHash('sha256')
              .update(match[1] ?? '')
              .digest('base64')}'`,
        )
        const apiOrigin = new URL(apiUrl).origin

        const policy = [
          "default-src 'self'",
          `script-src 'self' ${inlineScriptHashes.join(' ')}`.trim(),
          "style-src 'self' 'unsafe-inline'",
          `img-src 'self' data: blob: https: ${apiOrigin}`,
          "font-src 'self' data:",
          `connect-src 'self' ${apiOrigin}`,
          "worker-src 'self'",
          "manifest-src 'self'",
          "object-src 'none'",
          "base-uri 'self'",
          "form-action 'self'",
        ].join('; ')

        return html.replace(
          /<meta charset="UTF-8"\s*\/?>/,
          (tag) => `${tag}\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`,
        )
      },
    },
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [
      vue(),
      vueDevTools(),
      // Mismo valor por defecto que `lib/api.ts`.
      contentSecurityPolicy(env.VITE_API_URL ?? 'http://localhost:8000/api'),
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
  }
})
