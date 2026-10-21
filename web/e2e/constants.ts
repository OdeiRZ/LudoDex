// Aparte de playwright.config.ts a propósito: ese fichero crea la base de datos
// de pruebas al cargarse, y los tests no deben ejecutarlo al importar.
export const API_PORT = 8766
export const WEB_PORT = 4174
export const API_URL = `http://127.0.0.1:${API_PORT}/api`
export const APP_URL = `http://127.0.0.1:${WEB_PORT}/`
