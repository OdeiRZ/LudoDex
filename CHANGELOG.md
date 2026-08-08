# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/), y este
proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

### Añadido

- Cimientos del proyecto: repo con `api/` (Laravel 12 + Sanctum) y `web/`
  (Vue 3 + Vite + TypeScript + Pinia + Vue Router), cada uno con su propio
  README.
- Autenticación completa de punta a punta: registro, login, logout y
  restauración de sesión al recargar la página, con token Bearer (Sanctum
  Personal Access Tokens) guardado en `localStorage` — ver la nota de
  arquitectura en el README raíz sobre por qué token en vez de cookie de
  sesión.
- Suite de tests Pest en la API (registro, login, logout, rutas protegidas) y
  Pint + Larastan (nivel 5) en CI local desde el primer commit, para no
  repetir la deuda de tenerlos que añadir a posteriori como en otro proyecto
  del portfolio.
- ESLint + Prettier + Vitest configurados en el frontend desde el scaffolding
  inicial (vía `create-vue`).
