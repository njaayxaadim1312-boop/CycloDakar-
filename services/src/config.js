import 'dotenv/config'

/**
 * Configuration du service Node.
 *
 * Le service ne touche JAMAIS à MySQL (voir docs/architecture.md, règle d'or) :
 * il n'a donc besoin d'aucun identifiant de base. Sa seule dépendance est
 * Laravel, qu'il rappelle par un webhook signé.
 */

function required(name) {
  const value = process.env[name]
  if (!value) {
    throw new Error(
      `Variable d'environnement manquante : ${name}. ` +
        'Copiez .env.example en .env et renseignez-la.',
    )
  }
  return value
}

export const config = {
  env: process.env.NODE_ENV ?? 'development',
  port: Number(process.env.PORT ?? 4000),

  // URL de l'API Laravel, que Node rappelle en fin de rendu.
  laravelUrl: (process.env.LARAVEL_API_URL ?? 'http://127.0.0.1:8000').replace(/\/+$/, ''),

  /**
   * Secret partagé avec Laravel (NODE_SERVICE_SECRET côté backend).
   * Toutes les requêtes entre les deux services sont signées en HMAC-SHA256 :
   * aucun token utilisateur ne circule ici, c'est un service qui parle à un
   * service.
   */
  serviceSecret: required('SERVICE_SECRET'),

  // Fenêtre de tolérance de l'horodatage des signatures, en secondes.
  // Empêche le rejeu d'une requête interceptée.
  signatureToleranceS: Number(process.env.SIGNATURE_TOLERANCE_S ?? 300),

  // Origines autorisées pour le WebSocket (le navigateur envoie Origin).
  allowedOrigins: (process.env.ALLOWED_ORIGINS ?? 'http://localhost:5173')
    .split(',')
    .map((origin) => origin.trim())
    .filter(Boolean),

  video: {
    // Répertoire de travail du rendu (phase 15).
    workDir: process.env.VIDEO_WORK_DIR ?? './storage/video',
    ffmpegPath: process.env.FFMPEG_PATH ?? 'ffmpeg',
    // Nombre de rendus simultanés : le rendu vidéo est gourmand, on le borne.
    concurrency: Number(process.env.VIDEO_CONCURRENCY ?? 1),
  },

  logLevel: process.env.LOG_LEVEL ?? 'info',
}
