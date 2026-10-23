import { describe, expect, it } from 'vitest'
import { escapeHtml, isPreviewBot, pickLocale, previewTexts } from '../sharePreview'

describe('isPreviewBot', () => {
  it.each([
    'WhatsApp/2.23.20.0 A',
    'TelegramBot (like TwitterBot)',
    'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'Slackbot-LinkExpanding 1.0',
  ])('detects %s', (ua) => expect(isPreviewBot(ua)).toBe(true))

  it.each([
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 15; CPH2655) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36',
    null,
  ])('lets %s through', (ua) => expect(isPreviewBot(ua)).toBe(false))
})

describe('pickLocale', () => {
  it('uses English only when it is the first language', () => {
    expect(pickLocale('en-US,en;q=0.9')).toBe('en')
    expect(pickLocale('es-ES,es;q=0.9,en;q=0.8')).toBe('es')
    expect(pickLocale(null)).toBe('es')
  })
})

describe('previewTexts', () => {
  it('names the owner and counts the games with the right plural', () => {
    expect(previewTexts({ ownerName: 'Ana', gamesCount: 120 }, 'es')).toEqual({
      title: 'Colección de juegos de mesa de Ana',
      description: '120 juegos en LudoDex',
    })
    expect(previewTexts({ ownerName: 'Ana', gamesCount: 1 }, 'es').description).toBe(
      '1 juego en LudoDex',
    )
    expect(previewTexts({ ownerName: 'Ana', gamesCount: 3 }, 'en')).toEqual({
      title: "Ana's board game collection",
      description: '3 games on LudoDex',
    })
  })

  it('falls back to neutral texts without data, never mentioning a name', () => {
    expect(previewTexts(null, 'es').title).toBe('LudoDex')
    expect(previewTexts(null, 'en').title).toBe('LudoDex')
  })
})

describe('escapeHtml', () => {
  it('escapes what could break out of a meta attribute', () => {
    expect(escapeHtml('"><script>&')).toBe('&quot;&gt;&lt;script&gt;&amp;')
  })
})
