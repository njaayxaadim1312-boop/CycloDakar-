import { WebSocketServer } from 'ws'
import { config } from './config.js'
import { logger } from './logger.js'

/**
 * Diffusion temps réel (WebSocket).
 *
 * Usages prévus :
 *  - progression d'un rendu vidéo (phase 15) ;
 *  - suivi en direct d'une sortie de groupe (post-MVP) ;
 *  - notifications instantanées au tableau de bord du trésorier (phase 17).
 *
 * Le serveur ne fait QUE diffuser. Il ne décide rien, ne stocke rien et
 * n'authentifie personne par lui-même : les clients s'abonnent à des canaux
 * dont Laravel leur a donné l'identifiant.
 */

/** @type {Map<string, Set<import('ws').WebSocket>>} */
const channels = new Map()

export function createRealtimeServer(httpServer) {
  const wss = new WebSocketServer({ server: httpServer, path: '/ws' })

  wss.on('connection', (socket, request) => {
    const origin = request.headers.origin

    // En production, on n'accepte que les origines déclarées.
    if (config.env !== 'development' && origin && !config.allowedOrigins.includes(origin)) {
      logger.warn({ origin }, 'Connexion WebSocket refusée (origine non autorisée)')
      socket.close(1008, 'Origine non autorisée')
      return
    }

    socket.isAlive = true
    socket.channels = new Set()

    socket.on('pong', () => {
      socket.isAlive = true
    })

    socket.on('message', (raw) => {
      let message
      try {
        message = JSON.parse(raw.toString())
      } catch {
        socket.send(JSON.stringify({ type: 'error', message: 'JSON invalide.' }))
        return
      }

      if (message.type === 'subscribe' && typeof message.channel === 'string') {
        subscribe(socket, message.channel)
        socket.send(JSON.stringify({ type: 'subscribed', channel: message.channel }))
        return
      }

      if (message.type === 'unsubscribe' && typeof message.channel === 'string') {
        unsubscribe(socket, message.channel)
        return
      }

      if (message.type === 'ping') {
        socket.send(JSON.stringify({ type: 'pong' }))
      }
    })

    socket.on('close', () => {
      for (const channel of socket.channels) {
        unsubscribe(socket, channel)
      }
    })

    socket.send(JSON.stringify({ type: 'welcome', service: 'cyclo-dakar-realtime' }))
  })

  // Détection des connexions mortes : sur mobile, une coupure réseau ne ferme
  // pas proprement la socket. Sans ce ping, on accumule des sockets fantômes.
  const heartbeat = setInterval(() => {
    for (const socket of wss.clients) {
      if (socket.isAlive === false) {
        socket.terminate()
        continue
      }
      socket.isAlive = false
      socket.ping()
    }
  }, 30_000)

  wss.on('close', () => clearInterval(heartbeat))

  logger.info('WebSocket disponible sur /ws')
  return wss
}

function subscribe(socket, channel) {
  if (!channels.has(channel)) channels.set(channel, new Set())
  channels.get(channel).add(socket)
  socket.channels.add(channel)
}

function unsubscribe(socket, channel) {
  channels.get(channel)?.delete(socket)
  if (channels.get(channel)?.size === 0) channels.delete(channel)
  socket.channels.delete(channel)
}

/**
 * Diffuse un événement à tous les abonnés d'un canal.
 * @param {string} channel  ex. `video-job.9f2c...`
 * @param {object} payload
 */
export function broadcast(channel, payload) {
  const subscribers = channels.get(channel)
  if (!subscribers?.size) return 0

  const frame = JSON.stringify({ type: 'event', channel, payload })
  let delivered = 0

  for (const socket of subscribers) {
    if (socket.readyState === socket.OPEN) {
      socket.send(frame)
      delivered += 1
    }
  }

  return delivered
}

export function channelStats() {
  return {
    channels: channels.size,
    subscribers: [...channels.values()].reduce((total, set) => total + set.size, 0),
  }
}
