import crypto from 'node:crypto'
import { config } from './config.js'

/**
 * Signature HMAC des échanges Laravel <-> Node.
 *
 * Contrat (identique des deux côtés) :
 *
 *   base       = `${timestamp}.${corps JSON brut}`
 *   signature  = HMAC-SHA256(base, secret) en hexadécimal
 *
 *   En-têtes :  X-Cyclo-Timestamp: <epoch secondes>
 *               X-Cyclo-Signature: <hex>
 *
 * L'horodatage est inclus dans la base signée : sans lui, une requête
 * interceptée pourrait être rejouée indéfiniment.
 */

export function sign(rawBody, timestamp = Math.floor(Date.now() / 1000)) {
  const signature = crypto
    .createHmac('sha256', config.serviceSecret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex')

  return { timestamp, signature }
}

/**
 * @returns {{ok: true} | {ok: false, reason: string}}
 */
export function verify(rawBody, timestamp, signature) {
  if (!timestamp || !signature) {
    return { ok: false, reason: 'Signature ou horodatage absent.' }
  }

  const age = Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp))
  if (!Number.isFinite(age) || age > config.signatureToleranceS) {
    return { ok: false, reason: 'Horodatage hors de la fenêtre de tolérance.' }
  }

  const expected = crypto
    .createHmac('sha256', config.serviceSecret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex')

  const a = Buffer.from(expected, 'utf8')
  const b = Buffer.from(String(signature), 'utf8')

  // Comparaison à temps constant : une comparaison naïve (===) fuit la
  // signature attendue octet par octet via le temps de réponse.
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return { ok: false, reason: 'Signature invalide.' }
  }

  return { ok: true }
}

/**
 * Middleware Express : refuse toute requête non signée par Laravel.
 * À appliquer sur /render et toute route interne.
 */
export function requireSignature(req, res, next) {
  const rawBody = req.rawBody ?? ''
  const result = verify(
    rawBody,
    req.get('X-Cyclo-Timestamp'),
    req.get('X-Cyclo-Signature'),
  )

  if (!result.ok) {
    req.log?.warn({ reason: result.reason, path: req.path }, 'Requête non signée rejetée')
    return res.status(401).json({ message: 'Requête non authentifiée.', code: 'BAD_SIGNATURE' })
  }

  return next()
}
