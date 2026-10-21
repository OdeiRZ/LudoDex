<#
.SYNOPSIS
  Saca un refresh token de Gmail (scope gmail.send) para GMAIL_REFRESH_TOKEN.

.DESCRIPTION
  Abre el navegador en la pantalla de autorización de Google, recoge el código
  que Google devuelve a un servidor local temporal (127.0.0.1) y lo cambia por
  un refresh token. El token se imprime y se copia al portapapeles: NO se guarda
  en ningún fichero. Ver "Alternativa a Resend sin dominio propio (API de Gmail)"
  en api/README.md.

  Pasos: iniciar sesión con la cuenta que envía los correos (MAIL_FROM_ADDRESS),
  y, si Google avisa de "app no verificada", Avanzado -> continuar (una sola vez).

  Importante: la pantalla de consentimiento debe estar "En producción". Un token
  emitido mientras estaba en "Prueba" caduca a los 7 días.

.PARAMETER ClientId
  GMAIL_CLIENT_ID (cliente OAuth de tipo "Aplicación de escritorio").

.PARAMETER ClientSecret
  GMAIL_CLIENT_SECRET.

.PARAMETER LoginHint
  Cuenta de Gmail que envía los correos (solo para preseleccionarla).

.EXAMPLE
  powershell -File scripts/gmail-refresh-token.ps1 -ClientId "xxx.apps.googleusercontent.com" -ClientSecret "GOCSPX-..."
#>
param(
  [Parameter(Mandatory = $true)][string]$ClientId,
  [Parameter(Mandatory = $true)][string]$ClientSecret,
  [string]$LoginHint = 'soporteludodex@gmail.com',
  [int]$Port = 8085,
  # No abre el navegador: escribe la URL de autorización para abrirla a mano (p. ej. en
  # otro perfil de Chrome con la sesión de la cuenta que envía).
  [switch]$NoBrowser,
  # No imprime el token: solo lo deja en el portapapeles.
  [switch]$Quiet
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Web

$redirect = "http://127.0.0.1:$Port"
$scope = [uri]::EscapeDataString('https://www.googleapis.com/auth/gmail.send')
$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth' +
  "?client_id=$([uri]::EscapeDataString($ClientId))" +
  "&redirect_uri=$([uri]::EscapeDataString($redirect))" +
  '&response_type=code' +
  "&scope=$scope" +
  '&access_type=offline&prompt=consent' +
  "&login_hint=$([uri]::EscapeDataString($LoginHint))"

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("$redirect/")
$listener.Start()

try {
  if ($NoBrowser) {
    Write-Host "AUTH_URL=$authUrl"
  } else {
    Write-Host "Abriendo el navegador para autorizar con $LoginHint ..."
    Start-Process $authUrl
  }

  # Espera como mucho 5 minutos a que Google redirija con el código.
  $pending = $listener.GetContextAsync()
  if (-not $pending.Wait([TimeSpan]::FromMinutes(5))) { throw 'Tiempo agotado esperando la autorización.' }
  $context = $pending.Result

  $query = [System.Web.HttpUtility]::ParseQueryString($context.Request.Url.Query)
  $page = if ($query['code']) { 'Listo. Puedes cerrar esta pestaña y volver a la terminal.' } else { "Google devolvió un error: $($query['error'])" }
  $bytes = [Text.Encoding]::UTF8.GetBytes("<html><body style='font-family:sans-serif'><p>$page</p></body></html>")
  $context.Response.ContentType = 'text/html; charset=utf-8'
  $context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
  $context.Response.Close()

  if (-not $query['code']) { throw "No se recibió código de autorización (error: $($query['error']))." }

  $token = Invoke-RestMethod -Method Post -Uri 'https://oauth2.googleapis.com/token' -Body @{
    code          = $query['code']
    client_id     = $ClientId
    client_secret = $ClientSecret
    redirect_uri  = $redirect
    grant_type    = 'authorization_code'
  }

  if (-not $token.refresh_token) {
    throw 'Google no devolvió refresh_token. Revoca el acceso previo de la app en https://myaccount.google.com/permissions y repite.'
  }

  Set-Clipboard -Value $token.refresh_token
  Write-Host ''
  Write-Host 'GMAIL_REFRESH_TOKEN copiado al portapapeles.'
  if (-not $Quiet) { Write-Host $token.refresh_token }
  Write-Host ''
  Write-Host 'Pégalo en la variable GMAIL_REFRESH_TOKEN de Render (ludodex-api) y reinicia el servicio.'
}
finally {
  $listener.Stop()
}
