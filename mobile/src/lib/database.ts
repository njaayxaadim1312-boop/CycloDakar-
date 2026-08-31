import * as SQLite from 'expo-sqlite'

/**
 * Base locale de l'application mobile.
 *
 * C'est la pièce maîtresse du mode hors ligne : pendant une sortie, TOUT est
 * écrit ici, immédiatement. Le réseau n'intervient qu'après.
 *
 * Trois exigences ont façonné ce schéma :
 *
 * 1. **Survivre à un arrêt brutal.** Batterie vide, Android qui tue le
 *    processus (Xiaomi, Oppo, Tecno sont agressifs) : au redémarrage, la
 *    sortie doit être intacte et reprenable. D'où le mode WAL et l'écriture
 *    à chaque point, jamais en mémoire seule.
 *
 * 2. **Être écrite depuis deux contextes.** La tâche de localisation en
 *    arrière-plan tourne dans un contexte JavaScript SÉPARÉ de l'interface :
 *    elle ne peut pas toucher l'état React. SQLite est le seul terrain
 *    d'entente entre les deux.
 *
 * 3. **Ne pas recalculer 10 000 points à chaque affichage.** Les statistiques
 *    courantes sont accumulées sur la ligne de l'activité au fur et à mesure.
 */

const DATABASE_NAME = 'cyclodakar.db'

let connection: SQLite.SQLiteDatabase | null = null

/**
 * Ouvre la base et applique le schéma.
 *
 * Appelée à chaque accès : `openDatabaseAsync` réutilise la connexion
 * existante, et la tâche de fond n'a aucun moyen de savoir si l'interface l'a
 * déjà ouverte.
 */
export async function getDatabase(): Promise<SQLite.SQLiteDatabase> {
  if (connection !== null) {
    return connection
  }

  connection = await SQLite.openDatabaseAsync(DATABASE_NAME)

  await connection.execAsync(`
    -- WAL : permet d'écrire pendant qu'on lit, et surtout résiste à une
    -- coupure brutale. Sans lui, une batterie vide en pleine sortie pourrait
    -- corrompre le fichier et perdre l'enregistrement entier.
    PRAGMA journal_mode = WAL;
    PRAGMA synchronous = NORMAL;

    CREATE TABLE IF NOT EXISTS activities (
      uuid            TEXT PRIMARY KEY NOT NULL,
      sport           TEXT NOT NULL,
      -- RECORDING | PAUSED | FINISHED
      status          TEXT NOT NULL,
      started_at      TEXT NOT NULL,
      ended_at        TEXT,

      -- Statistiques accumulées au fil de l'eau, pour l'affichage en direct.
      -- Ce sont des valeurs PROVISOIRES : le serveur recalcule tout à la
      -- finalisation, à partir des points bruts.
      distance_m      REAL NOT NULL DEFAULT 0,
      moving_ms       INTEGER NOT NULL DEFAULT 0,
      paused_ms       INTEGER NOT NULL DEFAULT 0,
      max_speed_mps   REAL NOT NULL DEFAULT 0,
      elevation_gain_m REAL NOT NULL DEFAULT 0,

      last_seq        INTEGER NOT NULL DEFAULT 0,
      raw_count       INTEGER NOT NULL DEFAULT 0,

      -- État de la synchronisation, pour reprendre exactement où l'on s'est
      -- arrêté après une coupure.
      server_created  INTEGER NOT NULL DEFAULT 0,
      synced_seq      INTEGER NOT NULL DEFAULT 0,
      finalized       INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS points (
      activity_uuid TEXT NOT NULL,
      seq           INTEGER NOT NULL,
      lat           REAL NOT NULL,
      lng           REAL NOT NULL,
      altitude_m    REAL,
      speed_mps     REAL,
      accuracy_m    REAL,
      heading_deg   REAL,
      recorded_at   TEXT NOT NULL,
      is_paused     INTEGER NOT NULL DEFAULT 0,

      -- Même clé que côté serveur : rejouer une écriture est sans effet.
      PRIMARY KEY (activity_uuid, seq)
    );

    CREATE INDEX IF NOT EXISTS points_sync
      ON points (activity_uuid, seq);
  `)

  return connection
}

/* -------------------------------------------------------------------------- */
/* Types                                                                      */
/* -------------------------------------------------------------------------- */

export type LocalActivityStatus = 'RECORDING' | 'PAUSED' | 'FINISHED'

export interface LocalActivity {
  uuid: string
  sport: string
  status: LocalActivityStatus
  started_at: string
  ended_at: string | null
  distance_m: number
  moving_ms: number
  paused_ms: number
  max_speed_mps: number
  elevation_gain_m: number
  last_seq: number
  raw_count: number
  server_created: number
  synced_seq: number
  finalized: number
}

export interface LocalPoint {
  activity_uuid: string
  seq: number
  lat: number
  lng: number
  altitude_m: number | null
  speed_mps: number | null
  accuracy_m: number | null
  heading_deg: number | null
  recorded_at: string
  is_paused: number
}

/* -------------------------------------------------------------------------- */
/* Activités                                                                  */
/* -------------------------------------------------------------------------- */

export async function createLocalActivity(
  uuid: string,
  sport: string,
  startedAt: string,
): Promise<void> {
  const db = await getDatabase()

  await db.runAsync(
    `INSERT OR IGNORE INTO activities (uuid, sport, status, started_at)
     VALUES (?, ?, 'RECORDING', ?)`,
    [uuid, sport, startedAt],
  )
}

export async function getLocalActivity(uuid: string): Promise<LocalActivity | null> {
  const db = await getDatabase()

  return db.getFirstAsync<LocalActivity>(
    'SELECT * FROM activities WHERE uuid = ?',
    [uuid],
  )
}

/**
 * Activité en cours d'enregistrement, s'il y en a une.
 *
 * C'est ce qui permet de proposer une reprise au redémarrage : si Android a
 * tué l'application en pleine sortie, la trace est toujours là.
 */
export async function getOngoingActivity(): Promise<LocalActivity | null> {
  const db = await getDatabase()

  return db.getFirstAsync<LocalActivity>(
    `SELECT * FROM activities
     WHERE status IN ('RECORDING', 'PAUSED')
     ORDER BY started_at DESC LIMIT 1`,
  )
}

/** Activités terminées mais pas encore transmises au serveur. */
export async function getPendingActivities(): Promise<LocalActivity[]> {
  const db = await getDatabase()

  return db.getAllAsync<LocalActivity>(
    `SELECT * FROM activities
     WHERE status = 'FINISHED' AND finalized = 0
     ORDER BY started_at ASC`,
  )
}

export async function setActivityStatus(
  uuid: string,
  status: LocalActivityStatus,
): Promise<void> {
  const db = await getDatabase()
  await db.runAsync('UPDATE activities SET status = ? WHERE uuid = ?', [status, uuid])
}

export async function finishLocalActivity(uuid: string, endedAt: string): Promise<void> {
  const db = await getDatabase()

  await db.runAsync(
    `UPDATE activities SET status = 'FINISHED', ended_at = ? WHERE uuid = ?`,
    [endedAt, uuid],
  )
}

/**
 * Met à jour les statistiques courantes et enregistre un point, en une seule
 * transaction.
 *
 * Les deux vont ensemble : si le point était écrit sans que la distance le
 * soit, un arrêt brutal entre les deux laisserait un compteur faux.
 */
export async function appendPoint(
  point: LocalPoint,
  deltas: {
    distanceM: number
    movingMs: number
    pausedMs: number
    speedMps: number
    elevationGainM: number
  },
): Promise<void> {
  const db = await getDatabase()

  await db.withTransactionAsync(async () => {
    await db.runAsync(
      `INSERT OR IGNORE INTO points
         (activity_uuid, seq, lat, lng, altitude_m, speed_mps, accuracy_m,
          heading_deg, recorded_at, is_paused)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        point.activity_uuid,
        point.seq,
        point.lat,
        point.lng,
        point.altitude_m,
        point.speed_mps,
        point.accuracy_m,
        point.heading_deg,
        point.recorded_at,
        point.is_paused,
      ],
    )

    await db.runAsync(
      `UPDATE activities SET
         distance_m       = distance_m + ?,
         moving_ms        = moving_ms + ?,
         paused_ms        = paused_ms + ?,
         max_speed_mps    = MAX(max_speed_mps, ?),
         elevation_gain_m = elevation_gain_m + ?,
         last_seq         = MAX(last_seq, ?)
       WHERE uuid = ?`,
      [
        deltas.distanceM,
        deltas.movingMs,
        deltas.pausedMs,
        deltas.speedMps,
        deltas.elevationGainM,
        point.seq,
        point.activity_uuid,
      ],
    )
  })
}

/** Compte un point reçu, qu'il ait été retenu ou rejeté par le filtre. */
export async function countRawPoint(uuid: string): Promise<void> {
  const db = await getDatabase()
  await db.runAsync('UPDATE activities SET raw_count = raw_count + 1 WHERE uuid = ?', [uuid])
}

/* -------------------------------------------------------------------------- */
/* Points                                                                     */
/* -------------------------------------------------------------------------- */

/** Dernier point enregistré — sert de référence au filtre. */
export async function getLastPoint(uuid: string): Promise<LocalPoint | null> {
  const db = await getDatabase()

  return db.getFirstAsync<LocalPoint>(
    'SELECT * FROM points WHERE activity_uuid = ? ORDER BY seq DESC LIMIT 1',
    [uuid],
  )
}

/**
 * Points restant à transmettre, par lots.
 *
 * On ne récupère que ce qui suit le dernier `seq` confirmé par le serveur :
 * c'est ce qui rend la reprise après coupure exacte, sans tout renvoyer.
 */
export async function getPointsToSync(
  uuid: string,
  afterSeq: number,
  limit = 500,
): Promise<LocalPoint[]> {
  const db = await getDatabase()

  return db.getAllAsync<LocalPoint>(
    `SELECT * FROM points
     WHERE activity_uuid = ? AND seq > ?
     ORDER BY seq ASC LIMIT ?`,
    [uuid, afterSeq, limit],
  )
}

export async function countPoints(uuid: string): Promise<number> {
  const db = await getDatabase()

  const row = await db.getFirstAsync<{ total: number }>(
    'SELECT COUNT(*) AS total FROM points WHERE activity_uuid = ?',
    [uuid],
  )

  return row?.total ?? 0
}

/**
 * Trace allégée pour la carte.
 *
 * On décime : afficher 10 000 points ferait ramer la carte sur un téléphone
 * d'entrée de gamme, alors qu'un point sur N donne exactement le même tracé à
 * l'échelle de l'écran.
 */
export async function getTraceForMap(
  uuid: string,
  maxPoints = 500,
): Promise<{ latitude: number; longitude: number }[]> {
  const db = await getDatabase()
  const total = await countPoints(uuid)
  const step = Math.max(1, Math.ceil(total / maxPoints))

  const rows = await db.getAllAsync<{ lat: number; lng: number }>(
    `SELECT lat, lng FROM points
     WHERE activity_uuid = ? AND is_paused = 0 AND (seq % ?) = 0
     ORDER BY seq ASC`,
    [uuid, step],
  )

  return rows.map((row) => ({ latitude: row.lat, longitude: row.lng }))
}

/* -------------------------------------------------------------------------- */
/* Synchronisation                                                            */
/* -------------------------------------------------------------------------- */

export async function markServerCreated(uuid: string): Promise<void> {
  const db = await getDatabase()
  await db.runAsync('UPDATE activities SET server_created = 1 WHERE uuid = ?', [uuid])
}

export async function markSyncedUpTo(uuid: string, seq: number): Promise<void> {
  const db = await getDatabase()

  await db.runAsync(
    'UPDATE activities SET synced_seq = MAX(synced_seq, ?) WHERE uuid = ?',
    [seq, uuid],
  )
}

export async function markFinalized(uuid: string): Promise<void> {
  const db = await getDatabase()
  await db.runAsync('UPDATE activities SET finalized = 1 WHERE uuid = ?', [uuid])
}

/**
 * Purge les traces transmises depuis plus de N jours.
 *
 * Les téléphones d'entrée de gamme du club ont 32 Go : garder indéfiniment
 * des centaines de milliers de points finirait par saturer le stockage, alors
 * que le serveur en détient déjà la copie de référence.
 */
export async function purgeSyncedActivities(olderThanDays = 7): Promise<number> {
  const db = await getDatabase()
  const cutoff = new Date(Date.now() - olderThanDays * 86_400_000).toISOString()

  const result = await db.runAsync(
    `DELETE FROM points WHERE activity_uuid IN (
       SELECT uuid FROM activities WHERE finalized = 1 AND started_at < ?
     )`,
    [cutoff],
  )

  await db.runAsync(
    'DELETE FROM activities WHERE finalized = 1 AND started_at < ?',
    [cutoff],
  )

  return result.changes
}

/** Réservé aux tests : vide entièrement la base locale. */
export async function resetDatabase(): Promise<void> {
  const db = await getDatabase()
  await db.execAsync('DELETE FROM points; DELETE FROM activities;')
}
