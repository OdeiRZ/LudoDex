// Rango de jugadores o de duración tal como se enseña en las tarjetas: "1–4",
// o un solo número cuando los dos extremos coinciden ("2", no "2–2") o falta
// uno de ellos ("5", no "–5"). null cuando no hay ninguno.
export function formatRange(
  min: number | null | undefined,
  max: number | null | undefined,
): string | null {
  const low = min || null
  const high = max || null
  if (low === null && high === null) return null
  if (low === null || high === null || low === high) return String(low ?? high)
  return `${Math.min(low, high)}–${Math.max(low, high)}`
}
