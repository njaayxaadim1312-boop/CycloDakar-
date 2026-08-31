import L from 'leaflet'
import { useMemo } from 'react'
import { MapContainer, Marker, Polyline, TileLayer } from 'react-leaflet'
import { decodePolyline } from '@/lib/polyline'
import type { ActivityBounds } from '@/types/api'
import 'leaflet/dist/leaflet.css'

interface ActivityMapProps {
  polyline: string | null
  bounds: ActivityBounds | null
  height?: number
  /** Masque les marqueurs de départ et d'arrivée — utile pour une miniature. */
  compact?: boolean
}

/**
 * Trace d'une sortie sur une carte.
 *
 * **OpenStreetMap** et non Mapbox : coût nul, aucune clé API à gérer, et le
 * fournisseur reste interchangeable (ADR-004). Le fond satellite du prototype
 * demanderait Mapbox — c'est un choix à faire quand le club aura mesuré son
 * volume réel de consultations.
 *
 * La trace vient de la polyline encodée, jamais des points bruts : ~1 Ko
 * contre ~500 Ko.
 */
export function ActivityMap({
  polyline,
  bounds,
  height = 320,
  compact = false,
}: ActivityMapProps) {
  const points = useMemo(() => decodePolyline(polyline), [polyline])

  const positions = useMemo<[number, number][]>(
    () => points.map((p) => [p.lat, p.lng]),
    [points],
  )

  if (positions.length === 0) {
    return (
      <div
        className="flex items-center justify-center rounded-[var(--cd-radius)] border border-[var(--cd-border)] bg-[var(--cd-surface-2)] text-sm text-[var(--cd-text-muted)]"
        style={{ height }}
      >
        Aucune trace enregistrée pour cette sortie.
      </div>
    )
  }

  // On cadre sur les limites fournies par le serveur plutôt que de les
  // recalculer : elles portent sur la trace COMPLÈTE, là où la polyline est
  // simplifiée et pourrait avoir perdu un point extrême.
  const fitBounds: [[number, number], [number, number]] = bounds
    ? [
        [bounds.min_lat, bounds.min_lng],
        [bounds.max_lat, bounds.max_lng],
      ]
    : [positions[0]!, positions[positions.length - 1]!]

  return (
    <div
      className="overflow-hidden rounded-[var(--cd-radius)] border border-[var(--cd-border)]"
      style={{ height }}
    >
      <MapContainer
        bounds={fitBounds}
        boundsOptions={{ padding: [24, 24] }}
        scrollWheelZoom={!compact}
        dragging={!compact}
        zoomControl={!compact}
        attributionControl={!compact}
        style={{ height: '100%', width: '100%' }}
      >
        <TileLayer
          // L'attribution est une OBLIGATION de la licence OpenStreetMap,
          // pas une politesse.
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          maxZoom={19}
        />

        <Polyline
          positions={positions}
          pathOptions={{
            // Vert « trace » de la charte, en valeur littérale : Leaflet pose
            // cette couleur comme attribut SVG via JavaScript, où une variable
            // CSS ne serait pas résolue de façon fiable.
            color: '#32CD32',
            weight: 4,
            opacity: 0.9,
            lineJoin: 'round',
            lineCap: 'round',
          }}
        />

        {!compact && (
          <>
            <Marker position={positions[0]!} icon={startIcon} />
            <Marker position={positions[positions.length - 1]!} icon={endIcon} />
          </>
        )}
      </MapContainer>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Marqueurs dessinés en HTML plutôt qu'en images.
 *
 * Les icônes par défaut de Leaflet sont livrées comme fichiers PNG dont les
 * chemins cassent systématiquement avec un empaqueteur moderne. Un `divIcon`
 * évite le problème, suit le thème, et pèse zéro requête.
 */
function pinIcon(color: string, label: string): L.DivIcon {
  return L.divIcon({
    className: '',
    html: `
      <span style="
        display:flex;align-items:center;justify-content:center;
        width:24px;height:24px;border-radius:999px;
        background:${color};color:#1a1a1a;
        font:700 11px/1 system-ui, sans-serif;
        border:2px solid #fff;
        box-shadow:0 1px 4px rgba(0,0,0,.35);
      ">${label}</span>`,
    iconSize: [24, 24],
    iconAnchor: [12, 12],
  })
}

const startIcon = pinIcon('#32CD32', 'D')
const endIcon = pinIcon('#FF8C00', 'A')
