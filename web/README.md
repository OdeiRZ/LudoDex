# LudoDex Web

SPA en Vue 3 (Composition API, Pinia, Vue Router, TypeScript) para
[LudoDex](../README.md). Consume la [API](../api/README.md) por HTTP con un
token Bearer guardado en `localStorage`.

## Instalación

```bash
npm install
cp .env.example .env.local   # ajusta VITE_API_URL si la API no corre en localhost:8000
npm run dev
```

## Scripts

```bash
npm run dev          # servidor de desarrollo
npm run build         # type-check (vue-tsc) + build de producción
npm run lint          # ESLint (con --fix)
npm run format         # Prettier
npm run test:unit      # Vitest
```

## Estructura relevante

- `src/lib/api.ts` — instancia de axios con interceptor que añade el token
  Bearer a cada petición.
- `src/stores/auth.ts` — store de Pinia: sesión (usuario + token), acciones de
  registro/login/logout, y restauración de sesión al recargar la página.
- `src/router/index.ts` — rutas y guard de navegación (`requiresAuth` /
  `guestOnly`).
