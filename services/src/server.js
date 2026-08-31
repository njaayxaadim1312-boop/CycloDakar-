import http from 'node:http'
import cors from 'cors'
import express from 'express'
import helmet from 'helmet'
import { config } from './config.js'
import { logger } from './logger.js'
import { channelStats, createRealtimeServer } from './realtime.js'
import { requireSignature } from './signature.js'

/**
 * Service Node.js de Cyclo Dakar.
 *
 * Périmètre volontairement étroit (docs/architecture.md) :
 *   - WebSocket temps réel ;
 *   - rendu vidéo FFmpeg (phase 15).
 *
 * Tout le reste — authentification, membres, activités, finances — appartient
 * à Laravel. Ce service n'a AUCUN accès à la base de données.
 */

const app = express()

app.disable('x-powered-by')
app.use(helmet())
app.use(cors({ origin: config.allowedOrigins }))

// On conserve le corps brut : la vérification HMAC porte sur les octets
// exactement tels qu'ils ont été signés, pas sur un JSON re-sérialisé.
app.use(
  express.json({
    limit: '2mb',
    verify: (req, _res, buf) => {
      req.rawBody = buf.toString('utf8')
    },
  }),
)

app.use((req, _res, next) => {
  req.log = logger.child({ requestId: crypto.randomUUID() })
  next()
})

/* -------------------------------------------------------------------------- */
/* Routes publiques                                                            */
/* -------------------------------------------------------------------------- */

app.get('/health', (_req, res) => {
  res.json({
    data: {
      service: 'cyclo-dakar-services',
      status: 'healthy',
      environment: config.env,
      node: process.version,
      uptime_s: Math.round(process.uptime()),
      realtime: channelStats(),
      // Le rendu vidéo arrive en phase 15 ; l'infrastructure est prête.
      video: { enabled: false, phase: 15 },
    },
  })
})

/* -------------------------------------------------------------------------- */
/* Routes internes — appelées uniquement par Laravel, signées en HMAC          */
/* -------------------------------------------------------------------------- */

/**
 * Sonde d'authentification : permet à Laravel de vérifier que le secret
 * partagé est correctement configuré des deux côtés, sans rien déclencher.
 */
app.post('/internal/ping', requireSignature, (req, res) => {
  res.json({
    data: {
      pong: true,
      received_at: new Date().toISOString(),
      echo: req.body ?? null,
    },
  })
})

/**
 * PHASE 15 — Lancement d'un rendu vidéo.
 *
 * L'architecture est en place (signature, file, WebSocket de progression,
 * webhook de retour) ; le moteur de rendu lui-même est REPORTÉ À LA PHASE 15.
 * On renvoie 501 plutôt qu'un faux succès : un client ne doit jamais croire
 * qu'un rendu a démarré alors que rien ne tourne.
 */
app.post('/render', requireSignature, (req, res) => {
  req.log.info({ jobId: req.body?.job_id }, 'Demande de rendu reçue (non implémentée)')

  res.status(501).json({
    message: 'Le moteur de rendu vidéo sera livré en phase 15.',
    code: 'NOT_IMPLEMENTED',
    phase: 15,
  })
})

/* -------------------------------------------------------------------------- */

app.use((_req, res) => {
  res.status(404).json({ message: 'Route inconnue.', code: 'NOT_FOUND' })
})

app.use((error, req, res, _next) => {
  req.log?.error({ err: error }, 'Erreur non gérée')
  res.status(500).json({
    message: config.env === 'development' ? error.message : 'Erreur interne du service.',
    code: 'INTERNAL_ERROR',
  })
})

/* -------------------------------------------------------------------------- */

const server = http.createServer(app)
createRealtimeServer(server)

server.listen(config.port, () => {
  logger.info(
    `Service Cyclo Dakar démarré sur http://localhost:${config.port} (${config.env})`,
  )
  logger.info(`API Laravel attendue sur ${config.laravelUrl}`)
})

// Arrêt propre : on laisse les rendus en cours se terminer plutôt que de
// couper au milieu d'un encodage FFmpeg.
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    logger.info(`${signal} reçu, arrêt du service…`)
    server.close(() => process.exit(0))
    setTimeout(() => process.exit(1), 10_000).unref()
  })
}

export { app, server }
